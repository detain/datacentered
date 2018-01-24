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
// start the process, open a vmstat process, and broadcast vmstat process output to all browser clients
$worker->onWorkerStart = function($worker) {
	global $global;
	$global = new GlobalDataClient('127.0.0.1:2207');	 // initialize the GlobalData client
	if ($worker->id == 0) {
		// Save the process handle, close the handle when the process is closed
		$worker->process_handle = popen('vmstat -n 1', 'r');
		if ($worker->process_handle) {
			$process_connection = new TcpConnection($worker->process_handle);
			$process_connection->onMessage = function($process_connection, $data) use ($worker) {
				$msg = [
					'type' => 'vmstat',
					'content' => [
						'r' => 0,
						'b' => 0,
						'swpd' => 0,
						'free' => 0,
						'buff' => 0,
						'cache' => 0,
						'si' => 0,
						'so' => 0,
						'bi' => 0,
						'bo' => 0,
						'in' => 0,
						'cs' => 0,
						'us' => 0,
						'sy' => 0,
						'id' => 0,
						'wa' => 0,
						'st' => 0
					]
				];
				list($msg['content']['r'], $msg['content']['b'], $msg['content']['swpd'], $msg['content']['free'], $msg['content']['buff'], $msg['content']['cache'], $msg['content']['si'], $msg['content']['so'], $msg['content']['bi'], $msg['content']['bo'], $msg['content']['in'], $msg['content']['cs'], $msg['content']['us'], $msg['content']['sy'], $msg['content']['id'], $msg['content']['wa'], $msg['content']['st']) = preg_split('/ +/', trim($data));
				if (is_numeric($msg['content']['r']))
					Gateway::sendToGroup('vmstat', json_encode($msg));
			};
		} else {
		   echo "vmstat 1 fail\n";
		}
	}
};
// when the process is closed
$worker->onWorkerStop = function($worker) {
	if ($worker->id == 0) {
		@shell_exec('killall vmstat');
		@pclose($worker->process_handle);
	}
};
$worker->onConnect = function($connection) {
	//$connection->send(json_encode(['type' => 'vmstat', 'content' => "procs -----------memory---------- ---swap-- -----io---- --system-- -----cpu-----\n"]));
	//$connection->send(json_encode(['type' => 'vmstat', 'content' => " r  b   swpd   free   buff  cache   si   so    bi    bo   in   cs us sy id wa st\n"]));
};

if(!defined('GLOBAL_START')) // If it is not started in the root directory, run the runAll method
	Worker::runAll();
