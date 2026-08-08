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

require_once __DIR__.'/Process.php';
require_once __DIR__.'/stdObject.php';
require_once __DIR__.'/FeatureFlags.php';

class Events
{
    /** Dedicated task-worker pool for payment processing, isolated from the
     *  shared 2208 pool so slow VPS/HyperV tasks cannot starve activations. */
    const PAYMENT_TASK_ADDRESS = 'Text://127.0.0.1:2209';

    /** Bounded per-channel hot-cache depth (PROTOCOL_V1.md §4 / plan B6:
     *  last N=100 messages per channel serve channel.join history and the
     *  live tail; the DB (chat_messages) is the unbounded durable store). */
    const CHAT_HISTORY_MAX = 100;

    public static $process_handle = null;
    public static $process_pipes = null;
    public static $db = null;
    public static $running = [];

    /**
     * Optional test seam for hub-originated broadcasts. Null in production, in
     * which case broadcastDcPresence() sends via Gateway::sendToGroup(). When
     * set to a callable by a test it is invoked as ($group, $message) INSTEAD,
     * enabling unit tests to capture broadcasts without a running
     * Gateway/event-loop. Guarded by a strict null check so it never affects
     * the deployed runtime.
     *
     * NOTE (BUG-A3): the production branch used to be
     * \Channel\Client::publish() which was dead — no subscriber, wrong port,
     * and the `channel` service only starts on myadmin1. The seam signature is
     * unchanged so existing tests keep working.
     *
     * @var callable|null
     */
    public static $channelClient = null;

    /**
     * Optional test seam for dispatchTask(). Null in production (the real
     * AsyncTcpConnection path runs unchanged). When set to a callable by a
     * test, dispatchTask() invokes it as
     *   ($type, $args, $onResult, $onError, $address)
     * INSTEAD of opening a TaskWorker connection, so the BusinessWorker-side
     * bridge (queue.* etc.) can be unit-tested without an event loop /
     * running TaskWorker. Guarded by a strict null check so it never affects
     * the deployed runtime.
     *
     * @var callable|null
     */
    public static $taskDispatcher = null;

    /**
     * Batched presence move updates collected over a 50ms sliding window before
     * publishing as a single dc.presence.batch_updated Channel broadcast.
     *
     * @var array<string, array{x:float,z:float,yaw:float,ts:int,uid:int|float,name:string,client_id:int}>
     */
    public static $moveBatch = [];

    /**
     * One-shot timer reference for the batch flush. Null when no flush is pending.
     *
     * @var \Workerman\Timer|null
     */
    public static $moveBatchTimer = null;

    // Note: oneshot timer is intentionally not cleared — worker restarts (~daily) reclaim memory.
    // If long-running workers become a memory concern, add Timer::del($moveBatchTimer) on worker shutdown.

    // ====================================================================
    // DC presence liveness keys (BUG-B3/B4)
    //
    // ONE unambiguous representation, used identically by the `pong` dispatch
    // handler, trackSessionClient()'s duplicate-session prune and
    // setupSessionHealthTimer():
    //
    //   dc_ping:<client_id>      unix ts of the last pong RECEIVED from the
    //                            client. 0/absent == never ponged.
    //   dc_ping_sent:<client_id> unix ts of the last ping the hub SENT to the
    //                            client. 0/absent == never pinged.
    //
    // Staleness is ALWAYS measured from the last pong received; a client that
    // has been pinged but has not yet had time to answer is never dropped
    // (see dcPresenceIsStale()).
    // ====================================================================

    /** GlobalData key prefix: unix ts of the last pong received from a client. */
    const DC_PONG_KEY_PREFIX = 'dc_ping:';

    /** GlobalData key prefix: unix ts of the last ping the hub sent to a client. */
    const DC_PING_SENT_KEY_PREFIX = 'dc_ping_sent:';

    /** Gateway group every dc_presence client is joined to at auth (auth.hello). */
    const DC_PRESENCE_GROUP = 'dc_presence';

    /** Seconds after which a reported dc_viewport entry is treated as absent (BUG-B5). */
    const DC_VIEWPORT_MAX_AGE = 30;

    /**
     * Retry ceiling for a contended shared-index CAS. Generous enough that genuine
     * contention (5 workers x 3 hosts) always wins, low enough that a CAS which can
     * never succeed fails loudly instead of wedging the worker. See casShouldRetry().
     */
    const CAS_MAX_ATTEMPTS = 50;

    /**
     * Seconds of dc_bot_state:<location> heartbeat silence after which a bot owned by
     * ANOTHER datacentered instance is presumed dead and may be taken over. moveBot()
     * refreshes that ts every BOT_MOVE_INTERVAL (0.5s), so this is ~20 missed ticks —
     * long enough that a busy or briefly-stalled peer is never robbed of its bot.
     */
    const BOT_OWNER_HEARTBEAT_MAX_AGE = 10;

    // ====================================================================
    // Bot Presence System (DataCenter 3D)
    // When a real user joins the DC presence session, spawn a simulated bot
    // avatar that walks around the datacenter building.
    // ====================================================================

    /** Default datacenter/location name when no location is specified. */
    const BOT_DEFAULT_LOCATION = 'main';

    /** Bot movement interval in seconds (500ms). */
    const BOT_MOVE_INTERVAL = 0.5;

    /**
     * Bot walking speed in SCENE units per second.
     *
     * dc.js uses UNITS_PER_INCH = 15/70, i.e. 1 scene unit ~= 0.1196 m, so a
     * realistic 1.4 m/s human walk is ~11.7 scene units/sec. The old value of
     * 1.2 was "units/sec" read as metres and made the bot creep at ~0.14 m/s
     * (the client's own walk speed is 14 u/s).
     */
    const BOT_WALK_SPEED = 11.7;

    /**
     * FALLBACK datacenter bounds, used ONLY until the browser reports the real
     * room extents via dc.presence.join `bounds` (contract BOT-BOUNDS). dc.js
     * lays racks out from offsetX/offsetZ = -100 and spawns the player at
     * roomSpawn = {x: cx, z: maxZ - ROOM_MARGIN*0.5}, so the room is nowhere
     * near the world origin and these numbers are only a last resort.
     */
    const BOT_BOUNDS_X_MIN = -50.0;
    const BOT_BOUNDS_X_MAX = 50.0;
    const BOT_BOUNDS_Z_MIN = -50.0;
    const BOT_BOUNDS_Z_MAX = 50.0;

    /** Distance threshold to consider bot has reached its target (units). */
    const BOT_TARGET_THRESHOLD = 1.0;

    /** GlobalData key prefix holding the browser-reported room bounds per location. */
    const DC_ROOM_BOUNDS_KEY_PREFIX = 'dc_room_bounds:';

    /** Spawn the bot within this many scene units of the joining player. */
    const BOT_SPAWN_RADIUS = 25.0;

    /** Pick wander targets within this many scene units of a real player. */
    const BOT_WANDER_RADIUS = 30.0;

    /** Keep the bot this far inside the reported walls so it never clips them. */
    const BOT_BOUNDS_INSET = 2.0;

    /** Reported-bounds sanity limits (contract BOT-BOUNDS validation). */
    const BOT_BOUNDS_MIN_SPAN = 4.0;
    const BOT_BOUNDS_MAX_SPAN = 5000.0;
    const BOT_BOUNDS_MAX_COORD = 100000.0;

    /**
     * Process-local map of location => Workerman timer id for the bot move
     * timer (THE BOT #4). Workerman timer ids are per-PROCESS and there are 5
     * BusinessWorkers, so the id must NEVER be shared through GlobalData —
     * Timer::del() from another process would delete an unrelated timer (e.g.
     * one of the onWorkerStart queue timers). GlobalData only carries the
     * OWNER pid (dc_bot_timer:<location>) so other processes can tell that a
     * bot exists without being able to (mis)delete its timer.
     *
     * @var array<string,int>
     */
    private static $botTimers = [];

    /**
     * Process-local map of session id => Workerman timer id for the 15s
     * duplicate-session prune one-shot armed by trackSessionClient().
     *
     * REVIEW-FIX: exactly the same per-process constraint as self::$botTimers —
     * dc_timer:<sessionId> used to hold the raw timer id and was Timer::del()'d
     * from whichever BusinessWorker happened to receive the next connection for
     * that session, silently destroying an unrelated timer in that process.
     * GlobalData now carries only the owning pid.
     *
     * @var array<string,int>
     */
    private static $sessionPruneTimers = [];

    /**
     * Cached gethostname(), identifying which of the three datacentered instances
     * this process belongs to. See processMarker() / botOwnerAlive().
     *
     * @var string|null
     */
    private static $localHostName = null;

    /**
     * Bot names - randomly selected for variety.
     *
     * @var string[]
     */
    private static $botNames = [
        'Visitor',
        'Guest',
        'Explorer',
        'Traveler',
        'Wanderer',
    ];

    /**
     * Emit a structured JSON log line (JSON Lines format).
     *
     * @param string $event event name (e.g. 'client.connect', 'message.error')
     * @param array $data additional key-value pairs to include in the entry
     */
    public static function logStructured(string $event, array $data = []): void
    {
        $entry = [
            'ts' => date('Y-m-d\TH:i:s.uP'),
            'event' => $event,
            'pid' => getmypid(),
        ] + $data;
        Worker::safeEcho(json_encode($entry) . "\n");
    }

