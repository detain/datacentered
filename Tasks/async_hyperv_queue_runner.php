<?php

use MyAdmin\App;
use Workerman\Worker;

require_once __DIR__.'/../Applications/Chat/SharedState.php';

function async_hyperv_queue_runner($args)
{
    require_once '/home/my/include/functions.inc.php';
    App::session()->sessionid = 'datacentered';
    App::session()->account_id = 160307;
    // Default to the 'services' ima for the 160307 service account. With a blank
    // ima the session is treated as 'client', which makes get_service() enforce
    // that the service owner matches account_id; that check fails for real
    // customers' services during activation (e.g. the welcome email) since we sit
    // on 160307 here. 'services' bypasses the ownership check (matches the baseline
    // in /home/sites/mystage/public_html/vps_queue.php). Set both the session
    // appnocache copy and tf->ima so every consumer sees it.
    App::session()->appnocache('ima', 'services');
    App::tf()->ima = 'services';
    $service_id = $args['id'];
    $service_master = $args['data'];
    $lockName = 'vps_host_'.$service_id;
    // Debug sibling of the lock: the op currently running. JSON value under the full
    // dc:lock: key (guard passes it), read back below when this process fails to acquire.
    // Pure observability: every set() below passes the lock family's TTL — keys are
    // overwritten each cycle, so the TTL only bounds orphans left by decommissioned hosts.
    $requestKey = SharedState::requestKey('vps_host_'.$service_id);
    // SET NX + TTL VPS_HOST_LOCK_TTL replaces cas($var, 0, time()). The former 900s
    // stale-lock reaper is gone: a crashed holder's lock now simply expires on its own
    // — and the TTL (>= the old 900s window) keeps
    // that self-heal window identical to the reaper it replaced, per ops requirement:
    // HyperV GetVMList can take 10+ minutes, so the lock must never expire before the
    // operation it guards. Every handler below renews while the lock is still owned.
    $token = SharedState::lock($lockName, SharedState::VPS_HOST_LOCK_TTL);
    if ($token !== null) {
        try {
            SharedState::set($requestKey, 'get_new_vps', SharedState::VPS_HOST_LOCK_TTL);
            Worker::safeEcho("timer running hyperv async queue processing for {$service_id} {$service_master['vps_name']}\n");
            function_requirements('vps_queue_handler');
            if (sizeof($service_master['newvps']) > 0) {
                // A failed renew means the lock expired or was taken — never start
                // another HyperV op holding nothing. Return still runs the finally;
                // the owner-checked unlock no-ops on a lost lock.
                if (!SharedState::renew($lockName, $token, SharedState::VPS_HOST_LOCK_TTL)) {
                    Worker::safeEcho("timer lost lock to run hyperv async get_new_vps for {$service_master['vps_name']} — aborting remaining handlers (lock expired or taken)\n");
                    return;
                }
                myadmin_log('myadmin', 'info', 'Processing New VPS for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
                vps_queue_handler($service_master, 'get_new_vps', $service_master['newvps']);
            }
            SharedState::set($requestKey, 'get_queue', SharedState::VPS_HOST_LOCK_TTL);
            if (sizeof($service_master['queue']) > 0) {
                if (!SharedState::renew($lockName, $token, SharedState::VPS_HOST_LOCK_TTL)) {
                    Worker::safeEcho("timer lost lock to run hyperv async get_queue for {$service_master['vps_name']} — aborting remaining handlers (lock expired or taken)\n");
                    return;
                }
                myadmin_log('myadmin', 'info', 'Processing VPS Queue for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
                vps_queue_handler($service_master, 'get_queue', $service_master['queue']);
            }
            SharedState::set($requestKey, 'server_list', SharedState::VPS_HOST_LOCK_TTL);
            if (!SharedState::renew($lockName, $token, SharedState::VPS_HOST_LOCK_TTL)) {
                Worker::safeEcho("timer lost lock to run hyperv async server_list for {$service_master['vps_name']} — aborting remaining handlers (lock expired or taken)\n");
                return;
            }
            vps_queue_handler($service_master, 'server_list');
        } finally {
            SharedState::unlock($lockName, $token);
        }
    } else {
        // Another process holds the host lock (or Redis is unavailable) — pre-existing
        // "skip" behaviour. Log the op currently running from the debug key. The old
        // "for N seconds" delay read the lock value as a timestamp; SET NX stores an
        // opaque token, so that number no longer exists and the delay is dropped.
        $currentOp = SharedState::get($requestKey);
        Worker::safeEcho("timer couldnt get lock to start hyperv async queue processing for {$service_master['vps_name']} (currently running {$currentOp})\n");
    }
}
