<?php
/** Generators as interruptible functions
 * To go from generators to coroutines it’s important to understand how they work internally: Generators are interruptible functions, where the yield statements constitute the interruption points.
 * Sticking to the above example, if you call xrange(1, 1000000) no code in the xrange() function is actually run. Instead PHP just returns an instance of the Generator class which implements the Iterator interface:
 *
 * @param     $start
 * @param     $end
 * @param int $step
 * @return \Generator
 */

function xrange($start, $end, $step = 1) {
    for ($i = $start; $i <= $end; $i += $step) {
        yield $i;
    }
}

$range = xrange(1, 1000000);
var_dump($range); // object(Generator)#1
var_dump($range instanceof Iterator); // bool(true)
