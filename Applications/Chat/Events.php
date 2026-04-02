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
     * Create a Workerman MySQL connection using the appropriate host config.
     *
     * @return \Workerman\MySQL\Connection
     */
    public static function createDbConnection()
    {
        $db_config = include '/home/my/include/config/config.db.php';
        global $useMysqlRouter;
        if ($useMysqlRouter === true) {
            return new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
        }
        $host = isset($db_config['db_hosts']) ? $db_config['db_hosts'][count($db_config['db_hosts']) - 1] : $db_config['db_host'];
        return new \Workerman\MySQL\Connection($host, $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
    }

    /**
     * Dispatch a task to the TaskWorker asynchronously.
     *
     * @param string $type task function name
     * @param array $args task arguments
     * @param callable|null $onResult optional callback receiving (string $task_result)
     */
    public static function dispatchTask($type, $args = [], $onResult = null, $onError = null)
    {
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
        $task_connection->send(json_encode(['type' => $type, 'args' => $args]));
        $responded = false;
        $task_connection->onMessage = function ($connection, $task_result) use ($task_connection, $onResult, &$responded) {
            $responded = true;
            if ($onResult) {
                $onResult($task_result);
            }
            $task_connection->close();
        };
        $task_connection->onClose = function ($connection) use ($type, $onError, &$responded) {
            if (!$responded) {
                Worker::safeEcho("TaskWorker connection closed without response for task {$type}".PHP_EOL);
                if ($onError) {
                    $onError();
                }
            }
        };
        $task_connection->onError = function ($connection, $code, $msg) use ($type, $onError, &$responded) {
            Worker::safeEcho("TaskWorker connection error for task {$type}: [{$code}] {$msg}".PHP_EOL);
            if (!$responded && $onError) {
                $responded = true;
                $onError();
            }
        };
        $task_connection->connect();
    }

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
        $global = new \GlobalData\Client(GLOBALDATA_IP.':2207');     // initialize the GlobalData client
        $global->queuein = 0;
        /**
        * @var \Memcached
        */
        global $memcache;
        $memcache = new \Memcached();
        $memcache->addServer('localhost', 11211);
        GlobalTimer::init(GLOBALDATA_IP,'3333');
        $loop = Worker::getEventLoop();
        self::$db = self::createDbConnection();
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
            } elseif (gethostname() == 'myadmin1.interserver.net') {
                $timers['processing_queue_timer'] = GlobalTimer::add(30, ['Events', 'processing_queue_timer'], $args);
                $timers['vps_queue_queue_timer'] = GlobalTimer::add(30, ['Events', 'vps_queue_timer'], $args);
                $timers['memcache_queue_timer'] = GlobalTimer::add(30, ['Events', 'memcache_queue_timer'], $args);
                $timers['map_queue_timer'] = GlobalTimer::add(60, ['Events', 'map_queue_timer'], $args);
                //$timers[] = GlobalTimer::add(60, ['Events', 'queue_queue_timer'], $args);
                //$timer_id = GlobalTimer::add(1, function() use (&$timer_id, $timers) { echo "worker[0] tick timer_id:$timer_id:'".print_r($timers,true)."\n"; });

                $rows = self::$db->select('vps_id')->from('vps_masters')->where('vps_type=11')->query();
                foreach ($rows as $row) {
                    $var = 'vps_host_'.$row['vps_id'];
                    $global->$var = 0;
                }
                $timers['hyperv_update_list_timer'] = GlobalTimer::add(3600, ['Events', 'hyperv_update_list_timer'], $args);
                $timers['hyperv_queue_timer'] = GlobalTimer::add(30, ['Events', 'hyperv_queue_timer'], $args);

                $global->timers = $timers;
                Events::memcache_queue_timer();
                Events::hyperv_update_list_timer();
            } elseif (gethostname() == 'my-web-2.interserver.net') {
                /*
                $timers = $global->timers;
                $global->timers = $timers;
                */
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
        if (!is_array($message_data)) {
            Worker::safeEcho("[{$client_id}] Invalid JSON from {$_SERVER['REMOTE_ADDR']}: ".substr($message, 0, 200).PHP_EOL);
            return;
        }
        if (!isset($message_data['type'])) {
            Worker::safeEcho("[{$client_id}] Got message but no type passed from {$_SERVER['REMOTE_ADDR']}".PHP_EOL);
            return;
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
                    $casRetries = 0;
                    do {
                        $old_value = $new_value = $global->hosts;
                        unset($new_value[$id]);
                        $casRetries++;
                        if ($casRetries > 100) {
                            Worker::safeEcho("[{$client_id}] CAS loop exceeded max retries removing host {$id}".PHP_EOL);
                            break;
                        }
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
                            if ($remove === true) {
                                $casRetries = 0;
                                do {
                                    $old_value = $new_value = $global->running;
                                    foreach ($new_value as $idx => $run) {
                                        if ($run['for'] == $_SESSION['uid']) {
                                            unset($new_value[$idx]);
                                        }
                                    }
                                    $casRetries++;
                                    if ($casRetries > 100) {
                                        Worker::safeEcho("[{$client_id}] CAS loop exceeded max retries cleaning running tasks".PHP_EOL);
                                        break;
                                    }
                                } while (!$global->cas('running', $old_value, $new_value));
                            }
                        }
                    }
                }
            }
        }
    }

    public static function queue_queue_timer()
    {
        Worker::safeEcho('Timer running for '.__METHOD__."\n");
        self::dispatchTask('queue_queue_task');
    }

    public static function map_queue_timer()
    {
        self::dispatchTask('map_queue_task');
    }

    public static function memcache_queue_timer()
    {
        self::dispatchTask('memcached_queue_task');
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
        $var = 'processing_queue';
        $lastVar = $var.'_last';
        if (!isset($global->$var)) {
            $global->$var = 0;
        }
        if ($global->cas($var, 0, time())) {
            $results = self::$db->select('*')->from('queue_log')->where('history_section="process_payment" and history_new_value="pending"')->query();
            Worker::safeEcho("Got Results ".json_encode($results, true)."\n");
            if (is_array($results) && sizeof($results) > 0) {
                self::process_results($results);
            } else {
                self::releaseProcessingLock();
            }
        }
    }

    /**
     * Release the processing queue lock and record last-run time.
     */
    private static function releaseProcessingLock()
    {
        global $global;
        $var = 'processing_queue';
        $lastVar = $var.'_last';
        $global->$lastVar = time();
        $global->$var = 0;
    }

    /**
     * Attempt a DB update with async timer-based retry (non-blocking).
     *
     * @param string $status the history_new_value to set
     * @param int $historyId the history_id to update
     * @param callable $onSuccess called when the update succeeds
     * @param int $try current attempt number
     * @param int $maxTries maximum retries
     */
    private static function dbUpdateWithRetry($status, $historyId, $onSuccess, $try = 0, $maxTries = 30)
    {
        $try++;
        try {
            self::$db->update('queue_log')->cols(['history_new_value' => $status])->where('history_id='.$historyId)->query();
            $onSuccess();
        } catch (\PDOException $e) {
            Worker::safeEcho('['.$try.'/'.$maxTries.'] Got PDO Exception #'.$e->getCode().': "'.$e->getMessage()."\"\n");
            if ($try >= $maxTries) {
                Worker::safeEcho("Max retries reached for history_id={$historyId}, releasing lock\n");
                self::releaseProcessingLock();
                return;
            }
            self::$db = self::createDbConnection();
            Timer::add(1, function () use ($status, $historyId, $onSuccess, $try, $maxTries) {
                self::dbUpdateWithRetry($status, $historyId, $onSuccess, $try, $maxTries);
            }, [], false);
        }
    }

    public static function process_results($results)
    {
        $result = array_shift($results);
        self::dbUpdateWithRetry('processing', $result['history_id'], function () use ($result, $results) {
            Worker::safeEcho("payment processing about to spawn task for ".json_encode($result, true)."\n");
            self::dispatchTask('processing_queue_task', $result, function ($task_result) use ($result, $results) {
                self::dbUpdateWithRetry('completed', $result['history_id'], function () use ($results) {
                    if (count($results) > 0) {
                        self::process_results($results);
                    } else {
                        self::releaseProcessingLock();
                    }
                    Worker::safeEcho("finished queued payment processing task\n");
                });
            }, function () {
                self::releaseProcessingLock();
            });
        });
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
                        $releaseLock = function () use ($var) {
                            global $global;
                            $global->$var = 0;
                        };
                        self::dispatchTask('vps_queue_task', ['id' => $server_id], function ($task_result) use ($server_id, $releaseLock) {
                            $task_result = json_decode($task_result, true);
                            if (trim($task_result['return']) != '') {
                                self::run_command($server_id, $task_result['return'], false, 'room_1', 80, 24, true);
                            }
                            $releaseLock();
                        }, $releaseLock);
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
        Worker::safeEcho("timer starting hyperv update list\n");
        self::dispatchTask('async_hyperv_get_list');
    }

    /**
     * hyperv specific queue timer check
     *
     */
    public static function hyperv_queue_timer()
    {
        self::dispatchTask('sync_hyperv_queue');
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
        self::$running[] = $process;
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
        self::dispatchTask('vps_get_list', [
            'name' => $_SESSION['name'],
            'id' => str_replace('vps', '', $_SESSION['uid']),
            'content' => $message_data['content']
        ]);
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
        self::dispatchTask('vps_update_info', [
            'name' => $_SESSION['name'],
            'id' => str_replace('vps', '', $_SESSION['uid']),
            'content' => $message_data['content']
        ]);
    }

    /**
     * handler for when receiving a get map message
     *
     * @param int $client_id
     * @param array $message_data
     */
    public static function msgGetMap($client_id, $message_data)
    {
        $uid = $_SESSION['uid'];
        $id = str_replace('vps', '', $uid);
        self::dispatchTask('get_map', ['id' => $id], function ($task_result) use ($client_id) {
            $task_result = json_decode($task_result, true);
            Gateway::sendToClient($client_id, json_encode([
                'type' => 'get_map',
                'content' => $task_result
            ]));
        });
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
        self::dispatchTask('bandwidth', [
            'name' => $_SESSION['name'],
            'uid' => $_SESSION['uid'],
            'content' => $message_data['content']
        ]);
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
                    $results = self::$db->select('accounts.*, account_value')
                        ->from('sessions')
                        ->leftJoin('accounts', 'session_owner=accounts.account_id')
                        ->leftJoin('accounts_ext', 'accounts.account_id=accounts_ext.account_id and accounts_ext.account_key="picture"')
                        ->where('account_ima="admin" and session_id= :session_id')
                        ->bindValues(['session_id' => $message_data['session_id']])
                        ->query();
                } else {
                    $results = self::$db->select('accounts.*, account_value')
                        ->from('accounts')
                        ->leftJoin('accounts_ext', 'accounts.account_id=accounts_ext.account_id and accounts_ext.account_key="picture"')
                        ->where('account_ima="admin" and account_lid= :username and account_passwd= :password')
                        ->bindValues(['username' => $message_data['username'], 'password' => md5($message_data['password'])])
                        ->query();
                }
                if (sizeof($results) == 0 || $results[0] === false) {
                    //error
                    $msg = "[{$client_id}] Invalid Credentials Specified For User {$message_data['username']}";
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
