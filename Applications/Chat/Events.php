<?php

/**
 * Used to detect business code cycle or prolonged obstruction and other issues
 * If the business card is found dead, you can open the following declare (remove the // comment), and execute php start.php reload
 * Then observe workerman.log for a period of time to see if there is a process_timeout exception
 */
//declare(ticks=1);

/**
 * Chat the main logic - Mainly onMessage onClose
 */
use Workerman\Worker;
use GatewayWorker\Lib\Gateway;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\GlobalTimer;

require_once __DIR__.'/Process.php';
require_once __DIR__.'/../../vendor/workerman/global-timer/src/GlobalTimer.php';
//require_once __DIR__.'/GlobalTimer.php';
require_once __DIR__.'/stdObject.php';

class Events
{
    public static $process_handle = null;
    public static $process_pipes = null;
    public static $db = null;
    public static $running = [];

    /**
     * when the workerman thread starts
     *
     * @param Workerman\Worker $worker
     */
    public static function onWorkerStart($worker)
    {
        //$worker->maxSendBufferSize = 102400000;
        //$worker->sendToGatewayBufferSize = 102400000;
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $global = new \GlobalData\Client(gethostname() == 'my.interserver.net' ? '127.0.0.1:2207' : '192.64.80.218:2207');     // initialize the GlobalData client
        /**
        * @var \Memcached
        */
        global $memcache;
        $memcache = new \Memcached();
        $memcache->addServer('localhost', 11211);
        GlobalTimer::init(gethostname() == 'my.interserver.net' ? '127.0.0.1' : '192.64.80.218','3333');
        $db_config = include '/home/my/include/config/config.db.php';
        $loop = Worker::getEventLoop();
        global $useMysqlRouter;
        if ($useMysqlRouter === true) {
            self::$db = new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
        } else {
            self::$db = new \Workerman\MySQL\Connection(isset($db_config['db_hosts']) ? $db_config['db_hosts'][count($db_config['db_hosts']) - 1] : $db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
        }        
        if ($global->add('running', [])) {
            $global->hosts = [];
            $global->rooms = [
                [
                    'id' => 'room_1',
                    'name' => 'General Chat',
                    'img' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a6/Rubik%27s_cube.svg/220px-Rubik%27s_cube.svg.png',
                    'members' => [],
                    'messages' => [],
                ]
            ];
        }
        if ($worker->id == 0) {
            $args = [];
            $timers = [];
            if (gethostname() == 'my.interserver.net') {
                $timers['processing_queue_timer'] = GlobalTimer::add(30, ['Events', 'processing_queue_timer'], $args);
                $timers['vps_queue_queue_timer'] = GlobalTimer::add(30, ['Events', 'vps_queue_timer'], $args);
                $timers['memcache_queue_timer'] = GlobalTimer::add(30, ['Events', 'memcache_queue_timer'], $args);
                $timers['map_queue_timer'] = GlobalTimer::add(60, ['Events', 'map_queue_timer'], $args);
                //$timers[] = GlobalTimer::add(60, ['Events', 'queue_queue_timer'], $args);
                //$timer_id = GlobalTimer::add(1, function() use (&$timer_id, $timers) { echo "worker[0] tick timer_id:$timer_id:'".print_r($timers,true)."\n"; });
                $global->timers = $timers;
            } elseif (gethostname() == 'my-web-2.interserver.net') {
                $timers = $global->timers;
                $rows = self::$db->select('vps_id')->from('vps_masters')->where('vps_type=11')->query();
                foreach ($rows as $row) {
                    $var = 'vps_host_'.$row['vps_id'];
                    $global->$var = 0;
                }
                $timers['hyperv_update_list_timer'] = GlobalTimer::add(3600, ['Events', 'hyperv_update_list_timer'], $args);
                $timers['hyperv_queue_timer'] = GlobalTimer::add(30, ['Events', 'hyperv_queue_timer'], $args);                
                Events::hyperv_update_list_timer();
                $global->timers = $timers;
            }
        }
    }

    /**
     * when the workerman process shuts down / closes
     *
     * @param Workerman\Worker $worker
     */
    public static function onWorkerStop($worker)
    {
        foreach ($worker->connections as $connection) {
            $connection->close();
        }
        if ($worker->id == 0) {
            /*@shell_exec('killall vmstat');
            @pclose(self::process_handle);*/
        }
    }

    /**
     * when a client connects
     *
     * @param int $client_id
     */
    public static function onConnect($client_id)
    {
    }

    /**
     * When there is news
     * @param int $client_id
     * @param string $message
     */
    public static function onMessage($client_id, $message)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        //Worker::safeEcho("[{$client_id}] client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} session:".json_encode($_SESSION)."\n onMessage:".serialize($message).PHP_EOL); // debug
        $message_data = json_decode($message, true); // Client is passed json data
        if (empty($message_data)) {
            return ;
        }
        if (!isset($message_data['type'])) {
            Worker::safeEcho("[{$client_id}] Got message {$message} but no type passed".PHP_EOL);
        }
        $method = 'msg'.str_replace(' ', '', ucwords(str_replace(['-','_'], [' ',' '], $message_data['type'])));
        if (method_exists('Events', $method)) {
            call_user_func(['Events', $method], $client_id, $message_data);
        } else {
            Worker::safeEcho("[{$client_id}] Wanted to call method {$method} but it doesnt exist".PHP_EOL);
        }
    }

