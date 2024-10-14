<?php

use Workerman\Worker;

function map_queue_task($args)
{
    /**
    * @var \Workerman\MySQL\Connection
    */
    global $worker_db;
    /**
    * @var \Memcached
    */
    global $memcache, $redis;
    $hosts = 0;
    //$memcached_start = time();
    foreach (['vps', 'quickservers'] as $module) {
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
        $servers = $worker_db->select('*')
            ->from($prefix.'_masters')
            ->where($prefix.'_type != 11')
            ->query();
        foreach ($servers as $server) {
            $maps = [
                //'slice' => '',
                'ip' => '',
                'vnc' => '',
                //'main' => ''
            ];
            if ($module == 'vps') {
                $maps['slice'] = '';
            }
            $rows = $worker_db->select('*')
                ->from($table)
                ->where($prefix.'_server= :server and '.$prefix.'_status= :status')
                ->bindValues(['server' => $server[$prefix.'_id'], 'status' => 'active'])
                ->query();
            foreach ($rows as $row) {
                if ($module == 'vps') {
                    $maps['slice'] .= $row[$prefix.'_vzid'].':'.$row[$prefix.'_slices'].PHP_EOL;
                }
                $maps['vnc'] .= $row[$prefix.'_vzid'].':'.$row[$prefix.'_vnc'].PHP_EOL;
                //$maps['main'] .= $row[$prefix.'_vzid'].':'.$row[$prefix.'_ip'].PHP_EOL;
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
                        if ($ipRow === false) {
                            Worker::safeEcho('Cannot find IP '.$ip.' On Server #'.$server[$prefix.'_id'].' '.$server[$prefix.'_name'].' In '.$prefix.' IPs Table'.PHP_EOL);
                        } else {
                            $uptext = [];
                            $update_ips = false;
                            if ($ipRow['ips_used'] != 1) {
                                $uptext[] = 'Changing Used to 1';
                            }
                            if ($ipRow['ips_main'] != 0 && $ip != $row[$prefix.'_ip']) {
                                $uptext[] = 'Changing Main to 0';
                            }
                            if ($ipRow['ips_'.$prefix] != $row[$prefix.'_id']) {
                                $uptext[] = "Changing {$tblname} {$ipRow['ips_'.$prefix]} to {$row[$prefix.'_id']}";
                            }
                            if (count($uptext) > 0) {
                                Worker::safeEcho('Updating '.$prefix.'_ips '.$ip.' '.implode(', ', $uptext).PHP_EOL);
                                $worker_db->update($prefix.'_ips')
                                    ->cols(['ips_used', 'ips_main', 'ips_'.$prefix])
                                    ->where("ips_ip='{$ip}'")
                                    ->bindValues(['ips_used' => 1, 'ips_main' => 0, 'ips_'.$prefix => $row[$prefix.'_id']])
                                    ->query();
                                error_log('SQL Query '.__LINE__.' '.__FILE__.' '."update {$prefix}_ips set ips_used=1, ips_main=0, ips_{$prefix}={$row[$prefix.'_id']} where ips_ip='{$ip}'");
                            }
                        }
                    }
                }
            }
            if (USE_REDIS === true) {
                try {
                    $redis->set('maps:'.$server[$prefix.'_ip'], json_encode($maps));
                } catch (\Exception $e) {
                    Worker::safeEcho('Caught Exception #'.$e->getCode().':'.$e->getMessage().' on '.__LINE__.'@'.__FILE__);
                }
            } else {
                $memcache->set('maps'.$server[$prefix.'_ip'], $maps);
            }
            $hosts++;
        }
    }
    //Worker::safeEcho('map_queue_task finished processing '.$hosts.' after '.(time() - $memcached_start).' seconds'.PHP_EOL);
    return;
}
