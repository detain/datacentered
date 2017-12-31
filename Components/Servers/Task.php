<?php
use Workerman\Worker;
require_once __DIR__.'/../../../../vendor/workerman/globaldata/src/Client.php';

$task_worker = new Worker('Text://127.0.0.1:2208');		// task worker, using the Text protocol
$task_worker->count = 30; 								// number of task processes can be opened more than needed
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function($worker) {
	global $global;
	$global = new \GlobalData\Client('127.0.0.1:2207');	 // initialize the GlobalData client
};
$task_worker->onMessage = function($connection, $task_data) {
	$task_data = json_decode($task_data, true);			// Suppose you send json data
	//echo "Starting Task {$task_data['function']}\n";
	if (isset($task_data['function'])) {				// According to task_data to deal with the corresponding task logic
		if (in_array($task_data['function'], ['sync_hyperv_queue', 'async_hyperv_get_list'])) {
			require_once __DIR__.'/../../Tasks/'.$task_data['function'].'.php';
			$return = isset($task_data['args']) ? call_user_func($task_data['function'], $task_data['args']) : call_user_func($task_data['function']);
		}
	}
	//echo "Ending Task {$task_data['function']}\n";
	$connection->send(json_encode($return));			// send the result
};
return $task_worker;