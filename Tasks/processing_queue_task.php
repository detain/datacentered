<?php

use Workerman\Worker;

function processing_queue_task($args)
{
	require_once '/home/my/include/functions.inc.php';
	//Worker::safeEcho('Processing Queue Task got here '.json_encode($args).PHP_EOL);
	$db = $GLOBALS['tf']->db;
	$db->query("update queue_log set history_new_value='processing' where history_id='{$args['history_id']}'", __LINE__, __FILE__);
	//Worker::safeEcho('Processing Queue Task got here after setting to processing, starting processing'.PHP_EOL);
	function_requirements('process_payment');
	$return = process_payment($args['history_type']);
	$db->query("update queue_log set history_new_value='completed' where history_id='{$args['history_id']}'", __LINE__, __FILE__);
	//Worker::safeEcho('Processing Queue Task Finished for Invoice '.$args['history_type'].PHP_EOL);
	return $return;
}
