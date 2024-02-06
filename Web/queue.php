<?php

use Workerman\Worker;

global $memcache, $redis;
if (isset($_POST['action']) && $_POST['action'] == 'map') {
    try {
        if (USE_REDIS === true) {
            $map = json_decode($redis->get('maps|'.$_SERVER['REMOTE_ADDR']), true);
        } else {
            $map = $memcache->get('maps'.$_SERVER['REMOTE_ADDR']);
        }
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
			    /root/cpaneldirect/provirted.phar vnc setup $vps $ip;
		    fi;
	    done;
    fi;
    ';
            }
            if (array_key_exists('main', $map)) {
                echo "echo '{$map['main']}' > /root/cpaneldirect/vps.mainips;".PHP_EOL;
            }
        }
    } catch (\Exception $e) {
        Worker::safeEcho('Caught Exception #'.$e->getCode().':'.$e->getMessage().' on '.__LINE__.'@'.__FILE__);
    }
} elseif (isset($_POST['action']) && $_POST['action'] == 'get_queue') {
    global $mysql_db;
    $ip = $_SERVER['REMOTE_ADDR'];
    if (validIp($ip)) {
        if (false !== $vpsMaster = $mysql_db->select('*')->from('vps_masters')->leftJoin('vps_master_details', 'vps_masters.vps_id=vps_master_details.vps_id')->where('vps_ip = :ip')->bindValues(['ip' => $_SERVER['REMOTE_ADDR']])->row()) {
            if (false !== $results = $mysql_db->select('*')->from('queue_log')->leftJoin('vps', 'vps_id=history_type')->where('history_section="vpsqueue" and vps_server=:id')->bindValues(['id' => $vpsMaster['vps_id']])->query()) {
                function_requirements('vps_queue_handler');
                foreach ($results as $result) {
                    echo vps_queue_handler($vpsMaster, 'get_queue', $result);
                }
            }
        } else {
            echo "Bad IP {$ip}";
        }
    } else {
        echo "Bad IP {$ip}";
    }
} elseif (isset($_POST['action']) && $_POST['action'] == 'get_new_vps') {
    global $mysql_db;
    $ip = $_SERVER['REMOTE_ADDR'];
    if (validIp($ip)) {
        if (false !== $vpsMaster = $mysql_db->select('*')->from('vps_masters')->leftJoin('vps_master_details', 'vps_masters.vps_id=vps_master_details.vps_id')->where('vps_ip = :ip')->bindValues(['ip' => $_SERVER['REMOTE_ADDR']])->row()) {
            function_requirements('vps_queue_handler');
            echo vps_queue_handler($vpsMaster, 'get_new_vps');
        } else {
            echo "Bad IP {$ip}";
        }
    } else {
        echo "Bad IP {$ip}";
    }
} elseif (isset($_POST['action']) && $_POST['action'] == 'get_qs_queue') {
    global $mysql_db;
    $ip = $_SERVER['REMOTE_ADDR'];
    if (validIp($ip)) {
        if (false !== $qsMaster = $mysql_db->select('*')->from('qs_masters')->leftJoin('qs_master_details', 'qs_masters.qs_id=qs_master_details.qs_id')->where('qs_ip = :ip')->bindValues(['ip' => $_SERVER['REMOTE_ADDR']])->row()) {
            if (false !== $results = $mysql_db->select('*')->from('queue_log')->leftJoin('quickservers', 'qs_id=history_type')->where('history_section="quickserversqueue" and qs_server=:id')->bindValues(['id' => $qsMaster['qs_id']])->query()) {
                function_requirements('qs_queue_handler');
                foreach ($results as $result) {
                    echo qs_queue_handler($qsMaster, 'get_queue', $result);
                }
            }
        } else {
            echo "Bad IP {$ip}";
        }
    } else {
        echo "Bad IP {$ip}";
    }
} elseif (isset($_POST['action']) && $_POST['action'] == 'get_new_qs') {
    global $mysql_db;
    $ip = $_SERVER['REMOTE_ADDR'];
    if (validIp($ip)) {
        if (false !== $qsMaster = $mysql_db->select('*')->from('qs_masters')->leftJoin('qs_master_details', 'qs_masters.qs_id=qs_master_details.qs_id')->where('qs_ip = :ip')->bindValues(['ip' => $_SERVER['REMOTE_ADDR']])->row()) {
            function_requirements('qs_queue_handler');
            echo qs_queue_handler($qsMaster, 'get_new_qs');
        } else {
            echo "Bad IP {$ip}";
        }
    } else {
        echo "Bad IP {$ip}";
    }
} elseif (isset($_POST['action']) && $_POST['action'] == 'queue') {
    try {
        if (USE_REDIS === true) {
            while (false !== $queue = $redis->lPop('queue|'.$_SERVER['REMOTE_ADDR'])) {
                echo $queue.PHP_EOL;
            }
        } else {
            $queueArray = $memcache->get('queue');
            if (is_array($queueArray)) {
                /*if (array_key_exists($_SERVER['REMOTE_ADDR'], $queueArray['new']) && count($queueArray['new'][$_SERVER['REMOTE_ADDR']]) > 0) {
                    echo implode(PHP_EOL, $queueArray['new'][$_SERVER['REMOTE_ADDR']]).PHP_EOL;
                }*/
                if (array_key_exists($_SERVER['REMOTE_ADDR'], $queueArray['queue']) && count($queueArray['queue'][$_SERVER['REMOTE_ADDR']]) > 0) {
                    echo implode(PHP_EOL, $queueArray['queue'][$_SERVER['REMOTE_ADDR']]).PHP_EOL;
                    $loopCount = 0;
                    do {
                        $response = $memcache->get('queue', function ($memcache, $key, &$value) {
                            $value = [];
                            return true;
                        }, \Memcached::GET_EXTENDED);
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
        }
    } catch (\Exception $e) {
        Worker::safeEcho('Caught Exception #'.$e->getCode().':'.$e->getMessage().' on '.__LINE__.'@'.__FILE__);
    }
} else {
    $item = ['get' => $_GET, 'post' => $_POST, 'ip' => $_SERVER['REMOTE_ADDR']];
    $output = '';
    try {
        if (USE_REDIS === true) {
            $redis->rPush('queuein|'.$_SERVER['REMOTE_ADDR'], json_encode($item));
        } else {
            $queuein = 'queuein'.$_SERVER['REMOTE_ADDR'];
            $loopCount = 0;
            /*
            $response = $memcache->get($queuein, function($memcache, $key, &$value) { $value = []; return true; }, \Memcached::GET_EXTENDED);
            if ($response === false) {
                $memcache->set($queuein, []);
                $response = $memcache->get($queuein, function($memcache, $key, &$value) { $value = []; return true; }, \Memcached::GET_EXTENDED);
            }
            $queue = $response['value'];
            */
            $queue = $memcache->get($queuein);
            $queue[] = $item;
            $memcache->set($queuein, $queue);
        }
    } catch (\Exception $e) {
        Worker::safeEcho('Caught Exception #'.$e->getCode().':'.$e->getMessage().' on '.__LINE__.'@'.__FILE__);
    }
}
//\Workerman\Protocols\Http::end($output);
