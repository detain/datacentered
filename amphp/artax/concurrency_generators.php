<?php
$composer = require_once(__DIR__./../../../vendor/autoload.php');
Amp\run(function() {
    $client = new Amp\Artax\Client;

    // Dispatch two requests at the same time
    $promiseArray = $client->requestMulti([
        'http://www.google.com',
        'http://www.bing.com',
    ]);

    try {
        // Yield control until all requests finish (magic sauce)
        list($google, $bing) = yield Amp\all($promiseArray);
        var_dump($google->getStatus(), $bing->getStatus());
    } catch (Exception $e) {
        echo $e;
    }
});