    /**
     * Create a Workerman MySQL connection using the appropriate host config.
     *
     * No explicit reconnect/charset logic is needed here: workerman/mysql auto-reconnects
     * transparently on MySQL "gone away"/"lost connection" errors (2006/2013) and re-applies
     * the 'utf8mb4' charset passed below on every reconnect.
     *
     * @return \Workerman\MySQL\Connection
     */
    // Note: on MySQL outage this retry loop blocks the worker for up to 5 seconds.
    // This is acceptable since workers restart daily and MySQL outages are rare.
    // TODO (optional): implement async retry with non-blocking delay.
    public static function createDbConnection()
    {
        $db_config = include '/home/my/include/config/config.db.php';
        if (!is_array($db_config)) {
            Worker::safeEcho("Events::createDbConnection - config.db.php returned non-array\n");
            return null;
        }
        global $useMysqlRouter;
        $maxTries = 5;
        for ($try = 1; $try <= $maxTries; $try++) {
            try {
                if ($useMysqlRouter === true) {
                    return new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
                }
                $host = isset($db_config['db_hosts']) ? $db_config['db_hosts'][count($db_config['db_hosts']) - 1] : $db_config['db_host'];
                return new \Workerman\MySQL\Connection($host, $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
            } catch (\Throwable $e) {
                Worker::safeEcho("Events::createDbConnection attempt {$try}/{$maxTries} failed: {$e->getMessage()}\n");
                if ($try < $maxTries) {
                    sleep(1);
                }
            }
        }
        Worker::safeEcho("Events::createDbConnection giving up after {$maxTries} attempts\n");
        return null;
    }

    /**
     * Dispatch a task to the TaskWorker asynchronously.
     *
     * @param string $type task function name
     * @param array $args task arguments
     * @param callable|null $onResult optional callback receiving (string $task_result)
     * @param callable|null $onError optional callback when the task connection fails
     * @param string $address task worker address to dispatch to (defaults to the
     *        shared pool on 2208; payment processing uses a dedicated pool on 2209
     *        so a flood of slow VPS/HyperV tasks cannot starve activations)
     */
    public static function dispatchTask($type, $args = [], $onResult = null, $onError = null, $address = 'Text://127.0.0.1:2208')
    {
        if (self::$taskDispatcher !== null) {
            // Test seam only (see $taskDispatcher docblock); null in production.
            (self::$taskDispatcher)($type, $args, $onResult, $onError, $address);
            return;
        }
        // send(false) makes Workerman stopAll() this whole process, so bail on a
        // failed encode rather than letting bad args kill the BusinessWorker.
        $payload = json_encode(['type' => $type, 'args' => $args]);
        if ($payload === false) {
            self::logStructured('task.error', ['type' => $type, 'msg' => 'payload encode failed: '.json_last_error_msg()]);
            if ($onError) {
                $onError();
            }
            return;
        }
        $task_connection = new AsyncTcpConnection($address);
        $task_connection->send($payload);
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
                self::logStructured('task.error', ['type' => $type, 'msg' => 'connection closed without response']);
                if ($onError) {
                    $onError();
                }
            }
        };
        $task_connection->onError = function ($connection, $code, $msg) use ($type, $onError, &$responded) {
            self::logStructured('task.error', ['type' => $type, 'code' => $code, 'msg' => $msg]);
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
        if (!($global instanceof \GlobalData\Client)) {
            Worker::safeEcho("GlobalData client initialization failed\n");
            $global = null;
        }
        if ($global !== null) {
            $global->queuein = 0;
        }
        /**
        * @var \Memcached
        */
        global $memcache;
        $memcache = new \Memcached();
        $memcache->addServer('localhost', 11211);
        self::$db = self::createDbConnection();
        if ($global !== null && $global->add('running', [])) {
            // Fresh GlobalData == a full (cold) restart, not a graceful reload.
            // Clear boardctl jobs orphaned by the restart so reruns aren't blocked.
            self::boardctl_startup_reap();
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
            $global->dc_active_clients = [];
        }
        if ($worker->id == 0) {
            $args = [];
            $timers = [];
            if (gethostname() == 'my.interserver.net') {
            } elseif (gethostname() == 'myadmin1.interserver.net') {
                // Timers are registered only in worker id 0 (guarded above) so each fires
                // exactly once across the BusinessWorker pool; GlobalTimer::add was a thin
                // wrapper around Timer::add and provided no cross-process semantics itself.
                // Registry shape (PROTOCOL_V1.md §2.9, step 2.8): each $global->timers
                // entry is {interval, timer_id} recorded at registration time only —
                // NO callback bodies are touched and scheduling is byte-identical.
                // live last_run tracking is deliberately deferred (safer-minimal option);
                // the only reader is v1 handleAdminTimers (legacy msgTimers ignores it).
                $timers['processing_queue_timer'] = ['interval' => 30, 'timer_id' => Timer::add(30, ['Events', 'processing_queue_timer'], $args)];
                $timers['processing_queue_reaper'] = ['interval' => 120, 'timer_id' => Timer::add(120, ['Events', 'processing_queue_reaper'], $args)];
                $timers['boardctl_queue_timer'] = ['interval' => 15, 'timer_id' => Timer::add(15, function() {
                    try {
                        Events::boardctl_queue_timer();
                    } catch (\Throwable $e) {
                        Worker::safeEcho("boardctl_queue_timer error: {$e->getMessage()}\n");
                    }
                }, $args)];
                $timers['vps_queue_queue_timer'] = ['interval' => 30, 'timer_id' => Timer::add(30, ['Events', 'vps_queue_timer'], $args)];
                $timers['memcache_queue_timer'] = ['interval' => 30, 'timer_id' => Timer::add(30, ['Events', 'memcache_queue_timer'], $args)];
                $timers['map_queue_timer'] = ['interval' => 60, 'timer_id' => Timer::add(60, ['Events', 'map_queue_timer'], $args)];
                //$timers[] = Timer::add(60, ['Events', 'queue_queue_timer'], $args);
                //$timer_id = Timer::add(1, function() use (&$timer_id, $timers) { echo "worker[0] tick timer_id:$timer_id:'".print_r($timers,true)."\n"; });

                $rows = self::$db->select('vps_id')->from('vps_masters')->where('vps_type=11')->query();
                foreach ($rows as $row) {
                    $var = 'vps_host_'.$row['vps_id'];
                    $global->$var = 0;
                }
                $timers['hyperv_update_list_timer'] = ['interval' => 3600, 'timer_id' => Timer::add(3600, ['Events', 'hyperv_update_list_timer'], $args)];
                $timers['hyperv_queue_timer'] = ['interval' => 30, 'timer_id' => Timer::add(30, ['Events', 'hyperv_queue_timer'], $args)];

                // Reaper: clean stale sysinfos entries every 5 minutes (MAJOR-10)
                // Each entry has form: ['host' => $host, 'ts' => timestamp, ...]
                Timer::add(300, function() use ($global) {
                    $cutoff = microtime(true) - 60; // entries older than 60 seconds
                    if (!isset($global->sysinfos)) return;
                    $oldValue = $global->sysinfos;
                    if (!is_array($oldValue)) return;
                    $newValue = $oldValue;
                    $changed = false;
                    foreach ($newValue as $k => $v) {
                        if (is_array($v) && isset($v['ts']) && $v['ts'] < $cutoff) {
                            unset($newValue[$k]);
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        $attempts = 0;
                        do {
                            $oldValue = $global->sysinfos;
                            if (!is_array($oldValue)) break;
                            $newValue = $oldValue;
                            foreach ($oldValue as $k => $v) {
                                if (is_array($v) && isset($v['ts']) && $v['ts'] < $cutoff) {
                                    unset($newValue[$k]);
                                }
                            }
                        } while (!$global->cas('sysinfos', $oldValue, $newValue) && $attempts++ < 5 && usleep(1000));
                        if ($attempts >= 5 && !$global->cas('sysinfos', $oldValue, $newValue)) {
                            Worker::safeEcho("sysinfos_reaper: CAS failed after all retries, stale entries remain\n");
                        }
                    }
                });

                // Reaper: clean stale channel messages entries every 60 seconds (MAJOR-11)
                // Remove channels from channel_msgs_channels if no activity for 1 hour
                Timer::add(60, function() use ($global) {
                    $cutoff = time() - 3600; // 1 hour inactivity threshold
                    if (!isset($global->channel_msgs_channels)) return;
                    $oldChannels = $global->channel_msgs_channels;
                    if (!is_array($oldChannels)) return;
                    $newChannels = [];
                    foreach ($oldChannels as $channel) {
                        $tsKey = 'channel_msgs_ts:' . $channel;
                        $ts = $global->$tsKey;
                        if ($ts !== null && $ts > $cutoff) {
                            $newChannels[] = $channel;
                        } else {
                            // Channel is stale - unset its timestamp key
                            unset($global->$tsKey);
                        }
                    }
                    if (count($newChannels) !== count($oldChannels)) {
                        $attempts = 0;
                        while ($attempts < 5) {
                            if ($global->cas('channel_msgs_channels', $oldChannels, $newChannels)) {
                                break;
                            }
                            $attempts++;
                            usleep(1000);
                        }
                    }
                });

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
     * @param string $client_id
     */
    public static function onConnect($client_id)
    {
        self::logStructured('client.connect', ['client_id' => $client_id]);
    }

    /**
     * When there is news
     * @param string $client_id
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
            self::logStructured('message.error', ['client_id' => $client_id, 'msg' => 'invalid JSON: ' . substr($message, 0, 200)]);
            return;
        }
        if (self::isV1Envelope($message_data)) {
            // Protocol v1 envelope (docs/PROTOCOL_V1.md §1). Additive path beside the
            // legacy {"type":...} dispatch below; gated by Flag A (plan B8) inside
            // dispatchV1() — with the flag OFF the message is inert (no reply).
            self::dispatchV1($client_id, $message_data);
            return;
        }
        if (!isset($message_data['type'])) {
            self::logStructured('message.error', ['client_id' => $client_id, 'msg' => 'no type in message']);
            return;
        }
        $method = 'msg'.str_replace(' ', '', ucwords(str_replace(['-','_','.'], [' ',' ',' '], $message_data['type'])));
        if (method_exists('Events', $method)) {
            call_user_func(['Events', $method], $client_id, $message_data);
        } else {
            self::logStructured('message.error', ['client_id' => $client_id, 'msg' => "method {$method} does not exist"]);
        }
    }

    /**
     * Check whether a decoded message is a protocol v1 request envelope.
     *
     * Per docs/PROTOCOL_V1.md §1 a v1 request carries top-level fields
     * v (int, ==1), id (str), op (str), ts (int) and data (obj, may be {}).
     * Legacy messages dispatch on a top-level "type" key and never carry
     * "op", so the two shapes are disjoint; anything not matching the full
     * v1 shape falls through to the legacy dispatch untouched.
     *
     * NOTE on `data`: the spec (§1) types `data` as an object, but the check
     * below uses is_array(), which is deliberately lenient — a JSON array
     * (e.g. `"data":[]` or `"data":[1,2]`) also passes. This is safe for
     * detection/routing (the only ops wired so far, ping/pong, ignore `data`),
     * but per-op handlers added in later steps MUST validate their own `data`
     * shape rather than assume an associative object here.
     *
     * NOTE on `enc:"gzip"` (§1, wired in step 2.6): when the optional `enc`
     * field is present with value "gzip", `data` is a base64 STRING of the
     * zlib-compressed JSON payload instead of an object, so the shape check
     * also accepts a string `data` in that case. This stays a pure shape
     * detector — no decoding happens here; dispatchV1() decodes via
     * v1DecodeEnvelopeData() before any handler reads `data`.
     *
     * @param mixed $message_data json_decode()d message (assoc array expected)
     * @return bool true only when the message matches the v1 request envelope
     */
    private static function isV1Envelope($message_data)
    {
        return is_array($message_data)
            && isset($message_data['op']) && is_string($message_data['op']) && $message_data['op'] !== ''
            && isset($message_data['v']) && $message_data['v'] === 1
            && isset($message_data['id']) && is_string($message_data['id']) && $message_data['id'] !== ''
            && isset($message_data['ts']) && is_int($message_data['ts'])
            && array_key_exists('data', $message_data)
            && (is_array($message_data['data'])
                || (isset($message_data['enc']) && $message_data['enc'] === 'gzip' && is_string($message_data['data'])));
    }

    /**
     * Decode an envelope's optional `enc:"gzip"` data in place (docs/PROTOCOL_V1.md
     * §1; plan step 2.6). Per §1, when `enc` is present its only legal value is
     * "gzip" and `data` is a base64 string of the zlib-compressed JSON payload
     * (the §0 `b64gz` type — base64_encode(gzcompress(json_encode(...))), the
     * same gzcompress/gzuncompress pairing legacy msgClients and
     * Tasks/memcached_queue_task.php already use).
     *
     * Called by dispatchV1() BEFORE any per-op handler reads $envelope['data'],
     * so handlers always see `data` as a plain decoded array regardless of
     * wire encoding. Plain (unencoded) envelopes pass through untouched —
     * purely additive, fully backward compatible.
     *
     * Returns false on any malformed input (unknown enc value, enc:"gzip" with
     * non-string data, bad base64, bad zlib stream, or decompressed bytes that
     * are not a JSON object/array) — the caller replies bad_request instead of
     * crashing. On success the decoded array replaces $envelope['data'] and
     * `enc` is removed (it described the wire form, which no longer applies).
     *
     * @param array $envelope v1 envelope (modified in place on success)
     * @return bool true when $envelope['data'] is a usable array afterwards
     */
    private static function v1DecodeEnvelopeData(&$envelope)
    {
        if (!isset($envelope['enc'])) {
            // Plain envelope — isV1Envelope() already guaranteed data is an array.
            return is_array($envelope['data']);
        }
        if ($envelope['enc'] !== 'gzip' || !is_string($envelope['data'])) {
            // §1: "gzip" is the ONLY legal enc value, and it requires string data.
            return false;
        }
        $raw = base64_decode($envelope['data'], true);
        if ($raw === false) {
            return false;
        }
        $json = @gzuncompress($raw);
        if ($json === false) {
            return false;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return false;
        }
        $envelope['data'] = $data;
        unset($envelope['enc']);
        return true;
    }

    /**
     * Protocol v1 envelope router (docs/PROTOCOL_V1.md §1–2; plan step 2.1).
     *
     * Gated by Flag A `WS_NEW_HANDLING` (plan B8) via FeatureFlags::useNewHandling():
     * with the flag OFF (the default) v1 envelopes are inert — no business logic
     * runs and no reply is sent, so deploying this router is a runtime no-op.
     * With the flag ON, only the `ping` op is implemented at this step (replied
     * with a v1 pong: {"v":1,"re":"<id>","ok":true,"data":{}}); every other op
     * gets a clean ok:false reply with error.code "not_implemented" so the
     * dispatch skeleton round-trips end-to-end without touching legacy state.
     *
     * The hostId passed to useNewHandling() is null here: there is no
     * authenticated identity yet at this point in the flow, so only the
     * global Flag A default is consulted.
     *
     * Auth gate (step 2.2, retrofitting the 2.1 known gap): per
     * PROTOCOL_V1.md §2.1, `auth.hello` MUST be the first message — any other
     * op received before successful v1 authentication is answered with
     * {ok:false,error:{code:"auth_required"}} and the connection is closed.
     * This applies to `ping` too. v1 auth state is tracked in the GatewayWorker
     * session as $_SESSION['v1_authed'] (set only by handleAuthHello() on
     * success — the same session storage legacy auth uses for 'login').
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope (see isV1Envelope())
     */
    public static function dispatchV1($client_id, $envelope)
    {
        /**
         * REVIEW-FIX (critical): this declaration was MISSING while the `pong`
         * case below writes $global->{dc_ping:<client_id>}. Without it $global
         * is an undefined local, and on PHP 8 "assign property on null" is a
         * fatal Error — so every pong a dc client sent killed the
         * BusinessWorker (Workerman catches the Throwable in the read handler
         * and stopAll()s the process), i.e. a restart per keepalive round.
         * `global` is function-scoped: onMessage()'s declaration does NOT carry
         * into this function.
         *
         * @var \GlobalData\Client
         */
        global $global;
        if (!FeatureFlags::useNewHandling()) {
            // Flag A OFF: new handling is dormant — parse but do not act (plan B8 state 1).
            return;
        }
        $op = $envelope['op'];
        if ($op === 'auth.hello') {
            if (!self::v1DecodeEnvelopeData($envelope)) {
                self::sendV1Error($client_id, $envelope['id'], 'bad_request', 'invalid envelope encoding: enc:"gzip" requires data to be base64(gzcompress(json)) (PROTOCOL_V1 §1)');
                return;
            }
            self::handleAuthHello($client_id, $envelope);
            return;
        }
        if (empty($_SESSION['v1_authed'])) {
            // PROTOCOL_V1.md §2.1 hard rule: any op other than auth.hello before
            // successful auth => error.code "auth_required" + close.
            self::sendV1Error($client_id, $envelope['id'], 'auth_required', 'auth.hello must be the first message; authenticate before sending other ops');
            Gateway::closeClient($client_id);
            return;
        }
        // §1 enc:"gzip" (step 2.6): decode compressed data in place BEFORE any
        // handler reads $envelope['data'] — handlers always see a plain array.
        // Plain envelopes pass through untouched. Notably this is what lets the
        // telemetry.sysinfo reply leg (§2.5: b64gz "expressed as enc:gzip on
        // the envelope") arrive from a host without being dropped.
        if (!self::v1DecodeEnvelopeData($envelope)) {
            self::sendV1Error($client_id, $envelope['id'], 'bad_request', 'invalid envelope encoding: enc:"gzip" requires data to be base64(gzcompress(json)) (PROTOCOL_V1 §1)');
            return;
        }
        switch ($op) {
            // cmd.* streamed command execution (PROTOCOL_V1.md §2.2; plan step 2.3).
            case 'cmd.exec':
                self::handleCmdExec($client_id, $envelope);
                return;
            case 'cmd.stdin':
                self::handleCmdStdin($client_id, $envelope);
                return;
            case 'cmd.output':
                self::handleCmdOutput($client_id, $envelope);
                return;
            case 'cmd.exit':
                self::handleCmdExit($client_id, $envelope);
                return;
            case 'cmd.kill':
                self::handleCmdKill($client_id, $envelope);
                return;
            // pty.* real interactive terminals (PROTOCOL_V1.md §2.3/§5; plan step 2.4).
            case 'pty.open':
                self::handlePtyOpen($client_id, $envelope);
                return;
            case 'pty.data':
                self::handlePtyData($client_id, $envelope);
                return;
            case 'pty.resize':
                self::handlePtyResize($client_id, $envelope);
                return;
            case 'pty.close':
                self::handlePtyClose($client_id, $envelope);
                return;
            // queue.* parity bridge (PROTOCOL_V1.md §2.4; plan step 2.5).
            case 'queue.action':
                self::handleQueueAction($client_id, $envelope);
                return;
            case 'queue.pull':
                self::handleQueuePull($client_id, $envelope);
                return;
            case 'queue.provision':
                self::handleQueueProvision($client_id, $envelope);
                return;
            case 'queue.ack':
                self::handleQueueAck($client_id, $envelope);
                return;
            // telemetry.* host→hub metrics (PROTOCOL_V1.md §2.5; plan step 2.6).
            case 'telemetry.host':
                self::handleTelemetryHost($client_id, $envelope);
                return;
            case 'telemetry.host_extra':
                self::handleTelemetryHostExtra($client_id, $envelope);
                return;
            case 'telemetry.cpu':
                self::handleTelemetryCpu($client_id, $envelope);
                return;
            case 'telemetry.bandwidth':
                self::handleTelemetryBandwidth($client_id, $envelope);
                return;
            case 'telemetry.inventory':
                self::handleTelemetryInventory($client_id, $envelope);
                return;
            case 'telemetry.sysinfo':
                self::handleTelemetrySysinfo($client_id, $envelope);
                return;
            // config.* hub→host configuration (PROTOCOL_V1.md §2.6; plan step 2.6).
            case 'config.maps':
                self::handleConfigMaps($client_id, $envelope);
                return;
            // vps.* service lifecycle callbacks (PROTOCOL_V1.md §2.7; plan step 2.6).
            case 'vps.lock':
                self::handleVpsLock($client_id, $envelope);
                return;
            case 'vps.unlock':
                self::handleVpsUnlock($client_id, $envelope);
                return;
            case 'vps.finished':
                self::handleVpsFinished($client_id, $envelope);
                return;
            case 'vps.progress':
                self::handleVpsProgress($client_id, $envelope);
                return;
            // channel.*/chat.* channels & messaging (PROTOCOL_V1.md §2.10; plan step 2.7).
            case 'channel.list':
                self::handleChannelList($client_id, $envelope);
                return;
            case 'channel.join':
                self::handleChannelJoin($client_id, $envelope);
                return;
            case 'channel.leave':
                self::handleChannelLeave($client_id, $envelope);
                return;
            case 'channel.create':
                self::handleChannelCreate($client_id, $envelope);
                return;
            case 'channel.publish':
                self::handleChannelPublish($client_id, $envelope);
                return;
            case 'chat.send':
                self::handleChatSend($client_id, $envelope);
                return;
            // admin.* admin/CLI introspection (PROTOCOL_V1.md §2.9; plan step 2.8).
            case 'admin.hosts':
                self::handleAdminHosts($client_id, $envelope);
                return;
            case 'admin.timers':
                self::handleAdminTimers($client_id, $envelope);
                return;
            case 'admin.running':
                self::handleAdminRunning($client_id, $envelope);
                return;
            // dc.presence.* datacenter 3D scene presence (dc.md step 7).
            case 'dc.presence.join':
                self::handleDcPresenceJoin($client_id, $envelope);
                return;
            case 'dc.presence.move':
                self::handleDcPresenceMove($client_id, $envelope);
                return;
            case 'dc.presence.leave':
                self::handleDcPresenceLeave($client_id, $envelope);
                return;
            // IDEA-3: dc.viewport.update — client reports its camera position + direction
            case 'dc.viewport.update':
                self::handleDcViewportUpdate($client_id, $envelope);
                return;
            // pong: client responded to a server-initiated health ping.
            // BUG-B3: record the RECEIPT time. The old `= 0` made a correctly
            // answering client look infinitely stale to every prune/watchdog
            // that compares dc_ping against (now - threshold), so answering the
            // health ping was what got you disconnected.
            case 'pong':
                // REVIEW-FIX: guard the null case too — onWorkerStart() sets
                // $global = null when the GlobalData client fails to init, and
                // a dropped pong must never be able to take the worker down.
                if ($global !== null) {
                    $global->{self::DC_PONG_KEY_PREFIX . $client_id} = time();
                }
                return;
            // ping: server responding to a client-initiated ping
            case 'ping':
                Gateway::sendToClient($client_id, json_encode([
                    'v' => 1,
                    'op' => 'pong',
                    'id' => $envelope['id'] ?? null,
                    'ts' => time(),
                    'data' => $envelope['data'] ?? new \stdClass()
                ]));
                return;
        }
        $reply = [
            'v' => 1,
            're' => $envelope['id'],
            'ok' => false,
            'error' => [
                'code' => 'not_implemented',
                'message' => "op '{$op}' not implemented yet"
            ]
        ];
        Gateway::sendToClient($client_id, json_encode($reply));
    }

    /**
     * Send a v1 error reply (docs/PROTOCOL_V1.md §1 reply shape).
     *
     * Note: "auth.error" in docs/AUTH_DESIGN.md diagrams is not a distinct op —
     * it is exactly this general {v,re,ok:false,error:{code,message}} reply to
     * an auth.hello request.
     *
     * @param string $client_id gateway client id
     * @param string $re the request envelope id being answered
     * @param string $code stable machine-readable error code
     * @param string $message human-readable detail
     */
    private static function sendV1Error($client_id, $re, $code, $message)
    {
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ]));
    }

    /**
     * v1 `auth.hello` handler (docs/AUTH_DESIGN.md §§4–5, PROTOCOL_V1.md §2.1;
     * plan step 2.2). Only reachable with Flag A ON (dispatchV1 gates it).
     *
     * Roles:
     *  - host: row fetch from vps_masters (module "vps", default) or qs_masters
     *    (module "quickservers") by primary key, then constant-time token compare
     *    (hash_equals against sha256 of the presented token; the rotation
     *    prev-hash is honored within its grace window), then source-IP
     *    defense-in-depth (hard fail per AUTH_DESIGN §4 step 4).
     *  - bot: same flow against ws_bots (by numeric bot_id or bot_name, optional
     *    "bot:" prefix stripped); bot_enabled=0 => bot_disabled; bot_ip NULL
     *    skips the IP pin.
     *  - admin: data.session validated with exactly the legacy msgLogin
     *    session_id query (sessions LEFT JOIN accounts, account_ima='admin').
     *    The MD5 username/password shape is NOT implemented on this path.
     *
     * On success the GatewayWorker session is populated with the same shape
     * legacy msgLogin sets (uid/module/name/ima/ip/type/online/login) plus
     * 'v1_authed' => true — the flag dispatchV1() checks for the auth_required
     * gate — and 'v1_session', the hub-assigned session token echoed in
     * auth.welcome. That token is a fresh random value (bin2hex(random_bytes(16)))
     * rather than the GatewayWorker client_id, so it is unguessable and stable
     * for the life of the connection; it identifies this authenticated WS
     * session only and grants nothing by itself.
     *
     * Legacy msgLogin is not modified and remains the only auth path whenever
     * Flag A is OFF (and stays available under Flag B regardless).
     *
     * KNOWN ASYMMETRY (test-pinned, non-blocking follow-up — NOT a spec
     * violation): every failure path in THIS method replies via sendV1Error and
     * then calls Gateway::closeClient(). The one exception is the malformed-gzip
     * pre-decode failure for `auth.hello`, which lives upstream in dispatchV1()
     * (the enc:"gzip" v1DecodeEnvelopeData() gate before this handler is called):
     * it replies `bad_request` but does NOT close the connection. This is
     * spec-conformant — §2.1's auto-close-on-error rule is scoped to
     * non-`auth.hello` ops, and `auth.hello` itself has no mandated close — but it
     * is inconsistent with every OTHER `auth.hello` failure path above, which do
     * close. The current behavior is deliberately pinned by
     * tests/EventsV1AuthHelloTest.php::testAuthHelloMalformedGzipRepliesBadRequestButDoesNotClose;
     * unifying the close behavior is a future-cleanup consideration only.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleAuthHello($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $re = $envelope['id'];
        $role = isset($data['role']) && is_string($data['role']) ? $data['role'] : '';
        if (!in_array($role, ['host', 'bot', 'admin'], true)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'auth.hello data.role must be "host", "bot" or "admin"');
            Gateway::closeClient($client_id);
            return;
        }
        if (is_null(self::$db)) {
            self::$db = self::createDbConnection();
            if (is_null(self::$db)) {
                self::sendV1Error($client_id, $re, 'internal', 'authentication backend unavailable');
                Gateway::closeClient($client_id);
                return;
            }
        }

        if ($role === 'admin') {
            // Admin path: validate the mystage session id exactly as legacy
            // msgLogin's session_id branch does (same DB, same query). The MD5
            // username/password branch is deliberately not implemented in v1.
            $session_id = isset($data['session']) && is_string($data['session']) ? $data['session'] : '';
            if ($session_id === '') {
                // AUTH_DESIGN §5: the legacy MD5 username/password shape is not
                // a defined v1 credential — reject it with the distinct
                // machine-readable code so clients know to re-authenticate via
                // a session rather than retrying the same shape.
                if (isset($data['username']) && isset($data['password'])) {
                    self::sendV1Error($client_id, $re, 'unsupported_credential', 'username/password is not supported on the v1 path; role "admin" requires data.session');
                    Gateway::closeClient($client_id);
                    return;
                }
                self::sendV1Error($client_id, $re, 'bad_session', 'auth.hello data.session is required for role "admin"');
                Gateway::closeClient($client_id);
                return;
            }
            try {
                $results = self::$db->select('accounts.*, account_value')
                    ->from('sessions')
                    ->leftJoin('accounts', 'session_owner=accounts.account_id')
                    ->leftJoin('accounts_ext', 'accounts.account_id=accounts_ext.account_id and accounts_ext.account_key="picture"')
                    ->where('account_ima="admin" and session_id= :session_id')
                    ->bindValues(['session_id' => $session_id])
                    ->query();
            } catch (\Throwable $e) {
                Worker::safeEcho("[{$client_id}] auth.hello admin DB error: {$e->getMessage()}".PHP_EOL);
                self::$db = self::createDbConnection();
                self::sendV1Error($client_id, $re, 'internal', 'authentication backend error');
                Gateway::closeClient($client_id);
                return;
            }
            if (!is_array($results) || sizeof($results) == 0 || $results[0] === false) {
                self::sendV1Error($client_id, $re, 'bad_session', 'session not found or not an admin session');
                Gateway::closeClient($client_id);
                return;
            }
            $uid = $results[0]['account_id'];
            // BUG-5: If account has IP-based session limits, validate connecting IP
            if (!empty($results[0]['session_limit'])) {
                $myip = \MyAdmin\Session::get_client_ip();
                $ipAddress = \IPLib\Factory::addressFromString($myip);
                $limits = myadmin_unstringify($results[0]['session_limit']);
                $found = false;
                foreach ($limits as $limit) {
                    if (empty($limit['restrict']) || htmlspecialchars_decode($limit['restrict']) == 'Web & API') {
                        try {
                            $range = strpos($limit['start'], '/') !== false && $limit['start'] == $limit['end']
                                ? \IPLib\Factory::rangeFromString($limit['start'])
                                : \IPLib\Factory::rangeFromBoundaries($limit['start'], $limit['end']);
                            if (!is_null($range) && $range->contains($ipAddress)) {
                                $found = true;
                                break;
                            }
                        } catch (\Exception $e) {
                            Worker::safeEcho("[{$client_id}] BUG-5 IP range check exception: {$e->getMessage()}\n");
                        }
                    }
                }
                if (!$found) {
                    Worker::safeEcho("[{$client_id}] BUG-5 auth.hello admin IP {$myip} not in session_limit ranges\n");
                    self::sendV1Error($client_id, $re, 'ip_not_allowed', 'Your IP is not within the allowed session limits for this account');
                    Gateway::closeClient($client_id);
                    return;
                }
            }
            $hub_session = bin2hex(random_bytes(16));
            $_SESSION['uid'] = $uid;
            $_SESSION['name'] = $results[0]['account_lid'];
            $_SESSION['ima'] = 'admin';
            $_SESSION['online'] = date('Y-m-d H:i:s');
            $_SESSION['img'] = is_null($results[0]['account_value']) ? 'https://secure.gravatar.com/avatar/'.md5(strtolower(trim($results[0]['account_lid']))).'?s=80&d=identicon&r=x' : $results[0]['account_value'];
            $_SESSION['login'] = true;
            $_SESSION['v1_authed'] = true;
            $_SESSION['v1_session'] = $hub_session;
            Gateway::setSession($client_id, $_SESSION);
            // Track client_id → session_id for dc-ws session health & deduplication
            $sessionId = isset($data['session']) && is_string($data['session']) ? $data['session'] : '';
            self::trackSessionClient($client_id, $sessionId);
            Gateway::bindUid($client_id, $uid);
            Gateway::joinGroup($client_id, 'admins');
            Gateway::joinGroup($client_id, 'dc_presence');
            Worker::safeEcho("[{$client_id}] v1 auth.hello: admin {$results[0]['account_lid']} authenticated from {$_SERVER['REMOTE_ADDR']}".PHP_EOL);
            Gateway::sendToClient($client_id, json_encode([
                'v' => 1,
                're' => $re,
                'ok' => true,
                'data' => [
                    'session' => $hub_session,
                    'uid' => $uid,
                    'clientId' => $client_id,
                    'name' => $results[0]['account_lid'] ?? $results[0]['account_id'] ?? 'Unknown',
                    'hub_time' => time()
                ]
            ]));
            return;
        }

        // Host/bot path. NEVER log $data['token'] (AUTH_DESIGN §3 redaction rule).
        $host_id = $data['host_id'] ?? null;
        $token = isset($data['token']) && is_string($data['token']) ? $data['token'] : '';
        $module = isset($data['module']) && $data['module'] === 'quickservers' ? 'quickservers' : 'vps';
        try {
            if ($role === 'bot') {
                // Accept numeric bot_id or bot_name (optional "bot:" prefix).
                $bot_ref = is_string($host_id) && strpos($host_id, 'bot:') === 0 ? substr($host_id, 4) : $host_id;
                if (is_numeric($bot_ref)) {
                    $row = self::$db->select('*')->from('ws_bots')->where('bot_id= :bot_id')->bindValues(['bot_id' => intval($bot_ref)])->row();
                } else {
                    $row = self::$db->select('*')->from('ws_bots')->where('bot_name= :bot_name')->bindValues(['bot_name' => (string) $bot_ref])->row();
                }
                $prefix = 'bot';
                $id_col = 'bot_id';
                $hash_col = 'bot_token_hash';
                $prev_hash_col = 'bot_token_prev_hash';
                $prev_exp_col = 'bot_token_prev_expires';
                $ip_col = 'bot_ip';
                $name_col = 'bot_name';
            } elseif ($module === 'quickservers') {
                $row = self::$db->select('*')->from('qs_masters')->where('qs_id= :qs_id')->bindValues(['qs_id' => intval($host_id)])->row();
                $prefix = 'qs';
                $id_col = 'qs_id';
                $hash_col = 'qs_token_hash';
                $prev_hash_col = 'qs_token_prev_hash';
                $prev_exp_col = 'qs_token_prev_expires';
                $ip_col = 'qs_ip';
                $name_col = 'qs_name';
            } else {
                $row = self::$db->select('*')->from('vps_masters')->where('vps_id= :vps_id')->bindValues(['vps_id' => intval($host_id)])->row();
                $prefix = 'vps';
                $id_col = 'vps_id';
                $hash_col = 'vps_token_hash';
                $prev_hash_col = 'vps_token_prev_hash';
                $prev_exp_col = 'vps_token_prev_expires';
                $ip_col = 'vps_ip';
                $name_col = 'vps_name';
            }
        } catch (\Throwable $e) {
            Worker::safeEcho("[{$client_id}] auth.hello {$role} DB error: {$e->getMessage()}".PHP_EOL);
            self::$db = self::createDbConnection();
            self::sendV1Error($client_id, $re, 'internal', 'authentication backend error');
            Gateway::closeClient($client_id);
            return;
        }
        if ($row === false || !is_array($row)) {
            self::sendV1Error($client_id, $re, 'unknown_host', 'no such '.$role.' identity');
            Gateway::closeClient($client_id);
            return;
        }
        if ($role === 'bot' && isset($row['bot_enabled']) && !intval($row['bot_enabled'])) {
            self::sendV1Error($client_id, $re, 'bot_disabled', 'bot is disabled');
            Gateway::closeClient($client_id);
            return;
        }
        if (!isset($row[$hash_col]) || is_null($row[$hash_col]) || $row[$hash_col] === '') {
            self::sendV1Error($client_id, $re, 'no_token_issued', 'no token has been issued for this identity');
            Gateway::closeClient($client_id);
            return;
        }
        // Constant-time compare (AUTH_DESIGN §4 step 3): current hash, then the
        // rotation prev-hash if still within its grace window.
        $presented_hash = hash('sha256', $token);
        $token_ok = hash_equals($row[$hash_col], $presented_hash);
        if (!$token_ok && !empty($row[$prev_hash_col]) && !empty($row[$prev_exp_col]) && strtotime($row[$prev_exp_col]) > time()) {
            $token_ok = hash_equals($row[$prev_hash_col], $presented_hash);
        }
        if (!$token_ok) {
            self::sendV1Error($client_id, $re, 'bad_token', 'token does not match');
            Gateway::closeClient($client_id);
            return;
        }
        // Source-IP defense in depth (AUTH_DESIGN §4 step 4): hard fail. Only
        // bots with a NULL bot_ip have no IP pin and skip this check; hosts
        // must always have their IP verified, so a host row with an empty
        // stored IP is an anomalous state that hard-fails rather than
        // silently skipping the check.
        // Note: bots intentionally skip IP validation (bot_ip=NULL is expected).
        // If bot IP validation is needed in future, add host-based verification here.
        if ($role !== 'bot' && empty($row[$ip_col])) {
            Worker::safeEcho("[{$client_id}] auth.hello ALERT: {$prefix}{$row[$id_col]} has no registered IP; refusing connection from {$_SERVER['REMOTE_ADDR']}".PHP_EOL);
            self::sendV1Error($client_id, $re, 'ip_mismatch', 'no registered IP for this identity; cannot verify source IP');
            Gateway::closeClient($client_id);
            return;
        }
        if (!empty($row[$ip_col]) && $row[$ip_col] !== $_SERVER['REMOTE_ADDR']) {
            // Operator-visible alert: valid token from the wrong IP smells like token theft.
            Worker::safeEcho("[{$client_id}] auth.hello ALERT: valid token for {$prefix}{$row[$id_col]} presented from {$_SERVER['REMOTE_ADDR']} but registered IP is {$row[$ip_col]}".PHP_EOL);
            self::sendV1Error($client_id, $re, 'ip_mismatch', 'source IP does not match the registered IP for this identity');
            Gateway::closeClient($client_id);
            return;
        }
        // Success: populate the same session shape legacy msgLogin sets so all
        // downstream handling is agnostic to which auth admitted the connection.
        $uid = $prefix.$row[$id_col];
        $hub_session = bin2hex(random_bytes(16));
        $_SESSION['uid'] = $uid;
        $_SESSION['module'] = $role === 'bot' ? 'bot' : $module;
        $_SESSION['name'] = $row[$name_col];
        $_SESSION['ima'] = $role;
        $_SESSION['ip'] = isset($row[$ip_col]) ? $row[$ip_col] : $_SERVER['REMOTE_ADDR'];
        $_SESSION['type'] = isset($row[$prefix.'_type']) ? $row[$prefix.'_type'] : '';
        $_SESSION['online'] = date('Y-m-d H:i:s');
        $_SESSION['login'] = true;
        $_SESSION['v1_authed'] = true;
        $_SESSION['v1_session'] = $hub_session;
        if ($role === 'host' && $module === 'vps') {
            // Same CAS update of the shared hosts map legacy msgLogin performs
            // (keyed by vps_id; qs/bot identities have no legacy equivalent).
            do {
                $old_value = $new_value = $global->hosts;
                $new_value[$row['vps_id']] = $row;
            } while (!$global->cas('hosts', $old_value, $new_value));
        }
        Gateway::setSession($client_id, $_SESSION);
        // Track client_id → session_id for dc-ws session health & deduplication
        $sessionId = isset($data['session']) && is_string($data['session']) ? $data['session'] : '';
        self::trackSessionClient($client_id, $sessionId);
        Gateway::bindUid($client_id, $uid);
        Gateway::joinGroup($client_id, $role.'s');
        Worker::safeEcho("[{$client_id}] v1 auth.hello: {$role} {$_SESSION['name']} ({$uid}) authenticated from {$_SERVER['REMOTE_ADDR']}".PHP_EOL);
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => [
                'session' => $hub_session,
                'host_id' => intval($row[$id_col]),
                'uid' => $uid,
                'clientId' => $client_id,
                'name' => $_SESSION['name'],
                'hub_time' => time(),
                // Minimal stub for this step: real timer scheduling is a later
                // phase; agents treat an empty map as "keep local defaults".
                'timers' => new \stdClass()
            ]
        ]));
    }

    /**
     * Generate an RFC 4122 version-4 UUID for v1 envelope ids.
     *
     * The hub-assigned auth session token (handleAuthHello) is a bare
     * bin2hex(random_bytes(16)) value; envelope ids are specced as uuids
     * (docs/PROTOCOL_V1.md §1), so this formats the same 16 random bytes
     * into the canonical 8-4-4-4-12 form with version/variant bits set.
     *
     * @return string uuid v4, e.g. "1f6f2f0a-9d5e-4c2b-8f3a-0e9d8c7b6a5f"
     */
    private static function v1Uuid()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Build an unsolicited v1 request envelope (docs/PROTOCOL_V1.md §1):
     * fresh id, an op, no re — used for hub-originated relays such as
     * cmd.exec/cmd.stdin/cmd.kill toward a host and cmd.output/cmd.exit
     * toward the originating admin.
     *
     * @param string $op v1 op name (e.g. "cmd.exec")
     * @param array $data op payload
     * @return array envelope ready for json_encode()
     */
    private static function v1Envelope($op, $data)
    {
        return [
            'v' => 1,
            'id' => self::v1Uuid(),
            'op' => $op,
            'ts' => time(),
            'data' => $data
        ];
    }

    /**
     * Broadcast a dc.presence.* event to every client in the `dc_presence`
     * Gateway group.
     *
     * BUG-A3: this used to go out via \Channel\Client::publish('dc_presence',…),
     * which was dead three ways — nothing ever registered a
     * Channel\Client::on('dc_presence') subscriber, publish() auto-connects to
     * the default 127.0.0.1:2206 while start_channel.php binds 0.0.0.0:3333,
     * and start.php only starts the `channel` service on
     * myadmin1.interserver.net. Because AsyncTcpConnection is non-blocking the
     * surrounding try/catch caught nothing and it failed silently forever.
     * Clients are already joined to the `dc_presence` group at auth.hello, and
     * Gateway::sendToGroup() is the mechanism that demonstrably works (chat
     * uses it), so presence uses it too.
     *
     * BUG-B6: the payload is now a full v1 envelope (v/id/op/ts/data) instead
     * of a bare {op,data}. v1Envelope() deliberately does NOT set `ok`, so the
     * browser's `ok === false && error` error short-circuit cannot mistake a
     * presence event for an error reply.
     *
     * The self::$channelClient test seam is still honoured when non-null so
     * unit tests can capture broadcasts without a Gateway/event loop.
     *
     * @param string $op      v1 op name (e.g. "dc.presence.joined")
     * @param array  $data    event payload
     * @param string $context short label used in the failure log line
     */
    private static function broadcastDcPresence($op, array $data, $context = 'dc.presence')
    {
        $payload = json_encode(self::v1Envelope($op, $data));
        if (self::$channelClient !== null) {
            (self::$channelClient)(self::DC_PRESENCE_GROUP, $payload);
            return;
        }
        try {
            Gateway::sendToGroup(self::DC_PRESENCE_GROUP, $payload);
        } catch (\Throwable $e) {
            Worker::safeEcho("{$context}: dc_presence group send failed: {$e->getMessage()}\n");
        }
    }

    /**
     * Decide whether a dc_presence client has stopped answering health pings.
     *
     * Pure function (no globals) so the caller can pass a snapshot taken
     * BEFORE it sends this round's pings — see setupSessionHealthTimer()'s
     * three-phase sweep, whose Phase 2 previously overwrote the very value
     * Phase 3 was about to test (BUG-B4: the 90s watchdog could never fire
     * because the value was never more than 30s old).
     *
     * @param int $lastPong     unix ts of the last pong RECEIVED (0 = never)
     * @param int $lastPingSent unix ts of the last ping SENT (0 = never)
     * @param int $now          current unix ts
     * @param int $threshold    seconds of silence tolerated
     * @return bool true when the client should be dropped
     */
    private static function dcPresenceIsStale(int $lastPong, int $lastPingSent, int $now, int $threshold): bool
    {
        if ($lastPong > 0) {
            return $lastPong < ($now - $threshold);
        }
        // Never ponged: only stale once a ping has been outstanding for longer
        // than the threshold. A client we have never pinged is never stale.
        return $lastPingSent > 0 && $lastPingSent < ($now - $threshold);
    }

    /**
     * v1 `cmd.exec` handler (docs/PROTOCOL_V1.md §2.2; plan step 2.3) —
     * admin-originated C→H, relayed H→A. The v1 counterpart of legacy
     * msgRun/run_command (which are NOT modified and keep serving legacy
     * clients). Only reachable via dispatchV1 (Flag A on + v1-authed).
     *
     * Requires role admin (§3 per-op authorization). Validates the frozen
     * §2.2 field list: run_id (required, UNIQUE uuid — v1 forbids the legacy
     * md5($cmd) collision-prone scheme, so the client must supply it),
     * command (required), interact (default false), rows (default 24 =
     * height→LINES), cols (default 80 = width→COLUMNS), update_after
     * (default false). Note the corrected rows/cols semantics: v1 freezes
     * cols=width default 80 and rows=height default 24, deliberately NOT
     * reproducing legacy run_command()'s swapped defaults ($rows=80,$cols=24).
     *
     * data.host names the target host (int vps_id or "vps<id>" uid); the
     * legacy equivalent is msgRun's message_data['host']. `for` is
     * hub-internal per the spec and MUST NOT be trusted from clients — the
     * originating admin's session uid is always taken from the session
     * ($_SESSION['uid']) and recorded as the run's delivery target.
     *
     * run_id-required + collision-rejection guard: run_id must be a non-empty
     * (trimmed) string, and if that key already names an in-flight registry
     * entry the exec is rejected with bad_request BEFORE any relay or CAS
     * write — overwriting a live entry would hijack the original run's
     * output/exit routing and orphan its process. (Legacy md5($cmd) keys can
     * silently collide; v1 forbids it.)
     *
     * QS LIMITATION: the target host uid is always built as "vps".intval(host),
     * so a QS host that authenticated as "qs<id>" cannot be targeted for a v1
     * cmd run — Gateway::isUidOnline("vps<id>") reports it offline and cmd.exec
     * returns not_online. This is the SAME limitation legacy run_command has
     * (it also keys hosts as "vps<id>"): parity with legacy, NOT a v1
     * regression. Revisit when v1 cmd routing learns the qs uid namespace.
     *
     * Registers the run in the SAME shared $global->running GlobalData
     * registry the legacy path uses (same CAS do/while pattern as
     * run_command), keyed by the unique run_id, so cmd.stdin/output/exit/kill
     * can route and so onClose cleanup + (later, step 2.8) admin.running see
     * v1 runs too. Legacy md5 keys and v1 uuid keys coexist without collision.
     * The entry also carries 'id' (legacy field name for the run id) so
     * pre-existing consumers of registry entries (e.g. onClose's stop_run
     * sweep) read it without notices.
     *
     * Replies {ok:true,data:{run_id}} on dispatch; error not_online when the
     * host uid is not connected.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleCmdExec($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'cmd.exec origination requires role admin');
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $run_id = isset($data['run_id']) && is_string($data['run_id']) ? trim($data['run_id']) : '';
        if ($run_id === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'cmd.exec data.run_id is required (unique uuid per invocation)');
            return;
        }
        $command = isset($data['command']) && is_string($data['command']) ? $data['command'] : '';
        if ($command === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'cmd.exec data.command is required');
            return;
        }
        $host = $data['host'] ?? null;
        if (is_string($host) && substr($host, 0, 3) == 'vps' && is_numeric(substr($host, 3))) {
            $host = substr($host, 3);
        }
        if (!is_numeric($host)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'cmd.exec data.host must be a host id (int vps_id or "vps<id>")');
            return;
        }
        $hostUid = 'vps'.intval($host);
        // Frozen §2.2 defaults: cols = width (80), rows = height (24) — the
        // corrected semantics, NOT the legacy run_command swapped defaults.
        $interact = isset($data['interact']) ? (bool) $data['interact'] : false;
        $rows = isset($data['rows']) && is_numeric($data['rows']) ? intval($data['rows']) : 24;
        $cols = isset($data['cols']) && is_numeric($data['cols']) ? intval($data['cols']) : 80;
        $update_after = isset($data['update_after']) ? (bool) $data['update_after'] : false;
        if (Gateway::isUidOnline($hostUid) != true) {
            self::sendV1Error($client_id, $re, 'not_online', "host {$hostUid} is not online");
            return;
        }
        $entry = [
            'run_id' => $run_id,
            'id' => $run_id, // legacy registry field name; keeps onClose stop_run sweep + shared consumers happy
            'host' => $hostUid,
            'for' => $_SESSION['uid'], // hub-internal routing; never trusted from the client (§2.2)
            'command' => $command,
            'interact' => $interact,
            'update_after' => $update_after,
            'rows' => $rows,
            'cols' => $cols,
            'started' => time(),
            'v' => 1
        ];
        // Reject run_id reuse: overwriting an in-flight registry entry would
        // hijack the original run's output/exit routing and orphan its process.
        $running = $global->running;
        if (is_array($running) && isset($running[$run_id])) {
            self::sendV1Error($client_id, $re, 'bad_request', "cmd.exec data.run_id \"{$run_id}\" is already in use by an in-flight run");
            return;
        }
        // Same CAS read-modify-write loop as legacy run_command; concurrent
        // legacy md5-keyed entries are preserved (whole-map compare-and-swap).
        do {
            $old_value = $new_value = $global->running;
            $new_value[$run_id] = $entry;
        } while (!$global->cas('running', $old_value, $new_value));
        $relay = self::v1Envelope('cmd.exec', [
            'run_id' => $run_id,
            'command' => $command,
            'interact' => $interact,
            'rows' => $rows,
            'cols' => $cols,
            'update_after' => $update_after
        ]);
        Gateway::sendToUid($hostUid, json_encode($relay));
        Worker::safeEcho("[{$client_id}] v1 cmd.exec run {$run_id} dispatched to {$hostUid}".PHP_EOL);
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => ['run_id' => $run_id]
        ]));
    }

    /**
     * v1 `cmd.stdin` handler (docs/PROTOCOL_V1.md §2.2; plan step 2.3) —
     * admin C→H, relayed H→A. The v1 split of the admin-sender half of
     * legacy overloaded msgRunning ({type:"running", id, stdin}).
     *
     * Requires role admin (§3). An unknown run_id is silently ignored,
     * mirroring legacy msgRunning's early return — the common cause is a
     * benign race where the run just exited.
     *
     * ANY-ADMIN LIMITATION: authorization is role-only — ANY admin may inject
     * stdin into ANY run, regardless of who originated it. There is no per-run
     * ownership check against the registry entry's 'for'. This matches legacy
     * msgRunning (role-only) and PROTOCOL_V1 §3 (per-op role auth); it is a
     * deliberate, documented revisit-later item, not an oversight.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleCmdStdin($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'cmd.stdin origination requires role admin');
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $run_id = isset($data['run_id']) && is_string($data['run_id']) ? $data['run_id'] : '';
        if ($run_id === '' || !isset($data['data']) || !is_string($data['data'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'cmd.stdin requires data.run_id and string data.data');
            return;
        }
        $running = $global->running;
        if (!isset($running[$run_id])) {
            // Mirror legacy msgRunning: silently drop input racing a finished run.
            return;
        }
        $relay = self::v1Envelope('cmd.stdin', [
            'run_id' => $run_id,
            'data' => $data['data']
        ]);
        Gateway::sendToUid($running[$run_id]['host'], json_encode($relay));
    }

    /**
     * v1 `cmd.output` handler (docs/PROTOCOL_V1.md §2.2; plan step 2.3) —
     * host A→H, relayed H→C to the run's originating admin. No reply. The v1
     * split of the host-sender half of legacy overloaded msgRunning
     * ({type:"running", id, stdout|stderr}), normalized to stream+data.
     *
     * Comes from role host (§3), and only from the host that owns the run
     * (sender uid must equal the registry entry's 'host'). Relays to the
     * run's hub-internal 'for' target — a uid, or a "#group" per the same
     * prefix convention msgRunning uses. Unknown run_id is silently ignored
     * (output racing exit cleanup).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleCmdOutput($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'host') {
            self::sendV1Error($client_id, $re, 'forbidden', 'cmd.output comes from role host');
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $run_id = isset($data['run_id']) && is_string($data['run_id']) ? $data['run_id'] : '';
        $stream = $data['stream'] ?? '';
        if ($run_id === '' || !in_array($stream, ['stdout', 'stderr'], true) || !isset($data['data']) || !is_string($data['data'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'cmd.output requires data.run_id, data.stream ("stdout"|"stderr") and string data.data');
            return;
        }
        $running = $global->running;
        if (!isset($running[$run_id])) {
            // Output racing the exit cleanup — drop silently, like legacy msgRunning.
            return;
        }
        $run = $running[$run_id];
        if (($_SESSION['uid'] ?? '') !== $run['host']) {
            self::sendV1Error($client_id, $re, 'forbidden', 'sender does not own this run');
            return;
        }
        $relay = self::v1Envelope('cmd.output', [
            'run_id' => $run_id,
            'stream' => $stream,
            'data' => $data['data']
        ]);
        if (substr($run['for'], 0, 1) == '#') {
            Gateway::sendToGroup($run['for'], json_encode($relay));
        } else {
            Gateway::sendToUid($run['for'], json_encode($relay));
        }
    }

    /**
     * v1 `cmd.exit` handler (docs/PROTOCOL_V1.md §2.2; plan step 2.3) —
     * host A→H, relayed H→C. No reply. The v1 counterpart of legacy msgRan,
     * except v1 relays a clean cmd.exit event instead of composing a chat
     * say() summary, then removes the run from the shared registry.
     *
     * Comes from role host (§3) and only from the host owning the run.
     *
     * ⛔ Exit-code invariant (PROTOCOL_V1.md §2.2 / plan E1): `code` and
     * `term` are propagated to the admin EXACTLY as received — no casting,
     * defaulting or remapping — because queue_log completion logic depends on
     * provirted's 0/1 exit codes. Exactly one of code/term is non-null per
     * the spec; the hub forwards whatever the agent reported. Optional
     * trailing stdout/stderr are carried through when present.
     *
     * CAS registry removal: on success the finished run is removed from the
     * shared $global->running registry using the same whole-map CAS
     * read-modify-write loop as the legacy paths (run_command registration /
     * onClose sweep), so concurrent legacy md5-keyed entries are never
     * clobbered — the v1 equivalent of msgRan's unset + write-back, made
     * CAS-safe. A forbidden/unknown-run_id path removes nothing.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleCmdExit($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'host') {
            self::sendV1Error($client_id, $re, 'forbidden', 'cmd.exit comes from role host');
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $run_id = isset($data['run_id']) && is_string($data['run_id']) ? $data['run_id'] : '';
        if ($run_id === '' || !array_key_exists('code', $data) || !array_key_exists('term', $data)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'cmd.exit requires data.run_id, data.code (int|null) and data.term (int|null)');
            return;
        }
        $running = $global->running;
        if (!isset($running[$run_id])) {
            // Already cleaned up (duplicate exit / restart race) — drop silently.
            return;
        }
        $run = $running[$run_id];
        if (($_SESSION['uid'] ?? '') !== $run['host']) {
            self::sendV1Error($client_id, $re, 'forbidden', 'sender does not own this run');
            return;
        }
        // Propagate code/term UNMODIFIED (⛔ exit-code invariant).
        $relayData = [
            'run_id' => $run_id,
            'code' => $data['code'],
            'term' => $data['term']
        ];
        if (isset($data['stdout']) && is_string($data['stdout'])) {
            $relayData['stdout'] = $data['stdout'];
        }
        if (isset($data['stderr']) && is_string($data['stderr'])) {
            $relayData['stderr'] = $data['stderr'];
        }
        $relay = self::v1Envelope('cmd.exit', $relayData);
        if (substr($run['for'], 0, 1) == '#') {
            Gateway::sendToGroup($run['for'], json_encode($relay));
        } else {
            Gateway::sendToUid($run['for'], json_encode($relay));
        }
        // Remove the finished run — CAS loop so concurrent legacy entries survive.
        do {
            $old_value = $new_value = $global->running;
            unset($new_value[$run_id]);
        } while (!$global->cas('running', $old_value, $new_value));
    }

    /**
     * v1 `cmd.kill` handler (docs/PROTOCOL_V1.md §2.2; plan step 2.3) —
     * admin C→H, relayed H→A. The v1 counterpart of legacy
     * {type:"stop_run", id}: the agent closes pipes and terminate(SIGKILL)s.
     *
     * Requires role admin (§3). The registry entry is deliberately NOT
     * removed here — the agent responds to the kill with a cmd.exit, which
     * performs the cleanup, matching the legacy stop_run→ran flow. Unknown
     * run_id is silently ignored (kill racing a natural exit).
     *
     * ANY-ADMIN LIMITATION: authorization is role-only — ANY admin may kill
     * ANY run, regardless of who originated it. There is no per-run ownership
     * check against the registry entry's 'for'. This matches legacy msgRunning
     * (role-only) and PROTOCOL_V1 §3 (per-op role auth); it is a deliberate,
     * documented revisit-later item, not an oversight.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleCmdKill($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'cmd.kill origination requires role admin');
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $run_id = isset($data['run_id']) && is_string($data['run_id']) ? $data['run_id'] : '';
        if ($run_id === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'cmd.kill data.run_id is required');
            return;
        }
        $running = $global->running;
        if (!isset($running[$run_id])) {
            // Kill racing a natural exit — nothing to do.
            return;
        }
        $relay = self::v1Envelope('cmd.kill', ['run_id' => $run_id]);
        Gateway::sendToUid($running[$run_id]['host'], json_encode($relay));
        Worker::safeEcho("[{$client_id}] v1 cmd.kill relayed for run {$run_id} to {$running[$run_id]['host']}".PHP_EOL);
    }

    /**
     * Emit a structured, parseable PTY audit line (PROTOCOL_V1.md §5).
     *
     * Every pty.open/pty.close is logged with session attribution — who,
     * which host, scope, command (for command scope), pty_id, timestamps —
     * as a single clearly-tagged JSON line via process-safe Worker::safeEcho:
     *
     *   pty_audit {"event":"open","pty_id":...,"who":...,"who_name":...,
     *              "host":...,"scope":...,"command":...,"ts":...}
     *
     * The "pty_audit " prefix makes the lines trivially grep/parse-able out
     * of billingd.log. This is already better than today's capability (the
     * legacy admin-gated chat Process.php shell has no structured pty audit
     * at all); a dedicated audit store beyond the log stream is a later step.
     *
     * @param string $event "open" | "close"
     * @param array $fields event-specific attribution fields
     */
    private static function ptyAudit($event, $fields)
    {
        Worker::safeEcho('pty_audit '.json_encode(array_merge(['event' => $event, 'ts' => time()], $fields)).PHP_EOL);
    }

    /**
     * Track a client_id → session_id mapping for dc-ws session health and
     * deduplication. Sends pings to existing clients when a duplicate session
     * connection is detected and schedules a timer to prune non-responsive clients.
     * Used by both admin and host/bot auth paths (MINOR-6 code deduplication).
     *
     * @param string $client_id  gateway client id (20-char hex string — see
     *                           Lib/Context.php, NEVER an int; do not add an
     *                           `int` type hint here, PHP 8 refuses the
     *                           coercion and the BusinessWorker dies)
     * @param string $sessionId  the session identifier
     */
    private static function trackSessionClient($client_id, string $sessionId): void
    {
        global $global;
        if ($sessionId === '') {
            return;
        }
        // REVIEW-FIX (unvalidated client input reaching GlobalData KEYS):
        // $sessionId is auth.hello's data['session'] with nothing but an
        // is_string() check on it, and it is concatenated into the
        // dc_session_clients:<id> / dc_timer:<id> key names. A client could
        // therefore create arbitrarily long (megabyte) or arbitrary-byte keys in
        // the shared store and grow it without bound. Real clients send
        // window.DC_SESSION_ID, a 32-char PHP session id, so constrain the key
        // component to that shape and ignore anything else.
        if (!preg_match('/^[A-Za-z0-9,_.:-]{1,128}$/', $sessionId)) {
            Worker::safeEcho("[dc_presence] rejecting malformed session id from {$client_id}\n");
            return;
        }
        $global->{'dc_client_session:' . $client_id} = $sessionId;
        $listKey = 'dc_session_clients:' . $sessionId;
        $clients = $global->$listKey ?? [];
        if (!is_array($clients)) {
            $clients = [];
        }
        // Filter out any stale (already-closed) client_ids
        $activeClients = [];
        foreach ($clients as $cid) {
            $ck = 'dc_client_session:' . $cid;
            if (($global->$ck ?? null) === $sessionId) {
                $activeClients[] = $cid;
            }
        }
        if (count($activeClients) >= 1) {
            // New connection for an existing session — ping all existing clients.
            // Non-responders (no pong within 15s) will be dropped, keeping only responsive clients.
            // REVIEW-FIX: one timestamp for the whole round, so the prune closure
            // below can judge responsiveness against the ping IT sent.
            $pingedAt = time();
            $pingedClients = $activeClients;
            foreach ($activeClients as $cid) {
                Gateway::sendToClient($cid, json_encode([
                    'v' => 1, 'op' => 'ping', 'id' => 'session_check', 'ts' => $pingedAt,
                    'data' => ['reason' => 'session_duplicate', 'count' => count($activeClients) + 1]
                ]));
                // BUG-B3: record when the ping was SENT under its own key.
                // dc_ping: is reserved for the last pong RECEIVED (see
                // self::DC_PONG_KEY_PREFIX docs) — writing the send time there
                // made answering the ping look identical to never answering it.
                $global->{self::DC_PING_SENT_KEY_PREFIX . $cid} = $pingedAt;
            }
            // Cancel any existing timer for this session to prevent duplicates (MAJOR-5).
            //
            // REVIEW-FIX (same cross-process hazard THE BOT #4 fixed for
            // dc_bot_timer, still live here): dc_timer:<sessionId> used to hold a
            // raw Workerman timer id. Timer ids are PER-PROCESS and a duplicate
            // session connection lands on whichever of the 5 BusinessWorkers the
            // Gateway picked, so Timer::del($idFromAnotherProcess) deleted an
            // unrelated timer in THIS process — including, realistically, this
            // process's bot move timer (which would freeze the bot permanently,
            // since botOwnerAlive() would still report its owner alive) or a
            // pending presence flush. The marker is now the OWNING PID and the
            // real id stays process-local, so a process only ever deletes a timer
            // it created itself.
            $timerOwner = $global->{"dc_timer:".$sessionId} ?? null;
            if (isset(self::$sessionPruneTimers[$sessionId])) {
                \Workerman\Timer::del(self::$sessionPruneTimers[$sessionId]);
                unset(self::$sessionPruneTimers[$sessionId]);
            } elseif ($timerOwner !== null && $timerOwner !== getmypid()) {
                // Another worker's one-shot is still pending; it will re-evaluate
                // the same shared state 15s from ITS arming, so letting it run is
                // harmless — and deleting its id from here is not.
                Worker::safeEcho("[dc_presence] session prune timer for {$sessionId} owned by pid {$timerOwner}; not deleting it from pid ".getmypid()."\n");
            }
            // BUG-B2: Timer::add(float, callable, ?array $args, bool $persistent).
            // The old call passed `false` as $args (a TypeError: bool is never
            // ?array) and left $persistent at its default TRUE, which would have
            // leaked a repeating 15s timer per session. $args must be [].
            self::$sessionPruneTimers[$sessionId] = \Workerman\Timer::add(15, function () use ($sessionId, $global, $pingedAt, $pingedClients) {
                // REVIEW-FIX: the one-shot has fired, so drop both the local id
                // and the shared marker (the latter only if it is still ours).
                // dc_timer:<sessionId> previously survived forever — one permanent
                // GlobalData key per distinct session the hub ever saw twice.
                unset(self::$sessionPruneTimers[$sessionId]);
                if (($global->{"dc_timer:".$sessionId} ?? null) === getmypid()) {
                    unset($global->{"dc_timer:".$sessionId});
                }
                $listKey = 'dc_session_clients:' . $sessionId;
                $clients = $global->$listKey ?? [];
                if (!is_array($clients)) {
                    $clients = [];
                }
                $stillActive = [];
                $toDrop = [];
                foreach ($clients as $cid) {
                    $ck = 'dc_client_session:' . $cid;
                    if (($global->$ck ?? null) !== $sessionId) {
                        continue;
                    }
                    // BUG-B3: responsive == a pong arrived at or after the
                    // session_check ping we sent 15s ago.
                    //
                    // REVIEW-FIX: compare against $pingedAt (the ping THIS closure
                    // sent), not the shared dc_ping_sent: key. The 30s health
                    // timer rewrites dc_ping_sent for every client, so a client
                    // that had answered our session_check could still be seen as
                    // "pong older than last ping sent" purely because the health
                    // timer had pinged it 1s ago — and got closed for it. Clients
                    // we did not ping in this round are never candidates.
                    $lastPong = (int) ($global->{self::DC_PONG_KEY_PREFIX . $cid} ?? 0);
                    if (!in_array($cid, $pingedClients, true) || $lastPong >= $pingedAt) {
                        $stillActive[] = ['cid' => $cid, 'pong' => $lastPong];
                    } else {
                        $toDrop[] = $cid;
                    }
                }
                // Keep at most the 2 most-recently-responsive connections per session.
                usort($stillActive, fn($a, $b) => $b['pong'] <=> $a['pong']);
                foreach (array_slice($stillActive, 2) as $k) {
                    $toDrop[] = $k['cid'];
                }
                foreach ($toDrop as $cid) {
                    $ck = 'dc_client_session:' . $cid;
                    unset($global->$ck);
                    unset($global->{self::DC_PONG_KEY_PREFIX . $cid});
                    unset($global->{self::DC_PING_SENT_KEY_PREFIX . $cid});
                    $clients = $global->$listKey ?? [];
                    $clients = array_values(array_filter($clients, fn($c) => $c !== $cid));
                    $global->$listKey = $clients;
                    Gateway::closeClient($cid, 'session_pruned');
                    Worker::safeEcho("[dc_presence] pruned non-responsive client {$cid} from session {$sessionId}\n");
                }
            }, [], false);
            $global->{"dc_timer:".$sessionId} = getmypid();
        }
        $clients[] = $client_id;
        $global->$listKey = $clients;
    }

    /**
     * v1 `pty.open` handler (docs/PROTOCOL_V1.md §2.3 + §5; plan step 2.4) —
     * admin-originated C→H, relayed H→A. HUB-SIDE relay only: the hub
     * validates/authorizes, tracks the pty session in the SEPARATE
     * $global->ptys registry (decoupled from the cmd $global->running
     * registry), and relays the v1 envelope to the target host. The actual
     * PTY allocation happens on the host (Phase 3 agent). Only reachable via
     * dispatchV1 (Flag A on + v1-authed) — fully dormant with Flag A off.
     *
     * Requires role admin (§3/§5). Frozen §2.3 fields: pty_id (required
     * unique uuid; reuse of an in-flight pty_id is rejected with bad_request
     * — collision guard like cmd.exec), scope (default "command"), command
     * (required when scope=="command"), cols (default 80 = width), rows
     * (default 24 = height), env (optional map — see below).
     *
     * SCOPE GATING (§5, OQ7): scope:"command" runs exactly the supplied
     * command in a PTY and requires the standard admin role. scope:"shell"
     * (full login shell, command absent) requires an ELEVATED role check
     * server-side — a distinct privilege BEYOND ima='admin' — enforced here
     * BEFORE any relay to the agent.
     *
     * SPEC-GAP RESOLUTION (shell elevation): AUTH_DESIGN.md (§5 and the
     * reconciliation notes) does not yet define the concrete elevation
     * privilege for shell scope ("exact role/flag defined with the auth
     * design"). Until that grant exists, this handler takes the CONSERVATIVE
     * DENY posture: it checks an explicit session elevation marker,
     * $_SESSION['pty_shell'] === true, which handleAuthHello() never sets —
     * so scope:"shell" is denied with `forbidden` for ALL current admins by
     * default. This is spec-consistent (shell stays OFF pending a real
     * elevation grant, per §5's "distinct privilege beyond ima='admin'") and
     * does not regress below today (there is no working v1 pty at all today);
     * command-scope terminals work for every admin. Wiring the actual grant
     * (which admins get pty_shell, and how) is a follow-up for the auth
     * design / a later step.
     *
     * ENV HANDLING: §2.3 says env is "allowlisted server-side", but no
     * allowlist policy is defined yet. Safe choice taken: client-supplied
     * env is DROPPED entirely — never relayed to the agent — so arbitrary
     * attacker-controlled environment (LD_PRELOAD, PATH, BASH_ENV, ...)
     * cannot reach the host. TODO: define the env allowlist policy (auth/
     * agent design) and relay only the whitelisted subset once it exists.
     *
     * Reply: {ok:true,data:{pty_id}}. NOTE: §2.3 words the reply as "once
     * allocated on the host"; this hub-side step replies on relay dispatch
     * (exactly like handleCmdExec) because the agent side does not exist
     * until Phase 3 — deferring the reply to an agent alloc-ack is a Phase 3
     * refinement. Errors: forbidden / bad_request / not_online per §1.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handlePtyOpen($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'pty.open origination requires role admin');
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $pty_id = isset($data['pty_id']) && is_string($data['pty_id']) ? trim($data['pty_id']) : '';
        if ($pty_id === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'pty.open data.pty_id is required (unique uuid per terminal)');
            return;
        }
        $scope = isset($data['scope']) && is_string($data['scope']) && $data['scope'] !== '' ? $data['scope'] : 'command';
        if (!in_array($scope, ['command', 'shell'], true)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'pty.open data.scope must be "command" or "shell"');
            return;
        }
        $command = isset($data['command']) && is_string($data['command']) ? $data['command'] : '';
        if ($scope === 'command' && $command === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'pty.open data.command is required when scope is "command"');
            return;
        }
        if ($scope === 'shell') {
            // §5 elevated-role gate — see SPEC-GAP RESOLUTION in the docblock:
            // conservative deny via an explicit session marker that no current
            // auth path sets, so shell scope is OFF for all admins by default.
            if (($_SESSION['pty_shell'] ?? null) !== true) {
                self::ptyAudit('open_denied', [
                    'pty_id' => $pty_id,
                    'who' => $_SESSION['uid'] ?? '',
                    'who_name' => $_SESSION['name'] ?? '',
                    'scope' => 'shell',
                    'reason' => 'shell scope requires elevated privilege (pty_shell) not granted'
                ]);
                self::sendV1Error($client_id, $re, 'forbidden', 'scope "shell" requires an elevated privilege beyond admin, which has not been granted to this session');
                return;
            }
        }
        $host = $data['host'] ?? null;
        if (is_string($host) && substr($host, 0, 3) == 'vps' && is_numeric(substr($host, 3))) {
            $host = substr($host, 3);
        }
        if (!is_numeric($host)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'pty.open data.host must be a host id (int vps_id or "vps<id>")');
            return;
        }
        $hostUid = 'vps'.intval($host);
        // Frozen §2.3 defaults: cols = width (80), rows = height (24).
        $cols = isset($data['cols']) && is_numeric($data['cols']) ? intval($data['cols']) : 80;
        $rows = isset($data['rows']) && is_numeric($data['rows']) ? intval($data['rows']) : 24;
        // env is deliberately NOT read/relayed — see ENV HANDLING in the docblock.
        if (Gateway::isUidOnline($hostUid) != true) {
            self::sendV1Error($client_id, $re, 'not_online', "host {$hostUid} is not online");
            return;
        }
        // Lazily create the separate pty registry (no-op when it already
        // exists); kept out of onWorkerStart so no legacy method is touched.
        $global->add('ptys', []);
        // Collision guard: reuse of an in-flight pty_id would hijack the
        // original session's duplex routing (same rationale as cmd.exec).
        $ptys = $global->ptys;
        if (is_array($ptys) && isset($ptys[$pty_id])) {
            self::sendV1Error($client_id, $re, 'bad_request', "pty.open data.pty_id \"{$pty_id}\" is already in use by an open pty");
            return;
        }
        $entry = [
            'pty_id' => $pty_id,
            'host' => $hostUid,
            'for' => $_SESSION['uid'], // owning admin; hub-internal, never trusted from the client
            'scope' => $scope,
            'command' => $command,
            'cols' => $cols,
            'rows' => $rows,
            'started' => time()
        ];
        // CAS-safe whole-map registration, same pattern as $global->running
        // but in the separate ptys registry so pty and cmd stay decoupled.
        do {
            $old_value = $new_value = $global->ptys;
            if (!is_array($new_value)) {
                $old_value = $new_value = [];
            }
            $new_value[$pty_id] = $entry;
        } while (!$global->cas('ptys', $old_value, $new_value));
        // §5 structured audit: who/host/scope/command/pty_id/timestamp.
        self::ptyAudit('open', [
            'pty_id' => $pty_id,
            'who' => $_SESSION['uid'],
            'who_name' => $_SESSION['name'] ?? '',
            'host' => $hostUid,
            'scope' => $scope,
            'command' => $scope === 'command' ? $command : null
        ]);
        $relayData = [
            'pty_id' => $pty_id,
            'scope' => $scope,
            'cols' => $cols,
            'rows' => $rows
        ];
        if ($scope === 'command') {
            $relayData['command'] = $command;
        }
        Gateway::sendToUid($hostUid, json_encode(self::v1Envelope('pty.open', $relayData)));
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => ['pty_id' => $pty_id]
        ]));
    }

    /**
     * v1 `pty.data` handler (docs/PROTOCOL_V1.md §2.3; plan step 2.4) —
     * full-duplex, any→hub→peer, no reply. data.data is BASE64-encoded raw
     * PTY bytes and is passed through UNMODIFIED (never decoded/re-encoded
     * hub-side — binary-safe relay per §2.3 "always base64").
     *
     * Party validation: the sender must be a party to the pty session —
     * either the owning admin (registry 'for') or the allocated host
     * (registry 'host') — anyone else gets `forbidden`. Admin-side frames
     * relay to the host; host-side frames relay to the owning admin.
     * An unknown pty_id is silently dropped (data racing a close), matching
     * the cmd.output convention.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handlePtyData($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $pty_id = isset($data['pty_id']) && is_string($data['pty_id']) ? $data['pty_id'] : '';
        if ($pty_id === '' || !isset($data['data']) || !is_string($data['data'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'pty.data requires data.pty_id and base64 string data.data');
            return;
        }
        $ptys = $global->ptys;
        if (!is_array($ptys) || !isset($ptys[$pty_id])) {
            // Data racing the close cleanup — drop silently.
            return;
        }
        $pty = $ptys[$pty_id];
        $sender = $_SESSION['uid'] ?? '';
        if ($sender === $pty['for']) {
            $target = $pty['host'];
        } elseif ($sender === $pty['host']) {
            $target = $pty['for'];
        } else {
            self::sendV1Error($client_id, $re, 'forbidden', 'sender is not a party to this pty session');
            return;
        }
        // Base64 payload relayed verbatim — no decode/re-encode.
        Gateway::sendToUid($target, json_encode(self::v1Envelope('pty.data', [
            'pty_id' => $pty_id,
            'data' => $data['data']
        ])));
    }

    /**
     * v1 `pty.resize` handler (docs/PROTOCOL_V1.md §2.3; plan step 2.4) —
     * admin C→H, relayed H→A, no reply. Requires role admin AND that the
     * sender is the pty session's owning admin (registry 'for') — resize is
     * origination-side only, unlike the duplex pty.data. Relays
     * {pty_id,cols,rows} to the allocated host and CAS-updates the registry
     * entry's cols/rows so later introspection reflects the live geometry.
     * Unknown pty_id is silently dropped (resize racing a close).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handlePtyResize($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'pty.resize origination requires role admin');
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $pty_id = isset($data['pty_id']) && is_string($data['pty_id']) ? $data['pty_id'] : '';
        if ($pty_id === '' || !isset($data['cols']) || !is_numeric($data['cols']) || !isset($data['rows']) || !is_numeric($data['rows'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'pty.resize requires data.pty_id, int data.cols and int data.rows');
            return;
        }
        $cols = intval($data['cols']);
        $rows = intval($data['rows']);
        $ptys = $global->ptys;
        if (!is_array($ptys) || !isset($ptys[$pty_id])) {
            // Resize racing the close cleanup — drop silently.
            return;
        }
        $pty = $ptys[$pty_id];
        if (($_SESSION['uid'] ?? '') !== $pty['for']) {
            self::sendV1Error($client_id, $re, 'forbidden', 'only the pty session owner may resize it');
            return;
        }
        Gateway::sendToUid($pty['host'], json_encode(self::v1Envelope('pty.resize', [
            'pty_id' => $pty_id,
            'cols' => $cols,
            'rows' => $rows
        ])));
        // Keep the registry geometry current (CAS-safe whole-map update; the
        // entry may already be gone if a close raced us — that is fine).
        do {
            $old_value = $new_value = $global->ptys;
            if (!is_array($new_value) || !isset($new_value[$pty_id])) {
                break;
            }
            $new_value[$pty_id]['cols'] = $cols;
            $new_value[$pty_id]['rows'] = $rows;
        } while (!$global->cas('ptys', $old_value, $new_value));
    }

    /**
     * v1 `pty.close` handler (docs/PROTOCOL_V1.md §2.3 + §5; plan step 2.4)
     * — any→hub→peer, no reply. Either party (the owning admin 'for' or the
     * allocated host 'host') may close; anyone else gets `forbidden`. The
     * close (with the optional exit `code` when the PTY child exited) is
     * relayed to the OTHER party, the entry is removed from the separate
     * $global->ptys registry via the CAS whole-map loop, and a §5 structured
     * audit line records pty_id / who closed / code / timestamp. Unknown
     * pty_id is silently dropped (duplicate close / restart race).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handlePtyClose($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $pty_id = isset($data['pty_id']) && is_string($data['pty_id']) ? $data['pty_id'] : '';
        if ($pty_id === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'pty.close data.pty_id is required');
            return;
        }
        $ptys = $global->ptys;
        if (!is_array($ptys) || !isset($ptys[$pty_id])) {
            // Already cleaned up (duplicate close / restart race) — drop silently.
            return;
        }
        $pty = $ptys[$pty_id];
        $sender = $_SESSION['uid'] ?? '';
        if ($sender === $pty['for']) {
            $target = $pty['host'];
        } elseif ($sender === $pty['host']) {
            $target = $pty['for'];
        } else {
            self::sendV1Error($client_id, $re, 'forbidden', 'sender is not a party to this pty session');
            return;
        }
        $relayData = ['pty_id' => $pty_id];
        if (array_key_exists('code', $data) && (is_int($data['code']) || is_null($data['code']))) {
            $relayData['code'] = $data['code'];
        }
        Gateway::sendToUid($target, json_encode(self::v1Envelope('pty.close', $relayData)));
        // CAS remove from the separate ptys registry.
        do {
            $old_value = $new_value = $global->ptys;
            if (!is_array($new_value)) {
                break;
            }
            unset($new_value[$pty_id]);
        } while (!$global->cas('ptys', $old_value, $new_value));
        // §5 structured audit: pty_id / who closed / code / timestamp.
        self::ptyAudit('close', [
            'pty_id' => $pty_id,
            'who' => $sender,
            'who_name' => $_SESSION['name'] ?? '',
            'host' => $pty['host'],
            'scope' => $pty['scope'] ?? '',
            'code' => isset($relayData['code']) ? $relayData['code'] : null
        ]);
    }

    /**
     * Resolve and authorize the queue-op identity from the AUTHENTICATED v1
     * session (docs/PROTOCOL_V1.md §2.4 + §3; plan step 2.5).
     *
     * §3: `queue.*` requires role host/bot bound to the matching host_id. The
     * binding is derived EXCLUSIVELY from the authed session ($_SESSION set by
     * handleAuthHello) — never from client-supplied identity fields:
     *  - data.module is required and must be "vps" or "quickservers", AND must
     *    equal the session's module (§2.4: "hub still validates the caller is
     *    that module's registered host"). A bot session carries module "bot",
     *    so bots — which are not bound to any single host_id — never pass the
     *    module match and are rejected with `forbidden`; queue access for bots
     *    needs a real host binding first (deliberate conservative posture).
     *  - the host_id is parsed from the session uid ("vps<id>" / "qs<id>")
     *    that token auth bound, mirroring how HTTP queue.php derives the
     *    master row from REMOTE_ADDR rather than trusting request fields.
     *
     * On failure the appropriate v1 error reply has already been sent and
     * null is returned; on success returns ['module' => str, 'host_id' => int].
     *
     * @param string $client_id gateway client id
     * @param string $re request envelope id being answered
     * @param array $data envelope data payload
     * @return array|null ['module','host_id'] or null after an error reply
     */
    private static function queueBindIdentity($client_id, $re, $data)
    {
        $ima = $_SESSION['ima'] ?? '';
        if (!in_array($ima, ['host', 'bot'], true)) {
            self::sendV1Error($client_id, $re, 'forbidden', 'queue.* requires role host or bot');
            return null;
        }
        $module = $data['module'] ?? null;
        if (!is_string($module) || !in_array($module, ['vps', 'quickservers'], true)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'data.module must be "vps" or "quickservers"');
            return null;
        }
        if (($_SESSION['module'] ?? '') !== $module) {
            // Bots (module "bot") land here too: not bound to a host_id, so no
            // queue access until a host binding exists (§3 conservative deny).
            self::sendV1Error($client_id, $re, 'forbidden', 'caller is not a registered '.$module.' host');
            return null;
        }
        $uid = $_SESSION['uid'] ?? '';
        $prefix = $module === 'quickservers' ? 'qs' : 'vps';
        if (substr($uid, 0, strlen($prefix)) !== $prefix || !is_numeric(substr($uid, strlen($prefix)))) {
            self::sendV1Error($client_id, $re, 'internal', 'authenticated session has no usable host binding');
            return null;
        }
        return ['module' => $module, 'host_id' => intval(substr($uid, strlen($prefix)))];
    }

