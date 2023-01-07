<?php

use Workerman\Worker;

function processing_queue_task($args)
{
	require_once '/home/my/include/functions.inc.php';
    $GLOBALS['tf']->db->haltOnError = 'report';
	//Worker::safeEcho('Processing Queue Task Started'.PHP_EOL);
    $GLOBALS['tf']->session->sessionid = 'datacentered';
    $GLOBALS['tf']->session->account_id = $args['history_owner'];
    $GLOBALS['tf']->accounts->data = $GLOBALS['tf']->accounts->read($args['history_owner']);
	$db = clone $GLOBALS['tf']->db;
	$db->query("update queue_log set history_new_value='processing' where history_id='{$args['history_id']}'", __LINE__, __FILE__);
	//Worker::safeEcho('Processing Queue Task got here after setting to processing, starting processing'.PHP_EOL);
	function_requirements('process_payment');
	$return = process_payment($args['history_type']);
	$db->query("update queue_log set history_new_value='completed' where history_id='{$args['history_id']}'", __LINE__, __FILE__);
    $GLOBALS['tf']->session->account_id = 160307;
    $GLOBALS['tf']->accounts->data = [];
	//Worker::safeEcho('Processing Queue Task Finished for Invoice '.$args['history_type'].PHP_EOL);
	return $return;
}
