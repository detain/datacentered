<?php

try {
    $client = new Amp\Artax\Client;
    $promise = $client->request('http://www.google.com');
    $response = Amp\wait($promise);
    printf(
        "\nHTTP/%s %d %s\n",
        $response->getProtocol(),
        $response->getStatus(),
        $response->getReason()
    );
} catch (Exception $error) {
    echo $error;
}
