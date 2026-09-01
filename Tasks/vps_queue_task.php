<?php

use MyAdmin\App;
use Workerman\Worker;

require_once __DIR__.'/../Applications/Chat/SharedState.php';

function vps_queue_task($args)
{
    require_once '/home/my/include/functions.inc.php';
    /*
     * REVIEW-FIX (decision B): the per-host lock is now HANDED DOWN by the
     * producer instead of being re-acquired here.
     *
     * Events::vps_queue_timer() takes dc:lock:vps_host_<id> and then dispatches
     * this task, whose first act used to be SharedState::lock() on the SAME key
     * — which therefore ALWAYS returned null, so the whole body was skipped and
     * the task returned ''. Every dispatch was a no-op; the timer has been dead
     * for as long as both sides have contended on that key (it behaved
     * identically under GlobalData's cas($var, 0, 1)). Audited callers: this
     * task has exactly ONE dispatcher, Events::vps_queue_timer(), so adopting
     * its token is safe.
     *
     * Adopting the producer's token also fixes the other half: Events held the
     * lock across an untimed dispatchTask round trip with NO renewal, so once
     * this task started doing real (SOAP) work the TTL could lapse and the next
     * 30s tick would dispatch a duplicate for the same host. The renewals below
     * now actually extend a lock we really own, which is what keeps the "one
     * command per host at a time" invariant true during long operations.
     *
     * Compatibility: with no lock_token in $args (a future/other caller) the
     * task acquires and releases its own lock exactly as before, so it is never
     * left running unprotected.
     */
    $inheritedToken = (string) ($args['lock_token'] ?? '');
    $inheritedFor = isset($args['id']) ? (int) $args['id'] : 0;
    $db = App::db();
    $db->bindValue('id', (int)$args['id'], 'int');
    $db->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_id=:id", __LINE__, __FILE__);
    $rows = [];
    $sids = [];
    $output = '';
    while ($db->next_record(MYSQL_ASSOC)) {
        $db->Record['newvps'] = [];
        $db->Record['queue'] = [];
        $rows[$db->Record['vps_id']] = $db->Record;
        $sids[] = $db->Record['vps_id'];
    }
    $sids = array_map('intval', $sids);
    $db->query("select * from vps where vps_status='pending-setup' and vps_server in (".implode(',', $sids).")", __LINE__, __FILE__);
    if ($db->num_rows() > 0) {
        while ($db->next_record(MYSQL_ASSOC)) {
            $rows[$db->Record['vps_server']]['newvps'][] = $db->Record;
        }
    }
    $db->query("select vps.*, hl1.* from vps, queue_log as hl1 left join queue_log as hl2 on hl2.history_type=hl1.history_id and hl2.history_section='vpsqueuedone' where hl1.history_section='vpsqueue' and hl1.history_type=vps_id and hl2.history_id is null and vps_server in (".implode(',', $sids).")", __LINE__, __FILE__);
    if ($db->num_rows() > 0) {
        while ($db->next_record(MYSQL_ASSOC)) {
            $rows[$db->Record['vps_server']]['queue'][] = $db->Record;
        }
    }
    foreach ($rows as $service_id => $service_master) {
        if (sizeof($service_master['newvps']) > 0 || sizeof($service_master['queue']) > 0) {
            $lockName = 'vps_host_'.$service_id;
            /*
             * Adopt the producer's hold when it is for THIS host (the driving
             * query is `where vps_id=:id`, so normally one row, but never adopt a
             * token across hosts if that ever changes). Otherwise take our own.
             *
             * TTL note: SharedState::VPS_HOST_LOCK_TTL exceeds both the old 900s
             * GlobalData stale-reap window and default_socket_timeout, per ops
             * requirement — HyperV GetVMList can take 10+ minutes, so the lock
             * must never expire before the operation it guards.
             */
            $adoptedLock = $inheritedToken !== '' && $inheritedFor === (int) $service_id;
            if ($adoptedLock) {
                $token = $inheritedToken;
                // Confirm the handed-down hold is still ours before doing any
                // work: false means it lapsed or was taken, so this dispatch is
                // stale and must not touch the host.
                if (!SharedState::renew($lockName, $token, SharedState::VPS_HOST_LOCK_TTL)) {
                    Worker::safeEcho("vps_queue_task inherited a stale hold on {$lockName} for {$service_master['vps_name']} — skipping this host\n");
                    continue;
                }
            } else {
                // A null token means another process already holds the host lock
                // (or Redis is unavailable) — the same "skip, retry next tick"
                // outcome the retired cas() produced. Absence == free, so the old
                // isset-seed / value-reset are no longer needed.
                $token = SharedState::lock($lockName, SharedState::VPS_HOST_LOCK_TTL);
            }
            if ($token !== null) {
                try {
                    function_requirements('vps_queue_handler');
                    if (sizeof($service_master['newvps']) > 0) {
                        myadmin_log('myadmin', 'info', 'Processing New VPS for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
                        $output .= vps_queue_handler($service_master, 'get_new_vps', $service_master['newvps']);
                    }
                    if (sizeof($service_master['queue']) > 0) {
                        // Renew between handlers: a false means the lock expired or was
                        // taken — never run another handler holding nothing. The
                        // finally-unlock below is owner-checked and no-ops on a lost lock.
                        if (!SharedState::renew($lockName, $token, SharedState::VPS_HOST_LOCK_TTL)) {
                            Worker::safeEcho("vps_queue_task lost lock {$lockName} before get_queue for {$service_master['vps_name']} — skipping remaining handlers\n");
                            continue;
                        }
                        myadmin_log('myadmin', 'info', 'Processing VPS Queue for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
                        $output .= vps_queue_handler($service_master, 'get_queue', $service_master['queue']);
                    }
                    if (!SharedState::renew($lockName, $token, SharedState::VPS_HOST_LOCK_TTL)) {
                        Worker::safeEcho("vps_queue_task lost lock {$lockName} before server_list for {$service_master['vps_name']} — skipping remaining handlers\n");
                        continue;
                    }
                    $output .= vps_queue_handler($service_master, 'server_list');
                    if (trim($output) != '') {
                        //echo "Got Output To Send: $output\n";
                    }
                } finally {
                    // Only release a lock we took ourselves. An ADOPTED hold
                    // belongs to the producer, whose dispatchTask callbacks
                    // release it (on both the result and error legs) — releasing
                    // it here would free the host while the producer still
                    // believes it is held, letting the next 30s tick start a
                    // second command against the same host.
                    if (!$adoptedLock) {
                        SharedState::unlock($lockName, $token);
                    }
                }
            }
        }
    }
    return $output;
}
