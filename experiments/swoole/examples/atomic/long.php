<?php
$l = new Swoole\Atomic\Long(-2 ** 36);
echo $l->get().PHP_EOL;
echo $l->add(20).PHP_EOL;
echo $l->sub(20).PHP_EOL;
echo $l->sub(-20).PHP_EOL;
echo $l->cmpset(-2 ** 36, 0).PHP_EOL;
echo $l->cmpset(-2 ** 36 + 20, 0).PHP_EOL;