    /**
     * When the client is disconnected
     *
     * @param integer $client_id client id
     */
    public static function onClose($client_id)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        Worker::safeEcho("[{$client_id}] client:".($_SESSION['name'] ?? '')." {$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} onClose:''".PHP_EOL); // debug
        if (isset($_SESSION['uid'])) {
            $clientIds = Gateway::getClientIdByUid($_SESSION['uid']);
            if (count($clientIds) == 1 && isset($global->rooms) && sizeof($global->rooms) > 0) {
                $logoutMessage = [
                    'type' => 'logout',
                    'id' => $_SESSION['uid'],
                    'time' => date('Y-m-d H:i:s')
                ];
                $rooms = $global->rooms;
                $updated = false;
                foreach ($rooms as $idx => $room) {
                    if (($key = array_search($_SESSION['uid'], $room['members'])) !== false) {
                        $updated = true;
                        unset($room['members'][$key]);
                        Gateway::sendToGroup($room['id'], json_encode($logoutMessage));
                        $rooms[$idx] = $room;
                    }
                }
                if ($updated === true) {
                    $global->rooms = $rooms;
                }
            }
            if (isset($_SESSION['ima'])) {
                if ($_SESSION['ima'] == 'host') {
                    $id = str_replace('vps', '', $_SESSION['uid']);
                    do {
                        $old_value = $new_value = $global->hosts;
                        unset($new_value[$id]);
                    } while (!$global->cas('hosts', $old_value, $new_value));
                } else {
                    if (count($clientIds) == 1) {
                        // Send command to stop running any processes that were running and directed at this user
                        $running = $global->running;
                        if (sizeof($running) > 0) {
                            $remove = false;
                            foreach ($running as $run) {
                                if ($run['for'] == $_SESSION['uid']) {
                                    $remove = true;
                                    Gateway::sendToUid($run['host'], json_encode(['type' => 'stop_run', 'id' => $run['id']]));
                                }
                            }
                            /* if ($remove === TRUE) {
                                do {
                                    $old_value = $new_value = $global->running;
                                    foreach ($new_value as $idx => $run)
                                        if ($run['for'] == $_SESSION['uid'])
                                            unset($new_values[$idx]);
                                } while(!$global->cas('running', $old_value, $new_value));
                            } */
                        }
                    }
                }
            }
        }
    }

    public static function queue_queue_timer()
    {
        Worker::safeEcho('Timer running for '.__METHOD__."\n");
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
        $task_connection->send(json_encode(['type' => 'queue_queue_task', 'args' => []]));
        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection) {
            $task_connection->close();
        };
        $task_connection->connect();
    }

    public static function map_queue_timer()
    {
        //Worker::safeEcho('Timer running for '.__METHOD__."\n");
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
        $task_connection->send(json_encode(['type' => 'map_queue_task', 'args' => []]));
        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection) {
            $task_connection->close();
        };
        $task_connection->connect();
    }

    public static function memcache_queue_timer()
    {
        //Worker::safeEcho('Timer running for '.__METHOD__."\n");
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
        $task_connection->send(json_encode(['type' => 'memcached_queue_task', 'args' => []]));
        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection) {
            $task_connection->close();
        };
        $task_connection->connect();
    }

    /**
     * timer function to check for payment processing queue items
     *
     */
    public static function processing_queue_timer()
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $var = 'processsing_queue';
        $lastVar = $var.'_last';
        if (!isset($global->$var)) {
            $global->$var = 0;
        }
        if ($global->cas($var, 0, time())) {
            /**
             * @var \React\MySQL\Connection
             */
            $results = self::$db->select('*')->from('queue_log')->where('history_section="process_payment" and history_new_value="pending"')->query();
            Worker::safeEcho("Got Results ".json_encode($results,true)."\n");
            if (is_array($results) && sizeof($results) > 0) {
                self::process_results($results);
            } else {
                $global->$lastVar = time();
                $global->$var = 0;
            }
        }
    }

    public static function process_results($results)
    {
        $result = array_shift($results);
        $maxTries = 30;
        $delay = 1;
        $try = 0;
        $updated = false;
        while ($updated === false && $try < $maxTries) {
            $try++;
            try {
                self::$db->update('queue_log')->cols(['history_new_value' => 'processing'])->where('history_id='.$result['history_id'])->query();
                $updated = true;
            } catch (\PDOException $e) {
                $check = 'SQLSTATE[40000]: Transaction rollback: 3101 Plugin instructed the server to rollback the current transaction.';
                Worker::safeEcho('['.$try.'/'.$maxTries.'] Got PDO Exception #'.$e->getCode().': "'.$e->getMessage()."\"\n");
                sleep($delay);
                $db_config = include '/home/my/include/config/config.db.php';                
                Events::$db = new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
            }
        }
        if ($updated === true) {
            Worker::safeEcho("payment processing about to spawn task for ".json_encode($result,true)."\n");
            $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
            $task_connection->send(json_encode(['type' => 'processing_queue_task', 'args' => $result]));
            $task_connection->onMessage = function ($connection, $task_result) use ($result, $results, $task_connection, $maxTries, $delay) {
                //Worker::safeEcho("finished queued payment processing task\n");
                $try = 0;
                $updated = false;
                while ($updated === false && $try < $maxTries) {
                    $try++;
                    try {
                        Events::$db->update('queue_log')->cols(['history_new_value' => 'completed'])->where('history_id='.$result['history_id'])->query();
                        $updated = true;
                    } catch (\PDOException $e) {
                        //$check = 'SQLSTATE[40000]: Transaction rollback: 3101 Plugin instructed the server to rollback the current transaction.';
                        Worker::safeEcho('['.$try.'/'.$maxTries.'] Got PDO Exception #'.$e->getCode().': "'.$e->getMessage()."\"\n");
                        sleep($delay);
                        $db_config = include '/home/my/include/config/config.db.php';
                        global $useMysqlRouter;
                        if ($useMysqlRouter === true) {
                            Events::$db = new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
                        } else {
                            Events::$db = new \Workerman\MySQL\Connection(isset($db_config['db_hosts']) ? $db_config['db_hosts'][count($db_config['db_hosts']) - 1] : $db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
                        }                        
                    }
                }
                $task_connection->close();
                if (count($results) > 0) {
                    Events::process_results($results);
                } else {
                    /**
                     * @var \GlobalData\Client
                     */
                    global $global;
                    $var = 'processsing_queue';
                    $lastVar = $var.'_last';
                    $global->$lastVar = time();
                    $global->$var = 0;
                }
                Worker::safeEcho("finished queued payment processing task (post close)\n");
            };
            $task_connection->onClose = function ($connection) {
                /**
                 * @var \GlobalData\Client
                 */
                global $global;
                $var = 'processsing_queue';
                $lastVar = $var.'_last';
                $global->$lastVar = time();
                $global->$var = 0;
            };
            $task_connection->connect();
        } else {
            /**
             * @var \GlobalData\Client
             */
            global $global;
            $var = 'processsing_queue';
            $lastVar = $var.'_last';
            $global->$lastVar = time();
            $global->$var = 0;
        }
    }


    /**
     * timer function to check for vps queue items
     *
     */
    public static function vps_queue_timer()
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        /**
         * @var \React\MySQL\Connection
         */
        $results = self::$db->select('*')->from('queue_log')->leftJoin('vps', 'vps_id=history_type')->where('history_section="vpsqueue"')->query();
        if (is_array($results) && sizeof($results) > 0) {
            $queues = [];
            foreach ($results as $results[0]) {
                if (is_numeric($results[0]['history_type'])) {
                    if (is_null($results[0]['vps_id'])) {
                        // no vps id in db matching, delete
                    } else {
                        $id = $results[0]['vps_server'];
                        if (in_array($id, array_keys($global->hosts))) {
                            if (!in_array($id, array_keys($queues))) {
                                $queues[$id] = [];
                            }
                            $queues[$id][] = $results[0];
                        }
                    }
                } else {
                    $id = str_replace('vps', '', $results[0]['history_type']);
                    if (in_array($id, array_keys($global->hosts))) {
                        if (!in_array($id, array_keys($queues))) {
                            $queues[$id] = [];
                        }
                        $queues[$id][] = $results[0];
                    }
                }
            }
            if (sizeof($queues) > 0) {
                foreach ($queues as $server_id => $rows) {
                    $server_data = $global->hosts[$server_id];
                    //if ($server_id != 467) {
                    //Worker::safeEcho('Wanted To Process Queues For Server '.$server_id.' '.$server_data['vps_name'].PHP_EOL);
                    //continue;
                    //} else {
                    Worker::safeEcho('Processing Queues For Server '.$server_id.' '.$server_data['vps_name'].PHP_EOL);
                    //}
                    $var = 'vps_host_'.$server_id;
                    if (!isset($global->$var)) {
                        $global->$var = 0;
                    }
                    if ($global->cas($var, 0, 1)) {
                        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
                        $task_connection->send(json_encode(['type' => 'vps_queue_task', 'args' => [
                            'id' => $server_id,
                        ]]));
                        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection, $server_id, $server_data) {
                            $task_result = json_decode($task_result, true);
                            //Worker::safeEcho("Got Result ".var_export($task_result, true).PHP_EOL);
                            //Worker::safeEcho("Bandwidth Update for ".$_SESSION['name']." content: ".json_encode($message_data['content'])." returned:".var_export($task_result,TRUE).PHP_EOL);
                            if (trim($task_result['return']) != '') {
                                self::run_command($server_id, $task_result['return'], false, 'room_1', 80, 24, true);
                            }
                            $task_connection->close();
                        };
                        $task_connection->connect();
                        $global->$var = 0;
                    }
                }
            }
        }
    }

    /**
     * function called at intervals to udpate vps list
     *
     */
    public static function hyperv_update_list_timer()
    {
        /*$new_message = [
            'type' => 'log',
            'content' => nl2br(htmlspecialchars('Running Update VPS List Timer')),
            'time' => date('Y-m-d H:i:s'),
        ];
        Gateway::sendToAll(json_encode($new_message));*/
        Worker::safeEcho("timer starting hyperv update list\n");
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
        $task_connection->send(json_encode(['type' => 'async_hyperv_get_list', 'args' => []]));
        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection) {
            //var_dump($task_result);
            $task_connection->close();
        };
        $task_connection->connect();
    }

    /**
     * hyperv specific queue timer check
     *
     */
    public static function hyperv_queue_timer()
    {
        /*$new_message = [
            'type' => 'log',
            'content' => nl2br(htmlspecialchars('Running VPS Queue Timer')),
            'time' => date('Y-m-d H:i:s'),
        ];
        Gateway::sendToAll(json_encode($new_message));*/
        //Worker::safeEcho("timer starting hyperv queue check\n");
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
        $task_connection->send(json_encode(['type' => 'sync_hyperv_queue', 'args' => []]));
        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection) {
            //var_dump($task_result);
            $task_connection->close();
        };
        $task_connection->connect();
    }

    /**
     * runs a command on a given host.
     *
     * @param string $cmd the command to run
     * @param bool $interact defaults false, if true the host will open up the process for stdin and handle forwarding i/o
     * @param mixed $for null for nobody, or a uid or reserved word to indicate how the response if any should be handled
     * @return void
     */
    public static function run_local($client_id, $cmd, $tag)
    {
        $process = new Process($client_id, $cmd, $tag);
        self::$running[] = $run;
        /*
        $worker->onMessage = function($connection, $data) {
            if(ALLOW_CLIENT_INPUT) {
                fwrite($connection->pipes[0], $data);
            }
        };
        $worker->onClose = function($connection) {
            $connection->process_stdin->close();
            $connection->process_stdout->close();
            fclose($connection->pipes[0]);
            $connection->pipes = null;
            proc_terminate($connection->process);
            proc_close($connection->process);
            $connection->process = null;
        };
        $worker->onWorkerStop = function($worker) {
            foreach($worker->connections as $connection) {
                $connection->close();
            }
        };
        */
    }

    /**
     * runs a command on a given host.
     *
     * @param int $host the host server id to run it on
     * @param string $cmd the command to run
     * @param bool $interact defaults false, if true the host will open up the process for stdin and handle forwarding i/o
     * @param mixed $for null for nobody, or a uid or reserved word to indicate how the response if any should be handled
     * @return void
     */
    public static function run_command($host, $cmd, $interact = false, $for = null, $rows = 80, $cols = 24, $update_after = false)
    {
        /**
        * @var \GlobalData\Client
        */
        global $global;
        // we need to store the command locally so we can easily react proeprly if we get a response
        if (substr($host, 0, 3) == 'vps' && is_numeric(substr($host, 3))) {
            $host = substr($host, 3);
        }
        $uid = 'vps'.$host;
        if (Gateway::isUidOnline($uid) == true) {
            $run_id = md5($cmd);
            $json = [
                'type' => 'run',
                'command' => $cmd,
                'id' => $run_id,
                'interact' => $interact,
                'update_after' => $update_after,
                'host' => $uid,
                'rows' => $rows,
                'cols' => $cols,
                'for' => $for
            ];
            do {
                $old_value = $new_value = $global->running;
                $new_value[$run_id] = $json;
            } while (!$global->cas('running', $old_value, $new_value));
            Gateway::sendToUid($uid, json_encode($json));
            Worker::safeEcho("Sending ".json_encode($json)." to {$uid}".PHP_EOL);
        } else {
            Worker::safeEcho("{$uid} is not online, cant send".PHP_EOL);
            // if they are not online then queue it up for later
        }
    }

    public static function say($from, $is, $to, $content, $from_name)
    {
        /**
        * @var \GlobalData\Client
        */
        global $global;
        Worker::safeEcho("Saying {$content} from {$from} to {$to} is {$is} name {$from_name}".PHP_EOL);
        if ($is == 'room') {
            $new_message = [
                'type' => 'say',
                'from' => $from,
                'is' => $is,
                'to' => $to,
                'content' => nl2br(htmlspecialchars($content)),
                'time' => date('Y-m-d H:i:s'),
            ];
            $rooms = $global->rooms;
            $rooms[0]['messages'][] = [
                'from_id' => $from,
                'from_name' => $from_name,
                'content' => nl2br(htmlspecialchars($content)),
                'time' => date('Y-m-d H:i:s'),
            ];
            $global->rooms = $rooms;
            return Gateway::sendToGroup($to, json_encode($new_message));
        } else {
            $new_message = [
                'type' => 'say',
                'from' => $from,
                'is' => $is,
                'to' => $to,
                'content' => nl2br(htmlspecialchars($content)),
                'time' => date('Y-m-d H:i:s'),
            ];
            return Gateway::sendToUid($to, json_encode($new_message));
        }
    }

    /**
     * handler for when receiving a self-update message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgSelfUpdate($client_id, $message_data)
    {
        if ($_SESSION['login'] == true && $_SESSION['ima'] == 'admin') {
            Gateway::sendToGroup('hosts', json_encode($message_data));
        }
        return;
    }



    /**
     * handler for when receiving a vps details lsit message
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgVpsList($client_id, $message_data)
    {
        if (!is_array($message_data['content'])) {
            Worker::safeEcho("[{$client_id}] error with vps list content " . var_export($message_data['content'], true).PHP_EOL);
            return;
        }
        //Worker::safeEcho("[{$client_id}] got vps list content " . var_export($message_data['content'], true).PHP_EOL);
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
        $task_connection->send(json_encode([
            'type' => 'vps_get_list',
            'args' => [
                'name' => $_SESSION['name'],
                'id' => str_replace('vps', '', $_SESSION['uid']),
                'content' => $message_data['content']
            ]
        ]));
        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection, $client_id, $message_data) {
            //$task_result = json_decode($task_result, true);
            //Worker::safeEcho("[{$client_id}] Process VPS List for ".$_SESSION['name']." returned:".$task_result.PHP_EOL);
            $task_connection->close();
        };
        $task_connection->connect();
        return;
    }

    /**
     * handler for when receiving a vps details lsit message
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgVpsInfo($client_id, $message_data)
    {
        if (!is_array($message_data['content'])) {
            Worker::safeEcho("[{$client_id}] error with vps info content " . var_export($message_data['content'], true).PHP_EOL);
            return;
        }
        //Worker::safeEcho("[{$client_id}] got vps list content " . var_export($message_data['content'], true).PHP_EOL);
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
        $task_connection->send(json_encode([
            'type' => 'vps_update_info',
            'args' => [
                'name' => $_SESSION['name'],
                'id' => str_replace('vps', '', $_SESSION['uid']),
                'content' => $message_data['content']
            ]
        ]));
        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection, $client_id, $message_data) {
            //$task_result = json_decode($task_result, true);
            //Worker::safeEcho("[{$client_id}] Process VPS Info for ".$_SESSION['name']." returned:".$task_result.PHP_EOL);
            $task_connection->close();
        };
        $task_connection->connect();
        return;
    }

    /**
     * handler for when receiving a get map message
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgGetMap($client_id, $message_data)
    {
        //Worker::safeEcho("[{$client_id}] got vps list content " . var_export($message_data['content'], true).PHP_EOL);
        //Worker::safeEcho("[{$client_id}] ".json_encode($_SESSION).PHP_EOL);
        $uid = $_SESSION['uid'];
        $id = str_replace('vps', '', $uid);
        //Worker::safeEcho("[{$client_id}] GetMap event calling get_map task uid $uid id $id".PHP_EOL);
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
        $task_connection->send(json_encode([
            'type' => 'get_map',
            'args' => [
                'id' => $id
            ]
        ]));
        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection, $client_id, $uid, $message_data) {
            $task_result = json_decode($task_result, true);
            //Gateway::sendToUid($uid, json_encode([
            Gateway::sendToClient($client_id, json_encode([
                'type' => 'get_map',
                'content' => $task_result
            ]));
            $task_connection->close();
        };
        $task_connection->connect();
        return;
    }


    /**
     * handler for when receiving a bandwidth message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgBandwidth($client_id, $message_data)
    {
        if (!is_array($message_data['content'])) {
            Worker::safeEcho("[{$client_id}] error with bandwidth content " . var_export($message_data['content'], true).PHP_EOL);
            return;
        }
        //Worker::safeEcho("msgBandwidth called with ($client_id, ".json_encode($message_data).")");
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
        $task_connection->send(json_encode([
            'type' => 'bandwidth',
            'args' => [
                'name' => $_SESSION['name'],
                'uid' => $_SESSION['uid'],
                'content' => $message_data['content']
            ]
        ]));
        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection, $client_id, $message_data) {
            //Worker::safeEcho("[{$client_id}] Bandwidth Update for ".$_SESSION['name']." content: ".json_encode($message_data['content'])." returned:".var_export($task_result,TRUE).PHP_EOL);
            $task_connection->close();
        };
        $task_connection->connect();
        return;
    }

    /**
     * handler for when receiving a clients message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgClients($client_id, $message_data)
    {
        /**
        * @var \GlobalData\Client
        */
        global $global;
        if ($_SESSION['login'] == true && $_SESSION['ima'] == 'admin') {
            $sessions = Gateway::getAllClientSessions();
            $clients = [];
            foreach ($sessions as $session_id => $session_data) {
                if (isset($session_data['uid'])) {
                    $client = [
                        'id' => $session_data['uid'],
                        'name' => $session_data['name'],
                        'ima' => $session_data['ima'],
                        'online' => $session_data['online'],
                        'messages' => [],
                    ];
                    if ($session_data['ima'] == 'host') {
                        $client['type'] = $session_data['type'];
                    } else {
                        $client['img'] = $session_data['img'];
                    }
                    $clients[] = $client;
                }
            }
            $rooms = $global->rooms;
            foreach ($rooms as $room) {
                $members = [];
                foreach ($room['members'] as $member) {
                    $members[] = ['contact' => $member];
                }
                $room['members'] = $members;
                $clients[] = $room;
            }
            $new_message = [ // Send the error response
                'type' => 'clients',
                'content' => base64_encode(gzcompress(json_encode($clients), 9)),
            ];
            Worker::safeEcho("[{$client_id}] Loaded Clients, Request Length:".strlen(json_encode($new_message)).PHP_EOL);
            Gateway::sendToCurrentClient(json_encode($new_message));
        }
        return;
    }


    /**
     * list timers
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgTimers($client_id, $message_data)
    {
        /**
        * @var \GlobalData\Client
        */
        global $global;
        if ($_SESSION['login'] == true && $_SESSION['ima'] == 'admin') {
            $message_data = [
                'type' => 'timers',
                //'channel' => ChannelClient::getStatus(),
                //'status' => GlobalTimer::getStatus(),
            ];
            Gateway::sendToCurrentClient(json_encode($message_data));
            /*
            $sessions = Gateway::getAllClientSessions();
            $clients = [];
            foreach ($sessions as $session_id => $session_data) {
                if (isset($session_data['uid'])) {
                    $client = [
                        'id' => $session_data['uid'],
                        'name' => $session_data['name'],
                        'ima' => $session_data['ima'],
                        'online' => $session_data['online'],
                        'messages' => [],
                    ];
                    if ($session_data['ima'] == 'host') {
                        $client['type'] = $session_data['type'];
                    } else {
                        $client['img'] = $session_data['img'];
                    }
                    $clients[] = $client;
                }
            }
            $rooms = $global->rooms;
            foreach ($rooms as $room) {
                $members = [];
                foreach ($room['members'] as $member) {
                    $members[] = ['contact' => $member];
                }
                $room['members'] = $members;
                $clients[] = $room;
            }
            $new_message = [ // Send the error response
                'type' => 'clients',
                'content' => base64_encode(gzcompress(json_encode($clients), 9)),
            ];
            Worker::safeEcho("[{$client_id}] Loaded Clients, Request Length:".strlen(json_encode($new_message)).PHP_EOL);
            Gateway::sendToCurrentClient(json_encode($new_message));
            */
        }
        return;
    }

    /**
     * handler for when receiving a say message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgSay($client_id, $message_data)
    {
        if ($_SESSION['login'] == true) {
            // client speaks message: {type:say, is: client|room, to:xx, content:xx}
            if (!isset($message_data['to'])) { // illegal request
                throw new \Exception("\$message_data['to'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
            }
            if (!isset($message_data['is'])) { // illegal request
                throw new \Exception("\$message_data['is'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
            }
            if (!isset($message_data['content'])) { // illegal request
                throw new \Exception("\$message_data['content'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
            }
            return self::say($_SESSION['uid'], $message_data['is'], $message_data['to'], $message_data['content'], $_SESSION['name']);
        }
        return;
    }

    /**
     * handler for when receiving a pong message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgPing($client_id, $message_data)
    {
        Gateway::sendToCurrentClient(json_encode(['type' => 'pong']));
        return;
    }
    /**
     * handler for when receiving a pong message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgPong($client_id, $message_data)
    {
        if (empty($_SESSION['login'])) {
            $msg = "[{$client_id}] You have not successfully authenticated within the allowed time, goodbye.";
            Worker::safeEcho($msg.PHP_EOL);
            $new_message = [ // Send the error response
                'type' => 'error',
                'content' => $msg,
            ];
            Gateway::sendToCurrentClient(json_encode($new_message));
            Gateway::closeClient($client_id);
        }
        return;
    }

    /**
     * handler for when receiving a run message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgRunLocal($client_id, $message_data)
    {
        Worker::safeEcho("[{$client_id}] Got Run Command ".json_encode($message_data).PHP_EOL);
        if ($_SESSION['login'] == true) {
            if ($_SESSION['ima'] == 'admin') {
                Worker::safeEcho("[{$client_id}] running command {$message_data['command']}".PHP_EOL);
                return self::run_local($client_id, $message_data['cmd'], $message_data['tag'] ?? '');
            } else {
                Worker::safeEcho("[{$client_id}] ima: {$_SESSION['ima']}".PHP_EOL);
            }
        }
        Worker::safeEcho("[{$client_id}] But not running it".PHP_EOL);
        return;
    }

    /**
     * handler for when receiving a run message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgRun($client_id, $message_data)
    {
        Worker::safeEcho("[{$client_id}] Got Run Command ".json_encode($message_data).PHP_EOL);
        if ($_SESSION['login'] == true) {
            if ($_SESSION['ima'] == 'admin') {
                Worker::safeEcho("[{$client_id}] running command {$message_data['command']}".PHP_EOL);
                return self::run_command($message_data['host'], $message_data['command'], $message_data['interact'] ?? false, $_SESSION['uid'], $message_data['rows'] ?? 80, $message_data['cols'] ?? 24, $message_data['update_after'] ?? false);
            } else {
                Worker::safeEcho("[{$client_id}] ima: {$_SESSION['ima']}".PHP_EOL);
            }
        }
        Worker::safeEcho("[{$client_id}] But not running it".PHP_EOL);
        return;
    }

    /**
     * handler for when receiving a running message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgRunning($client_id, $message_data)
    {
        /**
        * @var \GlobalData\Client
        */
        global $global;
        Worker::safeEcho("[{$client_id}] Got Running Command ".json_encode($message_data).PHP_EOL);
        if ($_SESSION['login'] == true) {
            $id = $message_data['id'];
            $running = $global->running;
            if (!isset($running[$id])) {
                return;
            }
            $run = $running[$id];
            if ($_SESSION['ima'] == 'admin') {
                // stdin to send to host/process
                return Gateway::sendToUid($run['host'], json_encode($message_data));
            } else {
                // stdout or stderr to display
                if (substr($run['for'], 0, 1) == '#') {
                    return Gateway::sendToGroup($run['for'], json_encode($message_data));
                } else {
                    return Gateway::sendToUid($run['for'], json_encode($message_data));
                }
            }
        }
        return;
    }


    /**
     * handler for when receiving a payment process message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgPaymentprocess($client_id, $message_data)
    {
        //Gateway::sendToClient($client_id, json_encode('ok'));
        Gateway::closeClient($client_id, json_encode('ok'));
        self::processing_queue_timer();
    }

    /**
     * handler for when receiving a ran message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgRan($client_id, $message_data)
    {
        /**
        * @var \GlobalData\Client
        */
        global $global;
        //Worker::safeEcho("[{$client_id}] Got Ran Command ".json_encode($message_data).PHP_EOL);
        // indicates both completion of a run process and its final exit code or terminal signal
        // response(s) from a run command
        $id = $message_data['id'];
        $running = $global->running;
        $run = $running[$id];
        $is = substr($run['for'], 0, 1) == '#' ? 'room' : 'client';
        unset($running[$id]);
        $global->running = $running;
        $message = 'Finished Running'.PHP_EOL;
        if (isset($message_data['stdout']) && trim($message_data['stdout']) != '') {
            $message .= PHP_EOL.'StdOut:'.$message_data['stdout'];
        }
        if (isset($message_data['stderr']) && trim($message_data['stderr']) != '') {
            $message .= PHP_EOL.'StdErr:'.$message_data['stderr'];
        }
        if ($message_data['term'] === null) {
            $message .= PHP_EOL.'Exited With Error Code '.$message_data['code'];
        } else {
            $message .= PHP_EOL.'Terminated With Signal '.$message_data['term'];
        }
        return self::say($_SESSION['uid'], $is, $run['for'], $message, $_SESSION['name']);
    }

    /**
     * handler for phpsysinfo proxying betweeen the client and host
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgPhpsysinfo($client_id, $message_data)
    {
        Worker::safeEcho(json_encode($message_data).PHP_EOL);
        if ($_SESSION['login'] == true) {
            if ($_SESSION['ima'] == 'admin') {
                Worker::safeEcho("[{$client_id}] Got phpsysinfo init message ".json_encode($message_data).PHP_EOL);
                $message_data['for'] = $_SESSION['uid']; // add the client 'for' field from session uid
                // stdin to send to host/process
                return Gateway::sendToUid('vps'.$message_data['host'], json_encode($message_data));
            } else {
                Worker::safeEcho("[{$client_id}] Got phpsysinfo response ".json_encode($message_data).PHP_EOL);
                $message_data['host'] = str_replace('vps', '', $_SESSION['uid']); // add the remote servers 'host' field from session uid
                return Gateway::sendToUid($message_data['for'], json_encode($message_data));
            }
        }
        return;
    }

    /**
     * handler for when receiving a login message.
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgLogin($client_id, $message_data)
    {
        /**
        * @var \GlobalData\Client
        */
        global $global;
        $ima = isset($message_data['ima']) && in_array($message_data['ima'], ['host', 'admin']) ? $message_data['ima'] : 'admin';
        //Worker::safeEcho("[{$client_id}] client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} session:".json_encode($_SESSION)." onMessage:".serialize($message).PHP_EOL); // debug
        switch ($ima) {
            case 'host':
                $row = self::$db->select('*')->from('vps_masters')->where('vps_ip= :vps_ip')->bindValues(['vps_ip'=>$_SERVER['REMOTE_ADDR']])->row();
                if ($row === false) {
                    //error
                    $msg = "[{$client_id}] This System {$_SERVER['REMOTE_ADDR']} does not appear to match up with one of our hosts.";
                    Worker::safeEcho($msg.PHP_EOL);
                    $new_message = [ // Send the error response
                        'type' => 'error',
                        'content' => $msg,
                    ];
                    return Gateway::sendToCurrentClient(json_encode($new_message));
                }
                /**
                 * @var \GlobalData\Client
                 */
                global $global;
                $uid = 'vps'.$row['vps_id'];
                $_SESSION['uid'] = $uid;
                $_SESSION['module'] = 'vps';
                $_SESSION['name'] = $row['vps_name'];
                $_SESSION['ima'] = $ima;
                $_SESSION['ip'] = $row['vps_ip'];
                $_SESSION['type'] = $row['vps_type'];
                $_SESSION['online'] = date('Y-m-d H:i:s');
                $_SESSION['login'] = true;
                do {
                    $old_value = $new_value = $global->hosts;
                    $new_value[$row['vps_id']] = $row;
                } while (!$global->cas('hosts', $old_value, $new_value));
                Gateway::setSession($client_id, $_SESSION);
                Gateway::bindUid($client_id, $uid);
                Gateway::joinGroup($client_id, $ima.'s');
                Worker::safeEcho("[{$client_id}] {$row['vps_name']} has been successfully logged in from {$_SERVER['REMOTE_ADDR']}".PHP_EOL);
                $new_message = [ // Send the error response
                    'type' => 'login',
                    'id' => $uid,
                    'self' => false,
                    'ip' => $row['vps_ip'],
                    'img' => $row['vps_type'],
                    'name' => $row['vps_name'],
                    'ima' => $ima,
                    'online' => time(),
                ];
                Gateway::sendToGroup('admins', json_encode($new_message));
                Gateway::sendToClient($client_id, json_encode($new_message));
                break;
            case 'admin':
                if (isset($message_data['session_id'])) {
                    $results = self::$db->query('select accounts.*, account_value from sessions left join accounts on session_owner=accounts.account_id left join accounts_ext on accounts.account_id=accounts_ext.account_id and accounts_ext.account_key="picture" where account_ima="admin" and session_id="'.$message_data['session_id'].'"');
                } else {
                    $results = self::$db->query('select accounts.*, account_value from accounts left join accounts_ext on accounts.account_id=accounts_ext.account_id and accounts_ext.account_key="picture" where account_ima="admin" and account_lid="'.$message_data['username'].'" and account_passwd="'.md5($message_data['password']).'"');
                }
                if (sizeof($results) == 0 || $results[0] === false) {
                    //error
                    $msg = "[{$client_id}] Invalid Credentials Specified For User {$mesage_data['username']}";
                    Worker::safeEcho($msg.PHP_EOL);
                    $new_message = [ // Send the error response
                        'type' => 'error',
                        'content' => $msg,
                    ];
                    return Gateway::sendToCurrentClient(json_encode($new_message));
                }
                $uid = $results[0]['account_id'];
                $_SESSION['uid'] = $uid;
                $_SESSION['name'] = $results[0]['account_lid'];
                $_SESSION['ima'] = $ima;
                $_SESSION['online'] = date('Y-m-d H:i:s');
                $_SESSION['img'] = is_null($results[0]['account_value']) ? 'https://secure.gravatar.com/avatar/'.md5(strtolower(trim($results[0]['account_lid']))).'?s=80&d=identicon&r=x' : $results[0]['account_value'];
                $_SESSION['login'] = true;
                Gateway::setSession($client_id, $_SESSION);
                Gateway::bindUid($client_id, $uid);
                Worker::safeEcho("[{$client_id}] {$results[0]['account_lid']} has been successfully logged in from {$_SERVER['REMOTE_ADDR']}".PHP_EOL);
                $rooms = $global->rooms;
                if (!in_array($uid, $rooms[0]['members'])) {
                    $rooms[0]['members'][] = $uid;
                }
                $global->rooms = $rooms;
                $new_message = [ // Send the error response
                    'type' => 'login',
                    'id' => $uid,
                    'self' => true,
                    'email' => $results[0]['account_lid'],
                    'name' => $results[0]['account_name'],
                    'ima' => $ima,
                    'online' => time(),
                    'img' => is_null($results[0]['account_value']) ? 'https://secure.gravatar.com/avatar/'.md5(strtolower(trim($results[0]['account_lid']))).'?s=80&d=identicon&r=x' : $results[0]['account_value'],
                ];
                Gateway::sendToCurrentClient(json_encode($new_message));
                $new_message['self'] = false;
                Gateway::sendToGroup('admins', json_encode($new_message));
                Gateway::joinGroup($client_id, $ima.'s');
                break;
            case 'client':
            case 'guest':
            default:
                $msg = "[{$client_id}] Invalid Login Type {$ima}. Check back later for \"client\" and \"guest\" support to be added in addition to the \"host\" and \"admin\" types.";
                Worker::safeEcho($msg.PHP_EOL);
                $new_message = [ // Send the error response
                    'type' => 'error',
                    'content' => $msg,
                ];
                Gateway::sendToCurrentClient(json_encode($new_message));
                break;
        }
        return;
    }
}
