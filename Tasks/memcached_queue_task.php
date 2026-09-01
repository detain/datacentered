<?php

use Workerman\Worker;

require_once __DIR__.'/../Applications/Chat/SharedState.php';

/**
 * Put an un-drained tail of a queue batch back where it came from.
 *
 * The drain reads with a destructive lPop batch, so abandoning a partly-processed
 * batch (lost drain lock, transport blip) would otherwise throw the remaining
 * items away. Items are restored to the HEAD in reverse order so the queue's
 * original FIFO order survives, and the next tick picks them up.
 *
 * The raw `queuein:<ip>` LIST is deliberately NOT routed through SharedState:
 * per the project contract the queue DATA lives OUTSIDE the `dc:*` namespace
 * (only the same-named drain LOCK goes through the facade), and prefixing it
 * would orphan every entry the VPS hosts are writing to.
 *
 * @param array       $batchItems the full batch as popped
 * @param int         $fromIndex  first index NOT yet processed
 * @param string      $hostIp     queue owner host ip
 * @param \Redis|null $redis      raw redis handle (USE_REDIS path)
 * @param mixed       $memcache   memcached handle (fallback path)
 * @return void
 */
function memcached_queue_requeue_tail(array $batchItems, int $fromIndex, string $hostIp, $redis, $memcache): void
{
    $tail = array_slice($batchItems, $fromIndex);
    if ($tail === []) {
        return;
    }
    $queueKey = 'queuein:'.$hostIp;
    try {
        if (USE_REDIS === true && $redis instanceof \Redis) {
            // lPush pushes onto the head, so walk the tail backwards to leave
            // $tail[0] at the head — i.e. exactly where lPop found it.
            foreach (array_reverse($tail) as $item) {
                $encoded = json_encode($item);
                if ($encoded === false) {
                    Worker::safeEcho('Could not re-encode a queue item for host '.$hostIp.' while requeueing — dropping that one item'.PHP_EOL);
                    continue;
                }
                $redis->lPush($queueKey, $encoded);
            }
        } elseif (is_object($memcache)) {
            $current = $memcache->get($queueKey);
            if (!is_array($current)) {
                $current = [];
            }
            $memcache->set($queueKey, array_merge($tail, $current));
        } else {
            Worker::safeEcho('No queue backend available to requeue '.count($tail).' items for host '.$hostIp.PHP_EOL);

            return;
        }
        Worker::safeEcho('Requeued '.count($tail).' un-drained items for host '.$hostIp.PHP_EOL);
    } catch (\Throwable $e) {
        // Best effort: a failed requeue is the same data loss we are trying to
        // avoid, so make it LOUD rather than silent. Never rethrow — this runs
        // inside a TaskWorker function.
        Worker::safeEcho('FAILED to requeue '.count($tail).' items for host '.$hostIp.': '.$e->getMessage().PHP_EOL);
    }
}

