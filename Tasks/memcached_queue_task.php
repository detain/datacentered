<?php

use Workerman\Worker;

function memcached_queue_task($args)
{
	//require_once '/home/my/include/functions.inc.php';
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
	global $influx_v2_client;
	global $influx_v2_database;
	/**
	* @var \Memcached
	*/
	global $memcache;
	$memcached_start = time();
    //
	//Worker::safeEcho('Task handler Started memcached_queue_task'.PHP_EOL);
	if (!isset($global->queuein)) {
		$global->queuein = 0;
	}
	if (!$global->cas('queuein', 0, 1)) {
		Worker::safeEcho('Cannot Get queuein Lock, Returning after '.(time() - $memcached_start).' seconds'.PHP_EOL);
		return;
	}
    $queuehosts = [];
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
        $queuehosts = array_merge($queuehosts, $worker_db->select($prefix.'_ip')
                    ->from($prefix.'_masters')
                    ->column());
    }
    if (!is_array($queuehosts)) {
        echo 'Queue Hosts is not array:'.var_export($queuehosts,true);
    }
	$processQueue = [];
    foreach ($queuehosts as $hostIp) {
	    $queue = $memcache->get('queuein'.$hostIp);
        if (is_array($queue)) {
            $processQueue = array_merge($processQueue, $queue);
            $queue = [];
            $memcache->set('queuein'.$hostIp, $queue);
        }
	}
	if (count($processQueue) == 0) {
		$global->queuein = 0;
		Worker::safeEcho('Empty Queue, Returning after '.(time() - $memcached_start).' seconds'.PHP_EOL);
		return;
	}
	/**
	* @var $processQueue an array of queued data sets to process
	*/
	//Worker::safeEcho('Processing '.count($processQueue).' Queues from Memcached after '.(time() - $memcached_start).' seconds'.PHP_EOL);

	$return = [];
	foreach ($processQueue as $idx => $queue) {
		$module = isset($queue['post']['module']) ? $queue['post']['module'] : 'vps';
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
		$server = $memcache->get($module.'_masters'.$queue['ip']);
		if ($server === false) {
			$server = $worker_db->select($prefix.'_id,'.$prefix.'_name,'.$prefix.'_hdsize,'.$prefix.'_bits,'.$prefix.'_ram,'.$prefix.'_cpu_model,'.$prefix.'_kernel,'.$prefix.'_cores,'.$prefix.'_raid_status,'.$prefix.'_raid_building,'.$prefix.'_mounts,'.$prefix.'_drive_type')
				->from($prefix.'_masters')
				->where($prefix.'_ip = :ip')
				->bindValues(['ip' => $queue['ip']])
				->row();
			if ($server === false) {
				// a queue for a server on a diff module
				continue;
			}
			$memcache->set($module.'_masters'.$queue['ip'], $server, 3600);
		}
		switch ($queue['post']['action']) {
			case 'cpu_usage':
				$cpu_usage = json_decode($queue['post']['cpu_usage'], true);
				//$queue['post']['cpu_usage'] = strlen($queue['post']['cpu_usage']).' byte string';
				//Worker::safeEcho('Queue #'.$idx.': '.json_encode($queue).PHP_EOL);
				if (!is_array($cpu_usage)) {
					continue 2;
				}
				$server_usage = array_shift($cpu_usage);
				$cpu_avg = $server_usage['cpu'];
				$serialized_server_usage = json_encode($server_usage);
				$points = [];
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
					try {
						$worker_db->update($prefix.'_master_details')
							->cols([$prefix.'_cpu_avg', $prefix.'_cpu_usage'])
							->where($prefix.'_id='.$server[$prefix.'_id'])
							->bindValues([$prefix.'_cpu_avg' => $cpu_avg, $prefix.'_cpu_usage' => $serialized_server_usage])
							->query();
					} catch (\PDOException $e) {
						if (in_array($e->getCode(), [3101, 1180])) {
							$worker_db->update($prefix.'_master_details')
								->cols([$prefix.'_cpu_avg', $prefix.'_cpu_usage'])
								->where($prefix.'_id='.$server[$prefix.'_id'])
								->bindValues([$prefix.'_cpu_avg' => $cpu_avg, $prefix.'_cpu_usage' => $serialized_server_usage])
								->query();
						}
					}
					$serverDetails[$prefix.'_cpu_avg'] = $cpu_avg;
					$serverDetails[$prefix.'_cpu_usage'] = $serialized_server_usage;
					$memcache->set($module.'_master_details'.$server[$prefix.'_id'], $serverDetails, 3600);
				}
				$serverVps = $memcache->get($module.'_vps'.$server[$prefix.'_id']);
				if ($serverVps === false) {
					$serverVps = [];
				}
				if (count($cpu_usage) > 0) {
					foreach ($cpu_usage as $veid => $influxValues) {
						if (array_key_exists($veid, $serverVps)) {
							$vps = $serverVps[$veid];
							if (INFLUX_V2 === true) {
								/*$point = \InfluxDB2\Point::measurement($prefix.'_stats')
									->addTag('vps', (int)$vps)
									->addTag('host', (int)$server[$prefix.'_id'])
									->time(time());*/
								$point = [];
								foreach ($influxValues as $key => $value) {
									//$point->addField($key, $value);
									if (is_numeric($value)) {
										$point[] = $key.'='.$value;
									} else {
										$point[] = $key.'="'.$value.'"';
									}
								}
								$point = $prefix.'_stats,vps='.(int)$vps.',host='.(int)$server[$prefix.'_id'].' '.implode(',',$point);
								$influx_v2_database->write($point);
							}
						} else {
							$row = $worker_db->select($prefix.'_id,'.$prefix.'_vzid')
								->from($table)
								->where($prefix.'_server = :server and '.$prefix.'_vzid = :veid')
								->bindValues(['server' => $server[$prefix.'_id'], 'veid' => $veid])
								->row();
							if ($row !== false) {
								$serverVps[$veid] = $row[$prefix.'_id'];
								$memcache->set($module.'_vps'.$server[$prefix.'_id'], $serverVps, 3600);
								if (INFLUX_V2 === true) {
									/*$point = \InfluxDB2\Point::measurement($prefix.'_stats')
										->addTag('vps', (int)$row[$prefix.'_id'])
										->addTag('host', (int)$server[$prefix.'_id'])
										->time(time());*/
									$point = [];
									foreach ($influxValues as $key => $value) {
										//$point->addField($key, $value);
										if (is_numeric($value)) {
											$point[] = $key.'='.$value;
										} else {
											$point[] = $key.'="'.$value.'"';
										}
									}
									$point = $prefix.'_stats,vps='.(int)$row[$prefix.'_id'].',host='.(int)$server[$prefix.'_id'].' '.implode(',',$point);
									$influx_v2_database->write($point);
								}
							}
						}
					}
					try {
						if (INFLUX_V2 === true) {
							$influx_v2_database->close();
						}
					} catch (\Exception $e) {
						Worker::safeEcho('InfluxDB got Exception '.$e->getMessage(). ' while writing bandwidth points to DB'.PHP_EOL);
					}
				}
				break;
			case 'bandwidth':
				$bandwidth = $queue['post']['bandwidth'];
				$servers = $queue['post']['servers'];
				//$queue['post']['bandwidth'] = strlen($queue['post']['bandwidth']).' byte string';
				//$queue['post']['servers'] = strlen($queue['post']['servers']).' byte string';
				//Worker::safeEcho('Queue #'.$idx.': '.json_encode($queue).PHP_EOL);
				$points = [];
				$bandwidth = base64_decode($bandwidth);
				$bandwidth = gzuncompress($bandwidth);
				$bandwidth = json_decode($bandwidth,true);
				$servers = base64_decode($servers);
				$servers = gzuncompress($servers);
				$servers = json_decode($servers,true);
				if (is_array($bandwidth)) {
					$serverVps = $memcache->get($module.'_vps'.$server[$prefix.'_id']);
					if ($serverVps === false) {
						$serverVps = [];
					}
					$errors = [];
					foreach ($bandwidth as $ip => $data) {
						if ($ip == '')
							continue;
						$iplong = sprintf('%u', ip2long($ip));
						$veid = $servers[$ip];
						$idFromVeid = preg_replace('/[A-Za-z\._\-]*/m', '', $servers[$ip]);
						if (!array_key_exists($veid, $serverVps)) {
							$row = $worker_db->select($prefix.'_id')
								->from($table)
								->where($prefix.'_server = :server and ('.$prefix.'_hostname = :hostname or '.$prefix.'_vzid = :veid or '.$prefix.'_vzid = :idFromVeid)')
								->bindValues(['server' => $server[$prefix.'_id'], 'hostname' => $veid, 'veid' => $veid, 'idFromVeid' => $idFromVeid])
								->row();
							if ($row === false) {
								$errors[] = $ip.'. Server '.$server[$prefix.'_id'].' VEID '.$veid.' IdFromVeid '.$idFromVeid;
								continue;
							}
							$serverVps[$veid] = $row[$prefix.'_id'];
							$memcache->set($module.'_vps'.$server[$prefix.'_id'], $serverVps, 3600);
						}
						$vps = $serverVps[$veid];
						if (INFLUX_V2 === true) {
							/*$point = \InfluxDB2\Point::measurement($influx_table)
								->addTag('vps', (int)$vps)
								->addTag('host', (int)$server[$prefix.'_id'])
								->addTag('ip', $ip)
								->addField('in', (int)$data['in'])
								->addField('out', (int)$data['out'])
								->time(time());*/
							$point = $influx_table.',vps='.(int)$vps.',host='.(int)$server[$prefix.'_id'].',ip='.$ip.' in='.(int)$data['in'].',out='.(int)$data['out'];
							$influx_v2_database->write($point);
						}
					}
					if (count($errors) > 0)
						Worker::safeEcho('Bandwidth Data with no matching IP: '.implode(', ', $errors).PHP_EOL);
					try {
						if (INFLUX_V2 === true) {
							$influx_v2_database->close();
						}
					} catch (\Exception $e) {
						Worker::safeEcho('InfluxDB got Exception '.$e->getMessage(). ' while writing bandwidth points to DB'.PHP_EOL);
					}
				}
				break;
			case 'server_info':
				$servers = $queue['post']['servers'];
				$servers = base64_decode($servers);
				$servers = json_decode($servers, true);
				//Worker::safeEcho('server_info '.$server[$prefix.'_name'].'got servers: '.var_export($servers,true).PHP_EOL);
				$fields = ['load', 'hdfree', 'iowait', 'hdsize', 'bits', 'ram', 'cpu_model', 'cpu_mhz', 'kernel', 'raid_building', 'cores', 'raid_status', 'mounts', 'drive_type'];
				if ($module == 'quickservers' && isset($servers['ram']))
					$servers['ram'] = floor($servers['ram'] * 0.90);
				$detailfields = ['ioping'];
				$skipfields = ['load', 'hdfree', 'iowait', 'cpu_mhz'];
				foreach ($skipfields as $field) {
					$key = $module.'|host|'.$server[$prefix.'_id'].'|'.$field;
					if (isset($servers[$field])) {
						//Worker::safeEcho('server_info setting '.$key.'='.$servers[$field].PHP_EOL);
						$memcache->set($key, $servers[$field]);
					}
				}
				foreach ($detailfields as $field) {
					$key = '|'.$module.'|hostd|'.$server[$prefix.'_id'].'|'.$field;
					if (isset($servers[$field]))
						$memcache->set($key, $servers[$field]);
				}
				$cols = [];
				$values = [];
				foreach ($fields as $field) {
					if (!in_array($field, $skipfields) && isset($servers[$field]) && isset($server[$prefix.'_'.$field]) && $server[$prefix.'_'.$field] != $servers[$field]) {
						$cols[] = $prefix.'_'.$field;
						$values[$prefix.'_'.$field] = $servers[$field];
						$server[$prefix.'_'.$field] = $servers[$field];
					}
				}
				if (count($cols) > 0)
					try {
						$worker_db->update($prefix.'_masters')
							->cols($cols)
							->where($prefix.'_id='.$server[$prefix.'_id'])
							->bindValues($values)
							->query();
					} catch (\PDOException $e) {
						Worker::safeEcho('Cought PDO Excetion #'.$e->getCode().':'.$e->getMessage().PHP_EOL);
						if (in_array($e->getCode(), [3101, 1180])) {
							$worker_db->update($prefix.'_masters')
								->cols($cols)
								->where($prefix.'_id='.$server[$prefix.'_id'])
								->bindValues($values)
								->query();
						}
					}
					$memcache->set($module.'_masters'.$queue['ip'], $server, 3600);
				break;
			default:
				Worker::safeEcho('Dont know how to handel this Queued Entry: '.json_encode($queue, true).PHP_EOL);
				break;
		}
	}
	if (count($return) > 0) {
        error_log(__FILE__.' return: '.json_encode($return));
		$loopCount = 0;
		do {
			$response = $memcache->get('queueout', function($memcache, $key, &$value) { $value = []; return true; }, \Memcached::GET_EXTENDED);
			$queue = $response['value'];
			$cas = $response['cas'];
			if (count($queue) > 0) {
				foreach ($return as $row)
					$queue[] = $row;
			}
			$loopCount++;
			if ($loopCount > 100) {
				$global->queuein = 0;
				Worker::SafeEcho('Hit 100 Attempts at CAS updating the queuein to 0 after '.(time() - $memcached_start).' seconds'.PHP_EOL);
				return;
			}
		} while (!$memcache->cas($response['cas'], 'queueout', $queue));
	}
	$global->queuein = 0;
	Worker::safeEcho('memcached_queue_task finished processing '.count($processQueue).' queues after '.(time() - $memcached_start).' seconds'.PHP_EOL);
	return;
}
