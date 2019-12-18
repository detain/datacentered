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
	require_once __DIR__.'/../../../my/include/functions.inc.php';
	$hosts = 0;
	$memcached_start = time();
	//Worker::safeEcho('Task handler Started queue_queue_task'.PHP_EOL);
	$db = $GLOBALS['tf']->db;
	$db2 = clone $db;
	$masters = [];
	$newvps = [];
	$queue = [];
	$output = '';
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
		$newvps[$module] = [];
		$queue[$module] = [];		
		$db->query("select * from vps where vps_status='pending-setup' and vps_type != 54", __LINE__, __FILE__);
		if ($db->num_rows() > 0) {
			while ($db->next_record(MYSQL_ASSOC)) {
				if (!array_key_exists($db->Record['vps_server'], $masters[$module])) {
					$db2->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_id={$db->Record['vps_server']}");
					$db2->next_record(MYSQL_ASSOC);
					$masters[$module][$db->Record['vps_server']] = $db->Record;
					//$queue[$module][$db->Record['vps_server']] = [];
				}
				if (!array_key_exists($db->Record['vps_server'], $newvps[$module])) {
					$newvps[$module][$db->Record['vps_server']] = [];
				}
				$newvps[$module][$db->Record['vps_server']][] = $db->Record;
			}
		}
		$db->query("select vps.*, hl1.* from vps, queue_log as hl1 left join queue_log as hl2 on hl2.history_type=hl1.history_id and hl2.history_section='vpsqueuedone' where hl1.history_section='vpsqueue' and hl1.history_type=vps_id and hl2.history_id is null and vps_type != 54", __LINE__, __FILE__);
		if ($db->num_rows() > 0) {
			while ($db->next_record(MYSQL_ASSOC)) {
				if (!array_key_exists($db->Record['vps_server'], $masters[$module])) {
					$db2->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_id={$db->Record['vps_server']}");
					$db2->next_record(MYSQL_ASSOC);
					$masters[$module][$db->Record['vps_server']] = $db->Record;
				}
				if (!array_key_exists($db->Record['vps_server'], $queue[$module])) {
					$queue[$module][$db->Record['vps_server']] = [];
				}
				$queue[$module][$db->Record['vps_server']][] = $db->Record;
			}
		}
		foreach ($masters[$module] as $host_id => $serviceMaster) {
			if (count($newvps[$module][$host_id]) > 0) {
				function_requirements('vps_queue_handler');
				if (sizeof($serviceMaster['newvps']) > 0) {
					myadmin_log('myadmin', 'info', 'Processing New VPS for '.$serviceMaster['vps_name'], __LINE__, __FILE__, 'vps');
					$output .= vps_queue_handler($serviceMaster, 'get_new_vps', $serviceMaster['newvps']);
				}
			}
			if (count($queue[$module][$host_id]) > 0) {
				function_requirements('vps_queue_handler');
				if (sizeof($serviceMaster['queue']) > 0) {
					myadmin_log('myadmin', 'info', 'Processing VPS Queue for '.$serviceMaster['vps_name'], __LINE__, __FILE__, 'vps');
					$output .= vps_queue_handler($serviceMaster, 'get_queue', $serviceMaster['queue']);
				}
			}
		}
		$memcache->set('queue'.$module.$server[$prefix.'_ip'], $output);
	}
	Worker::safeEcho('queue_queue_task finished processing '.$hosts.' after '.(time() - $memcached_start).' seconds'.PHP_EOL);
	return;
}
