<?php

use \Workerman\Worker;
use \GatewayWorker\BusinessWorker;
use \Workerman\Autoloader;

$worker = new BusinessWorker(); // bussinessWorker process
$worker->name = 'ChatBusinessWorker'; // worker name
$worker->count = 4; // bussinessWorker number of processes
$worker->registerAddress = '127.0.0.1:1236'; // Service registration address

if(!defined('GLOBAL_START')) // If it is not started in the root directory, run the runAll method
	Worker::runAll();
