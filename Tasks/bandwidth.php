<?php

function bandwidth($args) {
	global $worker_db, $influx_database;
	$points = [];
	$veids = [];
	foreach ($args['content'] as $ip => $data) {
		if (!isset($data['vps']))
			echo "Missing VPS for ip {$ip}\n";
		if (!isset($veids[$data['vps']]))
			$veids[$data['vps']] = $worker_db->select('*')->from('vps')->where('vps_server=:vps_server and (vps_hostname=:hostname or vps_vzid=:vzid)')->bindValues(['vps_server'=>$args['uid'],'hostname'=>$data['vps'],'vzid'=>$data['vps']])->row();
		$row = $veids[$data['vps']];
		if ($row !== FALSE)
			$points[] = new \InfluxDB\Point('bandwidth', NULL, [
				'vps' => (int)$row['vps_id'],
				'host' => (int)$args['uid'],
				'ip' => $ip
			], [
				'in' => (int)$data['in'],
				'out' => (int)$data['out']
			]);
	}
	$newPoints = $influx_database->writePoints($points, \InfluxDB\Database::PRECISION_SECONDS);
	return true;
}