<?php
$composer = require_once(__DIR__./../../../vendor/autoload.php');
use Amp\Artax\Client;

$reactor = Amp\reactor();
$client = new Client($reactor);
$request = (new Amp\Artax\Request)
    ->setUri('http://www.google.com')
    ->setHeader('Connection', 'close')
;
$promise = $client->request($request);
$promise->when(function(Exception $error = null, $response = null) {
    if ($error) {
        // something went wrong :(
    } else {
        printf("Response complete: %d\n", $response->getStatus());
    }
});

// Nothing will happen until the event reactor runs.
$reactor->run();
