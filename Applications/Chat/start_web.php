<?php

use Workerman\Worker;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Connection\TcpConnection;
use GatewayWorker\Gateway;
use GatewayWorker\BusinessWorker;
use Workerman\Autoloader;

$context = [																						// Certificate is best to apply for a certificate
	'ssl' => [																						// use the absolute/full paths
		//'local_cert' => '/home/my/files/apache_setup/interserver.net.crt',							// can also be a crt file
		//'local_pk' => '/home/my/files/apache_setup/interserver.net.key',
		//'cafile' => '/home/my/files/apache_setup/AlphaSSL.root.crt',
		'local_cert' => '/etc/letsencrypt/live/mynew.interserver.net/fullchain.pem',
		'local_pk' => '/etc/letsencrypt/live/mynew.interserver.net/privkey.pem',
		'verify_peer' => false,
		'verify_peer_name' => false,
	]
];
$web = new Worker('http://0.0.0.0:55151', $context);
$web->name	 = 'WebServer';
$web->count = 10; // WebServer number of processes
//$web->transport = 'ssl';

define('WEBROOT', realpath(__DIR__.'/../../Web'));

$web->onMessage = function (TcpConnection $connection, Request $request) {
	global $_GET, $_POST;
	$addr = explode(':', $connection->getRemoteAddress());
	$_SERVER['REMOTE_ADDR'] = $addr[0];
	$_GET = $request->get();
	$_POST = $request->post();
	$path = $request->path();
	if ($path === '/') {
		$connection->send(exec_php_file(WEBROOT.'/index.html'));
		return;
	}
	$file = realpath(WEBROOT. $path);
	if (false === $file) {
		$connection->send(new Response(404, array(), '<h3>404 Not Found</h3>'));
		return;
	}
	// Security check! Very important!!!
	if (strpos($file, WEBROOT) !== 0) {
		$connection->send(new Response(400));
		return;
	}
	if (\pathinfo($file, PATHINFO_EXTENSION) === 'php') {
		$connection->send(exec_php_file($file));
		return;
	}
	if (!empty($if_modified_since = $request->header('if-modified-since'))) {
		// Check 304.
		$info = \stat($file);
		$modified_time = $info ? \date('D, d M Y H:i:s', $info['mtime']) . ' ' . \date_default_timezone_get() : '';
		if ($modified_time === $if_modified_since) {
			$connection->send(new Response(304));
			return;
		}
	}
	$connection->send((new Response())->withFile($file));
};

function exec_php_file($file) {
	global $request;
	\ob_start();
	// Try to include php file.
	try {
		include $file;
	} catch (\Exception $e) {
		echo $e;
	}
	return \ob_get_clean();
}

if (!defined('GLOBAL_START')) { // If it is not started in the root directory, run the runAll method
	Worker::runAll();
}

$web->onWorkerStart = function ($worker) {
	global $memcache;
	$memcache = new \Memcached();
	$memcache->addServer('localhost', 11211);
	global $mysql_db;
	//$db_config = include __DIR__.'/../../../../my/include/config/config.db.php';
	//$mysql_db = new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
	$mysql_db = new \Workerman\MySQL\Connection('66.45.240.70', 3306, 'zonemta', 'Z0n3mt4!', 'zonemta', 'utf8mb4');	
};
	
$web->onWorkerStop = function ($worker) {
};
	
$web->onConnect = function ($connection) {
	$connection->maxSendBufferSize = 50663296;
};
