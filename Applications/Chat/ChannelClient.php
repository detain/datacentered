<?php

use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\AsyncTcpConnection;

/**
 * Channel/Client
 * @version 1.0.5
 */
class ChannelClient extends \Channel\Client
{
	public static function getStatus() {
		return self::$_events;
	}
}