    /**
     * Dispatch a queue action to the TaskWorker's queue_action executor and
     * reply to the WS client (plan step 2.5 Part B plumbing, shared by
     * queue.action / queue.pull / queue.provision).
     *
     * ARCHITECTURE (approved design): the bridge dispatches to the TaskWorker
     * via Events::dispatchTask() — NEVER inline in the BusinessWorker. The
     * TaskWorker is already bootstrapped with /home/my functions.inc.php +
     * App::db() and already runs vps_queue_handler today; Tasks/queue_action.php
     * re-resolves the master row from the authed host_id and invokes the
     * IDENTICAL vps_queue_handler()/qs_queue_handler() callable HTTP uses, so
     * the reply payload is byte-identical to the HTTP transport (§2.4 / B4).
     * The always-on hub bootstrap stays untouched.
     *
     * The TaskWorker wraps the task's return as {"return":<str>}; the task's
     * own return is the JSON {"ok":bool,"result"|"error":...} documented in
     * Tasks/queue_action.php. $onOk receives the raw handler render() output
     * (string, unmodified) and must send the op-specific reply.
     *
     * @param string $client_id gateway client id
     * @param string $re request envelope id being answered
     * @param string $module "vps" | "quickservers" (validated, session-matched)
     * @param string $action ServiceQueueHandler action (snake_case as HTTP)
     * @param array $wsArgs the §2.4 per-action args object
     * @param int $host_id authed host id (from the session, never the client)
     * @param callable $onOk function (string $result): sends the success reply
     */
    private static function dispatchQueueTask($client_id, $re, $module, $action, $wsArgs, $host_id, $onOk)
    {
        self::dispatchTask('queue_action', [
            'module' => $module,
            'action' => $action,
            'args' => $wsArgs,
            'host_id' => $host_id,
            'uid' => $_SESSION['uid'] ?? ''
        ], function ($task_result) use ($client_id, $re, $onOk) {
            $decoded = json_decode($task_result, true);
            $inner = is_array($decoded) && isset($decoded['return']) && is_string($decoded['return'])
                ? json_decode($decoded['return'], true) : null;
            if (!is_array($inner) || empty($inner['ok'])) {
                $msg = is_array($inner) && isset($inner['error']) && is_string($inner['error'])
                    ? $inner['error'] : 'queue task failed';
                self::sendV1Error($client_id, $re, 'internal', $msg);
                return;
            }
            $onOk(isset($inner['result']) && is_string($inner['result']) ? $inner['result'] : '');
        }, function () use ($client_id, $re) {
            self::sendV1Error($client_id, $re, 'internal', 'queue task dispatch failed');
        });
    }

