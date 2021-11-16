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
	$var = 'vps_host_'.$service_id;
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
}
