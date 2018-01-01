<?php
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;

$worker = new Worker('websocket://0.0.0.0:2346');		// Create a Websocket server
$worker->count = 6;
$worker->name = 'MyAdminWebSocketServer';
//$worker->user = 'www-data';

$worker->onWorkerStart = function($worker) {
	echo $worker->name . " starting...\n";
	/*
	// Add a Timer to Every worker process when the worker process start
        Timer::add(10, function()use($worker) {			// Timer every 10 seconds
            foreach($worker->connections as $connection) {	// Iterate over connections and send the time
                $connection->send(time());
            }
        });
	*/
};

$worker->onWorkerStop = function($worker) {
	echo "Worker stopping...\n";
};

$worker->onConnect = function($connection) {			// Emitted when new connection come
	$connection->maxSendBufferSize = 5*1024*1024;
	echo 'new connection from ip ' . $connection->getRemoteIp().PHP_EOL;
	// message handling only for the current connection
	/*
	$connection->onMessage = function($connection, $data) {
		var_dump($data);
		$connection->send('receive success');
	}; */
};

$worker->onMessage = function($connection, $data) {		// Emitted when data received
	var_dump($data);
	$connection->send('receive success');
};

$worker->onClose = function($connection) {			// Emitted when connection closed
	echo "Connection closed\n";
};

$worker->onBufferFull = function($connection) {
	echo "bufferFull and do not send again\n";
};

$worker->onBufferDrain = function($connection) {
	echo "buffer drain and continue send\n";
};

$worker->onError = function($connection, $code, $msg) {
	echo "error $code $msg\n";
};

Worker::runAll();						// Run worker