    /**
     * v1 `queue.action` handler (docs/PROTOCOL_V1.md §2.4; plan step 2.5) —
     * generic ServiceQueueHandler dispatch, A→H (host/bot), request/reply.
     * Only reachable via dispatchV1 (Flag A on + v1-authed) — fully dormant
     * with Flag A off, so deploying it is a runtime no-op (B8 state 1).
     *
     * Frozen §2.4 fields: module (required, must match the authed session —
     * see queueBindIdentity()), action (required, any snake_case
     * ServiceQueueHandler action exactly as HTTP), args (obj — the fields the
     * ResponseHandler reads from $_REQUEST today, same names; defaults to {}).
     *
     * The identity used to resolve the master row is ALWAYS the authed
     * session's host_id; data-level identity is never trusted. Execution
     * happens in the TaskWorker (Tasks/queue_action.php) against the
     * unchanged vps_queue_handler/qs_queue_handler callable — no queue logic
     * lives hub-side (⛔ invariant: legacy HTTP queue paths untouched).
     *
     * VERBATIM-ARG ENCODING (§2.4 AMENDMENT 1): args are injected VERBATIM
     * into the task's $_REQUEST/$_POST and reach the unchanged handlers, which
     * decode unconditionally. So the telemetry-shaped actions (server_info/
     * vps_info, server_info_extra, server_list, cpu_usage, bandwidth) REQUIRE
     * the legacy-encoded string form (base64/json/gzip/html-entity) — NOT a
     * plain object (which would raise a decode TypeError). The plain-obj path
     * for that data is the dedicated telemetry.* ops (§2.5), not queue.action.
     *
     * Reply: {ok:true,data:{result:<raw render() output, unmodified>}}.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleQueueAction($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $bound = self::queueBindIdentity($client_id, $re, $data);
        if ($bound === null) {
            return;
        }
        $action = isset($data['action']) && is_string($data['action']) ? trim($data['action']) : '';
        if ($action === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'queue.action data.action is required');
            return;
        }
        if (isset($data['args']) && !is_array($data['args'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'queue.action data.args must be an object');
            return;
        }
        $wsArgs = isset($data['args']) && is_array($data['args']) ? $data['args'] : [];
        self::dispatchQueueTask($client_id, $re, $bound['module'], $action, $wsArgs, $bound['host_id'], function ($result) use ($client_id, $re) {
            Gateway::sendToClient($client_id, json_encode([
                'v' => 1,
                're' => $re,
                'ok' => true,
                'data' => ['result' => $result]
            ]));
        });
    }

    /**
     * v1 `queue.pull` handler (docs/PROTOCOL_V1.md §2.4; plan step 2.5) —
     * named alias for the hot ServiceQueueHandler action `get_queue` (the SAME
     * action name for both modules: legacy HTTP `get_qs_queue` is only
     * Web/queue.php's POST verb — it too calls the handler with 'get_queue',
     * per Appendix A "get_queue / get_qs_queue → queue.pull"). A→H, role
     * host/bot bound to the matching host_id; only reachable via dispatchV1.
     *
     * Reply data: {jobs: arr}. KNOWN SHAPE DEVIATION (deliberate, documented):
     * §2.4 sketches jobs as [{history_id,command,args:{script}}] per queue_log
     * row, but the reusable GetQueue handler renders ALL pending rows into ONE
     * concatenated script text AND performs the legacy optimistic
     * `<module>queueold` row-flip inside the same render pass. Splitting that
     * output per-job would require forking/reimplementing GetQueue's queue_log
     * query + flip — forbidden by the ⛔ invariant (no queue logic copied, the
     * flip stays exactly where it is today). So queue.pull returns the raw
     * aggregated script byte-identical to the HTTP body, wrapped as a single
     * jobs entry [{history_id:0, command:"get_queue", args:{script:<raw>}}]
     * (history_id 0 = "aggregate, not a single row"), or jobs:[] when the
     * output is empty. Per-job decomposition is a later refactor once GetQueue
     * itself exposes per-row rendering.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleQueuePull($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $bound = self::queueBindIdentity($client_id, $re, $data);
        if ($bound === null) {
            return;
        }
        self::dispatchQueueTask($client_id, $re, $bound['module'], 'get_queue', [], $bound['host_id'], function ($result) use ($client_id, $re) {
            $jobs = [];
            if ($result !== '') {
                // Aggregate wrap — see the shape-deviation note in the docblock.
                $jobs[] = [
                    'history_id' => 0,
                    'command' => 'get_queue',
                    'args' => ['script' => $result]
                ];
            }
            Gateway::sendToClient($client_id, json_encode([
                'v' => 1,
                're' => $re,
                'ok' => true,
                'data' => ['jobs' => $jobs]
            ]));
        });
    }

    /**
     * v1 `queue.provision` handler (docs/PROTOCOL_V1.md §2.4; plan step 2.5)
     * — named alias for get_new_vps (module "vps") / get_new_qs (module
     * "quickservers"), exactly the per-module actions Web/queue.php and the
     * HTTP transport use. A→H, role host/bot bound to the matching host_id;
     * only reachable via dispatchV1 (Flag A on + v1-authed).
     *
     * Reply data: {script: str} — the raw provisioning script text (may be
     * ""), byte-identical to the HTTP response for the same host (§2.4).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleQueueProvision($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $bound = self::queueBindIdentity($client_id, $re, $data);
        if ($bound === null) {
            return;
        }
        $action = $bound['module'] === 'quickservers' ? 'get_new_qs' : 'get_new_vps';
        self::dispatchQueueTask($client_id, $re, $bound['module'], $action, [], $bound['host_id'], function ($result) use ($client_id, $re) {
            Gateway::sendToClient($client_id, json_encode([
                'v' => 1,
                're' => $re,
                'ok' => true,
                'data' => ['script' => $result]
            ]));
        });
    }

    /**
     * v1 `queue.ack` handler (docs/PROTOCOL_V1.md §2.4; plan step 2.5) — NEW
     * in v1 (no legacy equivalent), A→H, role host/bot bound to the matching
     * host_id; only reachable via dispatchV1 (Flag A on + v1-authed).
     *
     * ⛔ ADDITIVE-ONLY TELEMETRY (§2.4 Diff note / critical invariant): there
     * is no explicit ack today — HTTP get_queue flips queue_log rows to
     * `<module>queueold` optimistically at fetch time, and completion is
     * inferred via finished/install_progress callbacks. During dual-running
     * the hub MUST NOT let queue.ack alter that legacy optimistic flip or any
     * queue_log completion logic. This step therefore treats queue.ack as a
     * PURELY LOGGED acknowledgement: it validates the frozen fields
     * (history_id int, status "done"|"failed", output str — may be "") and
     * emits one structured, grep/parse-able line via process-safe safeEcho:
     *
     *   queue_ack {"history_id":..,"status":..,"module":..,"host_id":..,
     *              "who":..,"output_len":..,"ts":..}
     *
     * NO database write of any kind (not even to a new table) — additive-safe
     * is the priority; a durable ack store is a later, separately-reviewed
     * step. The full output body is deliberately NOT logged (only its length)
     * to keep billingd.log sane; agents keep output delivery on the existing
     * channels. Reply: {ok:true} (empty data object).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleQueueAck($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $bound = self::queueBindIdentity($client_id, $re, $data);
        if ($bound === null) {
            return;
        }
        $history_id = isset($data['history_id']) && is_numeric($data['history_id']) ? intval($data['history_id']) : 0;
        if ($history_id <= 0) {
            self::sendV1Error($client_id, $re, 'bad_request', 'queue.ack data.history_id must be a positive int');
            return;
        }
        $status = $data['status'] ?? '';
        if (!in_array($status, ['done', 'failed'], true)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'queue.ack data.status must be "done" or "failed"');
            return;
        }
        if (!isset($data['output']) || !is_string($data['output'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'queue.ack data.output must be a string (may be "")');
            return;
        }
        // Additive telemetry ONLY: structured log line, no DB write, no
        // queue_log/queueold interaction whatsoever (⛔ invariant).
        Worker::safeEcho('queue_ack '.json_encode([
            'history_id' => $history_id,
            'status' => $status,
            'module' => $bound['module'],
            'host_id' => $bound['host_id'],
            'who' => $_SESSION['uid'] ?? '',
            'output_len' => strlen($data['output']),
            'ts' => time()
        ]).PHP_EOL);
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => new \stdClass()
        ]));
    }

    /**
     * Resolve and authorize the telemetry/config identity from the
     * AUTHENTICATED v1 session (docs/PROTOCOL_V1.md §2.5–2.6 + §3; plan step
     * 2.6). The v1 counterpart of how the legacy metric handlers (msgVpsInfo/
     * msgVpsList/msgBandwidth/msgGetMap) derive the host id from
     * $_SESSION['uid'] — identity is NEVER taken from the message payload.
     *
     * Unlike queueBindIdentity(), the §2.5 frozen telemetry field lists carry
     * NO module field, so the module comes exclusively from the authed session
     * too. Role must be host (§3: telemetry/config pulls require role host/bot
     * bound to the matching host_id; bots have no host binding, so they are
     * conservatively denied exactly as queueBindIdentity() does).
     *
     * $requireVpsModule: the plain-obj metric Tasks this step reuses
     * (Tasks/vps_update_info.php, vps_get_list.php, get_map.php, bandwidth.php)
     * resolve their master row from vps_masters / the vps table only — the
     * exact same limitation the legacy WS transport has (msgLogin's host path
     * only queries vps_masters, so legacy WS metric ingestion is vps-only).
     * When true, a quickservers-module session is rejected with `forbidden`
     * (parity with legacy, NOT a regression; qs hosts keep the HTTP transport
     * and the queue.action bridge, which handle qs_masters natively).
     *
     * @param string $client_id gateway client id
     * @param string $re request envelope id being answered
     * @param bool $requireVpsModule reject quickservers sessions (vps-only Tasks)
     * @return array|null ['module','host_id'] or null after an error reply
     */
    private static function telemetryBindIdentity($client_id, $re, $requireVpsModule = false)
    {
        if (($_SESSION['ima'] ?? '') !== 'host') {
            self::sendV1Error($client_id, $re, 'forbidden', 'telemetry.*/config.* ops require role host');
            return null;
        }
        $module = ($_SESSION['module'] ?? '') === 'quickservers' ? 'quickservers' : 'vps';
        if ($requireVpsModule && $module !== 'vps') {
            // Legacy-WS parity: the reused metric Tasks are vps_masters-only.
            self::sendV1Error($client_id, $re, 'forbidden', 'this op is only available to vps-module hosts over WS (quickservers hosts use the HTTP transport or queue.action)');
            return null;
        }
        $uid = $_SESSION['uid'] ?? '';
        $prefix = $module === 'quickservers' ? 'qs' : 'vps';
        if (substr($uid, 0, strlen($prefix)) !== $prefix || !is_numeric(substr($uid, strlen($prefix)))) {
            self::sendV1Error($client_id, $re, 'internal', 'authenticated session has no usable host binding');
            return null;
        }
        return ['module' => $module, 'host_id' => intval(substr($uid, strlen($prefix)))];
    }

    /**
     * v1 `telemetry.host` handler (docs/PROTOCOL_V1.md §2.5; plan step 2.6) —
     * A→H, fire-and-forget (no reply unless error). Replaces legacy WS
     * `vps_info` (msgVpsInfo) / HTTP `server_info`. Only reachable via
     * dispatchV1 (Flag A on + v1-authed) — fully dormant with Flag A off.
     *
     * `data` IS the flat server metrics object (§2.5 field table: load, cores,
     * bits, kernel, ram, cpu_model, cpu_mhz, hdsize, hdfree, iowait, ioping,
     * mounts, drive_type, raid_building, raid_status, optional mem_free +
     * saturation metrics). PLAIN-OBJ PATH (§2.4 AMENDMENT 1): no legacy wire
     * encoding — the object is handed to the unchanged Tasks/vps_update_info.php
     * which passes it as ServiceQueueHandler queueData, and
     * ResponseHandlers/ServerInfo.php's queueData branch reads it directly.
     *
     * CONTENT-SHAPE NOTE (confirmed against both ends): ServerInfo.php reads
     * `queueData['server']` (nested), and the legacy agent
     * (vps_host_server/workerman/src/Tasks/vps_update_info.php) sends
     * `content:{server:<flat obj>}` — so v1's flat `data` is wrapped hub-side
     * as `content = {server: data}`. The host id comes from the authed session
     * only (telemetryBindIdentity), exactly like legacy msgVpsInfo derives it
     * from $_SESSION['uid'].
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleTelemetryHost($client_id, $envelope)
    {
        $re = $envelope['id'];
        $bound = self::telemetryBindIdentity($client_id, $re, true);
        if ($bound === null) {
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        if (empty($data)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.host data must be the non-empty server metrics object (§2.5)');
            return;
        }
        // Same dispatch as legacy msgVpsInfo (unchanged Tasks/vps_update_info.php),
        // with the §2.5 flat obj wrapped into the nested legacy content shape.
        self::dispatchTask('vps_update_info', [
            'id' => $bound['host_id'],
            'content' => ['server' => $data]
        ]);
    }

    /**
     * v1 `telemetry.host_extra` handler (docs/PROTOCOL_V1.md §2.5; plan step
     * 2.6) — A→H, fire-and-forget (no reply unless error). Replaces HTTP
     * `server_info_extra` / `vps_info_extra`. Only reachable via dispatchV1.
     *
     * ROUTE CHOICE (confirmed by reading ResponseHandlers/ServerInfoExtra.php):
     * that handler has NO queueData branch — it ONLY reads
     * $_REQUEST['servers'] (base64_decode → myadmin_unstringify). There is no
     * plain-obj Task for it either, so this op goes through the queue_action
     * $_REQUEST-injection path (dispatchQueueTask → Tasks/queue_action.php →
     * unchanged vps/qs_queue_handler), with the LEGACY ENCODING APPLIED
     * HUB-SIDE per §2.4 AMENDMENT 1: args.servers =
     * base64_encode(json_encode({cpu_flags, speed})) — myadmin_unstringify
     * decodes JSON natively, so this round-trips to the same array the HTTP
     * transport produces. Both modules are supported (queue_action resolves
     * qs_masters natively).
     *
     * Frozen §2.5 fields: cpu_flags (str, required), speed (num, required —
     * NIC link speed, NOT cpu_speed; frozen from ServerInfoExtra.php).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleTelemetryHostExtra($client_id, $envelope)
    {
        $re = $envelope['id'];
        $bound = self::telemetryBindIdentity($client_id, $re);
        if ($bound === null) {
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        if (!isset($data['cpu_flags']) || !is_string($data['cpu_flags'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.host_extra data.cpu_flags must be a string');
            return;
        }
        if (!isset($data['speed']) || !is_numeric($data['speed'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.host_extra data.speed must be numeric');
            return;
        }
        // Hub-side legacy encoding (§2.4 AMENDMENT 1): ServerInfoExtra.php does
        // base64_decode → myadmin_unstringify (json path), no gzuncompress.
        $encoded = base64_encode(json_encode([
            'cpu_flags' => $data['cpu_flags'],
            'speed' => $data['speed']
        ]));
        self::dispatchQueueTask($client_id, $re, $bound['module'], 'server_info_extra', ['servers' => $encoded], $bound['host_id'], function ($result) {
            // Fire-and-forget per §2.5: no success reply. Errors already reply.
        });
    }

    /**
     * v1 `telemetry.cpu` handler (docs/PROTOCOL_V1.md §2.5; plan step 2.6) —
     * A→H, fire-and-forget (no reply unless error). Replaces HTTP `cpu_usage`.
     * Only reachable via dispatchV1 (Flag A on + v1-authed).
     *
     * Frozen §2.5 fields: host (obj, MUST contain cpu:float — the host-level
     * usage), per_vps (map veid→usage obj; may be empty).
     *
     * HOST-AT-INDEX-0 ASSEMBLY (confirmed by reading
     * ResponseHandlers/CpuUsage.php — NOT modified): the handler reads only
     * $_REQUEST['cpu_usage'] (html_entity_decode → myadmin_unstringify) and
     * array_shift()s the FIRST element as the host entry (reading ['cpu']),
     * then treats the remaining keys as veids. So the bridge reassembles the
     * legacy shape as `[0 => host] + per_vps` — the array-union operator keeps
     * the host entry first and preserves per_vps insertion order AND its veid
     * keys (array_merge would renumber numeric veids) — then json_encode()s it
     * (myadmin_unstringify decodes JSON natively; html_entity_decode is a
     * no-op on plain JSON). A per_vps veid of literal 0 would collide with the
     * host slot and is dropped by the union; veid 0 is not a valid service id.
     * Routed via the queue_action $_REQUEST-injection path (no cpu_usage Task
     * exists and CpuUsage.php has no queueData branch). Both modules supported.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleTelemetryCpu($client_id, $envelope)
    {
        $re = $envelope['id'];
        $bound = self::telemetryBindIdentity($client_id, $re);
        if ($bound === null) {
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        if (!isset($data['host']) || !is_array($data['host']) || !isset($data['host']['cpu']) || !is_numeric($data['host']['cpu'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.cpu data.host must be an object containing numeric cpu');
            return;
        }
        if (!isset($data['per_vps']) || !is_array($data['per_vps'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.cpu data.per_vps must be a map of veid to usage object');
            return;
        }
        // Legacy shape reassembly: HOST FIRST at index 0, then per_vps entries
        // in their original order with their veid keys preserved (see docblock).
        $assembled = [0 => $data['host']] + $data['per_vps'];
        self::dispatchQueueTask($client_id, $re, $bound['module'], 'cpu_usage', ['cpu_usage' => json_encode($assembled)], $bound['host_id'], function ($result) {
            // Fire-and-forget per §2.5: no success reply. Errors already reply.
        });
    }

    /**
     * v1 `telemetry.bandwidth` handler (docs/PROTOCOL_V1.md §2.5; plan step
     * 2.6) — A→H, fire-and-forget (no reply unless error). Replaces legacy WS
     * `bandwidth` (msgBandwidth) / HTTP `bandwidth`. Only reachable via
     * dispatchV1 (Flag A on + v1-authed).
     *
     * Frozen §2.5 field: per_ip (map ip → {vps:str veid, in:int, out:int}).
     * PLAIN-OBJ PATH: dispatched to the unchanged Tasks/bandwidth.php exactly
     * like legacy msgBandwidth — {uid:<session uid>, content:<per_ip map>} —
     * which resolves each veid against the vps table and writes the Influx v2
     * `bandwidth` points directly. Deliberately NOT routed through
     * ResponseHandlers/Bandwidth.php (per the step spec): the Task is the WS
     * transport's existing consumer and needs no legacy wire encoding.
     * uid is passed as the full session uid string for byte-parity with
     * msgBandwidth's dispatch; the Task is vps-table-only, hence the
     * vps-module gate (legacy-WS parity).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleTelemetryBandwidth($client_id, $envelope)
    {
        $re = $envelope['id'];
        $bound = self::telemetryBindIdentity($client_id, $re, true);
        if ($bound === null) {
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        if (!isset($data['per_ip']) || !is_array($data['per_ip'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.bandwidth data.per_ip must be a map of ip to {vps,in,out}');
            return;
        }
        // Same dispatch shape as legacy msgBandwidth (unchanged Tasks/bandwidth.php).
        self::dispatchTask('bandwidth', [
            'uid' => $_SESSION['uid'],
            'content' => $data['per_ip']
        ]);
    }

    /**
     * v1 `telemetry.inventory` handler (docs/PROTOCOL_V1.md §2.5; plan step
     * 2.6) — A→H, fire-and-forget (no reply unless error). Replaces legacy WS
     * `vps_list` (msgVpsList) / HTTP `server_list`. Only reachable via
     * dispatchV1 (Flag A on + v1-authed).
     *
     * Frozen §2.5 fields: servers (map veid→server obj), ips (map veid→arr of
     * IPs, first = main), host (obj: bw_usage?/os_info?/cpu_usage? — the
     * host-level pseudo-entry v1 PROMOTES to a sibling key).
     *
     * CONTENT-SHAPE NOTE (confirmed against both ends): the unchanged
     * Tasks/vps_get_list.php passes content as ServiceQueueHandler queueData,
     * and ResponseHandlers/ServerList.php reads `queueData['servers']` and
     * `queueData['ips']` — with the host stats smuggled at `servers[0]` (an
     * entry WITHOUT a veid field; ServerList special-cases index 0 then
     * unset()s it). The legacy agent (vps_host_server/workerman/src/Tasks/
     * vps_get_list.php) builds exactly that: servers[0]['bw_usage'|'os_info'].
     * So the bridge DEMOTES v1's promoted `host` back into the legacy shape:
     * content = {servers: [0 => host] + servers, ips: ips} (array union keeps
     * the host entry at key 0 and preserves the veid keys/order of servers;
     * a literal veid-0 entry would be shadowed — not a valid service id).
     * PLAIN-OBJ PATH: no legacy wire encoding. Identity from the authed
     * session only (vps-module gate = legacy-WS parity; the Task is
     * vps_masters-only).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleTelemetryInventory($client_id, $envelope)
    {
        $re = $envelope['id'];
        $bound = self::telemetryBindIdentity($client_id, $re, true);
        if ($bound === null) {
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        if (!isset($data['servers']) || !is_array($data['servers'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.inventory data.servers must be a map of veid to server object');
            return;
        }
        if (!isset($data['ips']) || !is_array($data['ips'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.inventory data.ips must be a map of veid to IP list');
            return;
        }
        if (!isset($data['host']) || !is_array($data['host'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.inventory data.host must be the host-level stats object (may be empty)');
            return;
        }
        // Demote the promoted host obj back to the legacy servers[0] slot (see docblock).
        self::dispatchTask('vps_get_list', [
            'id' => $bound['host_id'],
            'content' => [
                'servers' => [0 => $data['host']] + $data['servers'],
                'ips' => $data['ips']
            ]
        ]);
    }

    /**
     * v1 `telemetry.sysinfo` handler (docs/PROTOCOL_V1.md §2.5; plan step 2.6)
     * — a THIN RELAY modeled on legacy msgPhpsysinfo, NOT a metric dispatch.
     * Request: admin C→H→A {host, params}; reply: host A→H→C {host, params,
     * data}. Only reachable via dispatchV1 (Flag A on + v1-authed).
     *
     * CORRELATION (per the §2.5 diff note — the legacy `for` field disappears
     * from the wire): the hub relays the request to the host as a fresh
     * envelope (id = relay id) and records {relay id → requesting admin uid +
     * the admin's original envelope id} in the GlobalData `sysinfos` registry
     * (lazily created, CAS-maintained like $global->ptys — BusinessWorker
     * processes are independent, so a process-local map cannot route the
     * reply). The host answers with a request-shaped envelope (op
     * telemetry.sysinfo, its own fresh id) carrying `re` = the relay id; the
     * hub looks the relay id up, forwards a v1 REPLY {re:<admin's original
     * id>, ok:true, data:{...}} to the recorded admin uid, and removes the
     * entry. `data.host` on the reply is overwritten from the authed host
     * session (never trusted from the payload), mirroring how legacy
     * msgPhpsysinfo sets `host` from $_SESSION['uid'] on the response leg.
     *
     * Roles (§2.5: admin-originated): the request leg requires role admin;
     * the reply leg requires role host AND that the sender is the host the
     * relay was addressed to (registry `host`). Unknown/expired relay ids on
     * the reply leg are silently dropped (response racing a restart).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleTelemetrySysinfo($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        $ima = $_SESSION['ima'] ?? '';
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        if ($ima === 'admin') {
            // Request leg: admin → hub → host.
            $host = $data['host'] ?? null;
            if (is_string($host) && substr($host, 0, 3) == 'vps' && is_numeric(substr($host, 3))) {
                $host = substr($host, 3);
            }
            if (!is_numeric($host)) {
                self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.sysinfo data.host must be a host id (int vps_id or "vps<id>")');
                return;
            }
            if (!isset($data['params']) || !is_array($data['params'])) {
                self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.sysinfo data.params must be an object');
                return;
            }
            $hostUid = 'vps'.intval($host);
            if (Gateway::isUidOnline($hostUid) != true) {
                self::sendV1Error($client_id, $re, 'not_online', "host {$hostUid} is not online");
                return;
            }
            $relay = self::v1Envelope('telemetry.sysinfo', [
                'host' => intval($host),
                'params' => $data['params']
            ]);
            // Record the pending request so the host's correlated response can
            // be routed back from ANY BusinessWorker process (CAS whole-map,
            // same pattern as $global->ptys; lazily created).
            // KNOWN FOLLOW-UP (carried forward from step 2.6 review): this
            // registry has NO reaper/expiry — a host that never answers leaks
            // its entry forever and the waiting admin gets no timeout error.
            $global->add('sysinfos', []);
            $entry = [
                'for' => $_SESSION['uid'],
                're' => $re,
                'host' => $hostUid,
                'ts' => time()
            ];
            do {
                $old_value = $new_value = $global->sysinfos;
                if (!is_array($new_value)) {
                    $old_value = $new_value = [];
                }
                $new_value[$relay['id']] = $entry;
            } while (!$global->cas('sysinfos', $old_value, $new_value));
            Gateway::sendToUid($hostUid, json_encode($relay));
            // No immediate reply — the ok reply is sent when the host responds.
            return;
        }
        if ($ima === 'host') {
            // Reply leg: host → hub → requesting admin, correlated by `re`.
            $relayId = isset($envelope['re']) && is_string($envelope['re']) ? $envelope['re'] : '';
            if ($relayId === '') {
                self::sendV1Error($client_id, $re, 'bad_request', 'telemetry.sysinfo responses must set envelope re to the relayed request id');
                return;
            }
            $sysinfos = $global->sysinfos;
            if (!is_array($sysinfos) || !isset($sysinfos[$relayId])) {
                // Response racing a restart/expiry — drop silently.
                return;
            }
            $entry = $sysinfos[$relayId];
            if (($_SESSION['uid'] ?? '') !== $entry['host']) {
                self::sendV1Error($client_id, $re, 'forbidden', 'sender is not the host this sysinfo request was addressed to');
                return;
            }
            $replyData = $data;
            // host comes from the authed session, never the payload (legacy
            // msgPhpsysinfo parity: response leg sets host from $_SESSION['uid']).
            $replyData['host'] = intval(str_replace('vps', '', $_SESSION['uid']));
            do {
                $old_value = $new_value = $global->sysinfos;
                if (!is_array($new_value)) {
                    break;
                }
                unset($new_value[$relayId]);
            } while (!$global->cas('sysinfos', $old_value, $new_value));
            Gateway::sendToUid($entry['for'], json_encode([
                'v' => 1,
                're' => $entry['re'],
                'ok' => true,
                'data' => $replyData
            ]));
            return;
        }
        self::sendV1Error($client_id, $re, 'forbidden', 'telemetry.sysinfo requires role admin (request) or host (response)');
    }

    /**
     * v1 `config.maps` handler (docs/PROTOCOL_V1.md §2.6; plan step 2.6) —
     * host pull: A→H with data:{} (legacy `{type:"get_map"}` from the agent's
     * get_map_timer), replied with the four registry map strings. The v1
     * counterpart of legacy msgGetMap. Only reachable via dispatchV1 (Flag A
     * on + v1-authed).
     *
     * Reply data: {slices, vnc, ips, mainips} — EXACTLY the unchanged
     * Tasks/get_map.php → ResponseHandlers/GetMap.php output, passed through
     * UNTRANSFORMED AND UNTRIMMED.
     *
     * ⛔ BYTE-COMPAT CONTRACT (§2.6 / plan C6 registry gate): the wire value
     * of each key is a "\n"-joined `k:v` line block WITH the trailing "\n"
     * GetMap.php appends per line (slices=`vzid:slices`, vnc=`vzid:vncport`,
     * ips=`mainip:addonip`, mainips=`vzid:mainip`). The HOST applies trim()
     * before writing /root/cpaneldirect/vps.{slicemap,vncmap,ipmap,mainips},
     * so on-disk = trim(wire) = the same lines with NO trailing newline —
     * byte-identical to today. The hub MUST NOT trim (or otherwise touch)
     * these strings; provirted reads the resulting files.
     *
     * Identity from the authed session only (vps-module gate: Tasks/get_map.php
     * resolves vps_masters — legacy-WS parity).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleConfigMaps($client_id, $envelope)
    {
        $re = $envelope['id'];
        $bound = self::telemetryBindIdentity($client_id, $re, true);
        if ($bound === null) {
            return;
        }
        self::dispatchTask('get_map', ['id' => $bound['host_id']], function ($task_result) use ($client_id, $re) {
            // TaskWorker wraps the task return as {"return":<str>}; the task's
            // return is GetMap.php's own json_encode of the four map strings.
            $decoded = json_decode($task_result, true);
            $map = is_array($decoded) && isset($decoded['return']) && is_string($decoded['return'])
                ? json_decode($decoded['return'], true) : null;
            if (!is_array($map) || !isset($map['slices']) || !isset($map['vnc']) || !isset($map['ips']) || !isset($map['mainips'])) {
                self::sendV1Error($client_id, $re, 'internal', 'get_map task returned an unexpected shape');
                return;
            }
            // ⛔ Byte-compat: the four strings pass through UNTRIMMED/untouched.
            Gateway::sendToClient($client_id, json_encode([
                'v' => 1,
                're' => $re,
                'ok' => true,
                'data' => [
                    'slices' => $map['slices'],
                    'vnc' => $map['vnc'],
                    'ips' => $map['ips'],
                    'mainips' => $map['mainips']
                ]
            ]));
        }, function () use ($client_id, $re) {
            self::sendV1Error($client_id, $re, 'internal', 'get_map task dispatch failed');
        });
    }

    /**
     * v1 `vps.lock` handler (docs/PROTOCOL_V1.md §2.7; plan step 2.6) — A→H,
     * fire-and-forget (no reply unless error). Replaces HTTP `lock`. Only
     * reachable via dispatchV1 (Flag A on + v1-authed).
     *
     * Frozen §2.7 fields: module (required — validated against the authed
     * session by queueBindIdentity; session-derived module wins, client value
     * is only accepted when it matches), vps_id (int; the §2.7 diff-note
     * rename of the legacy request field `id` — the bridge maps vps_id→id).
     * Routed via the queue_action $_REQUEST-injection path to the unchanged
     * ResponseHandlers/Lock.php (reads (int)$_REQUEST['id']).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleVpsLock($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $bound = self::queueBindIdentity($client_id, $re, $data);
        if ($bound === null) {
            return;
        }
        $vps_id = isset($data['vps_id']) && is_numeric($data['vps_id']) ? intval($data['vps_id']) : 0;
        if ($vps_id <= 0) {
            self::sendV1Error($client_id, $re, 'bad_request', 'vps.lock data.vps_id must be a positive int');
            return;
        }
        // §2.7 field mapping: vps_id → legacy request field `id`.
        self::dispatchQueueTask($client_id, $re, $bound['module'], 'lock', ['id' => $vps_id], $bound['host_id'], function ($result) {
            // Fire-and-forget per §2.7: no success reply. Errors already reply.
        });
    }

    /**
     * v1 `vps.unlock` handler (docs/PROTOCOL_V1.md §2.7; plan step 2.6) —
     * A→H, fire-and-forget (no reply unless error). Replaces HTTP `unlock`
     * (which also clears restore_status/backup_status — unchanged semantics,
     * it runs the unmodified ResponseHandlers/Unlock.php). Field mapping and
     * routing identical to vps.lock: vps_id → legacy `id`, module validated
     * against the authed session (queueBindIdentity). Only reachable via
     * dispatchV1 (Flag A on + v1-authed).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleVpsUnlock($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $bound = self::queueBindIdentity($client_id, $re, $data);
        if ($bound === null) {
            return;
        }
        $vps_id = isset($data['vps_id']) && is_numeric($data['vps_id']) ? intval($data['vps_id']) : 0;
        if ($vps_id <= 0) {
            self::sendV1Error($client_id, $re, 'bad_request', 'vps.unlock data.vps_id must be a positive int');
            return;
        }
        // §2.7 field mapping: vps_id → legacy request field `id`.
        self::dispatchQueueTask($client_id, $re, $bound['module'], 'unlock', ['id' => $vps_id], $bound['host_id'], function ($result) {
            // Fire-and-forget per §2.7: no success reply. Errors already reply.
        });
    }

    /**
     * v1 `vps.finished` handler (docs/PROTOCOL_V1.md §2.7; plan step 2.6) —
     * A→H, fire-and-forget (no reply unless error). Replaces HTTP `finished`
     * (delete/destroy commands trigger the repeat-invoice deletion in the
     * unmodified ResponseHandlers/Finished.php — unchanged semantics). Only
     * reachable via dispatchV1 (Flag A on + v1-authed).
     *
     * Frozen §2.7 fields: module (session-validated via queueBindIdentity),
     * vps_id (int; §2.7 diff-note rename of the legacy `service` field — the
     * bridge maps vps_id→service), command (str; the completed command).
     * Finished.php reads (int)$_REQUEST['service'] and $_REQUEST['command'].
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleVpsFinished($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $bound = self::queueBindIdentity($client_id, $re, $data);
        if ($bound === null) {
            return;
        }
        $vps_id = isset($data['vps_id']) && is_numeric($data['vps_id']) ? intval($data['vps_id']) : 0;
        if ($vps_id <= 0) {
            self::sendV1Error($client_id, $re, 'bad_request', 'vps.finished data.vps_id must be a positive int');
            return;
        }
        if (!isset($data['command']) || !is_string($data['command']) || $data['command'] === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'vps.finished data.command must be a non-empty string');
            return;
        }
        // §2.7 field mapping: vps_id → legacy request field `service`.
        self::dispatchQueueTask($client_id, $re, $bound['module'], 'finished', ['service' => $vps_id, 'command' => $data['command']], $bound['host_id'], function ($result) {
            // Fire-and-forget per §2.7: no success reply. Errors already reply.
        });
    }

    /**
     * v1 `vps.progress` handler (docs/PROTOCOL_V1.md §2.7; plan step 2.6) —
     * A→H, fire-and-forget (no reply unless error). Replaces HTTP
     * `install_progress`. Only reachable via dispatchV1 (Flag A on +
     * v1-authed).
     *
     * Frozen §2.7 fields: module (session-validated via queueBindIdentity),
     * server (str; vzid or numeric id — prefix stripping happens server-side
     * in the unmodified ResponseHandlers/InstallProgress.php, kept as-is),
     * progress (str; free-form status written to <prefix>_server_status).
     * InstallProgress.php reads $_REQUEST['server'] and $_REQUEST['progress'].
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleVpsProgress($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $bound = self::queueBindIdentity($client_id, $re, $data);
        if ($bound === null) {
            return;
        }
        if (!isset($data['server']) || !is_string($data['server']) || $data['server'] === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'vps.progress data.server must be a non-empty string (vzid or numeric id)');
            return;
        }
        if (!isset($data['progress']) || !is_string($data['progress'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'vps.progress data.progress must be a string');
            return;
        }
        // §2.7: server/progress map 1:1 onto the legacy request fields.
        self::dispatchQueueTask($client_id, $re, $bound['module'], 'install_progress', ['server' => $data['server'], 'progress' => $data['progress']], $bound['host_id'], function ($result) {
            // Fire-and-forget per §2.7: no success reply. Errors already reply.
        });
    }

    /**
     * Validate a v1 channel id (docs/PROTOCOL_V1.md §2.10; plan step 2.7).
     *
     * One channel abstraction `type:name` serves human chat and machine log
     * streaming: chat:noc, host:vps12, job:boardctl:4567, provision:vps1001,
     * dm:<uidA>:<uidB>. Shape enforced here: a lowercase alnum/underscore
     * type, a colon, then one or more [A-Za-z0-9_.:-] name characters, max
     * 191 bytes total (the chat_messages.channel VARCHAR(191) width).
     *
     * @param mixed $channel client-supplied channel id
     * @return bool true only for a well-formed `type:name` id
     */
    private static function chatValidChannelId($channel)
    {
        return is_string($channel)
            && strlen($channel) <= 191
            && preg_match('/^[a-z][a-z0-9_]*:[A-Za-z0-9_.:-]+$/', $channel) === 1;
    }

