<?php

$cli = new Swoole\Http\Client('127.0.0.1', 80);
$cli->setHeaders(['User-Agent' => 'swoole-http-client']);
$cli->setCookies(['test' => 'value']);

$cli->post('/dump.php', ["test" => 'abc'], function ($cli) {
    var_dump($cli->body);
    $cli->get('/index.php', function ($cli) {
        var_dump($cli->cookies);
        var_dump($cli->headers);
    });
});
