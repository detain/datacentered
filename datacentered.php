<?php
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;
require_once __DIR__.'/../../vendor/workerman/workerman/Autoloader.php';
require_once __DIR__.'/datacenterd_timer.php';
require_once __DIR__.'/datacenterd_task_server.php';
require_once __DIR__.'/datacenterd_ws_server.php';
require_once __DIR__.'/datacenterd_wss_server.php';
require_once __DIR__.'/datacenterd_globaldata_server.php';
require_once __DIR__.'/datacenterd_channel_server.php';

Worker::runAll();
