<?php

use \Workerman\Worker;
use \GatewayWorker\Gateway;
use \Workerman\Autoloader;

//require __DIR__.'/Events.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1)
	ini_set('default_socket_timeout', 1200);

$context = [																						// Certificate is best to apply for a certificate
	'ssl' => [																						// use the absolute/full paths
		'local_cert' => '/home/my/files/apache_setup/interserver.net.crt',							// can also be a crt file
		'local_pk' => '/home/my/files/apache_setup/interserver.net.key',
		'cafile' => '/home/my/files/apache_setup/AlphaSSL.root.crt',
		'verify_peer' => false,
		'verify_peer_name' => false,
	]
];
$gateway_ssl = new Gateway("Websocket://0.0.0.0:7272", $context);
$gateway_ssl->name = 'SslChatGateway';
$gateway_ssl->transport = 'ssl';
$gateway_ssl->count = 4; // Set the number of processes, the number of gateway process recommendations and cpu the same
$gateway_ssl->lanIp = '127.0.0.1'; // When distributed deployment set to intranet ip (non 127.0.0.1)
$gateway_ssl->startPort = 2400; // Internal communication start port. If $ gateway-> count = 4, the starting port is 2300. 2300 2301 2302 2303 4 ports are generally used as the internal communication port
$gateway_ssl->pingInterval = 60; // Heartbeat interval
$gateway_ssl->pingNotResponseLimit = 1;
$gateway_ssl->pingData = '{"type":"ping"}'; // heartbeat data
$gateway_ssl->registerAddress = '127.0.0.1:1236'; // Service registration address
//$gateway->maxSendBufferSize = 102400000;
//$gateway->onWorkerStart = function($worker) {};
$gateway_ssl->onConnect = function($connection) { // When the client is connected, set the connection onWebSocketConnect, that is, when the websocket handshake callback
	$connection->maxSendBufferSize = 100*1024*1024; // Set the current connection application layer send buffer size of the connection to 100mb, will override the default value
	$connection::$maxPackageSize = 100*1024*1024; // Set the current connection application layer received packet size to 100mb (default 10mb)
	//$connection->onWebSocketConnect = function($connection , $http_header) {
		//if (!preg_match('/\.interserver\.net(:[0-9]+)*/m', $_SERVER['HTTP_ORIGIN'])) // Here you can determine whether the source of the connection is legal, illegal to turn off the connection.  $_SERVER['HTTP_ORIGIN'] Identifies which site's web-initiated websocket link
			//$connection->close();
		// onWebSocketConnect Inside $_GET $_SERVER is available  var_dump($_GET, $_SERVER);
	//};
};
$gateway_ssl->onBufferFull = function($connection)
{
	Worker::safeEcho("GateWaySSL bufferFull and do not send again\n");
};
$gateway_ssl->onBufferDrain = function($connection)
{
	Worker::safeEcho("GateWaySSL buffer drain and continue send\n");
};
$gateway_ssl->onError = function($connection, $code, $msg)
{
	Worker::safeEcho("GateWaySSL error {$code} {$msg}\n");
};

// If it is not started in the root directory, run the runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();
