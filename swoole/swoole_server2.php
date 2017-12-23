<?php
$server = new swoole_server("127.0.0.1", 9501);
$server->on('connect', function ($server, $fd){
    echo "connection open: {$fd}\n";
});
$server->on('receive', function ($server, $fd, $from_id, $data) {
    $server->send($fd, "Swoole: {$data}");
    $server->close($fd);
});
$server->on('close', function ($server, $fd) {
    echo "connection close: {$fd}\n";
});
$server->start();
