<?php
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;

// SSL context.
$context = [
    'ssl' => [
        'local_cert' => '/your/path/of/server.pem',
        'local_pk'   => '/your/path/of/server.key'
    ]
];

// Create a Websocket server with ssl context.
$ws_worker = new Worker('websocket://0.0.0.0:2346', $context);

// Enable SSL. WebSocket+SSL means that Secure WebSocket (wss://).
// The similar approaches for Https etc.
$ws_worker->transport = 'ssl';

$ws_worker->onMessage = function($connection, $data)
{
    // Send hello $data
    $connection->send('hello '.$data);
};

Worker::runAll();
