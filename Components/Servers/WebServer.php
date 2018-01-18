<?php
use Workerman\Worker;
use Workerman\WebServer;

$webserver_worker= new WebServer('http://0.0.0.0:2209'); // WebServer, used to split html js css browser
$webserver_worker->count = 5; // WebServer number
$webserver_worker->addRoot(isset($_SERVER['HOSTNAME']) ? $_SERVER['HOSTNAME'] : trim(`hostname -f`), __DIR__.'/Web'); // Set the site root
$webserver_worker->addRoot('localhost', __DIR__ . '/Web');


return $webserver_worker;
