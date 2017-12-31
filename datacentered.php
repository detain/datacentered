<?php
use Workerman\Worker;
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../vendor/workerman/workerman/Autoloader.php';
//require_once __DIR__.'/Components/Timer.php';
require_once __DIR__.'/Components/Servers/Task.php';				// Task Server allows you to easily pass off tasks and handle them in an async manor using JSON
require_once __DIR__.'/Components/Servers/GlobalData.php';			// GlobalData is used to share variables between processes and deal with some concurrency issues
require_once __DIR__.'/Components/Servers/Channel.php';				// Channel is a distributed communication component used to complete inter-process communication or
																	// server-to-server communication based on the subscription release model with non-blocking IO
//require_once __DIR__.'/Components/Servers/WebSocket.php';			// Provides teh ws:// Websocket Server
require_once __DIR__.'/Components/Servers/WebSocketSecurity.php';	// Provides the wss:// SSL Websocket server
Worker::runAll();
