<?php
$composer = require_once __DIR__.'/../../../vendor/autoload.php';

Amp\run(function () {
    $githubIpv4 = yield Amp\Dns\resolve('github.com', $options = ['types' => Amp\Dns\Record::A]);
    var_dump($githubIpv4);

    $googleIpv4 = Amp\Dns\resolve('google.com', $options = ['types' => Amp\Dns\Record::A]);
    $googleIpv6 = Amp\Dns\resolve('google.com', $options = ['types' => Amp\Dns\Record::AAAA]);

    $firstGoogleResult = yield Amp\first([$ipv4Result, $ipv6Result]);
    var_dump($firstGoogleResult);

    $combinedGoogleResult = yield Amp\Dns\resolve('google.com');
    var_dump($combinedGoogleResult);

    $googleMx = yield Amp\Dns\query('google.com', Amp\Dns\Record::MX);
    var_dump($googleMx);
});
