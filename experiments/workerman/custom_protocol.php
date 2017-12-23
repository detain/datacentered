<?php
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;

// #### MyTextProtocol worker ####
$text_worker = new Worker('MyTextProtocol://0.0.0.0:5678');

$text_worker->onConnect = function($connection)
{
    echo "New connection\n";
};

$text_worker->onMessage =  function($connection, $data)
{
    // send data to client
    $connection->send("hello world \n");
};

$text_worker->onClose = function($connection)
{
    echo "Connection closed\n";
};

// run all workers
Worker::runAll();
