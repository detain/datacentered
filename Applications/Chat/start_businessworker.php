<?php

use \Workerman\Worker;
use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\BusinessWorker;
use \Workerman\Connection\TcpConnection;
use \Workerman\Autoloader;
use \GlobalData\Client as GlobalDataClient;

$worker = new BusinessWorker(); // bussinessWorker process
$worker->name = 'ChatBusinessWorker'; // worker name
$worker->count = 4; // bussinessWorker number of processes
$worker->registerAddress = '127.0.0.1:1236'; // Service registration address
//$worker->maxSendBufferSize = 102400000;
//$worker->sendToGatewayBufferSize = 102400000;
/*$worker->onConnect = function($connection) { // When the client is connected, set the connection onWebSocketConnect, that is, when the websocket handshake callback
	$connection->maxSendBufferSize = 102400000;
*/
/*
$worker->onWorkerStart = function($worker) { Events::setup_timers($worker); }; // start the process, open a vmstat process, and broadcast vmstat process output to all browser clients
*/

if(!defined('GLOBAL_START')) // If it is not started in the root directory, run the runAll method
	Worker::runAll();
