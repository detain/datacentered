<?php

function bandwidth($args) {
	$client = new \InfluxDB\Client('68.168.221.7', 8086, 'myadmin', 'MYp4ssw0rd');
	$database = $client->selectDB('myadmin');
	/*
	$dir = __DIR__.'/../../../logs/rrd/'.$args['name'];
	if (!file_exists($dir))
		@mkdir($dir, 0777, TRUE);
	*/
	$points = [];
	$time = time();
	foreach ($args['content'] as $ip => $data) {
		$points[] = new \InfluxDB\Point('bandwidth_in', $data['in'], ['ip' => $ip],[],$time);
		$points[] = new \InfluxDB\Point('bandwidth_out', $data['out'], ['ip' => $ip],[],$time);
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
	// Points will require second precision
	$newPoints = $database->writePoints($points, \InfluxDB\Database::PRECISION_SECONDS);
	return true;
}