function memcached_queue_task($args)
{
    //require_once '/home/my/include/functions.inc.php';
    /**
    * @var \Workerman\MySQL\Connection
    */
    global $worker_db;
    /**
    * @var \InfluxDB\Client
    */
    global $influx_v2_client;
    global $influx_v2_database;
    /**
    * @var \Redis
    */
    global $redis;
    if (USE_REDIS !== true) {
        /**
        * @var \Memcached
        */
        global $memcache;
    }
    $start = time();
    $maxTries = 5;
    //Worker::safeEcho('Task handler Started memcached_queue_task'.PHP_EOL);

    // Retry helper: runs $fn, retries on InnoDB cluster rollbacks with exponential backoff.
    // Only reconnects $worker_db on genuine connection-loss errors (2006, 2013).
    $dbRetry = function (callable $fn) use ($maxTries, &$worker_db): bool {
        $clusterErrors = [40000, 3101, 1180]; // GR certification failure — connection is fine, just retry
        $connErrors    = [2006, 2013];         // gone-away / lost connection — reconnect needed
        for ($try = 1; $try <= $maxTries; $try++) {
            try {
                $fn();
                return true;
            } catch (\PDOException $e) {
                Worker::safeEcho('['.$try.'/'.$maxTries.'] Got PDO Exception #'.$e->getCode().': "'.$e->getMessage()."\"\n");
                if (in_array($e->getCode(), $clusterErrors)) {
                    // Exponential backoff with jitter: 50–150 ms, 100–300 ms, 200–600 ms …
                    usleep((int)(50000 * (2 ** ($try - 1)) * (0.75 + (new \Random\Randomizer())->getFloat(0.0, 1.0) * 0.5)));
                } elseif (in_array($e->getCode(), $connErrors)) {
                    usleep((int)(100000 * $try));
                    try {
                        $db_config = include '/home/my/include/config/config.db.php';
                        global $useMysqlRouter;
                        $host = ($useMysqlRouter === true || !isset($db_config['db_hosts']))
                            ? $db_config['db_host']
                            : $db_config['db_hosts'][count($db_config['db_hosts']) - 1];
                        $worker_db = new \Workerman\MySQL\Connection($host, $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
                    } catch (\Exception $re) {
                        Worker::safeEcho('DB reconnect failed: '.$re->getMessage()."\n");
                    }
                } else {
                    // Unrecoverable — bubble up. (The old `$global->queuein = 0;`
                    // reset here referenced a vestigial GlobalData scalar unrelated to
                    // any lock; dropped with the $global dependency.)
                    throw $e; // unrecoverable, bubble up
                }
            }
        }
        return false;
    };

    // - Loop through vps, quickservers - $module
    $queuehosts = [];
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
        $ips = [];
        $ok = $dbRetry(function () use (&$ips, &$worker_db, $prefix) {
            // - Load all vps/qs_masters making an array of the host ip 0 - $queuehosts
            $ips = $worker_db->select($prefix.'_ip')->from($prefix.'_masters')->column();
        });
        if (!$ok) {
            Worker::safeEcho('['.$maxTries.'/'.$maxTries.'] Bailing after failed query'."\n");
            return;
        }
        $queuehosts = array_merge($queuehosts, $ips);
    }
    if (!is_array($queuehosts)) {
        echo 'Queue Hosts is not array:'.var_export($queuehosts,true);
        return;
    }

    // Per-host locking: process one host at a time per worker
    // Use LMPOP with COUNT for atomic batch retrieval (Redis 7.0+)
    // Limit items per host per run to reduce lock contention
    $maxItemsPerHost = 300;  // Batch size per host per invocation
    $processedAny = false;

    foreach ($queuehosts as $hostIp) {
        // Drain lock name for this host. lock() prepends the dc:lock: namespace, so the
        // LOCK key becomes `dc:lock:queuein:<ip>`. This is DISTINCT from the production
        // queue DATA list `queuein:<ip>` popped further below — that raw Redis LIST (and
        // its Memcached fallback) intentionally lives OUTSIDE the dc:* namespace and is
        // left completely untouched by this migration.
        $lockName = 'queuein:'.$hostIp;

        // Try to acquire the per-host drain lock with retries and random backoff.
        // SET NX + TTL 900 replaces cas($lockKey, 0, 1): a null token == contended or
        // Redis-down (skip), and a crashed holder self-expires — so the isset-seed is
        // gone. The 900s TTL mirrors the old GlobalData 900s stale-reap window (never
        // shorter per ops rule: host-side ops like HyperV GetVMList can take 10+ min);
        // the drain renews between items while the lock is still owned below.
        $token = null;
        $lockAttempts = 0;
        while ($lockAttempts < 3) {
            $token = SharedState::lock($lockName, 900);
            if ($token !== null) {
                break;
            }
            $lockAttempts++;
            // Random backoff: 10-50ms on first retry, 20-100ms on second
            usleep(rand(10000, 50000) * $lockAttempts);
        }

        if ($token === null) {
            // Another worker is processing this host, skip
            continue;
        }

        // Set lock expiration to prevent stale locks
        try {
            // Use atomic batch retrieval from this host's queue
            // Since PHP Redis doesn't have lmpop, we use individual lPop calls with a limit
            $batchItems = [];
            if (USE_REDIS === true) {
                for ($i = 0; $i < $maxItemsPerHost; $i++) {
                    $queue = $redis->lPop('queuein:'.$hostIp);
                    if ($queue === false) {
                        break;
                    }
                    $decoded = json_decode($queue, true);
                    if ($decoded !== null) {
                        $batchItems[] = $decoded;
                    }
                }
            } else {
                // Memcached fallback: use getAndFlush pattern
                $queue = $memcache->get('queuein:'.$hostIp);
                if (is_array($queue)) {
                    $batchItems = array_slice($queue, 0, $maxItemsPerHost);
                    $remaining = array_slice($queue, $maxItemsPerHost);
                    if (count($remaining) > 0) {
                        $memcache->set('queuein:'.$hostIp, $remaining);
                    } else {
                        $memcache->set('queuein:'.$hostIp, []);
                    }
                }
            }

            if (count($batchItems) == 0) {
                SharedState::unlock($lockName, $token);  // Release lock
                continue;
            }

            $processedAny = true;
            //            Worker::safeEcho('Processing '.count($batchItems).' items from host '.$hostIp.PHP_EOL);

            foreach ($batchItems as $batchIndex => $queue) {
                // Keep the drain lock alive between batches so a host with a full
                // $maxItemsPerHost backlog can't outlive the 900s TTL mid-drain. A false
                // renew means ownership is gone (expired or taken) — stop draining THIS
                // host this cycle rather than processing holding nothing; break falls to
                // the finally, whose token-checked unlock no-ops, and the outer loop moves
                // to the next host (cross-host parallelism stays per-host-key, by design).
                if (!SharedState::renew($lockName, $token, 900)) {
                    Worker::safeEcho('Lost queuein lock for host '.$hostIp.' mid-drain — stopping this host this cycle (lock expired or taken)'.PHP_EOL);
                    // REVIEW-FIX (data loss): items were removed from the queue by the
                    // DESTRUCTIVE lPop batch above, so breaking here used to DISCARD every
                    // item from $batchIndex onward — gone from Redis and gone from memory,
                    // silently under-reporting bandwidth/CPU for this host. renew() also
                    // returns false for a mere transport blip, so a 2s Redis hiccup was
                    // enough to drop up to $maxItemsPerHost records. Put the untouched
                    // tail back before giving up.
                    memcached_queue_requeue_tail($batchItems, $batchIndex, $hostIp, $redis, $memcache);
                    break;
                }
                $module = $queue['post']['module'] ?? 'vps';
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
                if (USE_REDIS === true) {
                    $server = $redis->get($module.'_masters:'.$queue['ip']);
                } else {
                    $server = $memcache->get($module.'_masters'.$queue['ip']);
                }
                if ($server === false) {
                    $dbRetry(function () use (&$server, &$worker_db, $prefix, $queue) {
                        $server = $worker_db->select($prefix.'_id,'.$prefix.'_name,'.$prefix.'_hdsize,'.$prefix.'_iowait,'.$prefix.'_cpu_mhz,'.$prefix.'_hdfree,'.$prefix.'_load,'.$prefix.'_bits,'.$prefix.'_ram,'.$prefix.'_cpu_model,'.$prefix.'_kernel,'.$prefix.'_cores,'.$prefix.'_raid_status,'.$prefix.'_raid_building,'.$prefix.'_mounts,'.$prefix.'_drive_type')
                            ->from($prefix.'_masters')
                            ->where($prefix.'_ip = :ip')
                            ->bindValues(['ip' => $queue['ip']])
                            ->row();
                    });
                    if ($server === false) {
                        // a queue for a server on a diff module
                        continue;
                    }
                    if (USE_REDIS === true) {
                        $redis->setEx($module.'_masters:'.$queue['ip'], 3600, json_encode($server));
                    } else {
                        $memcache->set($module.'_masters'.$queue['ip'], $server, 3600);
                    }
                }
                if (USE_REDIS === true && !is_array($server)) {
                    $server = json_decode($server, true);
                }
                switch ($queue['post']['action']) {
                    case 'cpu_usage':
                        $cpu_usage = json_decode($queue['post']['cpu_usage'], true);
                        //$queue['post']['cpu_usage'] = strlen($queue['post']['cpu_usage']).' byte string';
                        //Worker::safeEcho('Queue #'.$idx.': '.json_encode($queue).PHP_EOL);
                        if (!is_array($cpu_usage)) {
                            continue 2;
                        }
                        $server_usage = array_shift($cpu_usage);
                        $cpu_avg = $server_usage['cpu'];
                        $serialized_server_usage = json_encode($server_usage);
                        $points = [];
                        if (USE_REDIS === true) {
                            $serverDetails = $redis->get($module.'_master_details:'.$server[$prefix.'_id']);
                        } else {
                            $serverDetails = $memcache->get($module.'_master_details'.$server[$prefix.'_id']);
                        }
                        if ($serverDetails === false) {
                            $ok = $dbRetry(function () use (&$serverDetails, &$worker_db, $prefix, $server) {
                                $serverDetails = $worker_db->select($prefix.'_cpu_avg,'.$prefix.'_cpu_usage')
                                    ->from($prefix.'_master_details')
                                    ->where($prefix.'_id = :id')
                                    ->bindValues(['id' => $server[$prefix.'_id']])
                                    ->row();
                            });
                            if (!$ok) {
                                continue 2;
                            }
                            if ($serverDetails === false) {
                                $ok = $dbRetry(function () use (&$worker_db, $prefix, $server, $cpu_avg, $serialized_server_usage) {
                                    $worker_db->insert($prefix.'_master_details')
                                        ->cols([
                                            $prefix.'_id' => $server[$prefix.'_id'],
                                            $prefix.'_cpu_avg' => $cpu_avg,
                                            $prefix.'_cpu_usage' => $serialized_server_usage
                                        ])->query();
                                });
                                if (!$ok) {
                                    continue 2;
                                }
                                $serverDetails = [
                                    $prefix.'_cpu_avg' => $cpu_avg,
                                    $prefix.'_cpu_usage' => $serialized_server_usage
                                ];
                                if (USE_REDIS === true) {
                                    $redis->setEx($module.'_master_details:'.$server[$prefix.'_id'], 3600, json_encode($serverDetails));
                                } else {
                                    $memcache->set($module.'_master_details'.$server[$prefix.'_id'], $serverDetails, 3600);
                                }
                            }
                        }
                        if (USE_REDIS === true && !is_array($serverDetails)) {
                            $serverDetails = json_decode($serverDetails, true);
                        }
                        if ($serverDetails[$prefix.'_cpu_usage'] != $serialized_server_usage || $serverDetails[$prefix.'_cpu_avg'] != $cpu_avg) {
                            $dbRetry(function () use (&$worker_db, $prefix, $server, $cpu_avg, $serialized_server_usage) {
                                $worker_db->update($prefix.'_master_details')
                                    ->cols([$prefix.'_cpu_avg', $prefix.'_cpu_usage'])
                                    ->where($prefix.'_id='.$server[$prefix.'_id'])
                                    ->bindValues([$prefix.'_cpu_avg' => $cpu_avg, $prefix.'_cpu_usage' => $serialized_server_usage])
                                    ->query();
                            });
                            $serverDetails[$prefix.'_cpu_avg'] = $cpu_avg;
                            $serverDetails[$prefix.'_cpu_usage'] = $serialized_server_usage;
                            if (USE_REDIS === true) {
                                $redis->setEx($module.'_master_details:'.$server[$prefix.'_id'], 3600, json_encode($serverDetails));
                            } else {
                                $memcache->set($module.'_master_details'.$server[$prefix.'_id'], $serverDetails, 3600);
                            }
                        }
                        if (USE_REDIS === true) {
                            $serverVps = $redis->get($module.'_vps:'.$server[$prefix.'_id']);
                        } else {
                            $serverVps = $memcache->get($module.'_vps'.$server[$prefix.'_id']);
                        }
                        if (USE_REDIS === true && $serverVps !== false) {
                            $serverVps = json_decode($serverVps, true);
                        }
                        if ($serverVps === false) {
                            $serverVps = [];
                        }
                        if (count($cpu_usage) > 0) {
                            foreach ($cpu_usage as $veid => $influxValues) {
                                if (array_key_exists($veid, $serverVps)) {
                                    $vps = $serverVps[$veid];
                                    if (INFLUX_V2 === true) {
                                        /*$point = \InfluxDB2\Point::measurement($prefix.'_stats')
                                            ->addTag('vps', (int)$vps)
                                            ->addTag('host', (int)$server[$prefix.'_id'])
                                            ->time(time());*/
                                        $point = [];
                                        foreach ($influxValues as $key => $value) {
                                            //$point->addField($key, $value);
                                            if (is_numeric($value)) {
                                                $point[] = $key.'='.$value;
                                            } else {
                                                $point[] = $key.'="'.$value.'"';
                                            }
                                        }
                                        $point = $prefix.'_stats,vps='.(int)$vps.',host='.(int)$server[$prefix.'_id'].' '.implode(',',$point);
                                        $influx_v2_database->write($point);
                                    }
                                } else {
                                    $row = false;
                                    $dbRetry(function () use (&$row, &$worker_db, $prefix, $table, $server, $veid) {
                                        $row = $worker_db->select($prefix.'_id,'.$prefix.'_vzid')
                                            ->from($table)
                                            ->where($prefix.'_server = :server and '.$prefix.'_vzid = :veid')
                                            ->bindValues(['server' => $server[$prefix.'_id'], 'veid' => $veid])
                                            ->row();
                                    });
                                    if ($row !== false) {
                                        $serverVps[$veid] = $row[$prefix.'_id'];
                                        if (USE_REDIS === true) {
                                            $redis->setEx($module.'_vps:'.$server[$prefix.'_id'], 3600, json_encode($serverVps));
                                        } else {
                                            $memcache->set($module.'_vps'.$server[$prefix.'_id'], $serverVps, 3600);
                                        }
                                        if (INFLUX_V2 === true) {
                                            /*$point = \InfluxDB2\Point::measurement($prefix.'_stats')
                                                ->addTag('vps', (int)$row[$prefix.'_id'])
                                                ->addTag('host', (int)$server[$prefix.'_id'])
                                                ->time(time());*/
                                            $point = [];
                                            foreach ($influxValues as $key => $value) {
                                                //$point->addField($key, $value);
                                                if (is_numeric($value)) {
                                                    $point[] = $key.'='.$value;
                                                } else {
                                                    $point[] = $key.'="'.$value.'"';
                                                }
                                            }
                                            $point = $prefix.'_stats,vps='.(int)$row[$prefix.'_id'].',host='.(int)$server[$prefix.'_id'].' '.implode(',',$point);
                                            $influx_v2_database->write($point);
                                        }
                                    }
                                }
                            }
                        }
                        break;
                    case 'bandwidth':
                        $bandwidth = $queue['post']['bandwidth'];
                        $servers = $queue['post']['servers'];
                        //$queue['post']['bandwidth'] = strlen($queue['post']['bandwidth']).' byte string';
                        //$queue['post']['servers'] = strlen($queue['post']['servers']).' byte string';
                        //Worker::safeEcho('Queue #'.$idx.': '.json_encode($queue).PHP_EOL);
                        $points = [];
                        $bandwidth = base64_decode($bandwidth);
                        $bandwidth = gzuncompress($bandwidth);
                        $bandwidth = json_decode($bandwidth,true);
                        $servers = base64_decode($servers);
                        $servers = gzuncompress($servers);
                        $servers = json_decode($servers,true);
                        if (is_array($bandwidth)) {
                            if (USE_REDIS === true) {
                                $serverVps = $redis->get($module.'_vps:'.$server[$prefix.'_id']);
                            } else {
                                $serverVps = $memcache->get($module.'_vps'.$server[$prefix.'_id']);
                            }
                            if (USE_REDIS === true && $serverVps !== false) {
                                $serverVps = json_decode($serverVps, true);
                            }
                            if ($serverVps === false) {
                                $serverVps = [];
                            }
                            $errors = [];
                            foreach ($bandwidth as $ip => $data) {
                                if ($ip == '') {
                                    continue;
                                }
                                $iplong = sprintf('%u', ip2long($ip));
                                $veid = $servers[$ip];
                                $idFromVeid = preg_replace('/[A-Za-z\._\-]*/m', '', $servers[$ip]);
                                if (!array_key_exists($veid, $serverVps)) {
                                    $row = $worker_db->select($prefix.'_id')
                                        ->from($table)
                                        ->where($prefix.'_server = :server and ('.$prefix.'_hostname = :hostname or '.$prefix.'_vzid = :veid or '.$prefix.'_vzid = :idFromVeid)')
                                        ->bindValues(['server' => $server[$prefix.'_id'], 'hostname' => $veid, 'veid' => $veid, 'idFromVeid' => $idFromVeid])
                                        ->row();
                                    if ($row === false) {
                                        $errors[] = $ip.'. Server '.$server[$prefix.'_id'].' VEID '.$veid.' IdFromVeid '.$idFromVeid;
                                        continue;
                                    }
                                    $serverVps[$veid] = $row[$prefix.'_id'];
                                    if (USE_REDIS === true) {
                                        $redis->setEx($module.'_vps:'.$server[$prefix.'_id'], 3600, json_encode($serverVps));
                                    } else {
                                        $memcache->set($module.'_vps'.$server[$prefix.'_id'], $serverVps, 3600);
                                    }
                                }
                                $vps = $serverVps[$veid];
                                if (INFLUX_V2 === true) {
                                    /*$point = \InfluxDB2\Point::measurement($influx_table)
                                        ->addTag('vps', (int)$vps)
                                        ->addTag('host', (int)$server[$prefix.'_id'])
                                        ->addTag('ip', $ip)
                                        ->addField('in', (int)$data['in'])
                                        ->addField('out', (int)$data['out'])
                                        ->time(time());*/
                                    $point = $influx_table.',vps='.(int)$vps.',host='.(int)$server[$prefix.'_id'].',ip='.$ip.' in='.(int)$data['in'].',out='.(int)$data['out'];
                                    $influx_v2_database->write($point);
                                }
                            }
                            if (count($errors) > 0) {
                                Worker::safeEcho('Bandwidth Data with no matching IP: '.implode(', ', $errors).PHP_EOL);
                            }
                        }
                        break;
                    case 'server_info':
                        $servers = $queue['post']['servers'];
                        $servers = base64_decode($servers);
                        $servers = json_decode($servers, true);
                        //Worker::safeEcho('server_info '.$server[$prefix.'_name'].'got servers: '.var_export($servers,true).PHP_EOL);
                        $fields = ['load', 'hdfree', 'iowait', 'hdsize', 'bits', 'ram', 'cpu_model', 'cpu_mhz', 'kernel', 'raid_building', 'cores', 'raid_status', 'mounts', 'drive_type'];
                        if ($module == 'quickservers' && isset($servers['ram'])) {
                            $servers['ram'] = floor($servers['ram'] * 0.90);
                        }
                        $detailfields = ['ioping'];
                        $skipfields = ['load', 'hdfree', 'iowait', 'cpu_mhz'];
                        foreach ($skipfields as $field) {
                            $key = $module.':host:'.$server[$prefix.'_id'].':'.$field;
                            if (isset($servers[$field])) {
                                //Worker::safeEcho('server_info setting '.$key.'='.$servers[$field].PHP_EOL);
                                if (USE_REDIS === true) {
                                    $redis->set($key, $servers[$field]);
                                } else {
                                    $memcache->set($key, $servers[$field]);
                                }
                            }
                        }
                        foreach ($detailfields as $field) {
                            $key = $module.':hostd:'.$server[$prefix.'_id'].':'.$field;
                            if (isset($servers[$field])) {
                                if (USE_REDIS === true) {
                                    $redis->set($key, $servers[$field]);
                                } else {
                                    $memcache->set($key, $servers[$field]);
                                }
                            }
                        }
                        $cols = [];
                        $values = [];
                        // Minimum change threshold to avoid InnoDB Cluster certification
                        // conflicts from trivial updates (e.g., load changing by 0.01)
                        $minChangeThreshold = [
                            'load' => 0.5,
                            'cpu_usage' => 0.5,
                            'ram_free' => 1048576,  // 1MB minimum change
                            'cpu_iowait' => 0.1,
                            'cpu_steal' => 0.1,
                        ];
                        foreach ($fields as $field) {
                            if (isset($servers[$field]) && array_key_exists($prefix.'_'.$field, $server)) {
                                $oldVal = $server[$prefix.'_'.$field];
                                $newVal = $servers[$field];
                                // Skip if value hasn't changed significantly
                                if ($oldVal == $newVal) {
                                    continue;
                                }
                                // Apply threshold check if defined
                                if (isset($minChangeThreshold[$field]) && abs($newVal - $oldVal) < $minChangeThreshold[$field]) {
                                    continue;
                                }
                                $cols[] = $prefix.'_'.$field;
                                $values[$prefix.'_'.$field] = $newVal;
                                $server[$prefix.'_'.$field] = $newVal;
                            }
                        }
                        // Host saturation / capacity metrics reported by `provirted cron
                        // host-info` (App\Os\Saturation). Mirrors mystage's queue handler
                        // include/vps/queue/ResponseHandlers/ServerInfo.php so the columns
                        // added by mystage scripts/mysql/hostinfo.sql (present on both
                        // vps_masters and qs_masters) actually get persisted here too.
                        // Each <prefix>_<column> maps to the payload key(s) that supply it;
                        // ram_free accepts the new 'mem_free' key or the legacy 'ramfree'.
                        // NOTE: $server is loaded above with an explicit column list that
                        // does NOT include these columns, so we write whenever the column
                        // is absent/NULL/changed (cannot gate on its presence the way the
                        // mystage handler does, or live metrics would never persist).
                        $metricFields = [
                            'ram_free'            => ['mem_free', 'ramfree'],
                            'cpu_usage'           => ['cpu_usage'],
                            'cpu_iowait'          => ['cpu_iowait'],
                            'cpu_steal'           => ['cpu_steal'],
                            'cpu_steal_norm'      => ['cpu_steal_norm'],
                            'run_queue_norm'      => ['run_queue_norm'],
                            'cpu_capacity'        => ['cpu_capacity'],
                            'cpu_capacity_max'    => ['cpu_capacity_max'],
                            'io_pressure'         => ['io_pressure'],
                            'cpu_pressure'        => ['cpu_pressure'],
                            'mem_pressure'        => ['mem_pressure'],
                            'total_pressure'      => ['total_pressure'],
                            'zfs_arc_size'        => ['zfs_arc_size'],
                            'zfs_arc_min'         => ['zfs_arc_min'],
                            'zfs_arc_mac'         => ['zfs_arc_mac'],
                            'zfs_arc_reclaimable' => ['zfs_arc_reclaimable'],
                        ];
                        foreach ($metricFields as $column => $sourceKeys) {
                            $value = null;
                            foreach ($sourceKeys as $key) {
                                if (isset($servers[$key])) {
                                    $value = $servers[$key];
                                    break;
                                }
                            }
                            if ($value === null) {
                                continue;
                            }
                            $col = $prefix.'_'.$column;
                            // Only update if value actually changed and meets threshold
                            $oldVal = array_key_exists($col, $server) ? $server[$col] : null;
                            if ($oldVal !== null && $oldVal == $value) {
                                continue;  // No change
                            }
                            if ($oldVal !== null && isset($minChangeThreshold[$column]) && abs($value - $oldVal) < $minChangeThreshold[$column]) {
                                continue;  // Below threshold
                            }
                            $cols[] = $col;
                            $values[$col] = $value;
                            $server[$col] = $value;
                        }
                        if (count($cols) > 0) {
                            // Add small random delay before DB update to reduce InnoDB Cluster
                            // certification contention when multiple workers hit same row
                            usleep(rand(5000, 20000));  // 5-20ms random delay
                            $affected = 0;
                            $dbRetry(function () use (&$worker_db, &$affected, $prefix, $server, $cols, $values) {
                                $affected = $worker_db->update($prefix.'_masters')
                                    ->cols($cols)
                                    ->where($prefix.'_id='.$server[$prefix.'_id'])
                                    ->bindValues($values)
                                    ->query();
                            });
                            // Self-heal a stale IP->row mapping. The <module>_masters:<ip>
                            // cache below is re-set from memory with a fresh 3600s TTL on
                            // every server_info, so it never actually expires while metrics
                            // keep flowing. If a host is deleted and recreated for the same
                            // IP it gets a new <prefix>_id, but the cache keeps the dead id
                            // forever and every UPDATE ... WHERE <prefix>_id=<dead id>
                            // silently matches 0 rows -- so the live row never receives
                            // hdfree/hdsize/etc. We only reach this block with real field
                            // changes to apply, so 0 affected rows means the cached id is
                            // stale: drop it, reload the current row by IP and re-apply the
                            // same changes to the correct id.
                            if ((int)$affected === 0) {
                                Worker::safeEcho('server_info: cached '.$module.' row for ip '.$queue['ip'].' (id '.$server[$prefix.'_id'].') matched 0 rows; reloading by IP'.PHP_EOL);
                                $fresh = false;
                                $dbRetry(function () use (&$fresh, &$worker_db, $prefix, $queue) {
                                    $fresh = $worker_db->select($prefix.'_id,'.$prefix.'_name,'.$prefix.'_hdsize,'.$prefix.'_iowait,'.$prefix.'_cpu_mhz,'.$prefix.'_hdfree,'.$prefix.'_load,'.$prefix.'_bits,'.$prefix.'_ram,'.$prefix.'_cpu_model,'.$prefix.'_kernel,'.$prefix.'_cores,'.$prefix.'_raid_status,'.$prefix.'_raid_building,'.$prefix.'_mounts,'.$prefix.'_drive_type')
                                        ->from($prefix.'_masters')
                                        ->where($prefix.'_ip = :ip')
                                        ->bindValues(['ip' => $queue['ip']])
                                        ->row();
                                });
                                if (is_array($fresh) && (int)$fresh[$prefix.'_id'] !== (int)$server[$prefix.'_id']) {
                                    $freshId = (int)$fresh[$prefix.'_id'];
                                    $dbRetry(function () use (&$worker_db, $prefix, $freshId, $cols, $values) {
                                        $worker_db->update($prefix.'_masters')
                                            ->cols($cols)
                                            ->where($prefix.'_id='.$freshId)
                                            ->bindValues($values)
                                            ->query();
                                    });
                                    foreach ($values as $col => $val) {
                                        $fresh[$col] = $val;
                                    }
                                    $server = $fresh;
                                }
                            }
                        }
                        if (USE_REDIS === true) {
                            $redis->setEx($module.'_masters:'.$queue['ip'], 3600, json_encode($server));
                        } else {
                            $memcache->set($module.'_masters'.$queue['ip'], $server, 3600);
                        }
                        break;
                    default:
                        Worker::safeEcho('Dont know how to handel this Queued Entry: '.json_encode($queue, true).PHP_EOL);
                        break;
                }
            } // end foreach ($batchItems as $queue)
        } catch (\Exception $e) {
            Worker::safeEcho('Exception processing host '.$hostIp.': '.$e->getMessage().PHP_EOL);
        } finally {
            // Always release the per-host drain lock (owner-checked via token).
            SharedState::unlock($lockName, $token);
        }
    } // end foreach ($queuehosts as $hostIp)

    // Flush all buffered InfluxDB writes once after all queues have been processed.
    if (INFLUX_V2 === true) {
        try {
            $influx_v2_database->close();
        } catch (\Exception $e) {
            Worker::safeEcho('InfluxDB got Exception '.$e->getMessage().' while flushing writes'.PHP_EOL);
        }
    }
    //Worker::safeEcho('memcached_queue_task finished processing '.count($processQueue).' queues after '.(time() - $start).' seconds'.PHP_EOL);
    return;
}
