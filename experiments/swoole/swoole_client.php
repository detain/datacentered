<?php
// SWOOLE_SOCK_TCP, SWOOLE_SOCK_SYNC - Synchronous blocking
// SWOOLE_KEEP - keeps the connection i think or shares it maybe
// SWOOLE_SOCK_UDP, SWOOLE_SOCK_ASYNC - Asynchronous non-blocking
$client = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
$client->on("connect", function ($cli) {
    $cli->send("hello world\n");
});
$client->on("receive", function ($cli, $data) {
    echo "received: {$data}\n";
});
$client->on("error", function ($cli) {
    echo "connect failed\n";
});
$client->on("close", function ($cli) {
    echo "connection close\n";
});
$client->connect("127.0.0.1", 9501, 0.5);
