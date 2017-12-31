<?php
use Workerman\Worker;

function vps_queue($args) {
	include __DIR__.'/../../Tasks/vps_queue.php';
	return [];
}

function update_vps_list($args) {
	include __DIR__.'/../../Tasks/update_vps_list.php';
	return [];
}

$task_worker = new Worker('Text://127.0.0.1:2208');	// task worker, using the Text protocol
$task_worker->count = 30; 								// number of task processes can be opened more than needed
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function($worker) {
	global $global;
	$global = new \GlobalData\Client('127.0.0.1:2207');	 // initialize the GlobalData client
};
$task_worker->onMessage = function($connection, $task_data) {
	$task_data = json_decode($task_data, true);			// Suppose you send json data
	if (isset($task_data['function']))					// According to task_data to deal with the corresponding task logic
		$return = isset($task_data['args']) ? call_user_func($task_data['function'], $task_data['args']) : call_user_func($task_data['function']);
	$connection->send(json_encode($return));			// send the result
};
