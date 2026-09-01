<?php

use MyAdmin\App;
use Workerman\Worker;

require_once __DIR__.'/../Applications/Chat/SharedState.php';

function vps_queue_task($args)
{
    require_once '/home/my/include/functions.inc.php';
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
            // SET NX + TTL 900 replaces cas($var, 0, 1). A null token means another
            // process already holds the host lock (or Redis is unavailable) — the same
            // "skip, retry next tick" outcome the retired cas() produced. Absence == free,
            // so the old isset-seed / value-reset are no longer needed. The 900s TTL
            // mirrors the old 900s GlobalData stale-reap window per ops requirement:
            // HyperV GetVMList can take 10+ minutes, so the lock must never expire
            // before the operation it guards. Each handler below renews while owned.
            $token = SharedState::lock($lockName, 900);
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
                        if (!SharedState::renew($lockName, $token, 900)) {
                            Worker::safeEcho("vps_queue_task lost lock {$lockName} before get_queue for {$service_master['vps_name']} — skipping remaining handlers\n");
                            continue;
                        }
                        myadmin_log('myadmin', 'info', 'Processing VPS Queue for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
                        $output .= vps_queue_handler($service_master, 'get_queue', $service_master['queue']);
                    }
                    if (!SharedState::renew($lockName, $token, 900)) {
                        Worker::safeEcho("vps_queue_task lost lock {$lockName} before server_list for {$service_master['vps_name']} — skipping remaining handlers\n");
                        continue;
                    }
                    $output .= vps_queue_handler($service_master, 'server_list');
                    if (trim($output) != '') {
                        //echo "Got Output To Send: $output\n";
                    }
                } finally {
                    SharedState::unlock($lockName, $token);
                }
            }
        }
    }
    return $output;
}