    /**
     * Per-role channel ACL (docs/PROTOCOL_V1.md §2.10 + §3; plan B6:
     * "channel access gated by role; hosts may only publish to their own
     * host:* / job:* channels"). Identity ALWAYS from the authed v1 session —
     * never from client data.
     *
     * Rules implemented (design decisions for this step, flagged for review):
     *  - dm:* threads are PARTICIPANT-ONLY for every role, admins included —
     *    the session uid must be one of the id's `:` segments. Without this,
     *    channel.list/channel.join (which see dm ids via the hot cache) would
     *    let any admin read other people's DM history.
     *  - admin: any non-dm channel (§3 puts no channel restriction on admins).
     *  - host: its own host channel — `host:<uid>` exactly or any
     *    `host:<uid>:...` subpath — always allowed. `job:*` channels: the
     *    spec grants hosts "their own" job channels, but a job channel id
     *    (e.g. job:boardctl:4567) carries no host binding and the hub has no
     *    job→host registry yet, so ownership is inferred conservatively: a
     *    `job:*` id is allowed only when one of its `:`-separated segments
     *    equals the host's uid (e.g. job:provision:vps12). Anything else —
     *    including other hosts' channels and all chat:* / dm:* ids — is denied.
     *    A real job-registry ownership lookup is a documented follow-up.
     *  - bot: `chat:*` channels only (conservative; the ws_bots.bot_channels
     *    JSON allow-list from the token-auth migration is a documented
     *    follow-up — honoring it requires threading it into the auth session).
     *
     * @param string $channel validated `type:name` channel id
     * @return bool true when the authed session may join/publish this channel
     */
    private static function chatChannelAllowed($channel)
    {
        $ima = $_SESSION['ima'] ?? '';
        $uid = (string) ($_SESSION['uid'] ?? '');
        $type = substr($channel, 0, strpos($channel, ':'));
        if ($type === 'dm') {
            // DM threads are participant-only for EVERY role (see docblock).
            return $uid !== '' && in_array($uid, explode(':', $channel), true);
        }
        if ($ima === 'admin') {
            return true;
        }
        if ($uid === '') {
            return false;
        }
        if ($ima === 'host') {
            if ($channel === 'host:'.$uid || strpos($channel, 'host:'.$uid.':') === 0) {
                return true;
            }
            if ($type === 'job') {
                return in_array($uid, explode(':', $channel), true);
            }
            return false;
        }
        if ($ima === 'bot') {
            return $type === 'chat';
        }
        return false;
    }

    /**
     * Append a message to the bounded per-channel hot cache (docs/
     * PROTOCOL_V1.md §4; plan step 2.7). The cache is the GlobalData
     * `channels` map — channel id → array of §2.10 channel.message objects,
     * capped at the last CHAT_HISTORY_MAX (100) entries — maintained with the
     * same lazily-created + CAS whole-map read-modify-write convention as the
     * $global->ptys/$global->sysinfos registries. This is what serves
     * channel.join history and the live tail WITHOUT re-querying the DB;
     * unlike legacy rooms[0]['messages'] it is bounded and evicts (OQ5).
     *
     * KNOWN SCALABILITY FOLLOW-UP (documented, not addressed this step — more
     * substantive than a routine LOW note):
     *   The per-channel MESSAGE list is capped (CHAT_HISTORY_MAX=100) and
     *   evicts, but the NUMBER of channel KEYS in this single GlobalData map is
     *   NOT capped and there is NO idle eviction. Two growth vectors compound:
     *     (a) Unbounded dm:* key minting. chat.send's DM form (handleChatSend)
     *         does NOT validate the `to` uid for existence/format, so any authed
     *         user can mint an unlimited number of distinct `dm:<me>:<random>`
     *         keys, each of which lands here as a permanent map entry (and a
     *         chat_messages row) — a cheap way to inflate the map indefinitely.
     *     (b) CAS round-trip cost. Every append (and thus every channel.publish
     *         / chat.send at "chat"/"info"/"warn"/"error" level, and every
     *         log-level fan-out) reads and CAS-writes the ENTIRE all-channels
     *         map, not just the one channel — so per-op GlobalData payload size
     *         grows linearly with the total channel count across the whole fleet.
     *   The already-solved per-channel 100-message cap does NOT bound either of
     *   these. Suggested follow-up: move to per-channel GlobalData keys (one key
     *   per channel id, so an append touches only its own channel) instead of
     *   one giant map, and/or add a channel-count cap + idle-eviction policy,
     *   and validate the DM `to` uid so junk dm:* keys cannot be minted. Tracked
     *   as a Phase 2 follow-up; harmless at current channel counts.
     *
     * @param string $channel channel id
     * @param array $message §2.10 channel.message object (channel/from/
     *                       from_name/body/level/ts/msg_id)
     */
    private static function chatCacheAppend($channel, $message)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        // SCALABILITY (see docblock): per-channel key eliminates CAS contention
        // on a single shared map. Key pattern 'channel_msgs:' . $channel stores
        // each channel's message list independently.
        $channelKey = 'channel_msgs:' . $channel;
        $global->add($channelKey, []);

        // Track channels that have data for cleanup/enumeration (MAJOR-11)
        $channelsKey = 'channel_msgs_channels';
        $global->add($channelsKey, []);
        $timestampKey = 'channel_msgs_ts:' . $channel;
        $now = time();
        $global->$timestampKey = $now;

        // MAJOR-12: REMOVED the CAS loop that updated channel_msgs_channels here.
        // The per-channel timestamp keys (channel_msgs_ts:<channel>) already track
        // which channels are active for the reaper; the shared channel list was a
        // contention point where all channels competed on a single key.
        // do {
        //     $old_channels = $new_channels = $global->$channelsKey;
        //     if (!is_array($old_channels)) {
        //         $old_channels = $new_channels = [];
        //     }
        //     if (!in_array($channel, $old_channels, true)) {
        //         $new_channels[] = $channel;
        //     }
        // } while (!$global->cas($channelsKey, $old_channels, $new_channels));

