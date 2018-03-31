<?php
use \Workerman\Worker;
use \GatewayWorker\Lib\Gateway;
require_once __DIR__.'/../../../../vendor/workerman/globaldata/src/Client.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1)
	ini_set('default_socket_timeout', 1200);

$task_worker = new Worker('Text://127.0.0.1:2208');		// task worker, using the Text protocol
$task_worker->count = 5; 								// number of task processes can be opened more than needed
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function($worker) {
	global $global, $functions, $worker_db, $influx_client, $influx_database;
	$db_config = include __DIR__.'/../../../../include/config/config.db.php';
	$loop = Worker::getEventLoop();
	$worker_db = new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
	$influx_client = new \InfluxDB\Client('68.168.221.7', 8086, 'myadmin', 'MYp4ssw0rd');
	$influx_database = $influx_client->selectDB('myadmin');
	$global = new \GlobalData\Client('127.0.0.1:2207');
	$functions = [];
	foreach (glob(__DIR__.'/../../Tasks/*.php') as $file) {
		$function = basename($file, '.php');
		$functions[] = $function;
		require_once $file;
	}
};
$task_worker->onMessage = function($connection, $task_data) {
	global $functions;
	$task_data = json_decode($task_data, true);			// Suppose you send json data
	//echo "Starting Task {$task_data['function']}\n";
	$return = false;
	if (isset($task_data['function']) && in_array($task_data['function'], $functions)) {
		if (isset($task_data['args']))
			$return = call_user_func($task_data['function'], $task_data['args']);
		else
			$return = call_user_func($task_data['function']);
	}
	//echo "Ending Task {$task_data['function']}\n";
	$connection->send(json_encode($return));			// send the result
};

if(!defined('GLOBAL_START')) // If it is not started in the root directory, run the runAll method
	Worker::runAll();
