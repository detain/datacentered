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
     * Create a Workerman MySQL connection using the appropriate host config.
     *
     * No explicit reconnect/charset logic is needed here: workerman/mysql auto-reconnects
     * transparently on MySQL "gone away"/"lost connection" errors (2006/2013) and re-applies
     * the 'utf8mb4' charset passed below on every reconnect.
     *
     * @return \Workerman\MySQL\Connection
     */
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
        $task_connection = new AsyncTcpConnection($address);
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
        self::$db = self::createDbConnection();
        if ($global->add('running', [])) {
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
                $timers['boardctl_queue_timer'] = ['interval' => 15, 'timer_id' => Timer::add(15, ['Events', 'boardctl_queue_timer'], $args)];
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
        if (self::isV1Envelope($message_data)) {
            // Protocol v1 envelope (docs/PROTOCOL_V1.md §1). Additive path beside the
            // legacy {"type":...} dispatch below; gated by Flag A (plan B8) inside
            // dispatchV1() — with the flag OFF the message is inert (no reply).
            self::dispatchV1($client_id, $message_data);
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
     * @param int $client_id gateway client id
     * @param array $envelope validated v1 request envelope (see isV1Envelope())
     */
    public static function dispatchV1($client_id, $envelope)
    {
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
        if ($op === 'ping') {
            $reply = [
                'v' => 1,
                're' => $envelope['id'],
                'ok' => true,
                'data' => new \stdClass()
            ];
            Gateway::sendToClient($client_id, json_encode($reply));
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
            Gateway::bindUid($client_id, $uid);
            Gateway::joinGroup($client_id, 'admins');
            Worker::safeEcho("[{$client_id}] v1 auth.hello: admin {$results[0]['account_lid']} authenticated from {$_SERVER['REMOTE_ADDR']}".PHP_EOL);
            Gateway::sendToClient($client_id, json_encode([
                'v' => 1,
                're' => $re,
                'ok' => true,
                'data' => [
                    'session' => $hub_session,
                    'uid' => $uid,
                    'name' => $results[0]['account_lid'],
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
        // SCALABILITY (see docblock): this CAS round-trips the WHOLE channels
        // map on every append, and the map has no channel-key cap / idle
        // eviction — unbounded dm:* key minting (unvalidated chat.send `to`)
        // grows it without limit. Follow-up: per-channel keys + count cap.
        $global->add('channels', []);
        do {
            $old_value = $new_value = $global->channels;
            if (!is_array($new_value)) {
                $old_value = $new_value = [];
            }
            if (!isset($new_value[$channel]) || !is_array($new_value[$channel])) {
                $new_value[$channel] = [];
            }
            $new_value[$channel][] = $message;
            if (count($new_value[$channel]) > self::CHAT_HISTORY_MAX) {
                $new_value[$channel] = array_slice($new_value[$channel], -self::CHAT_HISTORY_MAX);
            }
        } while (!$global->cas('channels', $old_value, $new_value));
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
     * @param int $client_id publishing client (gets the ack)
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
        if (is_array($recipients)) {
            foreach (array_unique($recipients) as $uid) {
                Gateway::sendToUid($uid, $push);
            }
        } else {
            Gateway::sendToGroup($message['channel'], $push);
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
     * @param int $client_id publishing client
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
            'from' => $_SESSION['uid'] ?? '',
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
     * @param int $client_id gateway client id
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
        $cached = $global->channels;
        if (!is_array($cached)) {
            $cached = [];
        }
        $ids = array_unique(array_merge(array_keys($meta), array_keys($cached)));
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
     * @param int $client_id gateway client id
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
        $cached = $global->channels;
        $history = is_array($cached) && isset($cached[$channel]) && is_array($cached[$channel]) ? array_values($cached[$channel]) : [];
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
        self::chatPublishMessage($client_id, $re, $channel, $body, $level);
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
     * @param int $client_id gateway client id
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
        $from = $_SESSION['uid'] ?? '';
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
     * @param int $client_id gateway client id
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
        $registry = $global->hosts;
        if (!is_array($registry)) {
            $registry = [];
        }
        $hosts = [];
        $admins = [];
        $sessions = Gateway::getAllClientSessions();
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
        Gateway::sendToClient($client_id, json_encode([
            'v' => 1,
            're' => $re,
            'ok' => true,
            'data' => [
                'hosts' => $hosts,
                'admins' => $admins
            ]
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
     * @param int $client_id gateway client id
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
     * @param int $client_id gateway client id
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
            try {
                $results = self::$db->select('*')->from('queue_log')->where('history_section="process_payment" and history_new_value="pending"')->query();
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
        try {
            self::$db->query("UPDATE queue_log SET history_new_value='failed',"
                ." history_old_value=CONCAT(COALESCE(history_old_value,''), '\n[datacentered restarted — job did not survive; marked failed, re-queue to run again]\n')"
                ." WHERE history_section='boardctl' AND history_new_value='processing'");
        } catch (\Exception $e) {
            Worker::safeEcho("boardctl_startup_reap DB error: {$e->getMessage()}\n");
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
        try {
            $results = self::$db->select('*')->from('queue_log')->where('history_section="boardctl" and history_new_value="pending"')->query();
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
            self::dispatchTask('boardctl_task', $row, function ($task_result) use ($lockVar) {
                global $global;
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
