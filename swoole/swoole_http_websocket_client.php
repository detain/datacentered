<?php

$cli = new Swoole\Http\Client('127.0.0.1', 80);
$cli->setHeaders(array('User-Agent' => 'swoole-http-client'));
$cli->setCookies(array('test' => 'value'));

$cli->post('/dump.php', array("test" => 'abc'), function ($cli) {
    var_dump($cli->body);
    $cli->get('/index.php', function ($cli) {
        var_dump($cli->cookies);
        var_dump($cli->headers);
    });
});
