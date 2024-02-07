<?php
$composer = require_once __DIR__.'/../../../vendor/autoload.php';
$client = new Amp\Artax\Client;

// Dispatch two requests at the same time
$promiseArray = $client->requestMulti([
    'http://www.google.com',
    'http://www.bing.com'
                                      ]);

try {
    // Amp\all() flattens an array of promises into a new promise
    // that on which we can Amp\wait()
    [$google, $bing] = Amp\wait(Amp\all($promiseArray));
    var_dump($google->getStatus(), $bing->getStatus());
} catch (Exception $e) {
    echo $e;
}
