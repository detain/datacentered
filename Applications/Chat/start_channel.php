<?php

use \Workerman\Worker;

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
    ini_set('default_socket_timeout', 1200);
}

// Note: Channel\Server wraps a Worker internally (protected $_worker) with count=1.
// Setting count on the Server wrapper has no effect — it would need to be set on the Worker.
$global_channel_server = new \Channel\Server('0.0.0.0', 3333);


if (!defined('GLOBAL_START')) { // If it is not started in the root directory, run the runAll method
    Worker::runAll();
}
