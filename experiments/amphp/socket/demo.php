<?php // basic server

require __DIR__.'/../../../vendor/autoload.php';

use Amp as amp;
use Amp\Socket as socket;

amp\run(function () {
    $socket = socket\listen('tcp://127.0.0.1:1337');
    $server = new socket\Server($socket);
    echo "listening for new connections ...\n";
    while ($client = yield $server->accept()) {
        amp\resolve(onClient($client));
    }
});

// Generator coroutine is a lightweight "thread" for each client
/**
 * @param \Amp\Socket\Client $client
 */
function onClient(socket\Client $client)
{
    $clientId = $client->id();
    echo "+ connected: {$clientId}\n";
    while ($client->alive()) {
        $data = yield $client->readLine();
        echo "data read from {$clientId}: {$data}\n";
        $bytes = yield $client->write("echo: {$data}\n");
        echo  "{$bytes} written to client {$clientId}\n";
    }
    echo "- disconnected {$clientId}\n";
}
