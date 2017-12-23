<?php
$composer = require_once(__DIR__./../../../vendor/autoload.php');

$body = (new Amp\Artax\FormBody)
    ->addField('name', 'Zoroaster')
    ->addFile('file1', '/hard/path/to/some/file1')
    ->addFile('file2', '/hard/path/to/some/file2')
;

$request = (new Amp\Artax\Request)
    ->setUri('http://httpbin.org/post')
    ->setMethod('POST')
    ->setBody($body)
;

$response = Amp\wait((new Amp\Artax\Client)->request($request));
