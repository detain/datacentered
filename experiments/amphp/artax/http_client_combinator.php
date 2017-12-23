<?php
$composer = require_once(__DIR__./../../../vendor/autoload.php');

use function Amp\run;
use function Amp\all;
use function Amp\stop;

run(function() {
    $httpClient = new Amp\Artax\Client;
    $promiseArray = $httpClient->requestMulti([
        "google"    => "http://www.google.com",
        "news"      => "http://news.google.com",
        "bing"      => "http://www.bing.com",
        "yahoo"     => "https://www.yahoo.com",
    ]);

    try {
        // magic combinator sauce to flatten the promise
        // array into a single promise
        $responses = (yield all($promiseArray));

        foreach ($responses as $key => $response) {
            printf(
                "%s | HTTP/%s %d %s\n",
                $key,
                $response->getProtocol(),
                $response->getStatus(),
                $response->getReason()
            );
        }
    } catch (Amp\CombinatorException $e) {
        // If any one of the requests fails the combo
        // promise returned by Amp\all() will fail and
        // be thrown back into our generator here.
        echo $e->getMessage(), "\n";
    }

    stop();
});
