<?php

use Workerman\Worker;

function memcached_queue_task($args)
{
	//require_once __DIR__.'/../../../my/include/functions.inc.php';
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	$return = [];
	foreach ($args['queues'] as $queue) {
	}
	return $return;
}
