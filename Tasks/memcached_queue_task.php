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
	//Worker::safeEcho('Task handler Started memcached_queue_task'.PHP_EOL);
	if (!isset($global->queuein)) {
		$global->queuein = 0;
	}
	if (!$global->cas('queuein', 0, 1)) {
		Worker::safeEcho('Cannot Get queuein Lock, Returning after '.(time() - $memcached_start).' seconds'.PHP_EOL);
		return;
	}
	$loopCount = 0;
	do {
		$response = $memcache->get('queuein', function($memcache, $key, &$value) { $value = []; return true; }, \Memcached::GET_EXTENDED);
		if ($response === false) {
			$memcache->set('queuein', []);
			$response = $memcache->get('queuein', function($memcache, $key, &$value) { $value = []; return true; }, \Memcached::GET_EXTENDED);
		}
		$queue = $response['value'];
		$cas = $response['cas'];
		if (count($queue) == 0) {
			$global->queuein = 0;
			//Worker::safeEcho('Empty Queue, Returning after '.(time() - $memcached_start).' seconds'.PHP_EOL);
			return;
		}
		$processQueue = $queue;
		$queue = [];
		$loopCount++;
		if ($loopCount > 100) {
			$global->queuein = 0;
			Worker::SafeEcho('Hit 100 Attempts at CAS updating the queuein after '.(time() - $memcached_start).' seconds'.PHP_EOL);
			return;
		}
	} while (!$memcache->cas($response['cas'], 'queuein', $queue));
	if (count($processQueue) == 0) {
		$global->queuein = 0;
		//Worker::safeEcho('Empty Queue, Returning after '.(time() - $memcached_start).' seconds'.PHP_EOL);
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
		switch ($queue['post']['action']) {
			case 'cpu_usage':
				$cpu_usage = json_decode($queue['post']['cpu_usage'], true);
				//$queue['post']['cpu_usage'] = strlen($queue['post']['cpu_usage']).' byte string';
				//Worker::safeEcho('Queue #'.$idx.': '.json_encode($queue).PHP_EOL);
				if (!is_array($cpu_usage)) {
					break;
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
					$worker_db->update($prefix.'_master_details')
						->cols([$prefix.'_cpu_avg', $prefix.'_cpu_usage'])
						->where($prefix.'_id='.$server[$prefix.'_id'])
						->bindValues([$prefix.'_cpu_avg' => $cpu_avg, $prefix.'_cpu_usage' => $serialized_server_usage])
						->query();
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
							$points[] = new \InfluxDB\Point($prefix.'_stats', null, [
								'vps' => (int)$vps,
								'host' => (int)$server[$prefix.'_id'],
							], $influxValues);
						} else {
							$row = $worker_db->select($prefix.'_id,'.$prefix.'_vzid')
								->from($table)
								->where($prefix.'_server = :server and '.$prefix.'_vzid = :veid')
								->bindValues(['server' => $server[$prefix.'_id'], 'veid' => $veid])
								->row();
							if ($row !== false) {
								$serverVps[$veid] = $row[$prefix.'_id'];
								$memcache->set($module.'_vps'.$server[$prefix.'_id'], $serverVps, 3600);
								$points[] = new \InfluxDB\Point($prefix.'_stats', null, [
									'vps' => (int)$row[$prefix.'_id'],
									'host' => (int)$server[$prefix.'_id'],
								], $influxValues);
							}
							
						}
						
					}					
					try {
						$newPoints = $influx_database->writePoints($points, \InfluxDB\Database::PRECISION_SECONDS);
					} catch (\InfluxDB\Exception $e) {
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
				//Worker::safeEcho(print_r($bandwidth, true).PHP_EOL);
				//Worker::safeEcho(print_r($servers, true).PHP_EOL);
				if (is_array($bandwidth)) {
					$serverVps = $memcache->get($module.'_vps'.$server[$prefix.'_id']);
					if ($serverVps === false) {
						$serverVps = [];
					}
					foreach ($bandwidth as $ip => $data) {
						$iplong = sprintf('%u', ip2long($ip));
						$veid = $servers[$ip];
						$idFromVeid = preg_replace('/[A-Za-z\._\-]*/m', '', $servers[$ip]);
						if (array_key_exists($veid, $serverVps)) {
							$vps = $serverVps[$veid];
							$points[] = new \InfluxDB\Point($influx_table, null, [
								'vps' => (int)$vps,
								'host' => (int)$server[$prefix.'_id'],
								'ip' => $ip
							], [
								'in' => (int)$data['in'],
								'out' => (int)$data['out']
							]);
						} else {
							$row = $worker_db->select($prefix.'_id')
								->from($table)
								->where($prefix.'_server = :server and ('.$prefix.'_hostname = :hostname or '.$prefix.'_vzid = :veid or '.$prefix.'_vzid = :idFromVeid)')
								->bindValues(['server' => $server[$prefix.'_id'], 'hostname' => $veid, 'veid' => $veid, 'idFromVeid' => $idFromVeid])
								->row();
							if ($row !== false) {
								$serverVps[$veid] = $row[$prefix.'_id'];
								$memcache->set($module.'_vps'.$server[$prefix.'_id'], $serverVps, 3600);
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
					}
					try {
						$newPoints = $influx_database->writePoints($points, \InfluxDB\Database::PRECISION_SECONDS);
					} catch (\InfluxDB\Exception $e) {
						Worker::safeEcho('InfluxDB got Exception '.$e->getMessage(). ' while writing bandwidth points to DB'.PHP_EOL);
					}
				}
				break;
			case 'server_info':
				$servers = $queue['post']['servers'];
				$servers = base64_decode($servers);
				$servers = json_decode($servers, true);
				Worker::safeEcho('server_info got servers: '.var_export($servers,true).PHP_EOL);
				$fields = ['load', 'hdfree', 'iowait', 'hdsize', 'bits', 'ram', 'cpu_model', 'cpu_mhz', 'kernel', 'raid_building', 'cores', 'raid_status', 'mounts', 'drive_type'];
				if ($module == 'quickservers' && isset($servers['ram']))
					$servers['ram'] = floor($servers['ram'] * 0.90);
				$detailfields = ['ioping'];
				$skipfields = ['load', 'hdfree', 'iowait', 'cpu_mhz'];
				foreach ($skipfields as $field) {
					$key = $module.'|host|'.$server[$prefix.'_id'].'|'.$field;
					if (isset($server[$field]))
						$memcache->set($key, $servers[$field]);
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
					}
				}
				if (count($cols) > 0)
					$worker_db->update($prefix.'_masters')
						->cols($cols)
						->where($prefix.'_id='.$server[$prefix.'_id'])
						->bindValues($values)
						->query();
				break;
			case 'get_maps':
				$maps = [
					'slice' => '',
					'ip' => '',
					'vnc' => '',
					'main' => ''
				];
				$map = '';
				$rows = $worker_db->select('*')
					->from($table)
					->where($prefix.'_server= :server and '.$prefix.'_status= :status')
					->bindValues(['server' => $server[$prefix.'_id'], 'status' => 'active'])
					->query();
				foreach ($rows as $row) {
					$maps['slice'] .= $row[$prefix.'_vzid'].':'.$row[$prefix.'_slices'].PHP_EOL;
					$maps['vnc'] .= $row[$prefix.'_vzid'].':'.$row[$prefix.'_vnc'].PHP_EOL;
					$maps['main'] .= $row[$prefix.'_vzid'].':'.$row[$prefix.'_ip'].PHP_EOL;
					$repeatInvoices = $worker_db->select('*')
						->from('repeat_invoices')
						->where('repeat_invoices_module= :module and repeat_invoices_service= :service and repeat_invoices_description like :like and repeat_invoices_deleted=0')
						->bindValues(['module' => $module, 'service' => $row[$prefix.'_id'], 'like' => 'Additional IP % for '.$tblname.' '.$row[$prefix.'_id']])
						->query();
					foreach ($repeatInvoices as $repeatInvoice) {
						if (preg_match('/^Additional IP (.*) for '.$tblname.' '.$row[$prefix.'_id'].'$/', $repeatInvoice['repeat_invoices_description'], $matches)) {
							$ip = $matches[1];
							$maps['ip'] .= $row[$prefix.'_ip'].':'.$ip.PHP_EOL;
							$ipRow = $worker_db->select('*')
								->from($prefix.'_ips')
								->where('ips_ip = :ip')
								->bindValue('ip', $ip)
								->row();
							$uptext = [];
							$update_ips = false;
							if ($ipRow['ips_used'] != 1) {
								$uptext[] = 'Changing Used to 1';
							}
							if ($ipRow['ips_main'] != 0) {
								$uptext[] = 'Changing Main to 0';
							}
							if ($ipRow['ips_'.$prefix] != $row[$prefix.'_id']) {
								$uptext[] = "Changing {$tblname} {$ipRow['ips_'.$prefix]} to {$row[$prefix.'_id']}";
							}
							if (count($uptext) > 0) {
								myadmin_log($this->serviceQueueHandler->module, 'info', 'Updating vps_ips '.$ip.' '.implode(', ', $uptext), __LINE__, __FILE__, $this->serviceQueueHandler->module);
								$db3->query("update {$prefix}_ips set ips_used=1,ips_main=0,ips_{$prefix}={$row[$prefix.'_id']} where ips_ip='{$ip}'", __LINE__, __FILE__);
							}
						}
						
					}
				}
				$output = "echo '{$map}' > /root/cpaneldirect/vps.slicemap;";
				break;
				/*
			case 'ip_map':
				$output .= 'oldm="$(md5sum /root/cpaneldirect/vps.ipmap)";';
				$output .= "echo '{$ipmap}' > /root/cpaneldirect/vps.ipmap;";
				$output .= 'newm="$(md5sum /root/cpaneldirect/vps.ipmap)";';
				$output .= 'if [ "$newm" != "$oldm" ]; then bash /root/cpaneldirect/run_buildebtables.sh; fi;';
				break;
			case 'vnc_map':
				$output = "echo '{$map}' > /root/cpaneldirect/vps.vncmap;
if [ \"\$(which virsh)\" != \"\" ]; then
	for vps in \$(virsh list | grep -v -e \"State\$\" -e \"------\$\" -e \"^\$\" | awk \"{ print \\\$2 }\"); do
		ip=\"\$(grep \"\$vps:\" /root/cpaneldirect/vps.vncmap | cut -d: -f2)\";
		if [ \"\$ip\" = \"\" ]; then
		ip=\"66.45.228.100\";
		fi;
		if [ ! -e /etc/xinetd.d/\$vps ]; then
		sh /root/cpaneldirect/vps_kvm_setup_vnc.sh \$vps \$ip;
		fi;
	done;
fi;
";
				break;
			case 'main_ips':
				$output = "echo '{$map}' > /root/cpaneldirect/vps.mainips;";
				break;
				*/			
			default:
				Worker::safeEcho('Dont know how to handel this Queued Entry: '.json_encode($queue, true).PHP_EOL);
				break;
		}
	}
	if (count($return) > 0) {
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
