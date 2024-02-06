<?php
$composer = require_once __DIR__.'/../../vendor/autoload.php';
$myText = null;

Amp\run(function () {
    echo 'Please input some text: ';
    stream_set_blocking(STDIN, false);

    // Watch STDIN for input
    Amp\onReadable(STDIN, 'onInput');

    // Impose a 5-second timeout if nothing is input
    Amp\once("Amp\stop", $msDelay = 5000);
});
/**
 * @param $watcherId
 * @param $stream
 * @param $callbackData
 */
function onInput($watcherId, $stream, $callbackData)
{
    global $myText;
    $myText = fgets($stream);
    Amp\cancel($watcherId);
    stream_set_blocking(STDIN, true);
    Amp\stop();
}

var_dump($myText); // whatever you input on the CLI

// Continue doing regular synchronous things here.
