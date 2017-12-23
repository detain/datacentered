<?php
/** Generators
 * The basic idea behind generators is that a function doesn’t return a single value, but returns a sequence of values instead, where every value is emitted one by one. Or in other words, generators allow you to implement iterators more easily. A very simple example of this concept is the xrange() function:
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

foreach (xrange(1, 1000000) as $num)
	    echo $num, "\n";

/**
* The xrange() function shown above provides the same functionality as the built-in range() function. The only difference is that range() will return an array with one million numbers in the above case, whereas xrange() returns an iterator that will emit these numbers, but never actually compute an array with all of them.
* The advantages of this approach should be evident. It allows you to work with large datasets without loading them into memory all at once. You can even work with infinite data-streams.
* All this can also be done without generators, by manually implementing the Iterator interface. Generators only make it (a lot) more convenient, because you no longer have to implement five different methods for every iterator.
*/
