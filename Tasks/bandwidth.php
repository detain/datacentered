<?php

function bandwidth($args) {
	/*
	$dir = __DIR__.'/../../../logs/rrd/'.$args['name'];
	if (!file_exists($dir))
		@mkdir($dir, 0777, TRUE);
	*/
	$points = [];
	foreach ($args['content'] as $ip => $data) {
		if (!isset($data['vps'])) {
			echo "Missing VPS for ip $ip\n";
		}
		$points[] = new \InfluxDB\Point('bandwidth', $data['in'], ['type' => 'in', 'vps' => $data['vps'], 'ip' => $ip]);
		$points[] = new \InfluxDB\Point('bandwidth', $data['out'], ['type' => 'out', 'vps' => $data['vps'], 'ip' => $ip]);
		/*
		if (!file_exists($dir.'/'.$ip.'.rrd')) {
			$rrd = new RRDCreator($dir.'/'.$ip.'.rrd', 'now', 60);
			$rrd->addDataSource('in:ABSOLUTE:60:U:U');
			$rrd->addDataSource('out:ABSOLUTE:60:U:U');
			$rrd->addArchive('AVERAGE:0.5:1:10080');
			$rrd->addArchive('MIN:0.5:1:10080');
			$rrd->addArchive('MAX:0.5:1:10080');
			$rrd->addArchive('AVERAGE:0.5:60:8760');
			$rrd->addArchive('MIN:0.5:60:8760');
			$rrd->addArchive('MAX:0.5:60:8760');
			$rrd->addArchive('AVERAGE:0.5:1440:3650');
			$rrd->addArchive('MIN:0.5:1440:3650');
			$rrd->addArchive('MAX:0.5:1440:3650');
			$rrd->save();
		}
		$updater = new RRDUpdater($dir.'/'.$ip.'.rrd');
		$updater->update(['in' => $data['in'],'out' => $data['out']]);
		//echo 'Updated '.$dir.'/'.$ip.'.rrd File'.PHP_EOL;
		*/
	}
	$client = new \InfluxDB\Client('68.168.221.7', 8086, 'myadmin', 'MYp4ssw0rd');
	$database = $client->selectDB('myadmin');
	$newPoints = $database->writePoints($points, \InfluxDB\Database::PRECISION_SECONDS);
	return true;
}
