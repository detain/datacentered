<?php
$atomic = new swoole_atomic(123);
echo $atomic->add(12).PHP_EOL;
echo $atomic->sub(11).PHP_EOL;
echo $atomic->cmpset(122, 999).PHP_EOL;
echo $atomic->cmpset(124, 999).PHP_EOL;
echo $atomic->get().PHP_EOL;
