<?php

use Workerman\Worker;

function async_hyperv_queue_runner($args)
{
	require_once __DIR__.'/../../../my/include/functions.inc.php';
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	$service_id = $args['id'];
	$service_master = $args['data'];
	Worker::safeEcho("timer starting hyperv async queue processing for {$service_id} {$service_master['vps_name']}\n");
	$var = 'vps_host_'.$service_id;
	if ($global->cas($var, 0, 1)) {
		Worker::safeEcho("timer running hyperv async queue processing for {$service_id} {$service_master['vps_name']}\n");
		function_requirements('vps_queue_handler');
		if (sizeof($service_master['newvps']) > 0) {
			myadmin_log('myadmin', 'info', 'Processing New VPS for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
			vps_queue_handler($service_master, 'get_new_vps', $service_master['newvps']);
		}
		if (sizeof($service_master['queue']) > 0) {
	        myadmin_log('myadmin', 'info', 'Processing VPS Queue for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
			vps_queue_handler($service_master, 'get_queue', $service_master['queue']);
		}
		vps_queue_handler($service_master, 'server_list');
		$global->$var = 0;
	} else {
		Worker::safeEcho("timer couldnt get lock to start hyperv async queue processing for {$service_master['vps_name']}\n");
	}
}
