<?php

use \Workerman\Worker;
use \GatewayWorker\Gateway;
use \Workerman\Autoloader;

require __DIR__.'/Events.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
    ini_set('default_socket_timeout', 1200);
}

$gateway = new Gateway("websocket://0.0.0.0:7271");
$gateway->name = 'ChatGateway';
$gateway->count = 5; // Set the number of processes, the number of gateway process recommendations and cpu the same
$gateway->lanIp = '127.0.0.1'; // When distributed deployment set to intranet ip (non 127.0.0.1)
$gateway->startPort = 2300; // Internal communication start port. If $ gateway-> count = 4, the starting port is 2300. 2300 2301 2302 2303 4 ports are generally used as the internal communication port
$gateway->pingInterval = 60; // Heartbeat interval
$gateway->pingNotResponseLimit = 2;
//$gateway->pingData = '{"type":"ping"}'; // heartbeat data
$gateway->pingData = ''; // heartbeat data
$gateway->registerAddress = '127.0.0.1:1236'; // Service registration address
//$gateway->onWorkerStart = function($worker) {};
$gateway->onConnect = function ($connection) { // When the client is connected, set the connection onWebSocketConnect, that is, when the websocket handshake callback
    $connection->maxSendBufferSize = 100*1024*1024; // Set the current connection application layer send buffer size of the connection to 100mb, will override the default value
    $connection->maxPackageSize = 100*1024*1024; // Set the current connection application layer received packet size to 100mb (default 10mb)
    //$connection->onWebSocketConnect = function($connection , $http_header) {
        //if (!preg_match('/\.interserver\.net(:[0-9]+)*/m', $_SERVER['HTTP_ORIGIN'])) // Here you can determine whether the source of the connection is legal, illegal to turn off the connection.  $_SERVER['HTTP_ORIGIN'] Identifies which site's web-initiated websocket link
            //$connection->close();
        // onWebSocketConnect Inside $_GET $_SERVER is available  var_dump($_GET, $_SERVER);
    //};
};
$gateway->onBufferFull = function ($connection) {
    Worker::safeEcho("GateWay bufferFull and do not send again\n");
};
$gateway->onBufferDrain = function ($connection) {
    Worker::safeEcho("GateWay buffer drain and continue send\n");
};
$gateway->onError = function ($connection, $code, $msg) {
    Worker::safeEcho("GateWay error {$code} {$msg}\n");
};


// If it is not started in the root directory, run the runAll method
if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
