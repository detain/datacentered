<?php

use \Workerman\Worker;
use \Workerman\GlobalTimer;

require_once __DIR__.'/../../vendor/workerman/global-timer/src/GlobalTimer.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
	ini_set('default_socket_timeout', 1200);
}

$global_channel_server = new Channel\Server('127.0.0.1', 3333);


if (!defined('GLOBAL_START')) { // If it is not started in the root directory, run the runAll method
	Worker::runAll();
}