        do {
            $old_value = $new_value = $global->$channelKey;
            if (!is_array($new_value)) {
                $old_value = $new_value = [];
            }
            $new_value[] = $message;
            if (count($new_value) > self::CHAT_HISTORY_MAX) {
                self::logStructured('chat.cache.overflow', ['channel' => $channel]);
                $new_value = array_slice($new_value, -self::CHAT_HISTORY_MAX);
            }
        } while (!$global->cas($channelKey, $old_value, $new_value));
    }

    /**
     * Best-effort `channel.presence` broadcast (docs/PROTOCOL_V1.md §2.10;
     * plan step 2.7) — pushed to a channel's subscriber group after a join or
     * leave. Members are derived live from the Gateway sessions of the
     * channel's group (the same session data legacy msgClients reads),
     * de-duplicated by uid; `online` is always true here because group
     * membership itself implies a live connection. NOTE:
     * getClientIdCountByGroup/getClientSessionsByGroup count CONNECTIONS, so
     * a uid with two tabs appears once in members (deduped) but twice in
     * channel.list's members count — documented approximation.
     *
     * DEPTH NOTE (deliberate, documented): presence here is BEST-EFFORT per
     * the step scope — it fires only on channel.join/channel.leave. A
     * disconnect (onClose) does NOT yet emit channel.presence, because
     * touching onClose would modify legacy code (forbidden this step);
     * subscribers see the corrected member list on the next join/leave.
     *
     * @param string $channel channel id (also the Gateway group name)
     */
    private static function chatBroadcastPresence($channel)
    {
        $members = [];
        $sessions = Gateway::getClientSessionsByGroup($channel);
        if (is_array($sessions)) {
            foreach ($sessions as $session) {
                if (!isset($session['uid'])) {
                    continue;
                }
                $members[$session['uid']] = [
                    'id' => $session['uid'],
                    'name' => $session['name'] ?? '',
                    'ima' => $session['ima'] ?? '',
                    'online' => true
                ];
            }
        }
        Gateway::sendToGroup($channel, json_encode(self::v1Envelope('channel.presence', [
            'channel' => $channel,
            'members' => array_values($members)
        ])));
    }

    /**
     * Shared publish finisher (plan step 2.7): append the completed §2.10
     * message object (msg_id now known) to the bounded hot cache, fan it out
     * — to the channel's Gateway group (the same joinGroup/sendToGroup idiom
     * legacy room broadcasts and msgSelfUpdate's `hosts` group use) or, for
     * DMs, to exactly the two participant uids — and ack the publisher.
     *
     * Reply shape decision (documented — §2.10 does not spell out
     * channel.publish's reply): {ok:true,data:{msg_id:<int>}} — the minimal
     * ack plus the persisted chat_messages.id (0 when the DB write was
     * skipped/failed) so the sender can correlate scrollback immediately.
     *
     * @param string $client_id publishing client (gets the ack)
     * @param string $re request envelope id being answered
     * @param array $message completed §2.10 message object
     * @param array|null $recipients null = broadcast to the channel group;
     *                               array of uids = DM delivery to exactly those
     * @param string $op push op: "channel.message" or "chat.message" (DM)
     */
    private static function chatFinishPublish($client_id, $re, $message, $recipients, $op)
    {
        self::chatCacheAppend($message['channel'], $message);
        $push = json_encode(self::v1Envelope($op, $message));

        // The publishing connection must receive the message EXACTLY ONCE.
        //
        // Both fan-out paths below already reach the sender — sendToGroup() includes it
        // (it joined the channel group) and, for a DM, $recipients always contains the
        // sender's own uid so sendToUid() reaches every tab it has open. The
        // unconditional direct send that used to follow was therefore a second copy, and
        // every message the sender published appeared twice in its own log.
        //
        // It looked correct for a long time only because the sender was never actually
        // in the group: channel.join is sent from dc:auth-success, and that event did not
        // fire until the client learned to correlate its auth reply by id (replies carry
        // re+ok and no op). With auth broken the direct send was the sender's ONLY copy,
        // which is exactly what the "covers the race where channel.join is still
        // in-flight" comment was papering over. That race is real, so keep the direct
        // send as the sender's single delivery and take it out of the broadcast instead.
        $senderAlreadySent = false;
        if (is_array($recipients)) {
            $senderUid = $_SESSION['uid'] ?? null;
            foreach (array_unique($recipients) as $uid) {
                Gateway::sendToUid($uid, $push);
                if ($senderUid !== null && (string) $uid === (string) $senderUid) {
                    $senderAlreadySent = true;   // delivered to every tab of this uid
                }
            }
        } else {
            // Exclude the sender here; the direct send below is its one delivery, and it
            // works whether or not channel.join has landed yet. The sender's OTHER tabs
            // are distinct client_ids and still receive it via the group.
            Gateway::sendToGroup($message['channel'], $push, $client_id);
        }
        if (!$senderAlreadySent) {
            Gateway::sendToClient($client_id, $push);
        }
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => ['msg_id' => $message['msg_id']]
        ]));
    }

    /**
     * Core publish pipeline shared by channel.publish and chat.send (docs/
     * PROTOCOL_V1.md §2.10 + §4; plan step 2.7). Builds the §2.10 message
     * object — `from`/`from_name` ALWAYS from the authed session, `body`
     * stored RAW (no nl2br/htmlspecialchars at store time; rendering is a
     * client concern — the OQ5-driven fix vs legacy say()) — persists it, and
     * finishes via chatFinishPublish().
     *
     * DB-WRITE DESIGN (documented decision): persistence is dispatched to the
     * TaskWorker (Tasks/chat_message.php) via Events::dispatchTask(), NOT
     * written inline — keeping Events.php thin and the BusinessWorker event
     * loop unblocked, matching the step 2.5/2.6 queue_action precedent. The
     * task returns the AUTO_INCREMENT id, which becomes §2.10's required
     * msg_id on the fanned-out event and the cached history entry. Per §4,
     * level:"log" traffic SKIPS the DB write entirely (log channels already
     * persist via queue_log/Influx; msg_id is 0) — "chat"-level messages are
     * always persisted (both behaviors pinned by tests/EventsV1ChatTest.php::
     * testChannelPublishLogLevelSkipsDbWriteButStillFansOut and
     * ::testChannelPublishInfoLevelStillPersists). On a persist failure the
     * message still fans out live
     * with msg_id 0 (availability over durability for the live tail; the
     * failure is operator-logged). Because chat-level fan-out happens in the
     * task's async callback, two near-simultaneous publishes can fan out in
     * either order — DB ids remain strictly ordered (known minor caveat).
     *
     * @param string $client_id publishing client
     * @param string $re request envelope id being answered
     * @param string $channel validated + ACL-checked channel id
     * @param string $body raw message text
     * @param string $level validated level ("chat"|"log"|"info"|"warn"|"error")
     * @param array|null $recipients null = channel group; array of uids = DM
     * @param string $op push op for the fan-out event
     */
    private static function chatPublishMessage($client_id, $re, $channel, $body, $level, $recipients = null, $op = 'channel.message')
    {
        $message = [
            'channel' => $channel,
            // CAST IS LOAD-BEARING. §2.10 declares `from` as a str ("sender uid
            // — vps<id>, account id, or 'system'") and chat_messages.`from` is
            // VARCHAR(64). Host/bot sessions already hold a string uid
            // ($prefix.$row[$id_col] at auth.hello), but admin/client sessions
            // hold accounts.account_id, and workerman/mysql sets
            // PDO::ATTR_STRINGIFY_FETCHES=false + ATTR_EMULATE_PREPARES=false,
            // so that INT column arrives as a native PHP int. Uncast, it went
            // out on the wire as a JSON number (violating §2.10) and, worse,
            // survived json_encode/json_decode into Tasks/chat_message.php as an
            // int, where `is_string($args['from'])` rejected it — every message
            // an admin published failed to persist with "chat_message requires
            // channel, from and body". Also see chatChannelAllowed(), which
            // casts for the same reason.
            'from' => isset($_SESSION['uid']) && is_scalar($_SESSION['uid']) ? (string) $_SESSION['uid'] : '',
            'from_name' => $_SESSION['name'] ?? '',
            'body' => $body,
            'level' => $level,
            'ts' => time(),
            'msg_id' => 0
        ];
        if ($level === 'log') {
            // §4: high-volume log-level traffic may skip DB writes (log
            // channels already persist via queue_log/Influx) — cache + fan-out only.
            self::chatFinishPublish($client_id, $re, $message, $recipients, $op);
            return;
        }
        self::dispatchTask('chat_message', [
            'channel' => $channel,
            'from' => $message['from'],
            'body' => $body,
            'level' => $level,
            'ts' => $message['ts']
        ], function ($task_result) use ($client_id, $re, $message, $recipients, $op) {
            // TaskWorker wraps the task return as {"return":<str>}; the task's
            // return is chat_message()'s {"ok":bool,"msg_id"|"error":...}.
            $decoded = json_decode($task_result, true);
            $inner = is_array($decoded) && isset($decoded['return']) && is_string($decoded['return'])
                ? json_decode($decoded['return'], true) : null;
            if (is_array($inner) && !empty($inner['ok']) && isset($inner['msg_id']) && is_numeric($inner['msg_id'])) {
                $message['msg_id'] = intval($inner['msg_id']);
            } else {
                $err = is_array($inner) && isset($inner['error']) && is_string($inner['error']) ? $inner['error'] : 'unexpected task result';
                Worker::safeEcho("chat_message persist failed for channel {$message['channel']}: {$err}".PHP_EOL);
            }
            self::chatFinishPublish($client_id, $re, $message, $recipients, $op);
        }, function () use ($client_id, $re, $message, $recipients, $op) {
            Worker::safeEcho("chat_message persist dispatch failed for channel {$message['channel']}".PHP_EOL);
            self::chatFinishPublish($client_id, $re, $message, $recipients, $op);
        });
    }

    /**
     * v1 `channel.list` handler (docs/PROTOCOL_V1.md §2.10; plan step 2.7) —
     * C→H request/reply. Only reachable via dispatchV1 (Flag A on +
     * v1-authed) — fully dormant with Flag A off.
     *
     * CHANNEL-SOURCE DESIGN (documented decision): the hub has no standalone
     * channel table; the list is derived from the union of (a) the
     * $global->channel_meta registry — explicit channel.create'd channels
     * with {type,topic,created_by,created_at}, lazily created + CAS-
     * maintained like $global->ptys — and (b) every channel id that has
     * traffic in the $global->channels hot cache (so host:* / job:* log
     * channels appear once something is published to them). The list is
     * filtered by the caller's ACL (chatChannelAllowed), so hosts see only
     * their own channels and bots only chat:*. `members` counts the
     * channel's live Gateway group connections (connection count, not unique
     * uids — documented approximation); `topic` is "" for channels without
     * registry metadata.
     *
     * Reply: {channels:[{id,type,topic,members}]} per the frozen §2.10 list.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleChannelList($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        $meta = $global->channel_meta;
        if (!is_array($meta)) {
            $meta = [];
        }
        $ids = array_keys($meta);
        $channels = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (!self::chatValidChannelId($id) || !self::chatChannelAllowed($id)) {
                continue;
            }
            $channels[] = [
                'id' => $id,
                'type' => substr($id, 0, strpos($id, ':')),
                'topic' => isset($meta[$id]['topic']) && is_string($meta[$id]['topic']) ? $meta[$id]['topic'] : '',
                'members' => intval(Gateway::getClientIdCountByGroup($id))
            ];
        }
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => ['channels' => $channels]
        ]));
    }

    /**
     * v1 `channel.join` handler (docs/PROTOCOL_V1.md §2.10 + §4; plan step
     * 2.7) — C→H request/reply. Only reachable via dispatchV1 (Flag A on +
     * v1-authed).
     *
     * Validates the channel id shape and the §3 role ACL (chatChannelAllowed:
     * hosts only their own host:* / job:* channels, bots chat:* only, admins
     * any), then registers the client as a subscriber via
     * Gateway::joinGroup($client_id, $channel) — the SAME group idiom legacy
     * room broadcasts use (room_1 / the `hosts` group in msgSelfUpdate) — so
     * subsequent channel.publish fan-out reaches it through
     * Gateway::sendToGroup($channel, ...).
     *
     * Reply: {history:[<§2.10 channel.message obj>]} — the last N≤100
     * messages from the bounded GlobalData hot cache ONLY (never a DB query
     * on join, per §4's "hot cache serves channel.join history"; deeper
     * scrollback via msg_id pagination against chat_messages is a later
     * client-driven step). A best-effort channel.presence broadcast follows.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleChannelJoin($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $channel = $data['channel'] ?? null;
        if (!self::chatValidChannelId($channel)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'channel.join data.channel must be a valid "type:name" channel id');
            return;
        }
        if (!self::chatChannelAllowed($channel)) {
            self::sendV1Error($client_id, $re, 'forbidden', 'this session may not access channel '.$channel);
            return;
        }
        Gateway::joinGroup($client_id, $channel);
        $channelKey = 'channel_msgs:' . $channel;
        $cached = $global->$channelKey;
        $history = is_array($cached) ? array_values($cached) : [];
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => ['history' => $history]
        ]));
        self::chatBroadcastPresence($channel);
    }

    /**
     * v1 `channel.leave` handler (docs/PROTOCOL_V1.md §2.10; plan step 2.7)
     * — C→H request/reply, the symmetric Gateway::leaveGroup of
     * channel.join. No ACL check on the way out (leaving something you could
     * never join is a harmless no-op — leaveGroup on a non-member is safe).
     * Reply: {} per the frozen §2.10 list. A best-effort channel.presence
     * broadcast (which the leaver no longer receives) follows.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleChannelLeave($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $channel = $data['channel'] ?? null;
        if (!self::chatValidChannelId($channel)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'channel.leave data.channel must be a valid "type:name" channel id');
            return;
        }
        Gateway::leaveGroup($client_id, $channel);
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => new \stdClass()
        ]));
        self::chatBroadcastPresence($channel);
    }

    /**
     * v1 `channel.create` handler (docs/PROTOCOL_V1.md §2.10; plan step 2.7)
     * — C→H request/reply, ADMIN-GATED (plan B6/B7: user-created channels
     * come from the admin UI's "New Channel" button). User-created channels
     * are always type `chat:` per the frozen §2.10 note. Only reachable via
     * dispatchV1 (Flag A on + v1-authed).
     *
     * Frozen fields: name (required; constrained to a sane
     * [A-Za-z0-9][A-Za-z0-9_.-]{0,80} slug so the composed id passes
     * chatValidChannelId and fits chat_messages.channel), topic (optional
     * str). Writes {type,topic,created_by,created_at} into the
     * $global->channel_meta registry (lazily created + CAS whole-map loop,
     * same convention as $global->ptys); a duplicate id — checked INSIDE the
     * CAS loop so two racing creates cannot both win — is rejected with
     * bad_request (NO silent overwrite: an existing channel's
     * type/topic/created_by/created_at are never clobbered). Pinned by
     * tests/EventsV1ChatTest.php::testChannelCreateDuplicateRejectedBadRequest.
     * Reply: {channel:<full "chat:<name>" id>}.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleChannelCreate($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'channel.create requires role admin');
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
        if ($name === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,80}$/', $name) !== 1) {
            self::sendV1Error($client_id, $re, 'bad_request', 'channel.create data.name must be an alphanumeric slug ([A-Za-z0-9][A-Za-z0-9_.-]{0,80})');
            return;
        }
        $topic = isset($data['topic']) && is_string($data['topic']) ? $data['topic'] : '';
        $channel = 'chat:'.$name;
        $global->add('channel_meta', []);
        $duplicate = false;
        do {
            $old_value = $new_value = $global->channel_meta;
            if (!is_array($new_value)) {
                $old_value = $new_value = [];
            }
            if (isset($new_value[$channel])) {
                // Checked inside the CAS loop: two racing creates cannot both win.
                $duplicate = true;
                break;
            }
            $new_value[$channel] = [
                'type' => 'chat',
                'topic' => $topic,
                'created_by' => $_SESSION['uid'] ?? '',
                'created_at' => time()
            ];
        } while (!$global->cas('channel_meta', $old_value, $new_value));
        if ($duplicate) {
            self::sendV1Error($client_id, $re, 'bad_request', "channel {$channel} already exists");
            return;
        }
        Worker::safeEcho("[{$client_id}] v1 channel.create: ".($_SESSION['uid'] ?? '')." created {$channel}".PHP_EOL);
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => ['channel' => $channel]
        ]));
    }

    /**
     * v1 `channel.publish` handler (docs/PROTOCOL_V1.md §2.10 + §4; plan
     * step 2.7) — any→H. The v1 counterpart of the legacy say() room path,
     * rebuilt: raw-text storage (no nl2br/htmlspecialchars at store time),
     * durable chat_messages persistence via the TaskWorker, a bounded
     * per-channel hot cache instead of the unbounded rooms[0]['messages'],
     * and real channels instead of the hardcoded single room. Legacy
     * say()/msgSay and the rooms structure are NOT touched (parallel
     * rebuild; retirement is P7.1). Only reachable via dispatchV1 (Flag A on
     * + v1-authed).
     *
     * Frozen §2.10 fields: channel (required), body (required str — raw
     * text or log line), level (optional: "chat" default | "log" | "info" |
     * "warn" | "error"). ACL per §3/B6 via chatChannelAllowed(): hosts may
     * ONLY publish to their own host:* / job:* channels (uid match from the
     * authed session — client channel targeting is validated against the
     * session identity, never trusted beyond it), bots chat:* only, admins
     * anywhere. from/from_name always from the authed session.
     *
     * Flow: persist (Tasks/chat_message.php via dispatchTask; level:"log"
     * skips the DB per §4) → append to the bounded hot cache → fan out a
     * §2.10 channel.message push to all subscribers via
     * Gateway::sendToGroup($channel, ...) → ack the publisher
     * {ok:true,data:{msg_id}} (documented reply-shape choice — §2.10 leaves
     * channel.publish's reply unspecified; see chatFinishPublish()).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleChannelPublish($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $channel = $data['channel'] ?? null;
        if (!self::chatValidChannelId($channel)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'channel.publish data.channel must be a valid "type:name" channel id');
            return;
        }
        if (!self::chatChannelAllowed($channel)) {
            self::sendV1Error($client_id, $re, 'forbidden', 'this session may not publish to channel '.$channel);
            return;
        }
        $body = $data['body'] ?? null;
        if (!is_string($body) || $body === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'channel.publish data.body must be a non-empty string');
            return;
        }
        $level = isset($data['level']) ? $data['level'] : 'chat';
        if (!in_array($level, ['chat', 'log', 'info', 'warn', 'error'], true)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'channel.publish data.level must be "chat", "log", "info", "warn" or "error"');
            return;
        }
        $body = trim($body);
        if ($body === '/status') {
            self::handleStatusCommand($client_id, $re, $channel, $level);
            return;
        }
        $bodyLower = strtolower(trim($body));
        if ($bodyLower === 'ping') {
            self::handlePingCommand($client_id, $re, $channel, $level);
            return;
        }
        self::chatPublishMessage($client_id, $re, $channel, $body, $level);
    }

    /**
     * Handles the /status command — returns system status info to the
     * requesting client only (not broadcast to the channel).
     *
     * Gathers: connected WebSocket client count, current timestamp,
     * and number of active channels from the channel_meta registry.
     *
     * @param string $client_id gateway client id
     * @param mixed $re request envelope id being answered
     * @param string $channel the channel the command was received on
     * @param string $level message level (hardcoded to 'chat' for status responses so the name prefix renders)
     */
    private static function handleStatusCommand($client_id, $re, $channel, $level)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;

        $clientCount = 0;
        $sessions = Gateway::getAllClientSessions();
        if (is_array($sessions)) {
            $clientCount = count($sessions);
        }

        $channelCount = 0;
        $meta = $global->channel_meta ?? [];
        if (is_array($meta)) {
            $channelCount = count($meta);
        }

        $timestamp = date('Y-m-d H:i:s');
        $statusText = "Status: {$timestamp} | Clients: {$clientCount} | Channels: {$channelCount}";

        Gateway::sendToClient($client_id, json_encode(self::v1Envelope('channel.message', [
            'channel' => $channel,
            'from' => 'system',
            'from_name' => 'Status Bot',
            'body' => $statusText,
            'level' => 'chat',
            'msg_id' => 0
        ])));
    }

    /**
     * Handles the ping command — returns "pong" with bot coordinates to the
     * requesting client only (not broadcast to the channel).
     *
     * Reads the bot position from GlobalData dc_bot_state:<location> (falling
     * back to the bot's presence entry dc_presence:client:bot_<location>).
     * If no bot state exists, returns "pong - no bot present".
     *
     * THE BOT #5: this used to read 'dc_presence:bot_main', a key nothing ever
     * writes (spawnBotForLocation writes dc_bot_state:main and
     * dc_presence:client:bot_main), so /ping always said "no bot present".
     * This is a pure response with no side effects and no DB persistence.
     *
     * @param string $client_id gateway client id
     * @param mixed $re request envelope id being answered
     * @param string $channel the channel the command was received on
     * @param string $level message level (hardcoded to 'chat' for response rendering)
     */
    private static function handlePingCommand($client_id, $re, $channel, $level)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;

        $location = self::BOT_DEFAULT_LOCATION;
        $botState = $global->{'dc_bot_state:' . $location} ?? null;
        if (!is_array($botState)) {
            $botState = $global->{'dc_presence:client:bot_' . $location} ?? null;
        }
        if (!$botState || !is_array($botState)) {
            $body = 'pong - no bot present';
        } else {
            $x = $botState['x'] ?? '?';
            $z = $botState['z'] ?? '?';
            $body = "pong x={$x} z={$z}";
        }

        Gateway::sendToClient($client_id, json_encode(self::v1Envelope('channel.message', [
            'channel' => $channel,
            'from' => 'system',
            'from_name' => 'Ping Bot',
            'body' => $body,
            'level' => 'chat',
            'msg_id' => 0
        ])));
    }

    /**
     * v1 `chat.send` handler (docs/PROTOCOL_V1.md §2.10 + §4; plan step 2.7)
     * — C→H convenience wrapper. Only reachable via dispatchV1 (Flag A on +
     * v1-authed). Two forms per the frozen spec:
     *
     * CHANNEL FORM (no data.to): identical field list/behavior to
     * channel.publish — same validation, same ACL, same persist/cache/fan-out
     * pipeline. DESIGN NOTE (documented): the fan-out event is emitted as
     * `channel.message` (not chat.message) so a channel's subscribers receive
     * ONE event type regardless of which wrapper the sender used; §2.10
     * defines the two ops with identical field lists, so no information is
     * lost.
     *
     * DM FORM ({to:<uid>, body:str} — replaces legacy say() with
     * is:"client"): persists to chat_messages with channel
     * `dm:<uidA>:<uidB>` where the two uids are EXPLICITLY SORTED
     * (sort() on [sender, to]) so the same DM thread is found regardless of
     * who is "a"/"b" — fixing the legacy gap where DMs were never persisted
     * (§4/OQ5). The §2.10 chat.message push is routed ONLY to the two
     * participants via Gateway::sendToUid (sender included, covering their
     * other open connections) — never broadcast. Any authed role may DM any
     * uid (legacy say's client form had no role restriction beyond login —
     * parity; the recipient existing/being online is NOT validated: sendToUid
     * to an offline uid is a no-op and the message still persists for
     * scrollback — documented choice). data.level is honored like the channel
     * form (default "chat").
     *
     * DM `to`-VALIDATION GAP (documented follow-up): `to` is only checked for
     * being a non-empty string and for keeping the composed dm id ≤191 bytes —
     * it is NOT validated against any real user registry. A junk/nonexistent
     * `to` therefore still mints a permanent `dm:*` hot-cache key and a
     * chat_messages row, which is the growth vector behind the chatCacheAppend()
     * KNOWN SCALABILITY FOLLOW-UP. Low severity (a client can only spam its own
     * dm threads), fixed together with the per-channel-key rework.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleChatSend($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        if (!array_key_exists('to', $data)) {
            // Channel form: identical to channel.publish (§2.10 wrapper).
            self::handleChannelPublish($client_id, $envelope);
            return;
        }
        $to = $data['to'];
        if (!is_string($to) || trim($to) === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'chat.send data.to must be a non-empty uid string');
            return;
        }
        $to = trim($to);
        $body = $data['body'] ?? null;
        if (!is_string($body) || $body === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'chat.send data.body must be a non-empty string');
            return;
        }
        $level = isset($data['level']) ? $data['level'] : 'chat';
        if (!in_array($level, ['chat', 'log', 'info', 'warn', 'error'], true)) {
            self::sendV1Error($client_id, $re, 'bad_request', 'chat.send data.level must be "chat", "log", "info", "warn" or "error"');
            return;
        }
        // Cast for the same reason as chatPublishMessage(): admin/client uids are
        // native ints out of PDO, and $from is about to be sorted with SORT_STRING,
        // concatenated into the dm channel id and handed to Gateway::sendToUid().
        $from = isset($_SESSION['uid']) && is_scalar($_SESSION['uid']) ? (string) $_SESSION['uid'] : '';
        if ($from === '') {
            self::sendV1Error($client_id, $re, 'internal', 'authenticated session has no uid');
            return;
        }
        // §2.10: dm channel uids are SORTED so the thread id is order-independent.
        $pair = [$from, $to];
        sort($pair, SORT_STRING);
        $channel = 'dm:'.$pair[0].':'.$pair[1];
        if (strlen($channel) > 191) {
            self::sendV1Error($client_id, $re, 'bad_request', 'chat.send data.to produces a dm channel id longer than 191 characters');
            return;
        }
        self::chatPublishMessage($client_id, $re, $channel, $body, $level, [$from, $to], 'chat.message');
    }

    /**
     * v1 `admin.hosts` handler (docs/PROTOCOL_V1.md §2.9; plan step 2.8) —
     * admin C→H, request/reply. Replaces legacy chat `clients` (msgClients).
     * Only reachable via dispatchV1 (Flag A on + v1-authed) — fully dormant
     * with Flag A off.
     *
     * Requires role admin (§2.9/§3); the session identity is used ONLY for
     * the role check — the payload is registry/session data, never
     * identity-derived. Same data-gathering as legacy msgClients (iterate
     * Gateway::getAllClientSessions(), split host-ish vs admin sessions),
     * reshaped to the frozen §2.9 field lists, minus the chat-room noise
     * ($global->rooms) and minus the mandatory gzcompress legacy applies
     * (a client wanting compression uses envelope enc:"gzip" instead).
     *
     * hosts entries: {id (uid str), host_id (int, parsed from the uid the
     * hub itself bound at auth), name, ima, type, ip, online ("Y-m-d H:i:s"),
     * module}. Missing type/ip on older sessions fall back to the
     * $global->hosts registry row (vps module only — the registry is keyed
     * by vps_id with vps_masters rows). Bot sessions appear in hosts with
     * their real ima ("bot") per the §2.9 ima:str field. admins entries:
     * {id (str), name, ima:"admin", img, online}.
     *
     * MIXED-MODE NOTE: any non-admin Gateway session lands in `hosts`,
     * including a legacy ima:"client" chat session — it is lumped in with a
     * digits-stripped host_id (preg_replace of its uid). This is spec-faithful
     * (every non-admin session is a "host" row here), but tooling that mixes
     * legacy chat clients with real vps/qs hosts should be aware the `hosts`
     * array is not exclusively provisioning hosts. Sparse legacy sessions may
     * also carry empty-string fallbacks for online/name/ip — leniently typed
     * relative to the frozen §2.9 field types, but harmless (not a bug).
     *
     * Reply: {ok:true,data:{hosts:arr,admins:arr}}.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleAdminHosts($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'admin.hosts requires role admin');
            return;
        }
        $cacheKey = 'admin_hosts_cache';
        $ttlKey = 'admin_hosts_cache_ttl';
        $cached = $global->$cacheKey;
        $ttl = $global->$ttlKey;
        if ($cached !== null && $ttl !== null && (microtime(true) - $ttl) < 5) {
            Gateway::sendToClient($client_id, json_encode([
                'v' => 1,
                're' => $re,
                'ok' => true,
                'data' => $cached
            ]));
            return;
        }
        $registry = $global->hosts;
        if (!is_array($registry)) {
            $registry = [];
        }
        $hosts = [];
        $admins = [];
        $admin_sessions = Gateway::getClientSessionsByGroup('admins');
        $host_sessions = Gateway::getClientSessionsByGroup('hosts');
        $sessions = array_merge($admin_sessions ?: [], $host_sessions ?: []);
        foreach ($sessions as $session_id => $session_data) {
            if (!isset($session_data['uid'])) {
                continue;
            }
            if (($session_data['ima'] ?? '') === 'admin') {
                $admins[] = [
                    'id' => (string) $session_data['uid'],
                    'name' => isset($session_data['name']) ? $session_data['name'] : '',
                    'ima' => 'admin',
                    'img' => isset($session_data['img']) ? $session_data['img'] : '',
                    'online' => isset($session_data['online']) ? $session_data['online'] : ''
                ];
                continue;
            }
            $uid = (string) $session_data['uid'];
            // host_id from the uid the hub itself bound at auth ("vps<id>"/
            // "qs<id>"/"bot<id>") — never from client-supplied data.
            $host_id = intval(preg_replace('/[^0-9]/', '', $uid));
            $module = isset($session_data['module']) ? $session_data['module'] : 'vps';
            // vps-module fallback to the shared hosts registry (vps_masters
            // rows keyed by vps_id) for sessions missing type/ip.
            $row = $module === 'vps' && isset($registry[$host_id]) && is_array($registry[$host_id]) ? $registry[$host_id] : [];
            $hosts[] = [
                'id' => $uid,
                'host_id' => $host_id,
                'name' => isset($session_data['name']) ? $session_data['name'] : (isset($row['vps_name']) ? $row['vps_name'] : ''),
                'ima' => isset($session_data['ima']) ? $session_data['ima'] : '',
                'type' => isset($session_data['type']) ? $session_data['type'] : (isset($row['vps_type']) ? $row['vps_type'] : ''),
                'ip' => isset($session_data['ip']) ? $session_data['ip'] : (isset($row['vps_ip']) ? $row['vps_ip'] : ''),
                'online' => isset($session_data['online']) ? $session_data['online'] : '',
                'module' => $module
            ];
        }
        $data = ['hosts' => $hosts, 'admins' => $admins];
        $global->$cacheKey = $data;
        $global->$ttlKey = microtime(true);
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => $data
        ]));
    }

    /**
     * v1 `admin.timers` handler (docs/PROTOCOL_V1.md §2.9; plan step 2.8) —
     * admin C→H, request/reply. Replaces legacy chat `timers` (msgTimers,
     * whose status gathering is commented out so it replies with an EMPTY
     * payload — v1 returns the real registry). Only reachable via dispatchV1
     * (Flag A on + v1-authed) — fully dormant with Flag A off.
     *
     * Requires role admin (§2.9/§3). Reads the $global->timers registry that
     * onWorkerStart populates on the timer-hosting server (myadmin1, worker
     * id 0) at Timer::add() registration time: name → {interval, timer_id}
     * for each of processing_queue_timer, processing_queue_reaper,
     * boardctl_queue_timer, vps_queue_timer, memcache_queue_timer,
     * map_queue_timer, hyperv_update_list_timer, hyperv_queue_timer.
     *
     * last_run DEFERRAL (deliberate, spec-conformant — NOT a gap): last_run is
     * specced OPTIONAL per §2.9 ({interval:int, last_run:ts?, timer_id:int}),
     * and is emitted only when a registry entry actually carries it. Live
     * last_run tracking was intentionally NOT wired up: doing so would require
     * writing a timestamp from inside each timer callback body, and several of
     * those callbacks (processing_queue_timer / vps_queue_timer /
     * boardctl_queue_timer) are invariant-frozen — they hold CAS-lock,
     * DB-retry and task-dispatch logic that must stay byte-for-byte identical
     * during the migration. Emitting the optional field is the conservative,
     * spec-conformant choice (confirmed sound by an independent review), and
     * scheduling behavior stays exactly as today. A genuine future enhancement
     * if last_run is ever needed: careful, flag-gated instrumentation added
     * inside each callback (out of scope while the callbacks are frozen).
     *
     * Pre-enrichment scalar entries (bare Timer::add() ids from an old
     * registration) are normalized to {interval:0, timer_id:<id>}.
     *
     * Reply: {ok:true,data:{timers:map<str,obj>}} ({} when the registry is
     * absent, e.g. on a server that hosts no timers).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleAdminTimers($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'admin.timers requires role admin');
            return;
        }
        $registry = isset($global->timers) ? $global->timers : [];
        $timers = [];
        if (is_array($registry)) {
            foreach ($registry as $name => $info) {
                if (is_array($info)) {
                    $entry = [
                        'interval' => isset($info['interval']) ? intval($info['interval']) : 0,
                        'timer_id' => isset($info['timer_id']) ? intval($info['timer_id']) : 0
                    ];
                    if (isset($info['last_run'])) {
                        $entry['last_run'] = intval($info['last_run']);
                    }
                } else {
                    // Legacy scalar shape (bare Timer::add() id) — normalize.
                    $entry = ['interval' => 0, 'timer_id' => intval($info)];
                }
                $timers[$name] = $entry;
            }
        }
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => [
                // map<str,obj> — force object encoding when empty.
                'timers' => empty($timers) ? new \stdClass() : $timers
            ]
        ]));
    }

    /**
     * v1 `admin.running` handler (docs/PROTOCOL_V1.md §2.9; plan step 2.8) —
     * admin C→H, request/reply. Replaces legacy chat/agent `run_list`. Only
     * reachable via dispatchV1 (Flag A on + v1-authed) — fully dormant with
     * Flag A off.
     *
     * Requires role admin (§2.9/§3). Reads the SAME shared $global->running
     * registry both run paths write (v1 handleCmdExec entries keyed by uuid
     * run_id carrying run_id/id/host/for/command/interact/update_after/rows/
     * cols/started/v; legacy run_command entries keyed by md5($cmd) carrying
     * type/command/id/interact/update_after/host/rows/cols/for) and reshapes
     * every entry to the frozen §2.9 record: {run_id, host (uid), command,
     * interact, update_after, for, rows, cols, started}. Legacy `type` is
     * dropped; run_id falls back to the legacy `id` field / registry key for
     * legacy entries.
     *
     * started:0 SENTINEL: only step-2.3 v1 handleCmdExec entries set `started`.
     * A legacy run_command entry (md5-keyed, no `started` field) is reported
     * with started:0, an explicit sentinel meaning "predates v1 started
     * tracking" — NOT "started at unix epoch". Consumers must treat started:0
     * as "start time unknown", not as a real timestamp.
     *
     * READ-ONLY GUARANTEE: this handler only reads $global->running and never
     * writes/CAS-updates it — introspection cannot perturb in-flight run
     * routing (unlike handleCmdExec/handleCmdExit, which mutate the registry).
     *
     * Reply: {ok:true,data:{running:arr<obj>}} ([] when nothing is in flight).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleAdminRunning($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'admin.running requires role admin');
            return;
        }
        $registry = $global->running;
        $running = [];
        if (is_array($registry)) {
            foreach ($registry as $key => $run) {
                if (!is_array($run)) {
                    continue;
                }
                $run_id = isset($run['run_id']) && is_string($run['run_id']) && $run['run_id'] !== ''
                    ? $run['run_id']
                    : (isset($run['id']) && is_string($run['id']) && $run['id'] !== '' ? $run['id'] : (string) $key);
                $running[] = [
                    'run_id' => $run_id,
                    'host' => isset($run['host']) ? $run['host'] : '',
                    'command' => isset($run['command']) ? $run['command'] : '',
                    'interact' => !empty($run['interact']),
                    'update_after' => !empty($run['update_after']),
                    'for' => isset($run['for']) ? $run['for'] : null,
                    'rows' => isset($run['rows']) ? intval($run['rows']) : 0,
                    'cols' => isset($run['cols']) ? intval($run['cols']) : 0,
                    'started' => isset($run['started']) ? intval($run['started']) : 0
                ];
            }
        }
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => ['running' => $running]
        ]));
    }

    /**
     * IDEA-3: Check if a world position (x, z) falls within a peer's viewport AABB.
     * Uses axis-aligned bounding box: peer position ± viewDist * 2 on each axis.
     * Fails open (returns true) when viewport data is unavailable.
     *
     * @param float $moverX  world X of the moving client
     * @param float $moverZ  world Z of the moving client
     * @param array $peerViewport peer viewport data from $global (x, z, viewDist)
     * @return bool true if in viewport or viewport unknown (broadcast), false if out of range
     */
    /**
     * Check if a world position falls within a peer's view frustum (simplified pyramid).
     * Uses the peer's position, look direction, and viewDist/FOV to build a view cone.
     * Only broadcasts if mover is in front of peer AND within viewDist AND within horizontal FOV cone.
     * Falls back to true (broadcast) if any data is missing.
     */
    private static function isInPeerViewport(float $moverX, float $moverZ, array $peerViewport): bool
    {
        if (!isset($peerViewport['x'], $peerViewport['z'], $peerViewport['viewDist'],
                  $peerViewport['dirX'], $peerViewport['dirZ'])) {
            return true; // fail-open: no viewport data = broadcast
        }

        $peerX = (float) $peerViewport['x'];
        $peerZ = (float) $peerViewport['z'];
        $viewDist = (float)($peerViewport['viewDist'] ?? 50);
        $halfFov = deg2rad(60 / 2); // 60-degree horizontal FOV (configurable)

        // REVIEW-FIX: fail open on any non-finite / nonsensical input rather than
        // silently filtering everything out. handleDcViewportUpdate() writes ALL
        // of x/z/dirX/dirZ/viewDist with (float) defaults of 0, so the isset()
        // fail-open above can never trigger for a stored viewport — every
        // degenerate case has to be caught HERE or the peer goes blind.
        if (!is_finite($moverX) || !is_finite($moverZ) || !is_finite($peerX) || !is_finite($peerZ)) {
            return true;
        }
        if (!is_finite($viewDist) || $viewDist <= 0) {
            $viewDist = 50.0; // treat a missing/garbage radius as the default
        }

        // Vector from peer to mover
        $toMoverX = $moverX - $peerX;
        $toMoverZ = $moverZ - $peerZ;
        $distSq = $toMoverX * $toMoverX + $toMoverZ * $toMoverZ;
        $dist = sqrt($distSq);

        // Check 1: within max view distance
        if ($dist > $viewDist * 2) {
            return false;
        }

        // REVIEW-FIX: normalise the look direction IN THE XZ PLANE before using
        // it as a cosine. dc.js sends camera.getWorldDirection(), a unit vector
        // in 3D, so its horizontal part is only unit-length when the camera is
        // perfectly level: looking down 45 deg leaves |(dirX,dirZ)| ~= 0.707 and
        // the raw dot product could then never reach cos(30 deg) = 0.866, so
        // EVERY peer was filtered out — tilt the camera down and remote avatars
        // froze for up to DC_VIEWPORT_MAX_AGE. Looking straight up/down makes
        // the horizontal part (0,0), which also made $dot 0 and failed the
        // "behind peer" test for everyone.
        $dirX = (float)$peerViewport['dirX'];
        $dirZ = (float)$peerViewport['dirZ'];
        $dirLen = sqrt($dirX * $dirX + $dirZ * $dirZ);
        if (!is_finite($dirLen) || $dirLen < 1.0e-6) {
            return true; // no usable horizontal facing — fail open, do not blind the peer
        }
        $dirX /= $dirLen;
        $dirZ /= $dirLen;

        // Checks 2+3: in front of the peer AND inside the horizontal FOV cone.
        // cos(halfFov) > 0, so the "behind peer" case (cos <= 0) is subsumed.
        if ($dist > 0) {
            $cosAngle = ($toMoverX * $dirX + $toMoverZ * $dirZ) / $dist;
            if ($cosAngle < cos($halfFov)) {
                return false; // behind the peer or outside the FOV cone
            }
        }

        return true;
    }

    /**
     * Guarantee a shared list index EXISTS before anyone CAS-updates it.
     *
     * GlobalData's server reports an absent key as NULL and compares
     * md5(serialize($old)) (vendor/workerman/globaldata/src/Server.php, case 'cas').
     * Every index CAS here passes [] as the expected old value, and
     * md5(serialize(null)) !== md5(serialize([])) — so while the key does not exist
     * the CAS can NEVER succeed. Combined with a `while (true)` retry that had no
     * ceiling, the first dc.presence.join after a GlobalData cold start spun a
     * BusinessWorker forever at 100% CPU. onWorkerStart() seeds dc_active_clients in
     * its cold-start block but NOTHING seeded dc_presence_clients, so that key was
     * the live trigger.
     *
     * add() is the right primitive: it is atomic set-if-absent, so with the THREE
     * datacentered instances sharing this GlobalData store, whichever host calls it
     * first creates the key and the others get false and carry on. It never
     * overwrites an existing list, so it cannot disturb presence state another
     * instance is maintaining.
     *
     * @param string $key shared index key (dc_presence_clients / dc_active_clients)
     */
    private static function seedClientIndex(string $key): void
    {
        global $global;
        if ($global === null) {
            return;
        }
        $global->add($key, []);
    }

    /**
     * Should a contended index CAS be retried? Bounds every index CAS loop.
     *
     * Contention is real (5 BusinessWorkers per host x 3 hosts all mutating the same
     * lists), so retrying is correct — but unbounded retrying turns any unexpected
     * CAS mismatch into a wedged worker that also floods GlobalData. Losing one
     * client from an index degrades that client's broadcasts; spinning takes the
     * whole worker (and every connection on it) down, so bounded-and-noisy beats
     * infinite-and-silent.
     *
     * @param string $key     index being updated, for the log line
     * @param int    $attempt attempt number just completed
     * @return bool true to retry
     */
    private static function casShouldRetry(string $key, int $attempt): bool
    {
        if ($attempt < self::CAS_MAX_ATTEMPTS) {
            return true;
        }
        Worker::safeEcho(
            "[dc_presence] CAS on {$key} gave up after {$attempt} attempts; "
            ."index may be missing an entry (this is a bug — it must not livelock)\n"
        );
        return false;
    }

    /**
     * Handle dc.presence.join — client entering the datacenter 3D scene.
     *
     * Stores the member's position + metadata in $global->dc_presence[$uid]
     * and broadcasts dc.presence.joined to the dc_presence channel so other
     * clients in the scene can render the new avatar.
     *
     * @param string $client_id
     * @param array $envelope v1 envelope with data{x,z,yaw}
     */
    public static function handleDcPresenceJoin($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        $uid = $_SESSION['uid'] ?? null;
        if (empty($uid)) {
            self::sendV1Error($client_id, $re, 'forbidden', 'dc.presence.join requires authentication');
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $x   = isset($data['x']) && is_numeric($data['x']) ? (float) $data['x'] : 0.0;
        $z   = isset($data['z']) && is_numeric($data['z']) ? (float) $data['z'] : 0.0;
        $yaw = isset($data['yaw']) && is_numeric($data['yaw']) ? (float) $data['yaw'] : 0.0;
        $name = $_SESSION['name'] ?? '';

        // Contract BOT-BOUNDS: the browser MAY report the real room extents
        // (window.DC.roomBounds) so the bot wanders the actual room instead of
        // the ±50 box around the world origin. Optional + validated; a bad or
        // absent value simply leaves the previously-reported bounds in place.
        $reportedBounds = self::sanitiseRoomBounds($data['bounds'] ?? null);
        if ($reportedBounds !== null) {
            $global->{self::DC_ROOM_BOUNDS_KEY_PREFIX . self::BOT_DEFAULT_LOCATION} = $reportedBounds;
        }

        // Per-client_id key so multiple tabs with same session/uid each get their own presence entry
        $key = 'dc_presence:client:' . $client_id;
        $newEntry = [
            'uid' => $uid,
            'name' => $name,
            'x' => $x,
            'z' => $z,
            'yaw' => $yaw,
            'ts' => time(),
            'client_id' => $client_id,
        ];
        // CRIT-9: If cleanup is in progress for this client_id, log and proceed anyway (onClose will clean up after us)
        if ($client_id && $global->{'dc_cleanup:' . $client_id}) {
            Worker::safeEcho("dc.presence.join {$client_id}: cleanup in progress, overwriting anyway\n");
        }
        $global->$key = $newEntry;

        // Maintain client_id → key mapping for efficient iteration in setupSessionHealthTimer
        // CAS loop to prevent concurrent joins on different workers losing client_id entries.
        //
        // seedClientIndex() FIRST — see its docblock. Without it this loop was an
        // infinite spin that wedged a BusinessWorker at 100% CPU on the very first
        // join after a GlobalData cold start (observed live: one worker stuck for
        // 20+ minutes, hammering GlobalData, while the other four idled).
        $clientIndexKey = 'dc_presence_clients';
        self::seedClientIndex($clientIndexKey);
        $attempts = 0;
        do {
            $currentList = $global->$clientIndexKey;
            $clientList = is_array($currentList) ? array_values($currentList) : [];
            if (!in_array($client_id, $clientList, true)) {
                $clientList[] = $client_id;
            }
            $oldForCas = is_array($currentList) ? $currentList : [];
            if ($currentList === $clientList || $global->cas($clientIndexKey, $oldForCas, $clientList)) {
                break;
            }
        } while (self::casShouldRetry($clientIndexKey, ++$attempts));

        // CRIT-8 fix: Add to active clients for viewport filtering (CAS loop for atomicity)
        $activeClientsKey = 'dc_active_clients';
        self::seedClientIndex($activeClientsKey);
        $attempts = 0;
        do {
            $activeClients = $global->$activeClientsKey ?? [];
            $activeClients = is_array($activeClients) ? $activeClients : [];
            if (!in_array($client_id, $activeClients, true)) {
                $activeClients[] = $client_id;
            }
            $oldActiveClients = $global->$activeClientsKey ?? null;
            if ($oldActiveClients === $activeClients) {
                break;
            }
            if ($global->cas($activeClientsKey, $oldActiveClients ?? [], $activeClients)) {
                break;
            }
        } while (self::casShouldRetry($activeClientsKey, ++$attempts));

        // Bot Presence System: spawn a bot avatar for this location if one doesn't exist.
        // The bot spawns NEAR the joining player (contract BOT-BOUNDS) so it is
        // actually visible instead of wandering empty space somewhere else.
        if (FeatureFlags::dcBotPresenceEnabled()) {
            self::spawnBotForLocation(self::BOT_DEFAULT_LOCATION, ['x' => $x, 'z' => $z]);
        }

        Worker::safeEcho("[{$client_id}] dc.presence.join: {$uid} joined at ({$x}, {$z}, {$yaw})\n");
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => new \stdClass()
        ]));
        // Use the local entry that was just written — avoids race condition where
        // another worker could have modified $global->$key between write and re-read
        $broadcastEntry = $newEntry;
        // Frontend expects camelCase clientId, not snake_case client_id
        $broadcastEntry['clientId'] = $broadcastEntry['client_id'];
        unset($broadcastEntry['client_id']);
        self::broadcastDcPresence('dc.presence.joined', $broadcastEntry, "[{$client_id}] dc.presence.join");
    }

    /**
     * Handle dc.presence.move — client position/rotation update in the 3D scene.
     *
     * Fire-and-forget: NO reply is sent to the sender (reduces server→client
     * traffic). If the member has not yet called dc.presence.join (i.e. they
     * have no entry in $global->dc_presence), the update is silently ignored.
     *
     * Also accepts the SAME optional `bounds` field dc.presence.join accepts
     * (contract BOT-BOUNDS): the browser only knows the real room extents after
     * its inventory fetch + geometry build, which lands seconds AFTER join, so
     * join almost always arrives without them and the bot would wander the ±50
     * fallback box forever. The client re-reports them once the room exists and
     * again after a location switch — not on every move.
     *
     * @param string $client_id
     * @param array $envelope v1 envelope with data{x,z,yaw} (all optional) and
     *                        an optional data{bounds:{minX,maxX,minZ,maxZ}}
     */
    public static function handleDcPresenceMove($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $data = is_array($envelope['data']) ? $envelope['data'] : [];

        // Contract BOT-BOUNDS on the move path. Deliberately handled BEFORE the
        // 150ms throttle: a bounds report is rare and one-shot, so letting the
        // throttle swallow it would put us straight back to "bounds never
        // arrive". The common no-bounds move pays ONE isset() and performs no
        // extra GlobalData read or write.
        //
        // The <location> key component is the compile-time BOT_DEFAULT_LOCATION
        // constant, never anything the client sent — same as the join path — so
        // there is no key-injection surface here. Validation goes through the
        // shared sanitiseRoomBounds(), and a rejected value leaves whatever
        // bounds are already stored untouched rather than overwriting good
        // bounds with garbage.
        if (isset($data['bounds']) && $global !== null && !empty($_SESSION['uid'])) {
            $reportedBounds = self::sanitiseRoomBounds($data['bounds']);
            if ($reportedBounds !== null) {
                $global->{self::DC_ROOM_BOUNDS_KEY_PREFIX . self::BOT_DEFAULT_LOCATION} = $reportedBounds;
            }
        }

        // Per-client move rate limit: 150ms minimum between moves (matching client THROTTLE_MS)
        if ($global !== null) {
            $throttleKey = 'dc_move_throttle:'.$client_id;
            $lastMove = $global->{$throttleKey} ?? 0;
            if ($lastMove > 0 && (microtime(true) - $lastMove) < 0.15) {
                return; // Throttled - less than 150ms since last move
            }
            $global->{$throttleKey} = microtime(true);
        }
        $uid = $_SESSION['uid'] ?? null;
        if (empty($uid)) {
            return;
        }
        // ($data was decoded above, before the throttle, for the bounds report.)
        $x   = isset($data['x']) && is_numeric($data['x']) ? (float) $data['x'] : null;
        $z   = isset($data['z']) && is_numeric($data['z']) ? (float) $data['z'] : null;
        $yaw = isset($data['yaw']) && is_numeric($data['yaw']) ? (float) $data['yaw'] : null;

        // BUG-B1: NEVER trust a client-supplied clientId. The old
        // `intval($data['clientId'])` both mangled the hex client_id into
        // garbage AND let any client move ANOTHER client's avatar (no ownership
        // check). The connection's own $client_id is the only authority; a
        // supplied clientId is tolerated only when it matches (older clients
        // echo their own id back), otherwise the move is dropped.
        if (isset($data['clientId']) && (string) $data['clientId'] !== (string) $client_id) {
            return;
        }
        $moveClientId = $client_id;
        // Per-client key: each browser tab has its own presence entry
        $key = 'dc_presence:client:' . $moveClientId;
        $entry = $global->$key;
        if (!$entry || !is_array($entry)) {
            return;  // member not in scene — silent ignore per spec
        }

        // CAS the individual key (only this uid's entry, no shared array)
        $newEntry = $entry;
        $newEntry['x'] = $x ?? $entry['x'];
        $newEntry['z'] = $z ?? $entry['z'];
        $newEntry['yaw'] = $yaw ?? $entry['yaw'];
        $newEntry['ts'] = time();
        if (!isset($newEntry['client_id'])) {
            $newEntry['client_id'] = $client_id;
        }
        if (!$global->cas($key, $entry, $newEntry)) {
            // CAS failed — retry once
            $entry = $global->$key;
            if (!$entry) return;
            $newEntry = $entry;
            $newEntry['x'] = $x ?? $entry['x'];
            $newEntry['z'] = $z ?? $entry['z'];
            $newEntry['yaw'] = $yaw ?? $entry['yaw'];
            $newEntry['ts'] = time();
            if (!isset($newEntry['client_id'])) $newEntry['client_id'] = $client_id;
            if (!$global->cas($key, $entry, $newEntry)) {
                // Note: on CAS failure after 2 retries, we fall back to a direct write.
                // This may overwrite concurrent changes from another process. Acceptable
                // for infrequently-updated presence fields (x/z/yaw).
                $global->$key = $newEntry;
                return;
            }
        }

        // Queue for batched broadcast — stores in GlobalData so timer on ANY worker can flush.
        // Static $moveBatch is process-local; with N BusinessWorker processes the timer could
        // fire on a different process that has an empty batch, silently dropping all moves.
        $batchKey = 'dc_move_batch:' . $moveClientId;
        $global->{$batchKey} = json_encode($newEntry);

        // Schedule flush if not already scheduled (one-shot timer, re-armed on next move)
        self::scheduleDcPresenceFlush();
    }

    /**
     * Arm the one-shot 50ms presence-batch flush timer, if not already armed.
     *
     * BUG-B7: handleDcPresenceMove() and moveBot() each carried their own copy
     * of this closure and the copies had drifted (moveBot's skipped viewport
     * filtering entirely). Both now arm the SAME flushPresenceBatch().
     *
     * self::$moveBatchTimer is process-local static across the 5 BusinessWorker
     * processes; the one-shot + re-arm-on-next-move semantics are unchanged
     * (flushPresenceBatch() nulls it again as its first side effect).
     */
    private static function scheduleDcPresenceFlush(): void
    {
        if (self::$moveBatchTimer !== null) {
            return;
        }
        self::$moveBatchTimer = \Workerman\Timer::add(0.05, function () {
            self::flushPresenceBatch();
        }, [], false);
    }

    /**
     * Flush the pending dc_move_batch:* entries as one dc.presence.batch_updated
     * event. Shared by handleDcPresenceMove() and moveBot() (BUG-B7).
     *
     * Viewport filtering (BUG-B5) is decided PER RECIPIENT, not globally: the
     * old code set one $hasAnyViewport flag and, as soon as ANY client had a
     * dc_viewport entry, sent only to clients that had viewport data. dc.js
     * reports its viewport only on location switch / GPU-context restore, so
     * every client that had done neither silently received zero movement
     * updates. Now a client with FRESH viewport data gets the filtered subset
     * and a client with no/stale viewport data (older than
     * DC_VIEWPORT_MAX_AGE) gets the unfiltered batch.
     *
     * Note recipients are enumerated from the dc_active_clients index (kept in
     * step with dc_presence_clients by join/leave/onClose); when NOBODY has
     * fresh viewport data we fall back to a single group broadcast, which also
     * covers any dc_presence group member missing from that index.
     */
    private static function flushPresenceBatch(): void
    {
        global $global;
        // REVIEW-FIX: release the one-shot slot FIRST. The timer that scheduled us
        // has already fired, so the handle is stale by definition — but it used to
        // be nulled only after the GlobalData batch read, and
        // scheduleDcPresenceFlush() early-returns while the field is non-null.
        // Any Throwable before that assignment (a GlobalData read error is the
        // obvious one) therefore wedged the field non-null forever and NO further
        // presence flush could ever be armed in this worker again — all movement
        // broadcasting for its clients stops permanently, with no bot or player
        // ever recovering. Clearing it up front also means a move that lands
        // during this flush correctly arms the next one.
        self::$moveBatchTimer = null;
        // Read ALL move batch entries from GlobalData (keys are dc_move_batch:{client_id})
        $batch = [];
        $clientIndexKey = 'dc_presence_clients';
        $clientList = $global->$clientIndexKey ?? [];
        if (is_array($clientList)) {
            foreach ($clientList as $cid) {
                $bk = 'dc_move_batch:' . $cid;
                $encoded = $global->{$bk};
                if ($encoded) {
                    $decoded = json_decode($encoded, true);
                    // REVIEW-FIX: require an ARRAY. `if ($decoded)` also accepts
                    // a scalar (json_decode('5') === 5), and a scalar entry then
                    // reaches isInPeerViewport($entry['x'], ...) below as null,
                    // which is a TypeError against its float params — a fatal
                    // inside a 50ms timer callback.
                    if (is_array($decoded)) {
                        $batch[$cid] = $decoded;
                    }
                }
            }
        }
        if (empty($batch)) {
            return;
        }

        $vpCutoff = time() - self::DC_VIEWPORT_MAX_AGE;
        $activeClients = $global->dc_active_clients ?? [];
        if (!is_array($activeClients)) {
            $activeClients = [];
        }

        // Pass 1: split recipients into "has fresh viewport" and "does not".
        $filtered = [];   // cid => visible subset of $batch
        $unfiltered = []; // cids that must receive the whole batch
        foreach ($activeClients as $cid) {
            // Bots are presence entries, not sockets — never a send target.
            if (is_string($cid) && strpos($cid, 'bot_') === 0) {
                continue;
            }
            $ck = 'dc_client_session:' . $cid;
            if (!($global->$ck ?? null)) {
                continue;
            }
            $peerVp = $global->{'dc_viewport:' . $cid} ?? null;
            $vpFresh = is_array($peerVp) && (int) ($peerVp['ts'] ?? 0) >= $vpCutoff;
            if (!$vpFresh) {
                $unfiltered[] = $cid;
                continue;
            }
            $visibleEntries = [];
            foreach ($batch as $moverCid => $moverEntry) {
                // REVIEW-FIX: a batch entry with no/non-numeric x|z used to be
                // passed straight into isInPeerViewport()'s `float` params —
                // null there is a TypeError (fatal in the timer callback), and a
                // numeric string would silently coerce. Missing coordinates now
                // FAIL OPEN (entry is kept), matching isInPeerViewport()'s own
                // documented "no data = broadcast" contract; better a redundant
                // update than a dead flush timer.
                if (!isset($moverEntry['x'], $moverEntry['z'])
                    || !is_numeric($moverEntry['x']) || !is_numeric($moverEntry['z'])) {
                    $visibleEntries[$moverCid] = $moverEntry;
                    continue;
                }
                if (self::isInPeerViewport((float) $moverEntry['x'], (float) $moverEntry['z'], $peerVp)) {
                    $visibleEntries[$moverCid] = $moverEntry;
                }
            }
            $filtered[$cid] = $visibleEntries;
        }

        if (empty($filtered)) {
            // Nobody has usable viewport data — one group broadcast (this is the
            // path that used to be a dead Channel\Client::publish, see BUG-A3).
            self::broadcastDcPresence('dc.presence.batch_updated', $batch, 'dc.presence.batch');
        } else {
            foreach ($filtered as $cid => $visibleEntries) {
                if (empty($visibleEntries)) {
                    continue; // every mover is outside this client's viewport
                }
                Gateway::sendToClient($cid, json_encode(self::v1Envelope('dc.presence.batch_updated', $visibleEntries)));
            }
            if (!empty($unfiltered)) {
                $payload = json_encode(self::v1Envelope('dc.presence.batch_updated', $batch));
                foreach ($unfiltered as $cid) {
                    Gateway::sendToClient($cid, $payload);
                }
            }
        }

        // CRIT-7 fix: Clear batch entries from GlobalData after flush
        foreach ($batch as $moverCid => $moverEntry) {
            $bk = 'dc_move_batch:' . $moverCid;
            unset($global->{$bk});
        }
    }

    /**
     * Handle dc.presence.leave — client exiting the datacenter 3D scene.
     *
     * Removes the member's entry from $global->dc_presence[client:$client_id],
     * broadcasts dc.presence.left to the dc_presence channel (using client_id
     * so each browser tab is tracked independently), and replies with {ok: true}.
     *
     * @param string $client_id
     * @param array $envelope v1 envelope
     */
    public static function handleDcPresenceLeave($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $re = $envelope['id'];
        $uid = $_SESSION['uid'] ?? null;
        if (empty($uid)) {
            self::sendV1Error($client_id, $re, 'forbidden', 'dc.presence.leave requires authentication');
            return;
        }
        // Per-client key so each browser tab has its own presence entry
        $key = 'dc_presence:client:' . $client_id;
        $entry = $global->$key;
        if (!$entry) {
            self::sendV1Error($client_id, $re, 'forbidden', 'dc.presence.leave: member not found');
            return;
        }

        // Atomic delete: individual key deletion is inherently atomic (single key op)
        $global->$key = null;

        // Clean up client_id index (CAS loop to prevent concurrent leaves on different workers losing entries)
        $clientIndexKey = 'dc_presence_clients';

        // Bot Presence System: check if this was the last real user at this location
        // If so, clean up the bot for that location
        $wasLastRealUser = false;
        $clientListBefore = $global->$clientIndexKey ?? [];
        if (is_array($clientListBefore)) {
            $realUserCount = 0;
            foreach ($clientListBefore as $cid) {
                if (is_string($cid) && strpos($cid, 'bot_') === 0) {
                    continue;  // Skip bot client IDs
                }
                if ($cid !== $client_id) {
                    $realUserCount++;
                }
            }
            if ($realUserCount === 0) {
                $wasLastRealUser = true;
            }
        }

        do {
            $clientList = $global->$clientIndexKey ?? [];
            if (!is_array($clientList)) { break; }
            $newList = array_values(array_filter($clientList, fn($c) => $c !== $client_id));
            if ($newList === $clientList) { break; }  // nothing changed
            $oldList = $clientList;
        } while (!$global->cas($clientIndexKey, $oldList, $newList));

        // REVIEW-FIX (index drift): dc.presence.leave used to remove the client
        // from dc_presence_clients ONLY, leaving it in dc_active_clients. The two
        // indexes then disagreed until the socket happened to close, and
        // flushPresenceBatch() enumerates RECIPIENTS from dc_active_clients — so a
        // client that had left the scene kept being sent batch_updated events and
        // kept rendering avatars for a scene it was no longer in. Unlike the
        // health-timer drop path this one never calls closeClient(), so onClose()
        // does not clean up behind it.
        $activeClientsKey = 'dc_active_clients';
        do {
            $activeClients = $global->$activeClientsKey ?? [];
            $activeClients = is_array($activeClients) ? $activeClients : [];
            $filteredActive = array_values(array_filter($activeClients, fn($c) => $c !== $client_id));
            $oldActiveClients = $global->$activeClientsKey ?? null;
            if ($oldActiveClients === $filteredActive) {
                break;
            }
        } while (!$global->cas($activeClientsKey, $oldActiveClients ?? [], $filteredActive));

        // REVIEW-FIX: same orphaning as onClose() — once the client is out of
        // dc_presence_clients nothing will ever read or delete a pending batch
        // entry for it. The viewport entry is likewise scene-scoped.
        unset($global->{'dc_move_batch:' . $client_id});
        unset($global->{'dc_viewport:' . $client_id});

        // If this was the last real user, clean up the bot for this location
        if ($wasLastRealUser && FeatureFlags::dcBotPresenceEnabled()) {
            self::cleanupBotForLocation(self::BOT_DEFAULT_LOCATION);
        }

        Worker::safeEcho("[{$client_id}] dc.presence.leave: uid={$uid} client_id={$client_id} left the scene\n");
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => new \stdClass()
        ]));
        self::broadcastDcPresence(
            'dc.presence.left',
            ['uid' => $uid, 'clientId' => $client_id],
            "[{$client_id}] dc.presence.leave"
        );
    }

    /**
     * IDEA-3: Handle dc.viewport.update — client reports its camera position + look direction.
     * Stores viewport data in $global keyed by client_id for use in presence move filtering.
     *
     * @param string $client_id
     * @param array $envelope v1 envelope
     */
    public static function handleDcViewportUpdate($client_id, $envelope)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        // MAJOR-14: require session auth
        if (empty($_SESSION['uid']) || empty($_SESSION['login'])) {
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $vp = $data;  // $data IS the viewport object, not $data['data']
        $global->{'dc_viewport:' . $client_id} = [
            'x' => (float)($vp['x'] ?? 0),
            'y' => (float)($vp['y'] ?? 0),
            'z' => (float)($vp['z'] ?? 0),
            'dirX' => (float)($vp['dirX'] ?? 0),
            'dirY' => (float)($vp['dirY'] ?? 0),
            'dirZ' => (float)($vp['dirZ'] ?? 0),
            'viewDist' => (float)($vp['viewDist'] ?? 50),
            'ts' => time(),
        ];
    }

    /**
     * Session health timer: sends a ping to every dc_presence client every 30s
     * and drops any that have missed 3+ consecutive pings (>90s since last pong).
     */
    public static function setupSessionHealthTimer()
    {
        \Workerman\Timer::add(30, function () {
            global $global;
            $now = time();

            // Iterate via client_id index (dc_presence_clients) to avoid reading monolithic dc_presence array
            $clientIndexKey = 'dc_presence_clients';
            $clientList = $global->$clientIndexKey ?? [];
            if (!is_array($clientList) || empty($clientList)) {
                return;
            }

            // CRIT-9 fix: Three-phase approach to avoid reading newly-written ping timestamps.
            // Phase 1: Snapshot every client's last pong + last ping-sent BEFORE pinging.
            // Phase 2: Send this round's pings (writes dc_ping_sent AFTER the snapshot).
            // Phase 3: Judge staleness from the Phase 1 snapshot, then drop.
            //
            // BUG-B4: Phase 2 used to overwrite dc_ping (the value Phase 3 tests)
            // for EVERY client on EVERY sweep, so the 90s check could never be
            // true and this watchdog was dead. Ping-send times now live in
            // dc_ping_sent: and staleness is measured only from the last pong
            // RECEIVED (dc_ping:) — see dcPresenceIsStale().
            $threshold = 90;  // 3 × 30s missed = stale

            $toDrop = [];  // collect {clientId} entries to drop after the ping phase
            $clientEntries = [];  // clientId => [entry, clientId, lastPong, lastPingSent]

            // Phase 1: Read all entries and their OLD liveness timestamps
            foreach ($clientList as $clientId) {
                // Bots have a presence entry but no socket — never ping or drop
                // them.
                // REVIEW-FIX: this check has to come BEFORE the stale-entry check
                // below. A bot whose presence entry has gone missing (the window
                // inside cleanupBotForLocation() between nulling the entry and
                // removing the index entry, or an early `break` out of that CAS
                // loop) otherwise fell into the socket-drop path: closeClient() on
                // a non-hex "bot_main" id, and the index/presence cleanup ran
                // WITHOUT cleanupBotForLocation(), leaving dc_bot_state +
                // dc_bot_timer alive. The owning worker then kept walking a bot
                // that no longer appears in dc_presence_clients, so
                // flushPresenceBatch() could never pick its moves up again — a
                // permanently invisible bot with no path back.
                if (is_string($clientId) && strpos($clientId, 'bot_') === 0) {
                    continue;
                }
                $key = 'dc_presence:client:' . $clientId;
                $entry = $global->$key;
                if (!$entry || !is_array($entry)) {
                    $toDrop[] = ['clientId' => $clientId];  // stale entry, mark for cleanup
                    continue;
                }
                $clientEntries[$clientId] = [
                    'entry' => $entry,
                    'clientId' => $clientId,
                    'lastPong' => (int) ($global->{self::DC_PONG_KEY_PREFIX . $clientId} ?? 0),
                    'lastPingSent' => (int) ($global->{self::DC_PING_SENT_KEY_PREFIX . $clientId} ?? 0)
                ];
            }

            // Phase 2: Send pings to all active clients (records the SEND time only)
            foreach ($clientEntries as $clientId => $data) {
                Gateway::sendToClient($clientId, json_encode([
                    'v' => 1, 'op' => 'ping', 'id' => 'keepalive', 'ts' => $now,
                    'data' => new \stdClass()
                ]));
                // REVIEW-FIX: BUG-B4 was only half fixed. Rewriting dc_ping_sent
                // on EVERY sweep reproduced the original defect for the
                // never-ponged branch of dcPresenceIsStale(): Phase 1 always read
                // a value ~30s old, so `lastPingSent < now - 90` could never be
                // true and a client that never pongs at all was immune to the
                // watchdog forever. dc_ping_sent must mark the START of the
                // current UNANSWERED streak, so only re-arm it once the previous
                // ping has been answered (or none was ever sent). A client that
                // has an outstanding ping keeps its original send time and is
                // therefore dropped after ~120s of total silence — still well
                // past the 90s threshold, so "pinged but not yet ponged" is never
                // dropped prematurely.
                if ($data['lastPingSent'] === 0 || $data['lastPong'] >= $data['lastPingSent']) {
                    $global->{self::DC_PING_SENT_KEY_PREFIX . $clientId} = $now;
                }
            }

            // Phase 3: Check staleness using the Phase 1 snapshot and drop stale clients
            foreach ($clientEntries as $clientId => $data) {
                if (self::dcPresenceIsStale($data['lastPong'], $data['lastPingSent'], $now, $threshold)) {
                    $toDrop[] = ['clientId' => $clientId];
                }
            }

            // Drop stale clients AFTER the loop (avoids modifying clientList during iteration)
            // CRIT-9 fix: Two-phase approach — mark cleanup before CAS removal to prevent orphaned entries
            foreach ($toDrop as $dropInfo) {
                $clientId = $dropInfo['clientId'];
                if (!$clientId) {
                    continue;
                }
                $presenceKey = 'dc_presence:client:' . $clientId;
                $entry = $global->$presenceKey;

                // Phase 1: Mark client as being cleaned up (prevents race with handleDcPresenceJoin)
                // Write a sentinel value so concurrent joins can detect cleanup-in-progress
                $global->{'dc_cleanup:' . $clientId} = $now;

                // CRIT-9: If this client's cleanup has already been handled by onClose, skip
                // Only skip if sentinel is set AND key is already null (onClose completed cleanup)
                // If sentinel set but key is not null, timer should still proceed (onClose in progress)
                if ($global->{'dc_cleanup:' . $clientId} && $global->$presenceKey === null) {
                    // REVIEW-FIX (leak): this `continue` used to jump over the
                    // unset() further down, so every already-cleaned client left
                    // a dc_cleanup:<client_id> sentinel behind permanently — and
                    // this is the branch taken by EVERY stale index entry that
                    // Phase 1 queued (those have a null presence key by
                    // definition). Drop our own marker before bailing out.
                    unset($global->{'dc_cleanup:' . $clientId});
                    continue;
                }

                // Phase 2: Delete per-client presence key atomically
                $global->$presenceKey = null;

                // Clean up session mapping
                $ck = 'dc_client_session:' . $clientId;
                $sessionId = $global->$ck ?? null;
                if ($sessionId) {
                    $listKey = 'dc_session_clients:' . $sessionId;
                    $clients = $global->$listKey ?? [];
                    $clients = array_values(array_filter($clients, fn($c) => $c !== $clientId));
                    $global->$listKey = $clients;
                    unset($global->$ck);
                }
                unset($global->{self::DC_PONG_KEY_PREFIX . $clientId});
                unset($global->{self::DC_PING_SENT_KEY_PREFIX . $clientId});
                unset($global->{'dc_cleanup:' . $clientId});
                Gateway::closeClient($clientId, 'missed_keepalive');

                // Remove clientId from dc_presence_clients index (CAS loop)
                $clientIndexKey = 'dc_presence_clients';
                do {
                    $currentList = $global->$clientIndexKey ?? [];
                    if (!is_array($currentList)) break;
                    $newList = array_values(array_filter($currentList, fn($c) => $c !== $clientId));
                    if ($newList === $currentList) break;
                    $oldList = $currentList;
                } while (!$global->cas($clientIndexKey, $oldList, $newList));

                Worker::safeEcho("[dc_presence] dropped {$clientId} — missed keepalive\n");
            }
        });
    }

    // ====================================================================
    // Bot Presence System (DataCenter 3D)
    // ====================================================================

    /**
     * Validate + normalise browser-reported room bounds (contract BOT-BOUNDS).
     *
     * The browser MAY send `bounds: {minX,maxX,minZ,maxZ}` (window.DC.roomBounds)
     * on dc.presence.join. All four must be numeric + finite, minX < maxX,
     * minZ < maxZ, and the spans must be neither degenerate nor absurd —
     * anything else is rejected outright (returns null) so a hostile or buggy
     * client cannot teleport the bot to (1e300, 1e300).
     *
     * @param mixed $raw the untrusted `bounds` value
     * @return array{minX:float,maxX:float,minZ:float,maxZ:float}|null
     */
    private static function sanitiseRoomBounds($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        foreach (['minX', 'maxX', 'minZ', 'maxZ'] as $field) {
            if (!isset($raw[$field]) || !is_numeric($raw[$field])) {
                return null;
            }
        }
        $minX = (float) $raw['minX'];
        $maxX = (float) $raw['maxX'];
        $minZ = (float) $raw['minZ'];
        $maxZ = (float) $raw['maxZ'];
        foreach ([$minX, $maxX, $minZ, $maxZ] as $value) {
            if (!is_finite($value) || abs($value) > self::BOT_BOUNDS_MAX_COORD) {
                return null;
            }
        }
        if ($minX >= $maxX || $minZ >= $maxZ) {
            return null;
        }
        $spanX = $maxX - $minX;
        $spanZ = $maxZ - $minZ;
        if ($spanX < self::BOT_BOUNDS_MIN_SPAN || $spanZ < self::BOT_BOUNDS_MIN_SPAN) {
            return null;
        }
        if ($spanX > self::BOT_BOUNDS_MAX_SPAN || $spanZ > self::BOT_BOUNDS_MAX_SPAN) {
            return null;
        }
        return ['minX' => $minX, 'maxX' => $maxX, 'minZ' => $minZ, 'maxZ' => $maxZ];
    }

    /**
     * Resolve the walkable box for a location, inset slightly so the bot never
     * clips the walls.
     *
     * Uses the browser-reported bounds when a valid set has been recorded
     * (contract BOT-BOUNDS), otherwise falls back to the legacy ±50 constants —
     * which are around the WORLD ORIGIN and therefore nowhere near the room
     * dc.js actually builds (racks start at offsetX/offsetZ = -100).
     *
     * @param string $location Datacenter location name
     * @return array{minX:float,maxX:float,minZ:float,maxZ:float}
     */
    private static function dcRoomBounds(string $location): array
    {
        global $global;
        $bounds = null;
        if ($global !== null) {
            $bounds = self::sanitiseRoomBounds($global->{self::DC_ROOM_BOUNDS_KEY_PREFIX . $location} ?? null);
        }
        if ($bounds === null) {
            $bounds = [
                'minX' => self::BOT_BOUNDS_X_MIN,
                'maxX' => self::BOT_BOUNDS_X_MAX,
                'minZ' => self::BOT_BOUNDS_Z_MIN,
                'maxZ' => self::BOT_BOUNDS_Z_MAX
            ];
        }
        $inset = min(
            self::BOT_BOUNDS_INSET,
            ($bounds['maxX'] - $bounds['minX']) / 4,
            ($bounds['maxZ'] - $bounds['minZ']) / 4
        );
        return [
            'minX' => $bounds['minX'] + $inset,
            'maxX' => $bounds['maxX'] - $inset,
            'minZ' => $bounds['minZ'] + $inset,
            'maxZ' => $bounds['maxZ'] - $inset
        ];
    }

    /**
     * Pick a point inside $bounds, within $radius scene units of $near when a
     * reference position is available (uniform over the disc), otherwise
     * uniformly anywhere in $bounds.
     *
     * @param array{x:float,z:float}|null                     $near
     * @param array{minX:float,maxX:float,minZ:float,maxZ:float} $bounds
     * @param float                                           $radius
     * @return array{0:float,1:float} [x, z]
     */
    private static function randomPointNear(?array $near, array $bounds, float $radius): array
    {
        if ($near === null) {
            return [
                $bounds['minX'] + lcg_value() * ($bounds['maxX'] - $bounds['minX']),
                $bounds['minZ'] + lcg_value() * ($bounds['maxZ'] - $bounds['minZ'])
            ];
        }
        $angle = lcg_value() * 2 * M_PI;
        $dist = sqrt(lcg_value()) * $radius;  // sqrt => uniform over the disc
        return [
            max($bounds['minX'], min($bounds['maxX'], $near['x'] + cos($angle) * $dist)),
            max($bounds['minZ'], min($bounds['maxZ'], $near['z'] + sin($angle) * $dist))
        ];
    }

    /**
     * Last known position of a randomly chosen REAL (non-bot) client, so the
     * bot wanders where the humans are instead of picking uniformly random
     * points across the whole box (THE BOT #3 — the actual user request).
     *
     * @return array{x:float,z:float}|null null when no real player position is known
     */
    private static function randomRealClientPosition(): ?array
    {
        global $global;
        if ($global === null) {
            return null;
        }
        $clientList = $global->dc_presence_clients ?? [];
        if (!is_array($clientList) || empty($clientList)) {
            return null;
        }
        $positions = [];
        foreach ($clientList as $cid) {
            if (is_string($cid) && strpos($cid, 'bot_') === 0) {
                continue;  // not a real player
            }
            $entry = $global->{'dc_presence:client:' . $cid};
            if (!is_array($entry) || !isset($entry['x'], $entry['z'])
                || !is_numeric($entry['x']) || !is_numeric($entry['z'])) {
                continue;
            }
            $positions[] = ['x' => (float) $entry['x'], 'z' => (float) $entry['z']];
        }
        if (empty($positions)) {
            return null;
        }
        return $positions[array_rand($positions)];
    }

    /**
     * Is the BusinessWorker process that owns a location's bot timer still alive?
     *
     * The owner marker (dc_bot_timer:<location>) is a pid. When that process is
     * gone its timers died with it, so the bot must be respawned elsewhere
     * instead of sitting frozen. Non-numeric markers (legacy/test values) are
     * treated as alive so existing behaviour is preserved.
     *
     * THREE-HOST CORRECTNESS. datacentered runs on three systems that share ONE
     * GlobalData store, so a marker may name a process on a different machine —
     * and pids are not unique across machines. A bare `is_dir('/proc/<pid>')` is a
     * purely LOCAL check, so it was wrong in both directions:
     *   - foreign pid that happens to exist locally  -> "alive" -> a bot whose real
     *     owner died is never respawned; it just sits frozen (indistinguishable from
     *     "the bot is broken");
     *   - foreign pid that does not exist locally    -> "gone"  -> this host spawns a
     *     second bot over one another host is still driving.
     * Markers are therefore host-qualified ("<host>:<pid>", see processMarker()). For
     * a marker owned by ANOTHER host we cannot inspect the process, so liveness comes
     * from the bot's own heartbeat: moveBot() rewrites dc_bot_state:<location>.ts every
     * BOT_MOVE_INTERVAL (0.5s), so a fresh ts proves the remote driver is running. We
     * only take over once that heartbeat has gone stale, which means we never steal a
     * bot another instance is actively walking.
     *
     * @param mixed      $owner value stored in dc_bot_timer:<location>
     * @param array|null $state current dc_bot_state:<location>, for the heartbeat check
     * @return bool
     */
    private static function botOwnerAlive($owner, ?array $state = null): bool
    {
        // Legacy/test marker: a bare pid, always same-host by definition.
        if (is_int($owner) || (is_string($owner) && ctype_digit($owner))) {
            $pid = (int) $owner;
            return $pid === getmypid() ? true : is_dir('/proc/' . $pid);
        }
        if (!is_string($owner) || strpos($owner, ':') === false) {
            return true;  // unrecognised marker — nothing we can assert
        }
        [$host, $pidPart] = explode(':', $owner, 2);
        if (!ctype_digit($pidPart)) {
            return true;
        }
        $pid = (int) $pidPart;
        if ($host === self::localHostName()) {
            return $pid === getmypid() ? true : is_dir('/proc/' . $pid);
        }
        // Owned by another instance: trust the heartbeat, not our /proc.
        $ts = is_array($state) && isset($state['ts']) && is_numeric($state['ts'])
            ? (int) $state['ts']
            : 0;
        return $ts >= time() - self::BOT_OWNER_HEARTBEAT_MAX_AGE;
    }

    /**
     * Cached hostname. Identifies which of the three datacentered instances a
     * shared-GlobalData marker belongs to.
     */
    private static function localHostName(): string
    {
        if (self::$localHostName === null) {
            $host = gethostname();
            self::$localHostName = ($host === false || $host === '') ? 'unknown-host' : $host;
        }
        return self::$localHostName;
    }

    /**
     * Ownership marker for a process-local resource recorded in shared GlobalData:
     * "<host>:<pid>". Host-qualified because pids collide across the three instances.
     */
    private static function processMarker(): string
    {
        return self::localHostName() . ':' . getmypid();
    }

    /**
     * Does an ownership marker name THIS process? Tolerates the legacy bare-pid form
     * so a marker written by an instance that has not been reloaded yet is still
     * recognised during a rolling restart across the three hosts — otherwise the
     * owning process would fail to match its own marker and retire its own timer,
     * killing the bot on its first tick.
     *
     * @param mixed $marker value stored in dc_bot_timer:<location>
     */
    private static function markerIsSelf($marker): bool
    {
        if (is_int($marker) || (is_string($marker) && ctype_digit((string) $marker))) {
            return (int) $marker === getmypid();   // legacy bare pid
        }
        return is_string($marker) && $marker === self::processMarker();
    }

    /**
     * Spawn a bot avatar for a given datacenter location if one doesn't exist.
     * The bot walks around the datacenter building, simulating a real user.
     * Uses GlobalData to store bot state so multiple BusinessWorkers can access it.
     *
     * @param string                          $location Datacenter location name (default: 'main')
     * @param array{x:float,z:float}|null     $near     joining player's position; the bot spawns
     *                                                  within BOT_SPAWN_RADIUS of it (BOT-BOUNDS)
     * @param array|null                      $bounds   raw browser-reported room bounds to record
     *                                                  before spawning (validated here)
     */
    public static function spawnBotForLocation(string $location = self::BOT_DEFAULT_LOCATION, ?array $near = null, ?array $bounds = null): void
    {
        if (!FeatureFlags::dcBotPresenceEnabled()) {
            return;
        }

        global $global;

        $botId = 'bot_' . $location;
        $botTimerKey = 'dc_bot_timer:' . $location;
        $botStateKey = 'dc_bot_state:' . $location;

        // Contract BOT-BOUNDS: record any freshly reported room bounds first so
        // the spawn position below is computed inside the REAL room.
        $reportedBounds = self::sanitiseRoomBounds($bounds);
        if ($reportedBounds !== null) {
            $global->{self::DC_ROOM_BOUNDS_KEY_PREFIX . $location} = $reportedBounds;
        }

        // Check for a stale owner marker from lost state.
        // THE BOT #4: dc_bot_timer:<location> holds the OWNING PID, never a
        // timer id — Workerman timer ids are per-process and Timer::del() from
        // another BusinessWorker would kill an unrelated timer. The real id
        // lives in the process-local self::$botTimers.
        $existingOwner = $global->{$botTimerKey} ?? null;
        $existingState = $global->{$botStateKey} ?? null;
        if ($existingOwner !== null) {
            if ($existingState !== null && self::botOwnerAlive($existingOwner, $existingState)) {
                return; // Bot already exists for this location and its driver is alive
            }
            if ($existingState !== null) {
                // Owner process is gone (worker reload/crash) — its timer died with
                // it, so the bot would sit frozen forever. Take ownership here.
                Worker::safeEcho("[dc_bot] bot '{$location}' owner pid {$existingOwner} is gone; respawning in pid ".getmypid()."\n");
                unset($global->{$botStateKey});
            }
            // Stale marker with no state — only the creating process may delete
            // the timer; from anywhere else just drop the marker and respawn here.
            if (isset(self::$botTimers[$location])) {
                Timer::del(self::$botTimers[$location]);
                unset(self::$botTimers[$location]);
            } elseif ($existingOwner !== self::processMarker()) {
                Worker::safeEcho("[dc_bot] stale bot marker for '{$location}' owned by {$existingOwner}; not deleting its timer from ".self::processMarker()."\n");
            }
            unset($global->{$botTimerKey});
        }

        // Pick a random bot name
        $botName = self::$botNames[array_rand(self::$botNames)] . ' ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 4);

        // Spawn near the joining player (THE BOT #1/#3), clamped inside the room.
        $roomBounds = self::dcRoomBounds($location);
        $anchor = null;
        if (is_array($near) && isset($near['x'], $near['z']) && is_numeric($near['x']) && is_numeric($near['z'])) {
            $anchor = ['x' => (float) $near['x'], 'z' => (float) $near['z']];
        } else {
            $anchor = self::randomRealClientPosition();
        }
        list($spawnX, $spawnZ) = self::randomPointNear($anchor, $roomBounds, self::BOT_SPAWN_RADIUS);
        $spawnYaw = lcg_value() * 2 * M_PI;  // Random initial facing direction

        // Initialize bot state
        $botState = [
            'uid' => $botId,
            'name' => $botName,
            'x' => $spawnX,
            'z' => $spawnZ,
            'yaw' => $spawnYaw,
            'target_x' => $spawnX,
            'target_z' => $spawnZ,
            'ts' => time(),
            'client_id' => $botId,
            'location' => $location,
            'bounds' => $roomBounds,
        ];
        Worker::safeEcho('[dc_bot] spawn x=' . $botState['x'] . ' z=' . $botState['z'] . "\n");
        $global->$botStateKey = $botState;

        // Write bot presence entry to GlobalData (same format as real users)
        $presenceKey = 'dc_presence:client:' . $botId;
        $global->$presenceKey = $botState;

        // Add bot to active clients list (CRIT-8 pattern for atomicity).
        // seedClientIndex() + bounded retry for the same reason as the join path:
        // an absent key makes cas([], …) unsatisfiable forever. Reachable here only
        // via a join (which now seeds first), but this loop must not be the one that
        // wedges a worker if that ever stops being true.
        $activeClientsKey = 'dc_active_clients';
        self::seedClientIndex($activeClientsKey);
        $attempts = 0;
        do {
            $activeClients = $global->$activeClientsKey ?? [];
            $activeClients = is_array($activeClients) ? $activeClients : [];
            if (!in_array($botId, $activeClients, true)) {
                $activeClients[] = $botId;
            }
            $oldActiveClients = $global->$activeClientsKey ?? null;
            if ($oldActiveClients === $activeClients) {
                break;
            }
            if ($global->cas($activeClientsKey, $oldActiveClients ?? [], $activeClients)) {
                break;
            }
        } while (self::casShouldRetry($activeClientsKey, ++$attempts));

        // Add bot to client index so it's included in batch broadcasts
        $clientIndexKey = 'dc_presence_clients';
        self::seedClientIndex($clientIndexKey);
        $attempts = 0;
        do {
            $currentList = $global->$clientIndexKey;
            $clientList = is_array($currentList) ? array_values($currentList) : [];
            if (!in_array($botId, $clientList, true)) {
                $clientList[] = $botId;
            }
            $oldForCas = is_array($currentList) ? $currentList : [];
            if ($currentList === $clientList || $global->cas($clientIndexKey, $oldForCas, $clientList)) {
                break;
            }
        } while (self::casShouldRetry($clientIndexKey, ++$attempts));

        // Broadcast bot presence to the dc_presence group so frontends create avatars
        // Frontend expects camelCase clientId, not snake_case client_id
        $botBroadcastEntry = $botState;
        $botBroadcastEntry['clientId'] = $botBroadcastEntry['client_id'];
        unset($botBroadcastEntry['client_id']);
        self::broadcastDcPresence('dc.presence.joined', $botBroadcastEntry, "[dc_bot] {$botId}");

        // Start the bot movement timer
        // Using repeating timer that calls moveBot every BOT_MOVE_INTERVAL seconds
        $timerId = Timer::add(
            self::BOT_MOVE_INTERVAL,
            ['Events', 'moveBot'],
            [$location],
            true  // repeating
        );
        // THE BOT #4: keep the (process-local) timer id process-local, and publish
        // only the owning pid so other workers can see a bot exists.
        self::$botTimers[$location] = $timerId;
        // Host-qualified: three instances share this GlobalData store and pids are not
        // unique across them. See processMarker() / botOwnerAlive().
        $global->{$botTimerKey} = self::processMarker();

        Worker::safeEcho("[dc_bot] spawned bot '{$botName}' ({$botId}) at location '{$location}' at ({$spawnX}, {$spawnZ})\n");
    }

    /**
     * Move the bot for a given location - called every BOT_MOVE_INTERVAL seconds.
     * Implements realistic wandering: picks a target point and walks toward it.
     *
     * @param string $location Datacenter location name
     */
    public static function moveBot(string $location = self::BOT_DEFAULT_LOCATION): void
    {
        if (!FeatureFlags::dcBotPresenceEnabled()) {
            self::cleanupBotForLocation($location);
            return;
        }

        global $global;

        $botId = 'bot_' . $location;
        $botStateKey = 'dc_bot_state:' . $location;
        $botTimerKey = 'dc_bot_timer:' . $location;

        // THE BOT #4: only the marker-owning process drives the bot. If another
        // BusinessWorker has taken ownership (e.g. it respawned the bot after our
        // state was lost), retire OUR timer rather than double-driving the shared
        // state. A process only ever deletes a timer it created itself.
        $owner = $global->{$botTimerKey} ?? null;
        if (isset(self::$botTimers[$location]) && $owner !== null && !self::markerIsSelf($owner)) {
            Timer::del(self::$botTimers[$location]);
            unset(self::$botTimers[$location]);
            Worker::safeEcho("[dc_bot] retiring duplicate bot timer for '{$location}' in ".self::processMarker()." (owner is {$owner})\n");
            return;
        }

        $botState = $global->{$botStateKey};

        if (!$botState || !is_array($botState)) {
            // Bot state missing - try to recover or stop
            self::cleanupBotForLocation($location);
            return;
        }

        $currentX = (float)$botState['x'];
        $currentZ = (float)$botState['z'];
        $targetX = (float)($botState['target_x'] ?? $currentX);
        $targetZ = (float)($botState['target_z'] ?? $currentZ);

        // Calculate distance to target
        $dx = $targetX - $currentX;
        $dz = $targetZ - $currentZ;
        $distance = sqrt($dx * $dx + $dz * $dz);

        // Room bounds for this location (browser-reported when available).
        $roomBounds = self::dcRoomBounds($location);
        $botState['bounds'] = $roomBounds;

        // If close to target or no target, pick a new one NEAR a real player
        // (THE BOT #3) inside the room bounds (THE BOT #1); fall back to a
        // random point in bounds when no real player position is known.
        if ($distance < self::BOT_TARGET_THRESHOLD) {
            $anchor = self::randomRealClientPosition();
            list($targetX, $targetZ) = self::randomPointNear($anchor, $roomBounds, self::BOT_WANDER_RADIUS);
            $botState['target_x'] = $targetX;
            $botState['target_z'] = $targetZ;

            // Recalculate for new target
            $dx = $targetX - $currentX;
            $dz = $targetZ - $currentZ;
            $distance = sqrt($dx * $dx + $dz * $dz);
        }

        if ($distance > 0.01) {
            // Normalize direction
            $dirX = $dx / $distance;
            $dirZ = $dz / $distance;

            // Move toward target (speed * interval = distance per tick), never
            // PAST it: one tick is BOT_WALK_SPEED * BOT_MOVE_INTERVAL = 5.85
            // units, well over BOT_TARGET_THRESHOLD (1.0), so an unclamped step
            // would overshoot and the bot would oscillate around its target
            // forever instead of ever "arriving" and picking a new one.
            $moveDistance = min(self::BOT_WALK_SPEED * self::BOT_MOVE_INTERVAL, $distance);
            $newX = $currentX + $dirX * $moveDistance;
            $newZ = $currentZ + $dirZ * $moveDistance;

            // Clamp to the room bounds (should already be inside, but safety check)
            $newX = max($roomBounds['minX'], min($roomBounds['maxX'], $newX));
            $newZ = max($roomBounds['minZ'], min($roomBounds['maxZ'], $newZ));

            // Calculate yaw to face movement direction
            $yaw = atan2(-$dirX, -$dirZ);  // Yaw in radians, 0 = facing +Z

            $botState['x'] = $newX;
            $botState['z'] = $newZ;
            $botState['yaw'] = $yaw;
            $botState['ts'] = time();

            // Update bot state in GlobalData
            $global->$botStateKey = $botState;

            // NO per-tick position log here. moveBot() runs on a BOT_MOVE_INTERVAL
            // timer for every location with a bot, so a safeEcho() here is one
            // fflush()ed line per bot per tick, forever — it was the top line in
            // billingd.log once the GateWaySSL drain spam was fixed. The bot's
            // lifecycle is already covered by the spawn / cleanup / ownership logs
            // above, which fire once per event instead of once per tick.

            // Write to batch key so batch timer broadcasts this move
            $batchKey = 'dc_move_batch:' . $botId;
            $global->{$batchKey} = json_encode($botState);

            // BUG-B7: schedule the ONE shared flush (this used to be a second,
            // drifted copy of handleDcPresenceMove()'s closure that skipped
            // viewport filtering entirely).
            self::scheduleDcPresenceFlush();
        }
    }

    /**
     * Clean up (remove) the bot for a given datacenter location.
     * Called when the last real user leaves the location.
     *
     * @param string $location Datacenter location name
     */
    public static function cleanupBotForLocation(string $location = self::BOT_DEFAULT_LOCATION): void
    {
        global $global;

        $botId = 'bot_' . $location;
        $botTimerKey = 'dc_bot_timer:' . $location;
        $botStateKey = 'dc_bot_state:' . $location;

        // Stop and remove the timer.
        // THE BOT #4: a Workerman timer id is only valid in the process that
        // created it, so only that process may Timer::del() it. Everywhere else
        // we clear the shared state and let the owner's next moveBot() tick find
        // dc_bot_state gone, re-enter this function in ITS process and delete
        // its own timer.
        $owner = $global->{$botTimerKey} ?? null;
        if (isset(self::$botTimers[$location])) {
            Timer::del(self::$botTimers[$location]);
            unset(self::$botTimers[$location]);
            unset($global->{$botTimerKey});
        } elseif ($owner !== null) {
            Worker::safeEcho("[dc_bot] cleanup for '{$location}' requested in ".self::processMarker().", timer owned by {$owner} — leaving marker for the owner to reap\n");
        }

        // Remove bot state (this is what makes the owning process stop next tick)
        unset($global->{$botStateKey});

        // Remove bot presence entry
        $presenceKey = 'dc_presence:client:' . $botId;
        $global->$presenceKey = null;

        // Remove from active clients list (CRIT-8 pattern)
        $activeClientsKey = 'dc_active_clients';
        do {
            $activeClients = $global->$activeClientsKey ?? [];
            $activeClients = is_array($activeClients) ? $activeClients : [];
            $filtered = array_values(array_filter($activeClients, fn($c) => $c !== $botId));
            $oldActiveClients = $global->$activeClientsKey ?? null;
            if ($oldActiveClients === $filtered) {
                break;
            }
        } while (!$global->cas($activeClientsKey, $oldActiveClients ?? [], $filtered));

        // Remove from client index
        $clientIndexKey = 'dc_presence_clients';
        do {
            $currentList = $global->$clientIndexKey ?? [];
            if (!is_array($currentList)) break;
            $newList = array_values(array_filter($currentList, fn($c) => $c !== $botId));
            if ($newList === $currentList) break;
            $oldList = $currentList;
        } while (!$global->cas($clientIndexKey, $oldList, $newList));

        // Clean up any pending batch entries
        unset($global->{'dc_move_batch:' . $botId});

        // Tell the frontends to drop the bot avatar. spawnBotForLocation()
        // announces dc.presence.joined, so despawn must announce the matching
        // dc.presence.left — otherwise (now that presence broadcasts actually
        // reach clients, BUG-A3) a cleaned-up bot would linger as a ghost avatar
        // until the page reloads.
        self::broadcastDcPresence(
            'dc.presence.left',
            ['uid' => $botId, 'clientId' => $botId],
            "[dc_bot] {$botId}"
        );

        Worker::safeEcho("[dc_bot] cleaned up bot for location '{$location}'\n");
    }

    /**
     * Check if any real (non-bot) users are present at a location.
     * Returns true if there are real users, false if only bots or no users.
     *
     * @param string $location Datacenter location name
     * @return bool True if real users exist
     */
    private static function hasRealUsersAtLocation(string $location = self::BOT_DEFAULT_LOCATION): bool
    {
        global $global;

        $clientIndexKey = 'dc_presence_clients';
        $clientList = $global->$clientIndexKey ?? [];

        if (!is_array($clientList) || empty($clientList)) {
            return false;
        }

        foreach ($clientList as $clientId) {
            // Skip bot client IDs
            if (is_string($clientId) && strpos($clientId, 'bot_') === 0) {
                continue;
            }
            // This is a real user
            Worker::safeEcho('[dc_bot] hasRealUsersAtLocation=true location=' . $location . "\n");
            return true;
        }

        Worker::safeEcho('[dc_bot] hasRealUsersAtLocation=false location=' . $location . "\n");
        return false;
    }

    /**
     * When the client is disconnected
     *
     * @param string $client_id client id
     */
    public static function onClose($client_id)
    {
        /**
         * @var \GlobalData\Client
         */
        global $global;
        self::logStructured('client.close', ['client_id' => $client_id, 'uid' => $_SESSION['uid'] ?? null]);

        // Broadcast dc.presence.left BEFORE cleaning up — proactively notify remaining
        // clients so their avatars disappear immediately instead of waiting for
        // setupSessionHealthTimer (up to 30s later).
        $uid = $_SESSION['uid'] ?? null;
        if ($uid) {
            // Per-client presence key so each browser tab is tracked independently
            $presenceKey = 'dc_presence:client:' . $client_id;
            $presenceEntry = $global->$presenceKey;
            if ($presenceEntry && is_array($presenceEntry)) {
                self::broadcastDcPresence(
                    'dc.presence.left',
                    ['uid' => $uid, 'clientId' => $client_id],
                    "[{$client_id}] client.close"
                );
                // CRIT-9 fix: Two-phase cleanup — mark before CAS removal to prevent race with health timer
                // Phase 1: Mark client as being cleaned up (so health timer can detect and skip)
                $global->{'dc_cleanup:' . $client_id} = time();

                // Phase 2: Atomic delete of per-client key
                $global->$presenceKey = null;
                // Remove client_id from index (CAS loop)
                $clientIndexKey = 'dc_presence_clients';
                do {
                    $clientList = $global->$clientIndexKey ?? [];
                    if (!is_array($clientList)) {
                        break;
                    }
                    $newList = array_values(array_filter($clientList, fn($c) => $c !== $client_id));
                    if ($newList === $clientList) {
                        break;
                    }
                    $oldList = $clientList;
                } while (!$global->cas($clientIndexKey, $oldList, $newList));

                unset($global->{'dc_cleanup:' . $client_id});
            }
        }

        // Remove from active clients list (CRIT-8 fix: use CAS for atomicity)
        $activeClientsKey = 'dc_active_clients';
        do {
            $activeClients = $global->$activeClientsKey ?? [];
            $activeClients = is_array($activeClients) ? $activeClients : [];
            $filtered = array_values(array_filter($activeClients, fn($c) => $c !== $client_id));
            $oldActiveClients = $global->$activeClientsKey ?? null;
            if ($oldActiveClients === $filtered) {
                break;
            }
        } while (!$global->cas($activeClientsKey, $oldActiveClients ?? [], $filtered));

        // Clean up dc_client_session and the liveness keys for this client
        $sessionKey = 'dc_client_session:' . $client_id;
        $sessionId = $global->$sessionKey ?? null;
        if ($sessionId) {
            $listKey = 'dc_session_clients:' . $sessionId;
            $clients = $global->$listKey ?? [];
            if (is_array($clients)) {
                $clients = array_filter($clients, fn($c) => $c !== $client_id);
                $global->$listKey = array_values($clients);
            }
            unset($global->$sessionKey);
        }
        // Unconditional: these are written by the health timer even for clients
        // whose session mapping has already gone away.
        unset($global->{self::DC_PONG_KEY_PREFIX . $client_id});
        unset($global->{self::DC_PING_SENT_KEY_PREFIX . $client_id});
        // IDEA-3: clean up viewport data for this client
        unset($global->{'dc_viewport:' . $client_id});
        // MAJOR-13: clean up move throttle key for this client
        unset($global->{'dc_move_throttle:' . $client_id});
        // REVIEW-FIX (unbounded growth): dc_move_batch:<client_id> had NO deletion
        // path outside flushPresenceBatch(), and that flush only enumerates
        // dc_presence_clients. A client that disconnects in the <=50ms between
        // writing its move batch and the flush is removed from that index first,
        // so its batch key was orphaned in GlobalData forever. With a 150ms move
        // throttle against a 50ms flush the window is hit routinely, so the store
        // grew by one permanent key per unlucky disconnect.
        unset($global->{'dc_move_batch:' . $client_id});

        if (isset($_SESSION['uid'])) {
            $clientIds = Gateway::getClientIdByUid($_SESSION['uid']);
            if (count($clientIds) == 1 && isset($global->rooms) && sizeof($global->rooms) > 0) {
                $logoutMessage = [
                    'type' => 'logout',
                    'id' => $_SESSION['uid'],
                    'time' => date('Y-m-d H:i:s')
                ];
                $rooms = $global->rooms;
                if (!is_array($rooms)) $rooms = [];
                $oldRooms = $rooms;
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
                    $attempts = 0;
                    do {
                        $oldRooms = $global->rooms;
                        if (!is_array($oldRooms)) break;
                        $rooms = $oldRooms;
                        foreach ($oldRooms as $idx => $room) {
                            if (($key = array_search($_SESSION['uid'], $room['members'])) !== false) {
                                unset($rooms[$idx]['members'][$key]);
                            }
                        }
                        $rooms = array_values($rooms);
                    } while (!$global->cas('rooms', $oldRooms, $rooms) && $attempts++ < 5 && usleep(1000));
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
                    $global->admin_hosts_cache = null;
                    $global->admin_hosts_cache_ttl = null;
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
        if (is_null(self::$db)) {
            self::$db = self::createDbConnection();
            if (is_null(self::$db)) return;
        }
        /**
         * @var \GlobalData\Client
         */
        global $global;
        $var = 'processing_queue';
        $lastVar = $var.'_last';
        if (!isset($global->$var)) {
            $global->$var = 0;
        }
        $lockValue = $global->$var;
        if ($lockValue !== 0 && (time() - (int)$lockValue) > 900) {
            Worker::safeEcho("processing_queue_timer: stale lock held since ".date('c', (int)$lockValue).", force-resetting\n");
            $global->$var = 0;
        }
        if ($global->cas($var, 0, time())) {
            // NOTE: For performance, ensure queue_log has a compound index on (history_section, history_new_value).
            // Verified in staging with: SHOW INDEX FROM queue_log WHERE Key_name = 'idx_boardctl_pending';
            // If missing, run: ALTER TABLE queue_log ADD INDEX idx_boardctl_pending (history_section, history_new_value);
            // This index also benefits similar queries at boardctl_queue_timer (line ~4955), boardctl_startup_reap (line ~4210),
            // and processing_queue_reaper (line ~4263).
            try {
                $results = self::$db->select('*')->from('queue_log')->where("history_section='process_payment' and history_new_value='pending'")->query();
            } catch (\Exception $e) {
                Worker::safeEcho("processing_queue_timer DB error: {$e->getMessage()}\n");
                self::$db = self::createDbConnection();
                self::releaseProcessingLock();
                return;
            }
            if (!is_array($results)) {
                Worker::safeEcho("processing_queue_timer: DB query returned non-array, reconnecting\n");
                self::$db = self::createDbConnection();
                self::releaseProcessingLock();
                return;
            }
            if (sizeof($results) > 0) {
                self::process_results($results);
            } else {
                self::releaseProcessingLock();
            }
        }
    }

    /**
     * Mark the processing queue lock as still alive.
     *
     * The lock holds the acquisition time and processing_queue_timer() treats a
     * lock older than 900s as abandoned. Long-but-healthy chains call this to
     * push that deadline forward so their lock is not force-reset mid-run.
     */
    private static function refreshProcessingLock()
    {
        global $global;
        $var = 'processing_queue';
        // Only refresh a lock we actually hold; never resurrect a released one.
        if ((int)$global->$var !== 0) {
            $global->$var = time();
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
     * Recover boardctl jobs orphaned by a datacentered restart. A boardctl run is
     * a proc_open ssh child of the TaskWorker process, so a full restart kills it
     * while its queue_log row is still 'processing' — and boardctl_queue_job then
     * refuses to queue a rerun for that asset (duplicate guard). This resets such
     * rows to 'failed' so an operator can re-queue.
     *
     * Called ONLY from the GlobalData cold-start block in onWorkerStart (guarded by
     * $global->add('running')), which fires when the GlobalData server is freshly
     * created — i.e. a full restart, never a graceful reload. A long-running job
     * (up to the 6h cap) that survives a reload is therefore never touched. NOT a
     * periodic timer on purpose: a time-based sweep cannot tell a 6h job that is
     * still running apart from one that died, and would kill live jobs.
     */
    public static function boardctl_startup_reap()
    {
        if (is_null(self::$db)) {
            self::$db = self::createDbConnection();
            if (is_null(self::$db)) return;
        }
        // boardctl jobs now run as detached processes (scripts/boardctl_runner.php)
        // that survive a datacentered restart, so we must NOT blindly fail every
        // 'processing' row -- only those whose runner is genuinely gone. Each live
        // runner writes /home/my/logs/boardctl/<historyId>.pid; if that pid is
        // still alive the job is still going, so leave it. Rows with no pidfile or
        // a dead pid are orphans (pre-detach in-worker jobs, or a runner that died
        // hard) and get marked failed so they can be re-queued.
        $logDir = '/home/my/logs/boardctl';
        try {
            $rows = self::$db->select('history_id')->from('queue_log')
                ->where("history_section='boardctl' AND history_new_value='processing'")
                ->query();
        } catch (\Exception $e) {
            Worker::safeEcho("boardctl_startup_reap DB error: {$e->getMessage()}\n");
            return;
        }
        if (!is_array($rows) || count($rows) === 0) {
            return;
        }
        foreach ($rows as $row) {
            $historyId = intval($row['history_id']);
            if ($historyId <= 0) {
                continue;
            }
            $pidFile = $logDir.'/'.$historyId.'.pid';
            $alive = false;
            if (is_file($pidFile)) {
                $pid = intval(trim((string)@file_get_contents($pidFile)));
                // posix_kill($pid, 0) => true if the process exists and we may signal it.
                if ($pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0)) {
                    $alive = true;
                }
            }
            if ($alive) {
                Worker::safeEcho("boardctl_startup_reap: history_id={$historyId} runner still alive, leaving it\n");
                continue;
            }
            try {
                self::$db->query("UPDATE queue_log SET history_new_value='failed',"
                    ." history_old_value=CONCAT(COALESCE(history_old_value,''), '\n[datacentered restarted — job did not survive; marked failed, re-queue to run again]\n')"
                    ." WHERE history_id=".$historyId);
            } catch (\Exception $e) {
                Worker::safeEcho("boardctl_startup_reap DB error for history_id={$historyId}: {$e->getMessage()}\n");
            }
        }
    }

    /**
     * Recover payment-processing rows stuck in 'processing'. These happen when a
     * task connection closes without a response or a stale-lock force-reset
     * abandons an in-flight dispatch, leaving the row mid-flight forever. Reset
     * them to 'pending' so the timer retries them (process_payment is idempotent
     * — it skips already-active services). Scoped to recent rows so the historical
     * backlog of long-orphaned 'processing' rows is not mass-reprocessed.
     */
    public static function processing_queue_reaper()
    {
        if (is_null(self::$db)) {
            self::$db = self::createDbConnection();
            if (is_null(self::$db)) return;
        }
        try {
            self::$db->query("UPDATE queue_log SET history_new_value='pending'"
                ." WHERE history_section='process_payment' AND history_new_value='processing'"
                ." AND history_timestamp >= (NOW() - INTERVAL 6 HOUR)"
                ." AND history_timestamp < (NOW() - INTERVAL 15 MINUTE)");
        } catch (\Exception $e) {
            Worker::safeEcho("processing_queue_reaper DB error: {$e->getMessage()}\n");
            self::$db = self::createDbConnection();
        }
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
        /*
         * queue_log.history_timestamp is `DEFAULT CURRENT_TIMESTAMP` with no
         * ON UPDATE clause, so it records when the row was enqueued and is never
         * touched again. processing_queue_reaper() measures "stuck in
         * processing" from that column -- so without stamping it here, any row
         * that waited in pending longer than the reaper's 15 minute threshold
         * (a backlog, a restart, lock contention) is eligible for reaping the
         * instant it enters processing. The reaper flips it back to pending
         * while the task is still in flight and the timer dispatches a second
         * concurrent process_payment() for the same invoice.
         *
         * Stamping on every transition makes the column mean "time of the last
         * state change", which is what the reaper needs and what the column's
         * own comment already claims it holds.
         *
         * Written as raw SQL rather than through the query builder so NOW() is
         * evaluated server-side, matching the reaper's own NOW() comparisons --
         * a PHP-side timestamp would silently drift if PHP and MySQL disagree
         * on timezone. $status is interpolated, so it is whitelisted first.
         */
        if (!in_array($status, ['pending', 'processing', 'completed', 'failed'], true)) {
            Worker::safeEcho("dbUpdateWithRetry: refusing unknown status '{$status}' for history_id={$historyId}, releasing lock\n");
            self::releaseProcessingLock();
            return;
        }
        try {
            self::$db->query("UPDATE queue_log SET history_new_value='".$status."', history_timestamp=NOW()"
                ." WHERE history_id=".intval($historyId));
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
        /*
         * Refresh the lock before each result. The 900s stale-lock reset in
         * processing_queue_timer() is not tied to any bound on how long this
         * chain can run -- dispatchTask() has no timeout, and each result costs
         * a task round trip plus up to 30 seconds of dbUpdateWithRetry backoff,
         * so a large batch legitimately exceeds 900s. Without this heartbeat the
         * timer steals the lock from a chain that is still working and starts a
         * second one alongside it.
         *
         * Heartbeating keeps the stale reset meaningful: it now only fires for a
         * chain that has genuinely stopped making progress, rather than for one
         * that is merely slow. (boardctl solves the same problem by pinning its
         * timeout above a known runner cap; there is no equivalent cap here.)
         */
        self::refreshProcessingLock();
        $result = array_shift($results);
        self::dbUpdateWithRetry('processing', $result['history_id'], function () use ($result, $results) {
            Worker::safeEcho("payment processing about to spawn task for ".json_encode($result, true)."\n");
            self::dispatchTask('processing_queue_task', $result, function ($task_result) use ($result, $results) {
                $decoded = json_decode($task_result, true);
                $success = is_array($decoded) && !empty($decoded['return']);
                $status = $success ? 'completed' : 'failed';
                self::dbUpdateWithRetry($status, $result['history_id'], function () use ($result, $results, $status) {
                    Worker::safeEcho("finished queued payment processing task (history_id={$result['history_id']}, status={$status})\n");
                    if (count($results) > 0) {
                        self::process_results($results);
                    } else {
                        self::releaseProcessingLock();
                    }
                });
            }, function () {
                self::releaseProcessingLock();
            }, self::PAYMENT_TASK_ADDRESS);
        });
    }


    /**
     * timer function to check for vps queue items
     *
     */
    public static function vps_queue_timer()
    {
        if (is_null(self::$db)) {
            self::$db = self::createDbConnection();
            if (is_null(self::$db)) return;
        }
        /**
         * @var \GlobalData\Client
         */
        global $global;
        try {
            $results = self::$db->select('*')->from('queue_log')->leftJoin('vps', 'vps_id=history_type')->where('history_section="vpsqueue"')->query();
        } catch (\Exception $e) {
            Worker::safeEcho("vps_queue_timer DB error: {$e->getMessage()}\n");
            self::$db = self::createDbConnection();
            return;
        }
        if (!is_array($results)) {
            self::$db = self::createDbConnection();
            return;
        }
        if (sizeof($results) > 0) {
            $queues = [];
            foreach ($results as $row) {
                if (is_numeric($row['history_type'])) {
                    if (is_null($row['vps_id'])) {
                        // no vps id in db matching, delete — skip
                        continue;
                    }
                    $id = $row['vps_server'];
                    if (in_array($id, array_keys($global->hosts))) {
                        if (!in_array($id, array_keys($queues))) {
                            $queues[$id] = [];
                        }
                        $queues[$id][] = $row;
                    }
                } else {
                    $id = str_replace('vps', '', $row['history_type']);
                    if (in_array($id, array_keys($global->hosts))) {
                        if (!in_array($id, array_keys($queues))) {
                            $queues[$id] = [];
                        }
                        $queues[$id][] = $row;
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
     * @param string $client_id
     * @param array $message_data
     */
    public static function msgSelfUpdate($client_id, $message_data)
    {
        if ($_SESSION['login'] === true && $_SESSION['ima'] == 'admin') {
            Gateway::sendToGroup('hosts', json_encode($message_data));
        }
        return;
    }



    /**
     * handler for when receiving a vps details lsit message
     *
     * @param string $client_id
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
     * @param string $client_id
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
     * @param string $client_id
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
     * @param string $client_id
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
     * @param string $client_id
     * @param array $message_data
     */
    public static function msgClients($client_id, $message_data)
    {
        /**
        * @var \GlobalData\Client
        */
        global $global;
        if ($_SESSION['login'] === true && $_SESSION['ima'] == 'admin') {
            $admin_sessions = Gateway::getClientSessionsByGroup('admins');
            $host_sessions = Gateway::getClientSessionsByGroup('hosts');
            $sessions = array_merge($admin_sessions ?: [], $host_sessions ?: []);
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
     * @param string $client_id
     * @param array $message_data
     */
    public static function msgTimers($client_id, $message_data)
    {
        /**
        * @var \GlobalData\Client
        */
        global $global;
        if ($_SESSION['login'] === true && $_SESSION['ima'] == 'admin') {
            $message_data = [
                'type' => 'timers',
                //'channel' => ChannelClient::getStatus(),
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
     * @param string $client_id
     * @param array $message_data
     */
    public static function msgSay($client_id, $message_data)
    {
        if ($_SESSION['login'] === true) {
            // client speaks message: {type:say, is: client|room, to:xx, content:xx}
            if (!isset($message_data['to'])) {
                self::sendToClient($client_id, json_encode([
                    'op' => 'error',
                    'data' => ['code' => 'MISSING_TO', 'msg' => 'to field required']
                ]));
                return;
            }
            if (!isset($message_data['is'])) {
                self::sendToClient($client_id, json_encode([
                    'op' => 'error',
                    'data' => ['code' => 'MISSING_IS', 'msg' => 'is field required']
                ]));
                return;
            }
            if (!isset($message_data['content'])) {
                self::sendToClient($client_id, json_encode([
                    'op' => 'error',
                    'data' => ['code' => 'MISSING_CONTENT', 'msg' => 'content field required']
                ]));
                return;
            }
            return self::say($_SESSION['uid'], $message_data['is'], $message_data['to'], $message_data['content'], $_SESSION['name']);
        }
        return;
    }

    /**
     * handler for when receiving a pong message.
     *
     * @param string $client_id
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
     * @param string $client_id
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
     * @param string $client_id
     * @param array $message_data
     */
    public static function msgRunLocal($client_id, $message_data)
    {
        Worker::safeEcho("[{$client_id}] Got Run Command ".json_encode($message_data).PHP_EOL);
        if ($_SESSION['login'] === true) {
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
     * @param string $client_id
     * @param array $message_data
     */
    public static function msgRun($client_id, $message_data)
    {
        Worker::safeEcho("[{$client_id}] Got Run Command ".json_encode($message_data).PHP_EOL);
        if ($_SESSION['login'] === true) {
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
     * @param string $client_id
     * @param array $message_data
     */
    public static function msgRunning($client_id, $message_data)
    {
        /**
        * @var \GlobalData\Client
        */
        global $global;
        Worker::safeEcho("[{$client_id}] Got Running Command ".json_encode($message_data).PHP_EOL);
        if ($_SESSION['login'] === true) {
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
     * @param string $client_id
     * @param array $message_data
     */
    public static function msgPaymentprocess($client_id, $message_data)
    {
        //Gateway::sendToClient($client_id, json_encode('ok'));
        Gateway::closeClient($client_id, json_encode('ok'));
        self::processing_queue_timer();
        self::boardctl_queue_timer();
    }

    /**
     * timer function to check for queued boardctl jobs (run-all / recover-bmc-creds).
     *
     * Concurrency model: one job in-flight per asset at a time, but multiple assets
     * may run concurrently (capped only by TaskWorker process count, currently 20).
     * Per-asset locking uses GlobalData CAS on a key derived from the asset id; the
     * mystage queue helper already prevents duplicate pending/processing rows per
     * asset so the lock is mostly belt-and-braces against rare race windows.
     *
     * history_type is encoded as "<action>:<assetId>" — we parse the asset id out
     * for the lock key so different actions on the same asset still serialize.
     */
    public static function boardctl_queue_timer()
    {
        if (is_null(self::$db)) {
            self::$db = self::createDbConnection();
            if (is_null(self::$db)) return;
        }
        global $global;
        // NOTE: For performance, ensure queue_log has a compound index on (history_section, history_new_value).
        // Verified in staging with: SHOW INDEX FROM queue_log WHERE Key_name = 'idx_boardctl_pending';
        // If missing, run: ALTER TABLE queue_log ADD INDEX idx_boardctl_pending (history_section, history_new_value);
        // This index also benefits similar queries at processing_queue_timer (line ~4130), boardctl_startup_reap (line ~4210),
        // and processing_queue_reaper (line ~4263).
        try {
            $results = self::$db->select('*')->from('queue_log')->where("history_section='boardctl' and history_new_value='pending'")->query();
        } catch (\Exception $e) {
            Worker::safeEcho("boardctl_queue_timer DB error: {$e->getMessage()}\n");
            self::$db = self::createDbConnection();
            return;
        }
        if (!is_array($results) || sizeof($results) == 0) {
            return;
        }
        foreach ($results as $row) {
            $parts = explode(':', (string)$row['history_type'], 2);
            $assetId = isset($parts[1]) ? intval($parts[1]) : intval($row['history_type']);
            if ($assetId <= 0) {
                Worker::safeEcho("boardctl: skipping history_id={$row['history_id']} with unparseable type '{$row['history_type']}'\n");
                continue;
            }
            $lockVar = 'boardctl_asset_'.$assetId;
            if (!isset($global->$lockVar)) {
                $global->$lockVar = 0;
            }
            $lockValue = $global->$lockVar;
            // 22200s = 6hr task cap (boardctl_run_job BOARDCTL_MAX_RUNTIME_SECONDS) + 10min buffer.
            // Must stay >= the runner cap so a legitimately long-running job's lock
            // is never reset mid-run (which would let a duplicate job start).
            if ($lockValue !== 0 && (time() - (int)$lockValue) > 22200) {
                Worker::safeEcho("boardctl: stale lock for asset {$assetId}, force-resetting\n");
                $global->$lockVar = 0;
            }
            if (!$global->cas($lockVar, 0, time())) {
                // another job for this asset is already in flight
                continue;
            }
            try {
                self::$db->update('queue_log')->cols(['history_new_value' => 'processing'])->where('history_id='.intval($row['history_id']))->query();
            } catch (\Throwable $e) {
                Worker::safeEcho("boardctl: failed to mark history_id={$row['history_id']} processing: {$e->getMessage()}\n");
                $global->$lockVar = 0;
                continue;
            }
            Worker::safeEcho("boardctl spawning task for history_id={$row['history_id']} asset={$assetId} type={$row['history_type']}\n");
            // boardctl_task now only *spawns* a detached runner and returns at
            // once (the runner owns the CAS lock for the job's lifetime and
            // releases it on completion). So on a successful spawn we must NOT
            // release the lock here -- doing so would let a duplicate start. We
            // only release + mark failed when the spawn itself did not happen.
            self::dispatchTask('boardctl_task', $row, function ($task_result) use ($row, $lockVar) {
                global $global;
                $outer = json_decode((string)$task_result, true);
                $return = is_array($outer) && array_key_exists('return', $outer) ? $outer['return'] : $task_result;
                $decoded = is_string($return) ? json_decode($return, true) : $return;
                if (is_array($decoded) && !empty($decoded['spawned'])) {
                    // Runner launched; it will release $lockVar when the job ends.
                    return;
                }
                Worker::safeEcho("boardctl: runner did not spawn for history_id={$row['history_id']}, releasing lock\n");
                try {
                    self::$db->update('queue_log')->cols(['history_new_value' => 'failed'])->where('history_id='.intval($row['history_id']))->query();
                } catch (\Throwable $e) {
                    Worker::safeEcho("boardctl: failed to mark history_id={$row['history_id']} failed: {$e->getMessage()}\n");
                }
                $global->$lockVar = 0;
            }, function () use ($row, $lockVar) {
                global $global;
                try {
                    self::$db->update('queue_log')->cols(['history_new_value' => 'failed'])->where('history_id='.intval($row['history_id']))->query();
                } catch (\Throwable $e) {
                    Worker::safeEcho("boardctl: failed to mark history_id={$row['history_id']} failed: {$e->getMessage()}\n");
                }
                $global->$lockVar = 0;
            });
        }
    }

    /**
     * handler for when receiving a ran message.
     *
     * @param string $client_id
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
        if (!isset($running[$id])) {
            return;
        }
        $run = $running[$id];
        if (!is_string($run['for'] ?? null)) {
            return;
        }
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
     * @param string $client_id
     * @param array $message_data
     */
    public static function msgPhpsysinfo($client_id, $message_data)
    {
        Worker::safeEcho(json_encode($message_data).PHP_EOL);
        if ($_SESSION['login'] === true) {
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
     * @param string $client_id
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
                $global->admin_hosts_cache = null;
                $global->admin_hosts_cache_ttl = null;
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
