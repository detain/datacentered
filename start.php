#!/usr/bin/env php
<?php

use Workerman\Worker;
use Workerman\Connection\TcpConnection;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../../my/vendor/autoload.php';

TcpConnection::$defaultMaxSendBufferSize = 1024*1024*100; // sets the connections send write buffer size to 10mb (default 1mb)

//ini_set('mysqli.reconnect', 'on');
ini_set('display_errors', 'on');
ini_set('post_max_size', '100M');
ini_set('upload_max_filesize', '100M');
ini_set('memory_limit', '4096M');
// Reporting all.
error_reporting(E_ALL);
// Reset opcache.
if (function_exists('opcache_reset')) {
    opcache_reset();
}
if (strpos(strtolower(PHP_OS), 'win') === 0) {
    exit("start.php not support windows, please use start_for_win.bat\n");
}
if (!extension_loaded('pcntl')) {
    exit("Please install pcntl extension. See http://doc3.workerman.net/appendices/install-extension.html\n");
}
if (!extension_loaded('posix')) {
    exit("Please install posix extension. See http://doc3.workerman.net/appendices/install-extension.html\n");
}
define('GLOBAL_START', 1); // The flag is globally activated
//foreach (glob(__DIR__.'/Applications/*/start*.php') as $start_file) {
$services = ['task', 'gateway', 'gateway_ssl', 'businessworker', 'web'];
if (gethostname() == 'my.interserver.net')
    $services = array_merge(['globaldata', 'channel', 'register'], $services);
foreach ($services as $start_part) {
    $start_file = __DIR__.'/Applications/Chat/start_'.$start_part.'.php';
    require_once $start_file;
} // Load all Applications/*/start*.php to start all services

Worker::$stdoutFile = __DIR__.'/../../my/logs/billingd.log';
Worker::runAll(); // Run all services
