<?php
//composer require react/mysql
require_once __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;

$worker = new Worker('tcp://0.0.0.0:6161');
$worker->onWorkerStart = function() {
    global $mysql;
    $loop  = Worker::getEventLoop();
    $mysql = new React\MySQL\Connection($loop, [
        'host'   => '127.0.0.1',
        'dbname' => 'dbname',
        'user'   => 'user',
        'passwd' => 'passwd'
                                             ]
    );
    $mysql->on('error', function($e){
        echo $e;
    });
    $mysql->connect(function ($e) {
        if($e) {
            echo $e;
        } else {
            echo "connect success\n";
        }
    });
};
$worker->onMessage = function($connection, $data) {
    global $mysql;
    $mysql->query('show databases' /*trim($data)*/, function ($command, $mysql) use ($connection) {
        if ($command->hasError()) {
            $error = $command->getError();
        } else {
            $results = $command->resultRows;
            $fields  = $command->resultFields;
            $connection->send(json_encode($results));
        }
    });
};
Worker::runAll();
