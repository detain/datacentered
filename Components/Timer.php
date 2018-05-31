<?php
use Workerman\Worker;
use Workerman\Lib\Timer;

$task = new Worker();
$task->onWorkerStart = function($task)
{
	// 2.5 seconds
	$time_interval = 2.5;
	$timer_id = Timer::add($time_interval, function() {
            Worker::safeEcho("Timer run\n");
		}
	);
};
