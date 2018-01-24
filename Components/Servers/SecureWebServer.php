<?php

use Workerman\Worker;
use Workerman\WebServer;

$context = [																						// Certificate is best to apply for a certificate
	'ssl' => [																						// use the absolute/full paths
		'local_cert' => '/home/my/files/apache_setup/interserver.net.crt',							// can also be a crt file
		'local_pk' => '/home/my/files/apache_setup/interserver.net.key',
		'cafile' => '/home/my/files/apache_setup/AlphaSSL.root.crt',
		'verify_peer' => false,
		'verify_peer_name' => false,
	]
];
$securewebserver_worker= new WebServer('http://0.0.0.0:2210', $context); // WebServer, used to split html js css browser
$securewebserver_worker->count = 5; // SecureWebServer number of processes
$securewebserver_worker->name = 'SecureWebServer'; // worker name
$securewebserver_worker->transport = 'ssl';															// Set transport open ssl, websocket + ssl wss
$securewebserver_worker->addRoot(isset($_SERVER['HOSTNAME']) ? $_SERVER['HOSTNAME'] : trim(`hostname -f`), __DIR__.'/../../Web'); // Set the site root
$securewebserver_worker->addRoot('localhost', __DIR__ . '/../../Web');

return $securewebserver_worker;
