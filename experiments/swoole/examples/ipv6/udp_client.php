<?php
$client = new swoole_client(SWOOLE_SOCK_UDP6);
$client->connect('::1', 9502);
$client->send("admin");
echo $client->recv().PHP_EOL;
var_dump($client->getsockname());
var_dump($client->getpeername());
$client->sendto('::1', 9502, "admin2");
echo $client->recv().PHP_EOL;
sleep(1);
