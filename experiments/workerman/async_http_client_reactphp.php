<?php
//composer require react/http-client
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;

$worker = new Worker('tcp://0.0.0.0:6161');

$worker->onWorkerStart = function() {
    global   $client;
    $loop    = Worker::getEventLoop();
    $factory = new React\Dns\Resolver\Factory();
    $dns     = $factory->createCached('8.8.8.8', $loop);
    $factory = new React\HttpClient\Factory();
    $client = $factory->create($loop, $dns);
};

$worker->onMessage = function($connection, $host) {
    global     $client;
    $request = $client->request('GET', trim($host));
    $request->on('error', function(Exception $e) use ($connection) {
        $connection->send($e);
    });
    $request->on('response', function ($response) use ($connection) {
        $response->on('data', function ($data, $response) use ($connection) {
            $connection->send($data);
        });
    });
    $request->end();
};

Worker::runAll();
