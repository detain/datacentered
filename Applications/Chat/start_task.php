<?php
use \Workerman\Worker;
use \GatewayWorker\Lib\Gateway;

require_once __DIR__.'/../../vendor/workerman/globaldata/src/Client.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
	ini_set('default_socket_timeout', 1200);
}

$task_worker = new Worker('Text://127.0.0.1:2208');		// task worker, using the Text protocol
$task_worker->count = 20; 								// number of task processes can be opened more than needed
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function ($worker) {
	global $global, $functions, $worker_db, $influx_v2_client, $influx_v2_database, $memcache, $redis;
	require_once '/home/my/include/functions.inc.php';
	include_once '/home/my/include/config/config.settings.php';
	$db_config = include '/home/my/include/config/config.db.php';
    $GLOBALS['tf']->db->haltOnError = 'report';
	$loop = Worker::getEventLoop();
	$worker_db = new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
	if (INFLUX_V2 === true) {
		$influx_v2_client = new \InfluxDB2\Client([
			'url' => INFLUX_V2_URL,
			'token' => INFLUX_V2_TOKEN,
			'bucket' => INFLUX_V2_BUCKET,
			'org' => INFLUX_V2_ORG,
			'precision' => \InfluxDB2\Model\WritePrecision::S,
			'debug' => false,
		]);
		$influx_v2_database = $influx_v2_client->createWriteApi(['writeType' => \InfluxDB2\WriteType::BATCHING, 'batchSize' => 1000]);
	}
	$global = new \GlobalData\Client('127.0.0.1:2207');
    $queuehosts = [];
    $functions = [];
    foreach (glob(__DIR__.'/../../Tasks/*.php') as $file) {
        $function = basename($file, '.php');
        $functions[] = $function;
        require_once $file;
    }
    if (USE_REDIS === true) {
        try {
            $redis = new \Redis();
            if ($redis->connect(REDIS_HOST, REDIS_PORT, 2)) {
                //$redis->auth(REDIS_PASS);
            }
        } catch (\Exception $e) {
            Worker::safeEcho('Caught Exception #'.$e->getCode().':'.$e->getMessage().' on '.__LINE__.'@'.__FILE__);
        }
    }
	$memcache = new \Memcached();
	$memcache->addServer('localhost', 11211);
	$memcache->set('queuehosts', $queuehosts);
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
