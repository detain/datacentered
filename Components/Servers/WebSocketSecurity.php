<?php
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;

function update_vps_list_timer() {
	$task_connection = new AsyncTcpConnection('Text://127.0.0.1:12345'); // Asynchronous link with the remote task service, ip remote task service ip, if the machine is 127.0.0.1, if the cluster is lvs ip
	$task_data = [ // task and parameter data
		'function' => 'update_vps_list',
		'args'       => [],
	];
	$task_connection->send(json_encode($task_data)); // send data
	$task_connection->onMessage = function($task_connection, $task_result) use ($conn) { // get the result asynchronously
		 //var_dump($task_result);
		 $task_connection->close(); // remember to turn off the asynchronous link after getting the result
	};
	$task_connection->connect(); // execute async link
}

function vps_queue_timer() {
	$task_connection = new AsyncTcpConnection('Text://127.0.0.1:12345'); // Asynchronous link with the remote task service, ip remote task service ip, if the machine is 127.0.0.1, if the cluster is lvs ip
	$task_data = [ // task and parameter data
		'function' => 'vps_queue',
		'args'       => [],
	];
	$task_connection->send(json_encode($task_data)); // send data
	$task_connection->onMessage = function($task_connection, $task_result) use ($conn) { // get the result asynchronously
		 //var_dump($task_result);
		 $task_connection->close(); // remember to turn off the asynchronous link after getting the result
	};
	$task_connection->connect(); // execute async link
}

$context = [ // Certificate is best to apply for a certificate
	'ssl' => [ // use the absolute/full paths
		'local_cert' => '/home/my/files/apache_setup/interserver.net.crt', // can also be a crt file
		'local_pk' => '/home/my/files/apache_setup/interserver.net.key',
		'cafile' => '/home/my/files/apache_setup/AlphaSSL.root.crt',
		'verify_peer' => false,
		'verify_peer_name' => false,
	]
];
$worker = new Worker('websocket://0.0.0.0:4431', $context); // websocket server
$worker->name = 'WebsocketWorker';
$worker->count = 5; // 5 processes
$worker->transport = 'ssl'; // Set transport open ssl, websocket + ssl wss
$worker->onConnect = function($conn) { // Clients come up, that is completed after the TCP three-way handshake callback
	echo "new connection from ip " . $conn->getRemoteIp() . "\n";
	$conn->onWebSocketConnect = function($conn) { // Client websocket handshake when the callback onWebSocketConnect Get the value of X_REAL_IP from nginx through the http header on onWebSocketConnect callback
		//$conn->realIP = $_SERVER['HTTP_X_REAL_IP']; // Connection object There is no realIP attribute, here to dynamically add a connection object realIP attributes Remember that php objects can dynamically add properties, you can also use your favorite property name
		$conn->realIP = $conn->getRemoteIp();
	};
};
$worker->onMessage = function($conn, $message) {
	$conn->send("got '{$message}' from {$conn->realIP}"); // When using the client real ip, directly use the connection-> realIP can
	$task_connection = new AsyncTcpConnection('Text://127.0.0.1:12345'); // Asynchronous link with the remote task service, ip remote task service ip, if the machine is 127.0.0.1, if the cluster is lvs ip
	$task_data = [ // task and parameter data
		'function' => 'send_mail',
		'args'       => [
			'from' => 'xxx',
			'to' => 'xxx',
			'contents' => 'xxx'
	]];
	$task_connection->send(json_encode($task_data)); // send data
	$task_connection->onMessage = function($task_connection, $task_result) use ($conn) { // get the result asynchronously
		 var_dump($task_result); // result
		 $task_connection->close(); // remember to turn off the asynchronous linka fter getting the result
		 $conn->send('task complete'); // notify the corresponding websocket client task is completed
	};
	$task_connection->connect(); // execute async link


	// data sent by the client as event data
	$words = explode(' ', $message);
	if ($words[0] == 'login') {
		$event_name = array_shift($words);
		$event_data = $words;
	} else {
		$event_name = 'broadcast';
		$event_data = $message;
	}
	// Publish broadcast events to all worker processes
	\Channel\Client::publish($event_name, $event_data);
};
$worker->onWorkerStart = function($worker) {
	echo "Worker starting...\n";

	global $global;
	$global = new \GlobalData\Client('127.0.0.1:2207');	 // initialize the GlobalData client

	if($worker->id === 0) { // The timer is set only on the process whose id number is 0, and the processes of other 1, 2, and 3 processes do not set the timer
		Timer::add(600, 'update_vps_list_timer');
		Timer::add(60, 'vps_queue_timer');
	}


	// Channel client connected to the Channel server
	Channel\Client::connect('127.0.0.1', 2206);
	// Subscribe to the broadcast event and register the event callback
	Channel\Client::on('broadcast', function($event_data) use ($worker) {
		// Broadcast messages to all clients of the current worker process
		foreach($worker->connections as $connection) {
			$connection->send($event_data);
		}
	});
	Channel\Client::on('login', function($event_data) use ($worker) {
		list($login, $password) = $event_data;
		if ($login == 'test' && $password == 'test') {
		} else {
		}
		// Broadcast messages to all clients of the current worker process
		foreach($worker->connections as $connection) {
			$connection->send($event_data);
		}
	});
	// Channel\Client::unsubscribe($event_name);

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
