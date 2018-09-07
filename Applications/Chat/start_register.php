<?php

use \Workerman\Worker;
use \GatewayWorker\Register;

$register = new Register('text://0.0.0.0:1236'); // register service must be a text protocol

$register->onConnect = function ($connection) { // When the client is connected, set the connection onWebSocketConnect, that is, when the websocket handshake callback
	$connection->maxSendBufferSize = 100*1024*1024; // Set the current connection application layer send buffer size of the connection to 100mb, will override the default value
	$connection::$maxPackageSize = 100*1024*1024; // Set the current connection application layer received packet size to 100mb (default 10mb)
};
$register->onBufferFull = function ($connection) {
	Worker::safeEcho("Register bufferFull and do not send again\n");
};
$register->onBufferDrain = function ($connection) {
	Worker::safeEcho("Register buffer drain and continue send\n");
};
$register->onError = function ($connection, $code, $msg) {
	Worker::safeEcho("Register error {$code} {$msg}\n");
};

if (!defined('GLOBAL_START')) { // If it is not started in the root directory, run the runAll method
	Worker::runAll();
}
