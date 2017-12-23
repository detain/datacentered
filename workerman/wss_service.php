<?php
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../vendor/workerman/workerman/Autoloader.php';
use Workerman\Worker;

// Certificate is best to apply for a certificate
$context = [
	'ssl' => [
		// use the absolute/full path
		'local_cert' => '/home/my/files/apache_setup/interserver.net.crt', // can also be a crt file
	        'local_pk' => '/home/my/files/apache_setup/interserver.net.key',
		'cafile' => '/home/my/files/apache_setup/AlphaSSL.root.crt',
	        'verify_peer' => false,
	        'verify_peer_name' => false,
	]
];
// Set here is websocket agreement
$worker = new Worker('websocket://0.0.0.0:4431', $context);
$worker->name = 'WebsocketWorker';
// 5 processes
$worker->count = 5;
// Set transport open ssl, websocket + ssl wss
$worker->transport = 'ssl';
// Clients come up, that is completed after the TCP three-way handshake callback
$worker->onConnect = function($conn) {
	echo "new connection from ip " . $conn->getRemoteIp() . "\n";
	// Client websocket handshake when the callback onWebSocketConnect Get the value of X_REAL_IP from nginx through the http header on onWebSocketConnect callback
	$conn->onWebSocketConnect = function($conn) {
		// Connection object There is no realIP attribute, here to dynamically add a connection object realIP attributes Remember that php objects can dynamically add properties, you can also use your favorite property name
		$conn->realIP = $_SERVER['HTTP_X_REAL_IP'];
	};
};
$worker->onMessage = function($conn, $data) {
	// When using the client real ip, directly use the connection-> realIP can
	$conn->send("got '{$data}' from {$conn->realIP}");
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
