<?php
require_once __DIR__.'/SystemStats.php';
require_once __DIR__.'/StorageStats.php';
require_once __DIR__.'/NetworkStats.php';

$json['loadavg'] = SystemStats::loadavg();
$json['cpuinfo'] = SystemStats::cpuinfo();
$json['ramuse'] = SystemStats::ramuse();
$json['uptime'] = SystemStats::prober_uptime();
$json['network'] = NetworkStats::network();
$json['network']['v4'] = NetworkStats::Getipv4();
$json['network']['v6'] = NetworkStats::Checkipv6();
$json['hddusage'] = StorageStats::hddusage();

echo json_encode($json);

