<?php

function bandwidth($args) {
	if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1)
		ini_set('default_socket_timeout', 1200);
	$dir = __DIR__.'/../../../../logs/rrd/'.$args['name'];
	if (!file_exists($dir))
		@mkdir($dir, 0777, TRUE);
	foreach ($args['content'] as $ip => $data) {
		if (!file_exists($dir.'/'.$ip.'.rrd')) {
			$rrd = new \RRDCreator($dir.'/'.$ip.'.rrd', 'now', 60);
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
		$updater = new \RRDUpdater($dir.'/'.$ip.'.rrd');
		$updater->update(['in' => $data['in'],'out' => $data['out']]);
	}
}