<?php
$process = new swoole_process(function (swoole_process $worker) {
    echo "Worker: start. PID=" . $worker->pid.PHP_EOL;
    sleep(2);
    $worker->write("hello master\n");
    $worker->exit(0);
}, false);

$pid = $process->start();
$r = [$process];
$ret = swoole_select($r, null, null, 1.0);
var_dump($ret);
var_dump($process->read());
