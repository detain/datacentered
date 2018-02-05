<?php
$rrdFile = dirname(__FILE__) . '/speed.rrd';
$outputPngFile = dirname(__FILE__) . '/speed.png';

$rrd = new RRDCreator($rrdFile, 'now', 60);
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

$updater = new RRDUpdater($rrdFile);
$updater->update(['in' => '12345','out' => '54321'], '920804700');
$updater->update(['in' => '11098','out' => '56789'], '920805000');

$graphObj = new RRDGraph($outputPngFile);
$graphObj->setOptions([
	'--start' => '920804400',
	'--end' => 920808000,
	'--vertical-label' => 'm/s',
	'DEF:myspeed=$rrdFile:speed:AVERAGE',
	'CDEF:realspeed=myspeed,1000,*',
	'LINE2:realspeed#FF0000'
	'--title' => 'Banwidth Over the Past 24 Hours',
	'--width' => '400',
	'--height' => '100',
	'--start' => 'end-1d',
	'DEF:avg_in=db.rrd:in:AVERAGE',
	'DEF:min_in=db.rrd:in:MIN',
	'DEF:max_in=db.rrd:in:MAX',
	'DEF:avg_out=db.rrd:out:AVERAGE',
	'DEF:min_out=db.rrd:out:MIN',
	'DEF:max_out=db.rrd:out:MAX',
	'AREA:avg_in#3399FF',
	'LINE1:min_in#CC00FF',
	'LINE1:max_in#000099',
	'AREA:avg_out#FF9900',
	'LINE1:min_out#FFCCCC',
	'LINE1:max_out#FFFF99'
]);
$graphObj->save();
?>

