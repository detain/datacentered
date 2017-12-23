<?php
use Workerman\Worker;
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../vendor/workerman/workerman/Autoloader.php';
require_once __DIR__.'/Components/Timer.php';
require_once __DIR__.'/Components/Servers/Task.php';
require_once __DIR__.'/Components/Servers/WebSocket.php';
require_once __DIR__.'/Components/Servers/WebSocketSecurity.php';
require_once __DIR__.'/Components/Servers/GlobalData.php';
require_once __DIR__.'/Components/Servers/Channel.php';

Worker::runAll();
