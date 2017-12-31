<?php

function hyperv_cleanupresources($args) {
	require_once __DIR__.'/../../../include/functions.inc.php';
	if (ini_get('default_socket_timeout') < 600 && ini_get('default_socket_timeout') > 1)
		ini_set('default_socket_timeout', 600);
	global $global;
	$service_master = $args['service_master'];
	$parameters = [
		'hyperVAdmin' => 'Administrator',
		'adminPassword' => $service_master['vps_root']
	];
	$params = [
		'encoding' => 'UTF-8',
		'verifypeer' => FALSE,
		'verifyhost' => FALSE,
		'soap_version' => SOAP_1_2,
		'trace' => 1,
		'exceptions' => 1,
		'connection_timeout' => 180,
		'stream_context' => stream_context_create([
			'ssl' => [
				'ciphers' => 'RC4-SHA',
				'verify_peer' => FALSE,
				'verify_peer_name' => FALSE
		]])
	];
	try {
		$soap = new SoapClient("https://{$service_master['vps_ip']}/HyperVService/HyperVService.asmx?WSDL", $params);
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