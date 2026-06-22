<?php

use MyAdmin\App;
use Workerman\Worker;

function async_hyperv_queue_runner($args)
{
    require_once '/home/my/include/functions.inc.php';
    /**
    * @var \GlobalData\Client
    */
    global $global;
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
    $var = 'vps_host_'.$service_id;
    $requestVar = $var.'_request';
    if ($global->cas($var, 0, time())) {
        $global->$requestVar = 'get_new_vps';
        Worker::safeEcho("timer running hyperv async queue processing for {$service_id} {$service_master['vps_name']}\n");
        function_requirements('vps_queue_handler');
        if (sizeof($service_master['newvps']) > 0) {
            myadmin_log('myadmin', 'info', 'Processing New VPS for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
            vps_queue_handler($service_master, 'get_new_vps', $service_master['newvps']);
        }
        $global->$var = time();
        $global->$requestVar = 'get_queue';
        if (sizeof($service_master['queue']) > 0) {
            myadmin_log('myadmin', 'info', 'Processing VPS Queue for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
            vps_queue_handler($service_master, 'get_queue', $service_master['queue']);
        }
        $global->$var = time();
        $global->$requestVar = 'server_list';
        vps_queue_handler($service_master, 'server_list');
        $global->$var = 0;
    } else {
        $delay = (int)time() - (int)$global->$var;
        Worker::safeEcho("timer couldnt get lock to start hyperv async queue processing for {$service_master['vps_name']} (currently running {$global->$requestVar} for {$delay} seconds)\n");
    }
}
