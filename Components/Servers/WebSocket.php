<?php
use Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;

// websocket server
$worker = new Worker('websocket://0.0.0.0:8080');
$worker->name = 'WebsocketWorker';
// 5 processes
$worker->count = 5;
$worker->onConnect = function($conn) {
	echo "new connection from ip " . $conn->getRemoteIp() . "\n";
};
$worker->onMessage = function($conn, $message)
{
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
	$task_connection->onMessage = function($task_connection, $task_result)use($conn)
	{
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
$worker->onWorkerStart = function($worker) {
	echo "Worker starting...\n";
};
$worker->onWorkerStop = function($worker) {
	echo "Worker stopping...\n";
};
$worker->onClose = function($connection) {
	echo "connection closed\n";
};
$worker->onBufferFull = function($connection) {
	echo "bufferFull and do not send again\n";
};
$worker->onBufferDrain = function($connection) {
	echo "buffer drain and continue send\n";
};
$worker->onError = function($connection, $code, $msg) {
	echo "error {$code} : {$msg}\n";
};

Worker::runAll();
