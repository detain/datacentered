<?php
$fp = stream_socket_client("tcp://127.0.0.1:9501", $errno, $errstr, 30);
fwrite($fp, "HELLO world");

swoole_event_add($fp, function ($fp) {
    echo fread($fp, 1024).PHP_EOL;
    swoole_event_del($fp);
    fclose($fp);
});
