<?php
$composer = require_once(__DIR__./../../../vendor/autoload.php');

$promiseArray = (new Amp\Artax\Client)->requestMulti([
    'google'    => 'http://www.google.com',
    'news'      => 'http://news.google.com',
    'bing'      => 'http://www.bing.com',
    'yahoo'     => 'https://www.yahoo.com',
]);

$responses = Amp\wait(Amp\all($promiseArray));

foreach ($responses as $key => $response) {
    printf(
        "%s | HTTP/%s %d %s\n",
        $key, // <-- these keys match those from our original request array
        $response->getProtocol(),
        $response->getStatus(),
        $response->getReason()
    );
}
