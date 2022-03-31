<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Workerman\Timer;

require_once __DIR__.'/ChannelClient.php';

/**
 * Class GlobalTimer
 * @package Workerman
 */
class GlobalTimer
{
	/**
	 * @var string
	 */
	protected static $_uniqueId = '';

	public static function getStatus() {
		return static::$_uniqueId;
	}

	/**
	 * init.
	 *
	 * @param $channel_server_ip
	 * @param $channel_server_port
	 * @param string $unique_id
	 */
	public static function init($channel_server_ip, $channel_server_port, $unique_id = '')
	{
		mt_srand();
		static::$_uniqueId = !$unique_id ? mt_rand() : $unique_id;
		ChannelClient::connect($channel_server_ip, $channel_server_port);
		ChannelClient::on(static::$_uniqueId, function($data){
			if ($data['cmd'] == 'del') {
				Timer::del($data['real_timer_id']);
			}
		});
	}

	/**
	 * Add a timer.
	 *
	 * @param float    $time_interval
	 * @param callable $func
	 * @param mixed    $args
	 * @param bool     $persistent
	 * @return int/false
	 */
	public static function add($time_interval, $func, $args = array(), $persistent = true)
	{
		$real_timer_id = Timer::add($time_interval, $func, $args, $persistent);
		return static::$_uniqueId . '-' . $real_timer_id;
	}

	/**
	 * Remove a timer.
	 *
	 * @param mixed $timer_id
	 * @return bool
	 */
	public static function del($timer_id)
	{
		if($pos = strrpos($timer_id, '-')) {
			$real_timer_id = substr($timer_id, $pos + 1);
			$unique_id = substr($timer_id, 0, $pos);
			ChannelClient::publish($unique_id, array(
				'cmd'           => 'del',
				'real_timer_id' => $real_timer_id
			));
		}
	}
}
