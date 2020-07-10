<?php

use Workerman\Worker;

global $memcache;
if (isset($_POST['action']) && $_POST['action'] == 'map') {
	$map = $memcache->get('maps'.$_SERVER['REMOTE_ADDR']);
	if (is_array($map)) {
		if (array_key_exists('slice', $map)) {
			echo "echo '{$map['slice']}' > /root/cpaneldirect/vps.slicemap;".PHP_EOL;
		}
		if (array_key_exists('ip', $map)) {
			echo 'oldm="$(md5sum /root/cpaneldirect/vps.ipmap)";'.PHP_EOL;
			echo "echo '{$map['ip']}' > /root/cpaneldirect/vps.ipmap;".PHP_EOL;
			echo 'newm="$(md5sum /root/cpaneldirect/vps.ipmap)";'.PHP_EOL;
			echo 'if [ $(which virsh) != "" ] && [ "$newm" != "$oldm" ]; then bash /root/cpaneldirect/run_buildebtables.sh; fi;'.PHP_EOL;
		}
		if (array_key_exists('vnc', $map)) {
			echo "echo '{$map['vnc']}' > /root/cpaneldirect/vps.vncmap;".PHP_EOL;
			echo 'if [ "$(which virsh)" != "" ]; then
	for vps in $(virsh list | grep -v -e "State$" -e "------$" -e "^$" | awk \'{ print $2 }\'); do
		ip="$(grep "$vps:" /root/cpaneldirect/vps.vncmap | cut -d: -f2)";
		if [ "$ip" = "" ]; then
			ip="66.45.228.100";
		fi;
		if [ ! -e /etc/xinetd.d/$vps ]; then
			sh /root/cpaneldirect/vps_kvm_setup_vnc.sh $vps $ip;
		fi;
	done;
fi;
';
		}
		if (array_key_exists('main', $map)) {
			echo "echo '{$map['main']}' > /root/cpaneldirect/vps.mainips;".PHP_EOL;
		}
	}	
} elseif (isset($_POST['action']) && $_POST['action'] == 'queue') {
	$queueArray = $memcache->get('queue');
	if (is_array($queueArray)) {
		/*if (array_key_exists($_SERVER['REMOTE_ADDR'], $queueArray['new']) && count($queueArray['new'][$_SERVER['REMOTE_ADDR']]) > 0) {
			echo implode(PHP_EOL, $queueArray['new'][$_SERVER['REMOTE_ADDR']]).PHP_EOL;			
		}*/
		if (array_key_exists($_SERVER['REMOTE_ADDR'], $queueArray['queue']) && count($queueArray['queue'][$_SERVER['REMOTE_ADDR']]) > 0) {
			echo implode(PHP_EOL, $queueArray['queue'][$_SERVER['REMOTE_ADDR']]).PHP_EOL;
			$loopCount = 0;
			do {
				$response = $memcache->get('queue', function($memcache, $key, &$value) { $value = []; return true; }, \Memcached::GET_EXTENDED);
				$queue = $response['value'];
				$cas = $response['cas'];
				$queue['queue'][$_SERVER['REMOTE_ADDR']] = [];
				$loopCount++;
				if ($loopCount > 100) {
					Worker::safeEcho('Max Loops Reached Trying to Get queue CAS set '.PHP_EOL);
					break;
				}
			} while (!$memcache->cas($response['cas'], 'queue', $queue));
		}
	}	
} else {
	$item = ['get' => $_GET, 'post' => $_POST, 'ip' => $_SERVER['REMOTE_ADDR']];
	$loopCount = 0;
	$output = '';
	do {
		$response = $memcache->get('queuein', function($memcache, $key, &$value) { $value = []; return true; }, \Memcached::GET_EXTENDED);
		$queue = $response['value'];
		$cas = $response['cas'];
		// modify queue
		if (!is_array($queue)) {
			//Worker::safeEcho('Queue isnt an array its '.var_export($queue,true).' forcing it to an array'.PHP_EOL);
			$queue = [];
		}
		$queue[] = $item;
		$loopCount++;
		if ($loopCount > 100) {
			Worker::safeEcho('Max Loops Reached Trying to Get queuein CAS set '.PHP_EOL);
			break;
		}
	} while (!$memcache->cas($response['cas'], 'queuein', $queue));
	//Worker::safeEcho('CAS set queuein to  '.json_encode($queue).PHP_EOL); 
}
//\Workerman\Protocols\Http::end($output);