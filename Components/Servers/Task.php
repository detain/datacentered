<?php
use Workerman\Worker;

function update_vps_list($args) {
	$return = [];
	// get list of vps's
	return $return;
}

$task_worker = new Worker('Text://127.0.0.1:12345'); // task worker, using the Text protocol
$task_worker->count = 100; // number of task processes can be opened more than needed
$task_worker->name = 'TaskWorker';
$task_worker->onMessage = function($connection, $task_data) {
	 $task_data = json_decode($task_data, true); // Suppose you send json data
	 if (isset($task_data['function'])) // According to task_data to deal with the corresponding task logic
		 $return = isset($task_data['args']) ? call_user_func($task_data['function'], $task_data['args']) : call_user_func($task_data['function']);
	 $connection->send(json_encode($return)); // send the result
};
