<?php
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;
require_once __DIR__.'/../../vendor/workerman/workerman/Autoloader.php';
$worker = new Worker();
$worker->onWorkerStart = function() { // When the process is started
	$context = [ // Certificate is best to apply for a certificate
		'ssl' => [ // use the absolute/full paths
			'local_cert' => '/home/my/files/apache_setup/interserver.net.crt', // can also be a crt file
			'local_pk' => '/home/my/files/apache_setup/interserver.net.key',
			'cafile' => '/home/my/files/apache_setup/AlphaSSL.root.crt',
			'verify_peer' => false,
			'verify_peer_name' => false,
		]
	];
	$conn = new AsyncTcpConnection('ws://my3.interserver.net:7272', $context); // ssl need access to port 443
	$conn->transport = 'ssl'; // Set to ssl encryption access, making it wss
	$conn->onConnect = function($conn) { // send hello string after connected
		$conn->send(json_encode(['type'=>'login','ima'=>'admin','session_id'=>$_SERVER['argv'][2]]));
	};
	$conn->onMessage = function($conn, $data) { // When the remote websocket server sends a message
		echo "recv: {$data}\n";
		$data = json_decode($data, true);
		if ($data['type'] == 'login') 
			$conn->send('{"type":"phpsysinfo","host":"404","params":[]}');
		else
			print_r($data);

	};
	$conn->onError = function($conn, $code, $msg) { // connection error occurs, the general connection remote websocket server failure error
		echo "error: {$msg}\n";
	};
	$conn->onClose = function($conn) { // When the connection to the remote websocket server is disconnected
		echo "connection closed\n";
	};
	$conn->connect(); // Set the above various callbacks, the implementation of the connection operation
};
Worker::runAll();
