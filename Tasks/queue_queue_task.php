<?php

use Workerman\Worker;

function queue_queue_task($args)
{
	/**
	* @var \Workerman\MySQL\Connection
	*/
	global $worker_db;
	/**
	* @var \Memcached
	*/
	global $memcache;
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	require_once '/home/my/include/functions.inc.php';
	$hosts = 0;
	$memcached_start = time();
	//Worker::safeEcho('Task handler Started queue_queue_task'.PHP_EOL);
	$db = $GLOBALS['tf']->db;
	$db2 = clone $db;
	$masters = [];
	$output = $memcache->get('queue');
	if (!is_array($output) || !array_key_exists('queue', $output)) {
		$output = [
			'queue' => [],
			'new' => []
		];
	}
	$queued = 0;
	foreach (['vps', 'quickservers'] as $module) {
		if ($module == 'vps') {
			$tblname ='VPS';
			$table = 'vps';
			$prefix = 'vps';
			$influx_table = 'bandwidth';
		} else {
			$tblname = 'Rapid Deploy Servers';
			$table = 'quickservers';
			$prefix = 'qs';
			$influx_table = $prefix.'_bandwidth';
		}
		$masters[$module] = [];
		/*
		$db->query("select * from {$table} where {$prefix}_status='pending-setup' and {$prefix}_type != 54", __LINE__, __FILE__);
		if ($db->num_rows() > 0) {
			while ($db->next_record(MYSQL_ASSOC)) {
				if (!array_key_exists($db->Record[$prefix.'_server'], $masters[$module])) {
					$db2->query("select * from {$prefix}_masters left join {$prefix}_master_details using ({$prefix}_id) where {$prefix}_id={$db->Record[$prefix.'_server']}");
					$db2->next_record(MYSQL_ASSOC);
					$masters[$module][$db->Record[$prefix.'_server']] = $db2->Record;
				}
				$hostIp = $masters[$module][$db->Record[$prefix.'_server']];
				if (!array_key_exists($hostIp, $output['new'])) {
					$output['new'][$hostIp] = [];
				}
				$output['new'][$hostIp][$db->Record[$prefix.'_id']] = $db->Record;
				$queued++;
			}
		}
		*/
		$db->query("select {$table}.*, hl1.* from {$table}, queue_log as hl1 left join queue_log as hl2 on hl2.history_type=hl1.history_id and hl2.history_section='{$table}queuedone' where hl1.history_section='{$table}queue' and hl1.history_type={$prefix}_id and hl2.history_id is null and {$prefix}_type != 54 and {$prefix}_id=484", __LINE__, __FILE__);
		if ($db->num_rows() > 0) {
			while ($db->next_record(MYSQL_ASSOC)) {
				if (!array_key_exists($db->Record[$prefix.'_server'], $masters[$module])) {
					$db2->query("select * from {$prefix}_masters left join {$prefix}_master_details using ({$prefix}_id) where {$prefix}_id={$db->Record[$prefix.'_server']}");
					$db2->next_record(MYSQL_ASSOC);
					$masters[$module][$db->Record[$prefix.'_server']] = $db2->Record;
				}
				$hostIp = $masters[$module][$db->Record[$prefix.'_server']][$prefix.'_ip'];
				$queueCommand = vps_queue_handler($masters[$module][$db->Record[$prefix.'_server']], 'get_queue', $db->Record);
				$loopCount = 0;
				do {
					$response = $memcache->get('queue', function($memcache, $key, &$value) { $value = []; return true; }, \Memcached::GET_EXTENDED);
					$output = $response['value'];
					$cas = $response['cas'];
					if (!is_array($output) || !array_key_exists('queue', $output)) {
						$output = [
							'queue' => [],
							'new' => []
						];
					}
					if (!array_key_exists($hostIp, $output['queue'])) {
						$output['queue'][$hostIp] = [];
					}
					$output['queue'][$hostIp][] = $queueCommand;
					$loopCount++;
					if ($loopCount > 100) {
						Worker::safeEcho('Max Loops Reached Trying to Get queue CAS set '.PHP_EOL);
						break;
					}
				} while (!$memcache->cas($response['cas'], 'queue', $queue));				
				$queued++;
			}
		}
	}
	Worker::safeEcho('queue_queue_task finished processing '.$queued.' after '.(time() - $memcached_start).' seconds'.PHP_EOL);
	return;
}
