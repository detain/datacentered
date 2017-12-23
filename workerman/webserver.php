<?php
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\WebServer;
use Workerman\Worker;

// WebServer
$web = new WebServer('http://0.0.0.0:80');

// 4 processes
$web->count = 4;

// Set the root of domains
$web->addRoot('www.your_domain.com', '/your/path/Web');
$web->addRoot('www.another_domain.com', '/another/path/Web');
// run all workers
Worker::runAll();
