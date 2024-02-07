<?php

use \Workerman\Worker;

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
    ini_set('default_socket_timeout', 1200);
}

$global_channel_server = new \Channel\Server('0.0.0.0', 3333);
$global_channel_server->count = 5;


if (!defined('GLOBAL_START')) { // If it is not started in the root directory, run the runAll method
    Worker::runAll();
}
