<?php
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;

// Create a Worker and listens 2345 port，use HTTP Protocol
$http_worker = new Worker("http://0.0.0.0:2345");

// 4 processes
$http_worker->count = 4;

// Emitted when data is received
$http_worker->onMessage = function($connection, $data)
{
    var_dump($_GET, $_POST, $_COOKIE, $_SESSION, $_SERVER, $_FILE);
    // Send hello world to client
    $connection->send('hello world');
};

// Run all workers
Worker::runAll();
