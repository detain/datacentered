<?php
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;
require_once __DIR__.'/../../vendor/workerman/workerman/Autoloader.php';
require_once __DIR__.'/task_process_server.php';
require_once __DIR__.'/task_wss_server.php';

Worker::runAll();
