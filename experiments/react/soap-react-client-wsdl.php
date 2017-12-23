<?php
use Clue\React\Soap\Factory;
use Clue\React\Soap\Client;
require __DIR__.'/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$wsdl = 'https://my3.interserver.net/api.php?wsdl';

$factory->createClient($wsdl)->then(
    function (Client $client) {
        echo 'Functions:' . PHP_EOL .
             implode(PHP_EOL, $client->getFunctions()) . PHP_EOL .
             PHP_EOL .
             'Types:' . PHP_EOL .
             implode(PHP_EOL, $client->getTypes()) . PHP_EOL;
    },
    function (Exception $e) {
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
    }
);

