<?php

global $memcache;
$item = ['get' => $_GET, 'post' => $_POST, 'ip' => $_SERVER['REMOTE_ADDR']];
$loopCount = 0;
$output = '';
do {
	$response = $memcache->get('queuein', function($memcache, $key, &$value) { $value = []; return true; }, \Memcached::GET_EXTENDED);
	$queue = $response['value'];
	$cas = $response['cas'];
	// modify queue
	$queue[] = $item;
	$loopCount++;
	if ($loopCount > 100)
		break;
} while (!$memcache->cas($response['cas'], 'queuein', $queue));
 
//\Workerman\Protocols\Http::end($output);