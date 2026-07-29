<?php

use \Workerman\Worker;
use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\BusinessWorker;
use \Workerman\Connection\TcpConnection;
use \GlobalData\Client as GlobalDataClient;

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
    ini_set('default_socket_timeout', 1200);
}

if (!defined('GLOBALDATA_IP')) {
    require_once '/home/my/include/config/config.settings.php';
}


$worker = new BusinessWorker(); // bussinessWorker process
//$worker->name = 'ChatBusinessWorker'; // worker name
$worker->count = 5; // bussinessWorker number of processes
$worker->registerAddress = GLOBALDATA_IP.':1236'; // Service registration address
//$worker->maxSendBufferSize = 102400000;
//$worker->sendToGatewayBufferSize = 102400000;
$worker->onConnect = function ($connection) { // When the client is connected, set the connection onWebSocketConnect, that is, when the websocket handshake callback
    $connection->maxSendBufferSize = 100*1024*1024; // Set the current connection application layer send buffer size of the connection to 100mb, will override the default value
    $connection::$maxPackageSize = 100*1024*1024; // Set the current connection application layer received packet size to 100mb (default 10mb)
    $connection->_bufferFullCount = 0; // track bufferFull events for rate-limiting
};
$worker->onBufferFull = function ($connection) {
    Worker::safeEcho("bufferFull for client, disabling\n");
    // Disable sending (backpressure)
    $connection->sendBufferSize = 0;
    // After 3 full-buffer events, disconnect
    $count = ($connection->_bufferFullCount ?? 0) + 1;
    $connection->_bufferFullCount = $count;
    if ($count >= 3) {
        Worker::safeEcho("bufferFull 3 times, disconnecting client\n");
        $connection->close();
    }
};
$worker->onBufferDrain = function ($connection) {
    Worker::safeEcho("BusinessWorker buffer drain and continue send\n");
    $connection->sendBufferSize = 100*1024*1024; // restore
    $connection->_bufferFullCount = 0;
};
$worker->onError = function ($connection, $code, $msg) {
    Worker::safeEcho("BusinessWorker error {$code} {$msg}\n");
};
$worker->onWorkerStart = function ($worker) {
    if ($worker->id === 0) {
        \Events::onWorkerStart($worker);
        \Events::setupSessionHealthTimer();
    }
};

/*
$worker->onWorkerStart = function($worker) { Events::setup_timers($worker); }; // start the process, open a vmstat process, and broadcast vmstat process output to all browser clients
*/

if (!defined('GLOBAL_START')) { // If it is not started in the root directory, run the runAll method
    Worker::runAll();
}
