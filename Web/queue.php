<?php

use Workerman\Worker;

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
	if ($loopCount > 100) {
		Worker::safeEcho('Max Loops Reached Trying to Get queuein CAS set'.PHP_EOL);
		break;
	}
} while (!$memcache->cas($response['cas'], 'queuein', $queue));
Worker::safeEcho('CAS set queuein to  '.var_export($queue, true).PHP_EOL); 
//\Workerman\Protocols\Http::end($output);