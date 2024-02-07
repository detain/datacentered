<?php

include_once __DIR__.'/../../vps_hosts/workerman/SystemStats.php';
include_once __DIR__.'/../../vps_hosts/workerman/NetworkStats.php';
include_once __DIR__.'/../../vps_hosts/workerman/StorageStats.php';

if (method_exists('Workerman\\Protocols\\Http', 'header')) {
    \Workerman\Protocols\Http::header('Content-type: application/json');
} else {
    header('Content-type: application/json');
}
$stats = [];
if ($_GET['q'] == 'all') {
    foreach (['System','Network','Storage'] as $base) {
        $class_methods = get_class_methods($base.'Stats');
        foreach ($class_methods as $method_name) {
            if ($method_name != 'curl') {
                $stats[$method_name] = call_user_func($base.'Stats::'.$method_name);
            }
        }
    }
} elseif ($_GET['q'] == 'cpu') {
    $stats['cpu_load_perc_free'] = SystemStats::cpu_load_perc_free();
    $stats['cpu_load_perc_used'] = SystemStats::cpu_load_perc_used();
} elseif ($_GET['q'] == 'network') {
    $stats['dl_speed'] = NetworkStats::dl_speed();
    $stats['ul_speed'] = NetworkStats::ul_speed();
    $stats['total_downloaded'] = NetworkStats::total_downloaded();
    $stats['total_uploaded'] = NetworkStats::total_uploaded();
}
print json_encode($stats);
