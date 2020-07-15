<?php
use \Workerman\Worker;
use \GatewayWorker\Lib\Gateway;

require_once __DIR__.'/../../vendor/workerman/globaldata/src/Client.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
	ini_set('default_socket_timeout', 1200);
}

$task_worker = new Worker('Text://127.0.0.1:2208');		// task worker, using the Text protocol
$task_worker->count = 5; 								// number of task processes can be opened more than needed
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function ($worker) {
	global $global, $functions, $worker_db, $influx_client, $influx_database, $memcache;
	include_once __DIR__.'/../../../../my/include/config/config.settings.php';
	$db_config = include __DIR__.'/../../../../my/include/config/config.db.php';
	$loop = Worker::getEventLoop();
	$worker_db = new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
	$influx_client = new \InfluxDB\Client(INFLUX_HOST, INFLUX_PORT, INFLUX_USER, INFLUX_PASS, true);
	$influx_database = $influx_client->selectDB(INFLUX_DB);
	$global = new \GlobalData\Client('127.0.0.1:2207');
	$memcache = new \Memcached();
	$memcache->addServer('localhost', 11211);
	$queuehosts = [];
	$memcache->set('queuehosts', $queuehosts);
	$functions = [];
	foreach (glob(__DIR__.'/../../Tasks/*.php') as $file) {
		$function = basename($file, '.php');
		$functions[] = $function;
		require_once $file;
	}
};

$task_worker->onConnect = function ($connection) { // When the client is connected, set the connection onWebSocketConnect, that is, when the websocket handshake callback
	$connection->maxSendBufferSize = 100*1024*1024; // Set the current connection application layer send buffer size of the connection to 100mb, will override the default value
	$connection->maxPackageSize = 100*1024*1024; // Set the current connection application layer received packet size to 100mb (default 10mb)
};
$task_worker->onBufferFull = function ($connection) {
	Worker::safeEcho("TaskWorker bufferFull and do not send again\n");
};
$task_worker->onBufferDrain = function ($connection) {
	Worker::safeEcho("TaskWorker buffer drain and continue send\n");
};
$task_worker->onError = function ($connection, $code, $msg) {
	Worker::safeEcho("TaskWorker error {$code} {$msg}\n");
};

$task_worker->onMessage = function ($connection, $task_data) {
	global $functions;
	$task_data = json_decode($task_data, true);			// Suppose you send json data
	//echo "Starting Task {$task_data['type']}\n";
	$return = false;
	if (isset($task_data['type']) && in_array($task_data['type'], $functions)) {
		if (isset($task_data['args'])) {
			$return = call_user_func($task_data['type'], $task_data['args']);
		} else {
			$return = call_user_func($task_data['type']);
		}
	}
	$connection->send(json_encode(['return' => $return]));
};

if (!defined('GLOBAL_START')) { // If it is not started in the root directory, run the runAll method
	Worker::runAll();
}
