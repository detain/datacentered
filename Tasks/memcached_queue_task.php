<?php

use Workerman\Worker;

function memcached_queue_task($args)
{
	//require_once __DIR__.'/../../../my/include/functions.inc.php';
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	/**
	* @var \Workerman\MySQL\Connection
	*/
	global $worker_db;
	/**
	* @var \InfluxDB\Client
	*/
	global $influx_client;
	global $influx_database;
	/**
	* @var \Memcached
	*/
	global $memcache;
	$memcached_start = time();
	Worker::safeEcho('Task handler Started memcached_queue_task'.PHP_EOL);
	$return = [];
	foreach ($args['queues'] as $idx => $queue) {
		
		switch ($queue['post']['action']) {
			case 'cpu_usage':
				$cpu_usage = json_decode($queue['post']['cpu_usage'], true);
				$queue['post']['cpu_usage'] = strlen($queue['post']['cpu_usage']).' byte string';
				Worker::safeEcho('Queue #'.$idx.': '.json_encode($queue).PHP_EOL);
				if (!is_array($cpu_usage)) {
					break;
				}
				$server_usage = array_shift($cpu_usage);
				$cpu_avg = $server_usage['cpu'];
				$serialized_server_usage = json_encode($server_usage);
				$points = [];
				//foreach (['vps' => 'vps', 'quickservers' => 'qs'] as $module => $prefix) {
				{
					$module = 'vps';
					$prefix = 'vps';
					$table = $module;
					$server = $memcache->get($module.'_masters'.$queue['ip']);
					if ($server === false) {
						$server = $worker_db->select($prefix.'_id')
							->from($prefix.'_masters')
							->where($prefix.'_ip = :ip')
							->bindValues(['ip' => $queue['ip']])
							->row();
						if ($server === false) {
							break;
						}
						$memcache->set($module.'_masters'.$queue['ip'], $server, 3600);
					}
					$serverDetails = $memcache->get($module.'_master_details'.$server[$prefix.'_id']);
					if ($serverDetails === false) {
						$serverDetails = $worker_db->select($prefix.'_cpu_avg,'.$prefix.'_cpu_usage')
							->from($prefix.'_master_details')
							->where($prefix.'_id = :id')
							->bindValues(['id' => $server[$prefix.'_id']])
							->row();
						if ($serverDetails === false) {
							$worker_db->insert($prefix.'_master_details')
								->cols([
									$prefix.'_id' => $server[$prefix.'_id'],
									$prefix.'_cpu_avg' => $cpu_avg,
									$prefix.'_cpu_usage' => $serialized_server_usage
								])->query();
							$serverDetails = [
								$prefix.'_cpu_avg' => $cpu_avg,
								$prefix.'_cpu_usage' => $serialized_server_usage
							];
							$memcache->set($module.'_master_details'.$server[$prefix.'_id'], $serverDetails, 3600);
						}
					}
					if ($serverDetails[$prefix.'_cpu_usage'] != $serialized_server_usage || $serverDetails[$prefix.'_cpu_avg'] != $cpu_avg) {
						$worker_db->update($prefix.'_master_details')
							->cols([$prefix.'_cpu_avg', $prefix.'_cpu_usage'])
							->where($prefix.'_id='.$server[$prefix.'_id'])
							->bindValues([$prefix.'_cpu_avg' => $cpu_avg, $prefix.'_cpu_usage' => $serialized_server_usage])
							->query();
						$serverDetails[$prefix.'_cpu_avg'] = $cpu_avg;
						$serverDetails[$prefix.'_cpu_usage'] = $serialized_server_usage;
						$memcache->set($module.'_master_details'.$server[$prefix.'_id'], $serverDetails, 3600);
					}
					if (count($cpu_usage) > 0) {					
						$veids = implode(',', array_keys($cpu_usage));
						$rows = $worker_db->select($prefix.'_id,'.$prefix.'_vzid')
							->from($table)
							->where($prefix.'_server='.$server[$prefix.'_id'].' and '.$prefix.'_vzid in ('.$veids.')')
							->query();
						foreach ($rows as $order) {
							$influxTags = [
								'vps' => (int)$order[$prefix.'_id'],
								'host' => (int)$server[$prefix.'_id'],
							];
							if (array_key_exists($order[$prefix.'_vzid'], $cpu_usage)) {
								$influxValues = $cpu_usage[$order[$prefix.'_vzid']];
								if (!is_null($influxValues)) {
									$points[] = new \InfluxDB\Point($prefix.'_stats', null, $influxTags, $influxValues);
								}
							}
						}
						try {
							$newPoints = $influx_database->writePoints($points, \InfluxDB\Database::PRECISION_SECONDS);
						} catch (\InfluxDB\Exception $e) {
							Worker::safeEcho('InfluxDB got Exception '.$e->getMessage(). ' while writing bandwidth points to DB'.PHP_EOL);
						}
					}					
				}
				break;
			case 'bandwidth':
				$bandwidth = $queue['post']['bandwidth'];
				$servers = $queue['post']['servers'];
				$queue['post']['bandwidth'] = strlen($queue['post']['bandwidth']).' byte string';
				$queue['post']['servers'] = strlen($queue['post']['servers']).' byte string';
				Worker::safeEcho('Queue #'.$idx.': '.json_encode($queue).PHP_EOL);
				$points = [];
				$module = $queue['post']['module'];
				if ($module == 'vps') {
					$table = 'vps';
					$prefix = 'vps';
					$influx_table = 'bandwidth';
				} else {
					$table = 'quickservers';
					$prefix = 'qs';
					$influx_table = $prefix.'_bandwidth';
				}
				$bandwidth = base64_decode($bandwidth);
				$bandwidth = gzuncompress($bandwidth);
				$bandwidth = json_decode($bandwidth,true);
				$servers = base64_decode($servers);
				$servers = gzuncompress($servers);
				$servers = json_decode($servers,true);
				//Worker::safeEcho(print_r($bandwidth, true).PHP_EOL);
				//Worker::safeEcho(print_r($servers, true).PHP_EOL);
				$server = $memcache->get($module.'_masters'.$queue['ip']);
				if ($server === false) {
					$server = $worker_db->select($prefix.'_id')
						->from($prefix.'_masters')
						->where($prefix.'_ip = :ip')
						->bindValues(['ip' => $queue['ip']])
						->row();
					if ($server === false) {
						continue;
					}
					$memcache->set($module.'_masters'.$queue['ip'], $server, 3600);
				}
				if (is_array($bandwdith)) {
					foreach ($bandwidth as $ip => $data) {
						$iplong = sprintf('%u', ip2long($ip));
						$veid = $servers[$ip];
						$idFromVeid = preg_replace('/[A-Za-z\._\-]*/m', '', $servers[$ip]);
						$row = $worker_db->select($prefix.'_id')
							->from($table)
							->where($prefix.'_server = :server and ('.$prefix.'_hostname = :hostname or '.$prefix.'_vzid = :veid or '.$prefix.'_vzid = :idFromVeid)')
							->bindValues(['server' => $server[$prefix.'_id'], 'hostname' => $veid, 'veid' => $veid, 'idFromVeid' => $idFromVeid])
							->row();
						if ($row !== false) {
							$points[] = new \InfluxDB\Point($influx_table, null, [
								'vps' => (int)$row[$prefix.'_id'],
								'host' => (int)$server[$prefix.'_id'],
								'ip' => $ip
							], [
								'in' => (int)$data['in'],
								'out' => (int)$data['out']
							]);
						}
					}
					try {
						$newPoints = $influx_database->writePoints($points, \InfluxDB\Database::PRECISION_SECONDS);
					} catch (\InfluxDB\Exception $e) {
						Worker::safeEcho('InfluxDB got Exception '.$e->getMessage(). ' while writing bandwidth points to DB'.PHP_EOL);
					}
				}
				break;
			default:
				Worker::safeEcho('Dont know how to handel this Queued Entry: '.json_encode($queue, true).PHP_EOL);
				break;
		}
	}
	Worker::safeEcho('Finished Task memcached_queue_task after '.(time() - $memcached_start).' seconds'.PHP_EOL);
	return $return;
}
