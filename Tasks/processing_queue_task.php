<?php

use Workerman\Worker;

function processing_queue_task($args)
{
	require_once __DIR__.'/../../../my/include/functions.inc.php';
	function_requirements('process_payment');
	return process_payment($args['id']);
}
