<?php
$buffer = new swoole_buffer;
$buffer->append(str_repeat("A", 10));
$buffer->append(str_repeat("B", 20));
$buffer->append(str_repeat("C", 30));

var_dump($buffer);
echo $buffer->substr(0, 10, true).PHP_EOL;
echo $buffer->substr(0, 20, true).PHP_EOL;
echo $buffer->substr(0, 30).PHP_EOL;
$buffer->clear();

echo $buffer->substr(0, 10, true).PHP_EOL;
var_dump($buffer);
sleep(1);
