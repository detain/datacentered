<?php
//composer require react/dns
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;
$worker = new Worker('tcp://0.0.0.0:6161');
$worker->onWorkerStart = function() {
    global   $dns;
    // Get event-loop.
    $loop    = Worker::getEventLoop();
    $factory = new React\Dns\Resolver\Factory();
    $dns     = $factory->create('8.8.8.8', $loop);
};
$worker->onMessage = function($connection, $host) {
    global $dns;
    $host = trim($host);
    $dns->resolve($host)->then(function($ip) use($host, $connection) {
        $connection->send("$host: $ip");
    },function($e) use($host, $connection){
        $connection->send("$host: {$e->getMessage()}");
    });
};

Worker::runAll();
