<?php
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;
require_once __DIR__.'/../../vendor/workerman/workerman/Autoloader.php';
$worker = new Worker();
// When the process is started
$worker->onWorkerStart = function()
{
	// websocket agreement to connect to remote websocket server
	$con = new AsyncTcpConnection("ws://echo.websocket.org:80");
	// send hello string after connected
	$con->onConnect = function($connection){
		$connection->send('hello');
	};
	// When the remote websocket server sends a message
	$con->onMessage = function($connection, $data){
		echo "recv: $data\n";
	};
	// connection error occurs, the general connection remote websocket server failure error
	$con->onError = function($connection, $code, $msg){
		echo "error: $msg\n";
	};
	// When the connection to the remote websocket server is disconnected
	$con->onClose = function($connection){
		echo "connection closed\n";
	};
	// Set the above various callbacks, the implementation of the connection operation
	$con->connect();
};
Worker::runAll();
