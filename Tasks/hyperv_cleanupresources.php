<?php

function hyperv_cleanupresources($args) {
	require_once __DIR__.'/../../../include/functions.inc.php';
	if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1)
		ini_set('default_socket_timeout', 1200);
	global $global;
	$service_master = $args['service_master'];
	$parameters = [
		'hyperVAdmin' => 'Administrator',
		'adminPassword' => $service_master['vps_root']
	];
	try {
		$soap = new SoapClient("https://{$service_master['vps_ip']}/HyperVService/HyperVService.asmx?WSDL", \Detain\MyAdminHyperv\Plugin::getSoapClientParams());
		$response = $soap->CleanUpResources($parameters);
	} catch (Exception $e) {
		echo 'Caught exception: '.$e->getMessage().PHP_EOL;
		return false;
	}
	if (isset($args['queue']) && count($args['queue']) > 0) {
		function_requirements('vps_queue_handler');
		foreach ($args['queue'] as $queue)
		vps_queue_handler($service_master, $queue);
	}
}