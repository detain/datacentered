<?php
$composer = require_once __DIR__.'/../../vendor/autoload.php';
/**
 * @param $x
 * @param $y
 * @return \Generator
 */
function asyncMultiply($x, $y)
{
    yield new Amp\Pause($millisecondsToPause = 100);
    return ($x * $y);
}

Amp\run(function () {
    try {
        // Yield control until the generator resolves
        // and return its eventual result.
        $result = yield from asyncMultiply(2, 21); // int(42)
    } catch (Exception $e) {
        // If promise resolution fails the exception is
        // thrown back to us and we handle it as needed.
    }
});
