<?php
use Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;

// websocket server
$websocket_worker = new Worker('websocket://0.0.0.0:8081');
$websocket_worker->name = 'WebsocketWorker';
// 5 processes
$websocket_worker->count = 5;
$websocket_worker->onConnect = function($conn) {
	echo "new connection from ip " . $conn->getRemoteIp().PHP_EOL;
};
$websocket_worker->onMessage = function($conn, $message) {
	// Asynchronous link with the remote task service, ip remote task service ip, if the machine is 127.0.0.1, if the cluster is lvs ip
	$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
	// task and parameter data
	$task_data = array(
		'function' => 'send_mail',
		'args'       => array('from'=>'xxx', 'to'=>'xxx', 'contents'=>'xxx'),
	);
	// send data
	$task_connection->send(json_encode($task_data));
	// get the result asynchronously
	$task_connection->onMessage = function($task_connection, $task_result) use ($conn) {
		 // result
		 var_dump($task_result);
		 // remember to turn off the asynchronous linka fter getting the result
		 $task_connection->close();
		 // notify the corresponding websocket client task is completed
		 $conn->send('task complete');
	};
	// execute async link
	$task_connection->connect();
};
$websocket_worker->onWorkerStart = function($worker) {
	//echo "WebSocketWorker({$worker->id}) starting...\n";
};
$websocket_worker->onWorkerStop = function($worker) {
	//echo "WebSocketWorker({$worker->id}) stopping...\n";
};
$websocket_worker->onClose = function($connection) {
    Worker::safeEcho("connection closed\n");
};
$websocket_worker->onBufferFull = function($connection) {
    Worker::safeEcho("bufferFull and do not send again\n");
};
$websocket_worker->onBufferDrain = function($connection) {
    Worker::safeEcho("buffer drain and continue send\n");
};
$websocket_worker->onError = function($connection, $code, $msg) {
    Worker::safeEcho("error {$code} : {$msg}\n");
};

return $websocket_worker;
