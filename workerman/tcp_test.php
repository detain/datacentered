<?php
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;

// Creae A Worker and listen 2347 port，not specified protocol
$tcp_worker = new Worker("tcp://0.0.0.0:2347");

// 4 processes
$tcp_worker->count = 4;

// Emitted when new connection come
$tcp_worker->onConnect = function($connection)
{
    echo "New connection\n";
};

// Emitted when data is received
$tcp_worker->onMessage = function($connection, $data)
{
    // Send hello $data
    $connection->send('hello ' . $data);
};

// Emitted when connection closed
$tcp_worker->onClose = function($connection)
{
    echo "Connection closed\n";
};

// Run worker
Worker::runAll();
