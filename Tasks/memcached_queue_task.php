<?php

use Workerman\Worker;

function memcached_queue_task($args)
{
	//require_once __DIR__.'/../../../my/include/functions.inc.php';
	/**
	* @var \GlobalData\Client
	*/
	global $global, $worker_db, $influx_client, $influx_database;
	$return = [];
	foreach ($args['queues'] as $queue) {
		Worker::safeEcho('Queue: '.print_r($queue, true).PHP_EOL);
		switch ($queue['post']['action']) {
			case 'cpu_usage':
				$cpu_usage = json_decode($queue['post']['cpu_usage'], true);
				if (!is_array($cpu_usage)) {
					break;
				}
				$server_usage = array_shift($cpu_usage);
				$cpu_avg = $server_usage['cpu'];
				$serialized_server_usage = json_encode($server_usage);
				foreach (['vps' => 'vps', 'quickservers' => 'qs'] as $module => $prefix) {
					$table = $module;
					$row = $worker_db->select($prefix.'_masters.*,'.$prefix.'_master_details.'.$prefix.'_cpu_avg,'.$prefix.'_master_details.'.$prefix.'_cpu_usage')
						->from($prefix.'_masters')
						->leftJoin($prefix.'_master_details',$prefix.'_masters.'.$prefix.'_id = '.$prefix.'_master_details.'.$prefix.'_id')
						->where($prefix.'_ip = :ip')
						->bindValues(['ip' => $queue['ip']])
						->row();
					if ($row === false) {
						continue;
					}
					if (is_null($row[$prefix.'_cpu_usage'])) {
						$worker_db->insert($prefix.'_master_details')
							->cols([
								$prefix.'_id' => $row[$prefix.'_id'],
								$prefix.'_cpu_avg' => $cpu_avg,
								$prefix.'_cpu_usage' => $serialized_server_usage
							])->query();
					} else {
						if ($row[$prefix.'_cpu_usage'] != $serialized_server_usage || $row[$prefix.'_cpu_avg'] != $cpu_avg) {
							$worker_db->update($prefix.'_master_details')
								->cols([$prefix.'_cpu_avg', $prefix.'_cpu_usage'])
								->where('ID='.$row[$prefix.'_id'])
								->bindValues([$prefix.'_cpu_avg' => $cpu_avg, $prefix.'_cpu_usage' => $serialized_server_usage])
								->query();
						}
					}					
					$veids = implode(',', array_keys($cpu_usage));
					$rows = $worker_db->select($prefix.'_id,',$prefix.'_vzid')
						->from($table)
						->where($prefix.'_server='.$row[$prefix.'_id'].' and '.$prefix.'_vzid in ('.$veids.')')
						->query();
					foreach ($rows as $order) {
						$influxTags = [
							'vps' => (int)$order[$prefix.'_id'],
							'host' => (int)$row[$prefix.'_id'],
						];
						$influxValues = $cpu_usage[$order[$prefix.'_vzid']];
						if (!is_null($influxValues)) {
							$points[] = new \InfluxDB\Point($prefix.'_stats', null, $influxTags, $influxValues);
						}
					}
					
				}
				break;
			default:
				Worker::safeEcho('Dont know how to handel this Queued Entry: '.print_r($queue, true).PHP_EOL);
				break;
		}
	}
	return $return;
}
