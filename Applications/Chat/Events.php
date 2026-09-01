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
require_once __DIR__.'/SharedState.php';

class Events
{
    /** Dedicated task-worker pool for payment processing, isolated from the
     *  shared 2208 pool so slow VPS/HyperV tasks cannot starve activations. */
    public const PAYMENT_TASK_ADDRESS = 'Text://127.0.0.1:2209';

    /** Bounded per-channel hot-cache depth (PROTOCOL_V1.md §4 / plan B6:
     *  last N=100 messages per channel serve channel.join history and the
     *  live tail; the DB (chat_messages) is the unbounded durable store). */
    public const CHAT_HISTORY_MAX = 100;

    public static $process_handle = null;
    public static $process_pipes = null;
    public static $db = null;
    public static $running = [];

    /**
     * Ownership token for the SharedState `processing_queue` lock held by this
     * process's payment-processing chain (GlobalData→Redis migration, A1).
     * Set by processing_queue_timer() on acquire, cleared by
     * releaseProcessingLock(); ties every renew/unlock to this holder so a
     * chain can never renew or delete a lock another process took after its
     * TTL lapsed. Null means "not held here".
     *
     * @var string|null
     */
    public static $processingLockToken = null;

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
    // setupSessionHealthTimer(). Migration A2 moved these from GlobalData keys
    // to TTL-capped SharedState Redis keys (PRESENCE_PING_TTL):
    //
    //   dc:presence:ping:<client_id>       unix ts of the last pong RECEIVED
    //                                      from the client. 0/absent == never ponged.
    //   dc:presence:ping_sent:<client_id>  unix ts of the last ping the hub SENT
    //                                      to the client. 0/absent == never pinged.
    //
    // Staleness is ALWAYS measured from the last pong received; a client that
    // has been pinged but has not yet had time to answer is never dropped
    // (see dcPresenceIsStale()).
    // ====================================================================

    /** Redis key prefix (SharedState): unix ts of the last pong received from a client. */
    public const DC_PONG_KEY_PREFIX = 'dc:presence:ping:';

    /** Redis key prefix (SharedState): unix ts of the last ping the hub sent to a client. */
    public const DC_PING_SENT_KEY_PREFIX = 'dc:presence:ping_sent:';

    /** Gateway group every dc_presence client is joined to at auth (auth.hello). */
    public const DC_PRESENCE_GROUP = 'dc_presence';

    /** Seconds after which a reported dc:presence:viewport entry is treated as absent (BUG-B5). */
    public const DC_VIEWPORT_MAX_AGE = 30;

    // ====================================================================
    // SharedState (Redis) key map + TTLs — GlobalData→Redis migration (A2).
    //
    // The retired GlobalData monolithic maps became Redis HASHES keyed by
    // entry id (field = id, value = JSON): per-field HSET/HDEL are atomic on
    // their own, so the whole-map CAS read-modify-write loops those registries
    // needed are gone — two racing writers touching different fields cannot
    // clobber each other, and a crashed writer's damage is one field, not the
    // whole map. Per-entity STRING keys carry real TTLs, which likewise
    // replaces the hand-rolled staleness reapers (sysinfos, chat channels,
    // presence liveness).
    //
    // seedClientIndex()/CAS_MAX_ATTEMPTS/casShouldRetry() are gone with this:
    // Redis has no NULL-vs-empty absent-key trap — zAdd/sAdd/hSet create the
    // index on first write, and there is no compare-and-swap to livelock on.
    // ====================================================================

    /** Redis HASH: vps_id => JSON vps_masters row (the shared host registry). */
    public const HOSTS_REGISTRY_KEY = 'dc:state:hosts';

    /** Redis HASH: room id => JSON room object (legacy chat rooms registry). */
    public const ROOMS_REGISTRY_KEY = 'dc:state:rooms';

    /** Field of the seeded default room within ROOMS_REGISTRY_KEY. */
    public const DEFAULT_ROOM_ID = 'room_1';

    /** Redis HASH: channel id => JSON {type,topic,created_by,created_at}. */
    public const CHANNEL_META_REGISTRY_KEY = 'dc:state:channel_meta';

    /** Redis HASH: timer name => JSON {interval,timer_id}. */
    public const TIMERS_REGISTRY_KEY = 'dc:state:timers';

    /** Redis HASH: pty_id => JSON pty session entry. */
    public const PTYS_REGISTRY_KEY = 'dc:state:ptys';

    /** Redis STRING prefix: one JSON entry per in-flight cmd run (run_id). */
    public const RUNNING_KEY_PREFIX = 'dc:state:running:';

    /** Redis SET of run ids, the enumeration index beside RUNNING_KEY_PREFIX. */
    public const RUNNING_INDEX_KEY = 'dc:state:running_ids';

    /** Redis STRING prefix: telemetry.sysinfo relay correlation entries. */
    public const SYSINFO_KEY_PREFIX = 'dc:state:sysinfo:';

    /** Redis STRING: cache-aside payload of handleAdminHosts. */
    public const ADMIN_HOSTS_CACHE_KEY = 'dc:state:admin_hosts_cache';

    /** Redis STRING prefix: per-client dc presence record. */
    public const DC_PRESENCE_KEY_PREFIX = 'dc:presence:client:';

    /** Redis ZSET: every presence member (real + bot), score = last-seen ts. */
    public const DC_PRESENCE_INDEX_KEY = 'dc:presence:index';

    /** Redis ZSET: recipient-enumeration index, score = last-seen ts. */
    public const DC_ACTIVE_INDEX_KEY = 'dc:presence:active';

    /** Redis LIST prefix: bounded per-channel message tail (newest kept). */
    public const CHAT_MSGS_KEY_PREFIX = 'dc:chat:msgs:';

    /** Redis ZSET: channel id => last-activity ts (enumeration + window). */
    public const CHAT_ACTIVITY_KEY = 'dc:chat:activity';

    /** Redis STRING prefix: bot presence state per location. */
    public const BOT_STATE_KEY_PREFIX = 'dc:presence:bot_state:';

    /** Once-per-window cold-start reap lock TTL (seconds). The 60s TTL is the crash guard: a boot that dies mid-reap is retried after a minute instead of never. */
    public const STARTUP_REAP_LOCK_TTL = 60;

    /** In-flight run registry entry TTL (seconds) — bounds leaks from agents that die without cmd.exit. */
    public const RUNNING_ENTRY_TTL = 3600;

    /** telemetry.sysinfo correlation entry TTL (seconds) — replaces the 5-minute reaper Timer. */
    public const SYSINFO_TTL = 300;

    /** handleAdminHosts cache-aside TTL (seconds) — the old admin_hosts_cache_ttl sibling, made native. */
    public const ADMIN_HOSTS_CACHE_TTL = 5;

    /** Presence record/index staleness window (seconds); records carry it as a real TTL. */
    public const PRESENCE_STALE_TTL = 90;

    /**
     * How long a presence RECORD (and its index membership) is retained.
     *
     * REVIEW-FIX: PRESENCE_STALE_TTL used to serve three different jobs at once
     * — record TTL, index-sweep window, and the missed-keepalive DROP threshold
     * — and that conflation made the watchdog unreachable. touchPresence() writes
     * the record and the index score from the SAME pong timestamp, so a client
     * that went quiet had its record expire and its index membership swept in the
     * very tick that the drop test first became true (the sweep's bound is
     * inclusive, the drop test's is strict, and the sweep runs FIRST). The client
     * was therefore never judged and Gateway::closeClient(..., 'missed_keepalive')
     * never ran: a half-open socket leaked its Gateway connection and session
     * indefinitely, which is exactly what this watchdog exists to prevent.
     *
     * Retention is now 3x the drop threshold, so a silent client survives long
     * enough to be seen, judged and closed. The sweep is demoted to what it
     * should always have been: a backstop for records orphaned by a dead worker.
     *
     * INVARIANT: must stay comfortably GREATER than PRESENCE_STALE_TTL.
     */
    public const PRESENCE_RECORD_TTL = 270;

    /** Presence auxiliary per-client key TTLs (seconds) — safety nets; every delete path is deterministic. */
    public const PRESENCE_PING_TTL = 3600;
    public const PRESENCE_MOVE_TTL = 60;
    public const PRESENCE_SESSION_TTL = 86400;

    /** dc.presence.join/move window after which an idle channel's hot-cache tail is swept (seconds) — replaces the 60s chat reaper Timer. */
    public const CHAT_CHANNEL_IDLE_TTL = 3600;

    /** Minimum seconds between write-path chat sweeps (see sweepIdleChatChannelsThrottled()). */
    public const CHAT_SWEEP_MIN_INTERVAL = 300;

    /** @var int unix ts of the last write-path chat sweep in THIS process */
    private static $lastChatSweepAt = 0;

    /**
     * Bot ownership lock TTL (seconds) — the heartbeat cadence is
     * BOT_MOVE_INTERVAL (0.5s), so this is ~60 missed ticks of silence before
     * another instance may take the bot over. Replaces the GlobalData-era
     * BOT_OWNER_HEARTBEAT_MAX_AGE staleness constant with a real, enforced
     * expiry: a crashed owner frees the bot when the lock lapses, no reaper.
     *
     * REVIEW-FIX (decision D): was 10s. The GlobalData-era check used
     * /proc/<pid> liveness for a same-host owner, so a LIVE local process was
     * never robbed no matter how long it stalled; the pure-TTL rewrite dropped
     * that protection. These BusinessWorkers do synchronous MySQL and SOAP work,
     * so a >10s stall was entirely reachable, and losing the lock to a stall
     * means another worker takes over and respawns the bot with a NEW random
     * name and position (a visible teleport+rename, not a graceful handoff).
     * 30s keeps the takeover bounded while making a stall-induced steal
     * unlikely. BOT_STATE_TTL must stay strictly GREATER than this so a dead
     * owner still leaves a takeable ghost state rather than an instant vanish.
     */
    public const BOT_OWNER_LOCK_TTL = 30;

    /**
     * Bot state/presence record TTL (seconds) — refreshed every moveBot tick;
     * lapses with a dead owner. MUST outlive BOT_OWNER_LOCK_TTL (see there):
     * the lock frees the bot for takeover first, and the surviving state is
     * what lets the new owner adopt the bot's existing identity and position
     * instead of respawning it fresh.
     */
    public const BOT_STATE_TTL = 90;

    // ====================================================================
    // Bot Presence System (DataCenter 3D)
    // When a real user joins the DC presence session, spawn a simulated bot
    // avatar that walks around the datacenter building.
    // ====================================================================

    /** Default datacenter/location name when no location is specified. */
    public const BOT_DEFAULT_LOCATION = 'main';

    /** Bot movement interval in seconds (500ms). */
    public const BOT_MOVE_INTERVAL = 0.5;

    /**
     * Bot walking speed in SCENE units per second.
     *
     * dc.js uses UNITS_PER_INCH = 15/70, i.e. 1 scene unit ~= 0.1196 m, so a
     * realistic 1.4 m/s human walk is ~11.7 scene units/sec. The old value of
     * 1.2 was "units/sec" read as metres and made the bot creep at ~0.14 m/s
     * (the client's own walk speed is 14 u/s).
     */
    public const BOT_WALK_SPEED = 11.7;

    /**
     * FALLBACK datacenter bounds, used ONLY until the browser reports the real
     * room extents via dc.presence.join `bounds` (contract BOT-BOUNDS). dc.js
     * lays racks out from offsetX/offsetZ = -100 and spawns the player at
     * roomSpawn = {x: cx, z: maxZ - ROOM_MARGIN*0.5}, so the room is nowhere
     * near the world origin and these numbers are only a last resort.
     */
    public const BOT_BOUNDS_X_MIN = -50.0;
    public const BOT_BOUNDS_X_MAX = 50.0;
    public const BOT_BOUNDS_Z_MIN = -50.0;
    public const BOT_BOUNDS_Z_MAX = 50.0;

    /** Distance threshold to consider bot has reached its target (units). */
    public const BOT_TARGET_THRESHOLD = 1.0;

    /** Redis key prefix (SharedState) holding the browser-reported room bounds per location. */
    public const DC_ROOM_BOUNDS_KEY_PREFIX = 'dc:presence:room_bounds:';

    /** Spawn the bot within this many scene units of the joining player. */
    public const BOT_SPAWN_RADIUS = 25.0;

    /** Pick wander targets within this many scene units of a real player. */
    public const BOT_WANDER_RADIUS = 30.0;

    /** Keep the bot this far inside the reported walls so it never clips them. */
    public const BOT_BOUNDS_INSET = 2.0;

    /** Reported-bounds sanity limits (contract BOT-BOUNDS validation). */
    public const BOT_BOUNDS_MIN_SPAN = 4.0;
    public const BOT_BOUNDS_MAX_SPAN = 5000.0;
    public const BOT_BOUNDS_MAX_COORD = 100000.0;

    /**
     * Process-local map of location => Workerman timer id for the bot move
     * timer (THE BOT #4). Workerman timer ids are per-PROCESS and there are 5
     * BusinessWorkers, so the id must NEVER be shared — Timer::del() from
     * another process would delete an unrelated timer (e.g.
     * one of the onWorkerStart queue timers). Cross-process ownership moved
     * from the old GlobalData owner-pid marker to a SharedState Redis lock
     * (dc:lock:bot_owner:<location>, BOT_OWNER_LOCK_TTL): the lock IS the
     * marker now, and only its holder may renew or release it.
     *
     * @var array<string,int>
     */
    private static $botTimers = [];

    /**
     * Process-local map of location => ownership token for the SharedState bot
     * owner lock, exactly like $processingLockToken. Null/absent means this
     * process does not drive that location's bot; every renew/unlock of
     * dc:lock:bot_owner:<location> is token-checked so a worker that lost the
     * lock (TTL lapsed, another instance took over) can never extend or delete
     * the new owner's hold.
     *
     * @var array<string,string>
     */
    private static $botLockTokens = [];

    /**
     * Process-local map of session id => Workerman timer id for the 15s
     * duplicate-session prune one-shot armed by trackSessionClient().
     *
     * REVIEW-FIX: exactly the same per-process constraint as self::$botTimers —
     * dc:presence:timer:<sessionId> used to hold the raw timer id and was
     * Timer::del()'d from whichever BusinessWorker happened to receive the next
     * connection for that session, silently destroying an unrelated timer in
     * that process. The shared key now carries only the owning pid.
     *
     * @var array<string,int>
     */
    private static $sessionPruneTimers = [];

    /**
     * Cached gethostname(), identifying which of the three datacentered instances
     * this process belongs to. See processMarker().
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
         * @var \Redis|null
         *
         * GlobalData→Redis migration Phase 1: make the $redis global available
         * in every BusinessWorker process (same idiom as start_task.php's
         * onWorkerStart) so the SharedState facade resolves it. Placed HERE and
         * not in start_businessworker.php because BusinessWorker invokes
         * Events::onWorkerStart once per process on its own — a second init
         * site would resurrect the double-bootstrap defect pinned by
         * BusinessWorkerBootstrapTest.
         *
         * The \GlobalData\Client that used to be constructed above this block
         * is gone (migration A2): every former $global consumer in this file
         * now reads/writes SharedState, so nothing here needs the client —
         * including its old `$global->queuein = 0` scratch write, which the
         * Redis queuein LISTs (Web/queue.php) never read.
         */
        global $redis;
        if (!($redis instanceof \Redis) && defined('USE_REDIS') && USE_REDIS === true && class_exists('\Redis')) {
            $redis = null;
            try {
                $candidate = new \Redis();
                if ($candidate->connect(REDIS_HOST, REDIS_PORT, 2)) {
                    // No auth() — matches the existing $redis init idiom.
                    $redis = $candidate;
                }
            } catch (\Exception $e) {
                Worker::safeEcho('Caught Exception #'.$e->getCode().':'.$e->getMessage().' on '.__LINE__.'@'.__FILE__.PHP_EOL);
                $redis = null;
            }
        }
        /**
        * @var \Memcached
        */
        global $memcache;
        $memcache = new \Memcached();
        $memcache->addServer('localhost', 11211);
        self::$db = self::createDbConnection();
        /*
         * Cold-start gate (GlobalData→Redis migration, A2). The old code keyed
         * "is this a fresh server?" off `$global->add('running', [])` winning —
         * true only when the GlobalData store itself had just been created.
         * Redis outlives datacentered restarts, so that premise is gone; the
         * replacement semantic is "at most one reap per 60s window":
         * SharedState::lock('startup_reap', 60). boardctl_startup_reap() is
         * idempotent (it only fails rows whose runner pid is provably dead),
         * and the room seed is hSetNx, so a duplicate fire inside the window
         * is harmless anyway. Chosen release: unlock in `finally` on
         * completion — the work is bounded and seconds long, while the 60s TTL
         * stays purely as the crash guard (a boot that dies mid-reap must not
         * block the next boot's reap for longer than a minute).
         */
        $reapToken = SharedState::lock('startup_reap', self::STARTUP_REAP_LOCK_TTL);
        if ($reapToken !== null) {
            try {
                // Clear boardctl jobs orphaned by the restart so reruns aren't blocked.
                self::boardctl_startup_reap();
                // Idempotent default-room seed only. The old cold start ALSO
                // blanked hosts / dc_active_clients here; with Redis persistence
                // that would now CLOBBER live registries on every graceful
                // restart, and an empty hash/set already reads as empty
                // everywhere, so no seeding of those is either needed or safe.
                SharedState::hSetNx(self::ROOMS_REGISTRY_KEY, self::DEFAULT_ROOM_ID, [
                    'id' => self::DEFAULT_ROOM_ID,
                    'name' => 'General Chat',
                    'img' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a6/Rubik%27s_cube.svg/220px-Rubik%27s_cube.svg.png',
                    'members' => [],
                    'messages' => [],
                ]);
            } finally {
                SharedState::unlock('startup_reap', $reapToken);
            }
        }
        if ($worker->id == 0) {
            $args = [];
            $timers = [];
            if (gethostname() == 'my.interserver.net') {
            } elseif (gethostname() == 'myadmin1.interserver.net') {
                // Timers are registered only in worker id 0 (guarded above) so each fires
                // exactly once across the BusinessWorker pool; GlobalTimer::add was a thin
                // wrapper around Timer::add and provided no cross-process semantics itself.
                // Registry shape (PROTOCOL_V1.md §2.9, step 2.8): each dc:state:timers
                // hash field is {interval, timer_id} recorded at registration time only —
                // NO callback bodies are touched and scheduling is byte-identical.
                // live last_run tracking is deliberately deferred (safer-minimal option);
                // the only reader is v1 handleAdminTimers (legacy msgTimers ignores it).
                // REVIEW-FIX (worker-kill): every callback below MUST be wrapped in
                // safeTimerCallback(). Workerman does NOT contain a throwing timer
                // callback: Select::tick() routes it through safeCall(), which hands
                // the Throwable to the loop's errorHandler, and Worker::run() installs
                // that handler as stopAll(250, $e) — i.e. an uncaught throw here EXITS
                // the BusinessWorker and the master respawns it. processing_queue_timer()
                // throws by design on a dead Redis transport (so trigger_payment can
                // answer `unavailable`), which without this wrapper turned any Redis
                // outage into a 30s crash-respawn loop for worker 0.
                $timers['processing_queue_timer'] = ['interval' => 30, 'timer_id' => Timer::add(30, self::safeTimerCallback('processing_queue_timer', ['Events', 'processing_queue_timer']), $args)];
                $timers['processing_queue_reaper'] = ['interval' => 120, 'timer_id' => Timer::add(120, self::safeTimerCallback('processing_queue_reaper', ['Events', 'processing_queue_reaper']), $args)];
                $timers['boardctl_queue_timer'] = ['interval' => 15, 'timer_id' => Timer::add(15, self::safeTimerCallback('boardctl_queue_timer', ['Events', 'boardctl_queue_timer']), $args)];
                $timers['vps_queue_timer'] = ['interval' => 30, 'timer_id' => Timer::add(30, self::safeTimerCallback('vps_queue_timer', ['Events', 'vps_queue_timer']), $args)];
                $timers['memcache_queue_timer'] = ['interval' => 30, 'timer_id' => Timer::add(30, self::safeTimerCallback('memcache_queue_timer', ['Events', 'memcache_queue_timer']), $args)];
                $timers['map_queue_timer'] = ['interval' => 60, 'timer_id' => Timer::add(60, self::safeTimerCallback('map_queue_timer', ['Events', 'map_queue_timer']), $args)];
                //$timers[] = Timer::add(60, ['Events', 'queue_queue_timer'], $args);
                //$timer_id = Timer::add(1, function() use (&$timer_id, $timers) { echo "worker[0] tick timer_id:$timer_id:'".print_r($timers,true)."\n"; });

                // GlobalData→Redis migration (A1): per-host VPS dispatch locks now
                // live in SharedState (dc:lock:vps_host_<id>). Cold-start pre-clean
                // drops the lock plus its debug sibling; Redis TTLs already cap any
                // leak at 900s (frozen contract with the Tasks track), this just
                // keeps the first post-restart tick from waiting on a dead holder.
                // createDbConnection() never throws — a dead DB at boot leaves self::$db
                // null — so guard it the same is_null/is_array way the neighbouring timer
                // methods do and skip this best-effort sweep rather than fatal the worker.
                // Deliberately no early return: the hyperv timers + registry seed below
                // must still register; a leaked vps_host lock just waits out its 900s TTL.
                if (self::$db !== null) {
                    $rows = self::$db->select('vps_id')->from('vps_masters')->where('vps_type=11')->query();
                    if (is_array($rows)) {
                        foreach ($rows as $row) {
                            $lockKey = SharedState::PREFIX_LOCK.'vps_host_'.$row['vps_id'];
                            SharedState::del($lockKey, SharedState::requestKey('vps_host_'.$row['vps_id']));
                        }
                    }
                }
                $timers['hyperv_update_list_timer'] = ['interval' => 3600, 'timer_id' => Timer::add(3600, self::safeTimerCallback('hyperv_update_list_timer', ['Events', 'hyperv_update_list_timer']), $args)];
                $timers['hyperv_queue_timer'] = ['interval' => 30, 'timer_id' => Timer::add(30, self::safeTimerCallback('hyperv_queue_timer', ['Events', 'hyperv_queue_timer']), $args)];

                // The sysinfos (MAJOR-10) and channel-messages (MAJOR-11) reaper
                // Timers that lived here are DELETED: both registries now carry
                // real Redis TTLs (dc:state:sysinfo:* expires at SYSINFO_TTL,
                // idle dc:chat:* tails are swept by score window inside
                // handleChannelList), which is exactly what those CAS-spinning
                // closure sweeps existed to emulate.

                /*
                 * Registry seed: name => {interval, timer_id}, one hash field each.
                 *
                 * REVIEW-FIX (stale timer fields): under GlobalData this was a
                 * single whole-variable assignment ($global->timers = $timers), so a
                 * renamed or removed timer simply vanished. Writing one field per
                 * timer never deletes anything, and dc:state:timers has no TTL — so
                 * every timer name the hub has EVER registered accumulates and
                 * handleAdminTimers() reports phantom timers (with dead, process-
                 * local timer_ids) to admins forever. This changeset already renames
                 * vps_queue_queue_timer -> vps_queue_timer, which would leave both.
                 *
                 * Prune fields we are not registering to restore replace semantics.
                 * Every instance registers the same timer NAMES, so this stays
                 * correct with several datacentered instances sharing one Redis (it
                 * cannot delete a name another instance still uses).
                 */
                $staleTimerFields = array_diff(
                    array_keys(SharedState::hGetAll(self::TIMERS_REGISTRY_KEY)),
                    array_keys($timers)
                );
                if ($staleTimerFields !== []) {
                    SharedState::hDel(self::TIMERS_REGISTRY_KEY, ...array_map('strval', $staleTimerFields));
                    Worker::safeEcho('pruned stale timer registry fields: '.implode(', ', $staleTimerFields)."\n");
                }
                foreach ($timers as $timerName => $timerInfo) {
                    SharedState::hSet(self::TIMERS_REGISTRY_KEY, (string) $timerName, $timerInfo);
                }
                Events::memcache_queue_timer();
                Events::hyperv_update_list_timer();
            } elseif (gethostname() == 'my-web-2.interserver.net') {
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
     * Gated by Flag A `WS_NEW_HANDLING` (plan B8) via FeatureFlags::useNewHandling()
     * (unset default is ON since 9eabb50 — see docs/FEATURE_FLAGS.md): with the flag
     * explicitly OFF v1 envelopes are inert — no business logic
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
        if (!FeatureFlags::useNewHandling()) {
            /*
             * Flag A OFF: new handling is dormant — parse but do not act
             * (plan B8 state 1).
             *
             * REVIEW-FIX (decision C): useNewHandling() ALSO returns false when
             * the flag could not be read at all, and the two cases must not look
             * the same to a client. A dead Redis transport marks the whole facade
             * fail-safe for REPROBE_INTERVAL, so every v1 frame arriving in that
             * window was silently discarded — no reply, no log — and any client
             * waiting on `re` hung until its own timeout. One brief blip became
             * 30s of protocol black hole across all five BusinessWorkers.
             *
             * transportFailed() is exactly the "the answer I just gave you is not
             * authoritative" signal, so escalate only that: tell the client
             * `unavailable` and let it retry. A flag that is genuinely OFF, or a
             * deployment with no Redis configured at all (client() === null
             * without a dead window — a deliberate STATE, not a failure), stays
             * inert as documented and as pinned by
             * EventsV1RouterTest::testV1PingDormantByDefaultWhenRedisUnavailable.
             */
            if (SharedState::transportFailed()) {
                self::sendV1Error(
                    $client_id,
                    $envelope['id'],
                    'unavailable',
                    'shared state is temporarily unreachable; retry shortly'
                );
            }

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
                // Record the receipt in Redis: dc:presence:ping:<client_id>
                // (TTL-capped) replaced the GlobalData dc_ping: key. SharedState
                // is fail-safe when no client resolves, so the old `$global !==
                // null` guard that kept a dropped pong from taking the worker
                // down is no longer needed.
                SharedState::set(self::DC_PONG_KEY_PREFIX . $client_id, time(), self::PRESENCE_PING_TTL);
                // The pong doubles as the presence heartbeat: a member that is
                // alive but idle (no moves) must not be swept out of the scene
                // by the 90s index/record TTLs. Refreshing the record and the
                // index score here is the TTL-native replacement for the old
                // heartbeat staleness bookkeeping.
                self::touchPresence($client_id);
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
        $_SESSION['ip'] = $row[$ip_col] ?? $_SERVER['REMOTE_ADDR'];
        $_SESSION['type'] = $row[$prefix.'_type'] ?? '';
        $_SESSION['online'] = date('Y-m-d H:i:s');
        $_SESSION['login'] = true;
        $_SESSION['v1_authed'] = true;
        $_SESSION['v1_session'] = $hub_session;
        if ($role === 'host' && $module === 'vps') {
            // Registry update the shared hosts map legacy msgLogin performs
            // (keyed by vps_id; qs/bot identities have no legacy equivalent).
            // Migration A2: the whole-map CAS loop collapses to a single HSET —
            // hash fields are individually atomic, so two hosts authenticating
            // on different workers cannot clobber each other's rows anymore.
            SharedState::hSet(self::HOSTS_REGISTRY_KEY, (string) $row['vps_id'], $row);
            SharedState::del(self::ADMIN_HOSTS_CACHE_KEY);
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
     * Registers the run in the SAME shared running registry the legacy path
     * uses — migration A2 moved it from the GlobalData whole-map CAS to one
     * Redis STRING key per run (dc:state:running:<run_id>, JSON value,
     * RUNNING_ENTRY_TTL) plus a dc:state:running_ids SET index for the
     * admin.running enumeration — keyed by the unique run_id, so
     * cmd.stdin/output/exit/kill can route and so onClose cleanup + (later,
     * step 2.8) admin.running see v1 runs too. Legacy md5 keys and v1 uuid
     * keys coexist without collision.
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
        // Migration A2: add() (SET NX) makes the check-and-seed a single atomic
        // command — strictly stronger than the old read-then-CAS-loop, which
        // raced a duplicate between the check and the write.
        $runningKey = self::RUNNING_KEY_PREFIX . $run_id;
        if (!SharedState::add($runningKey, $entry, self::RUNNING_ENTRY_TTL)) {
            self::sendV1Error($client_id, $re, 'bad_request', "cmd.exec data.run_id \"{$run_id}\" is already in use by an in-flight run");
            return;
        }
        // add() (SET NX) and sAdd() are two separate commands: a crash in the gap
        // leaves the entry set but absent from the index (invisible to sMembers
        // enumeration) — a bounded orphan that self-reclaims when RUNNING_ENTRY_TTL
        // (EX3600) expires the key. Accepted eventuality under the eventual-consistency
        // model (mirrors the reverse: an index member whose entry already expired is
        // skipped on the onClose sweep).
        SharedState::sAdd(self::RUNNING_INDEX_KEY, $run_id);
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
        $run = SharedState::get(self::RUNNING_KEY_PREFIX . $run_id);
        if (!is_array($run)) {
            // Mirror legacy msgRunning: silently drop input racing a finished run.
            return;
        }
        // Activity keeps the run alive — see touchRun().
        self::touchRun($run_id);
        $relay = self::v1Envelope('cmd.stdin', [
            'run_id' => $run_id,
            'data' => $data['data']
        ]);
        Gateway::sendToUid($run['host'], json_encode($relay));
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
        $run = SharedState::get(self::RUNNING_KEY_PREFIX . $run_id);
        if (!is_array($run)) {
            // Output racing the exit cleanup — drop silently, like legacy msgRunning.
            return;
        }
        if (($_SESSION['uid'] ?? '') !== $run['host']) {
            self::sendV1Error($client_id, $re, 'forbidden', 'sender does not own this run');
            return;
        }
        // Activity keeps the run alive — see touchRun().
        self::touchRun($run_id);
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
     * Registry removal (migration A2): on success the finished run's own key
     * (dc:state:running:<run_id>) is deleted and its id removed from the
     * dc:state:running_ids index — the per-key equivalent of the retired
     * whole-map CAS loop, where a concurrent legacy md5-keyed run can no
     * longer be clobbered because nothing shares a payload with this one.
     * A forbidden/unknown-run_id path removes nothing.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleCmdExit($client_id, $envelope)
    {
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
        $run = SharedState::get(self::RUNNING_KEY_PREFIX . $run_id);
        if (!is_array($run)) {
            // Already cleaned up (duplicate exit / restart race) — drop silently.
            return;
        }
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
        // Remove the finished run: its own key plus its id from the index.
        SharedState::del(self::RUNNING_KEY_PREFIX . $run_id);
        SharedState::sRem(self::RUNNING_INDEX_KEY, $run_id);
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
        $run = SharedState::get(self::RUNNING_KEY_PREFIX . $run_id);
        if (!is_array($run)) {
            // Kill racing a natural exit — nothing to do.
            return;
        }
        $relay = self::v1Envelope('cmd.kill', ['run_id' => $run_id]);
        Gateway::sendToUid($run['host'], json_encode($relay));
        Worker::safeEcho("[{$client_id}] v1 cmd.kill relayed for run {$run_id} to {$run['host']}".PHP_EOL);
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
     * Presence heartbeat touch (migration A2). A pong proves the client is
     * alive even when it is standing still, so refresh its record TTL and both
     * index scores without mutating the position payload. No-op for sockets
     * that never joined the scene (no record).
     *
     * @param string $client_id
     */
    private static function touchPresence(string $client_id): void
    {
        $key = self::dcPresenceKey($client_id);
        $entry = SharedState::get($key);
        if (!is_array($entry)) {
            return;
        }
        $entry['ts'] = time();
        SharedState::set($key, $entry, self::PRESENCE_RECORD_TTL);
        self::presenceIndexAdd($client_id, $entry['ts']);
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
        if ($sessionId === '') {
            return;
        }
        // REVIEW-FIX (unvalidated client input reaching shared cache KEYS):
        // $sessionId is auth.hello's data['session'] with nothing but an
        // is_string() check on it, and it is concatenated into the
        // dc:presence:session_clients:<id> / dc:presence:timer:<id> key names.
        // A client could therefore create arbitrarily long (megabyte) or
        // arbitrary-byte keys in the shared store and grow it without bound.
        // Real clients send window.DC_SESSION_ID, a 32-char PHP session id, so
        // constrain the key component to that shape and ignore anything else.
        if (!preg_match('/^[A-Za-z0-9,_.:-]{1,128}$/', $sessionId)) {
            Worker::safeEcho("[dc_presence] rejecting malformed session id from {$client_id}\n");
            return;
        }
        $sessionKey = 'dc:presence:client_session:' . $client_id;
        $listKey = 'dc:presence:session_clients:' . $sessionId;
        SharedState::set($sessionKey, $sessionId, self::PRESENCE_SESSION_TTL);
        $clients = SharedState::get($listKey);
        if (!is_array($clients)) {
            $clients = [];
        }
        // Filter out any stale (already-closed) client_ids
        $activeClients = [];
        foreach ($clients as $cid) {
            if (SharedState::get('dc:presence:client_session:' . $cid) === $sessionId) {
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
                // dc:presence:ping: is reserved for the last pong RECEIVED (see
                // self::DC_PONG_KEY_PREFIX docs) — writing the send time there
                // made answering the ping look identical to never answering it.
                SharedState::set(self::DC_PING_SENT_KEY_PREFIX . $cid, $pingedAt, self::PRESENCE_PING_TTL);
            }
            // Cancel any existing timer for this session to prevent duplicates (MAJOR-5).
            //
            // REVIEW-FIX (same cross-process hazard THE BOT #4 fixed for bot
            // ownership, still live here): dc:presence:timer:<sessionId> holds
            // the OWNING PID, never a raw Workerman timer id. Timer ids are
            // PER-PROCESS and a duplicate session connection lands on whichever
            // of the 5 BusinessWorkers the Gateway picked, so
            // Timer::del($idFromAnotherProcess) deleted an unrelated timer in
            // THIS process — including, realistically, this process's bot move
            // timer (which would freeze the bot permanently) or a pending
            // presence flush. A process only ever deletes a timer it created.
            $timerKey = 'dc:presence:timer:' . $sessionId;
            $timerOwner = SharedState::get($timerKey);
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
            self::$sessionPruneTimers[$sessionId] = \Workerman\Timer::add(15, self::safeTimerCallback('sessionPrune', function () use ($sessionId, $pingedAt, $pingedClients) {
                // REVIEW-FIX: the one-shot has fired, so drop both the local id
                // and the shared marker (the latter only if it is still ours).
                // The marker previously survived forever — one permanent key per
                // distinct session the hub ever saw twice.
                unset(self::$sessionPruneTimers[$sessionId]);
                $timerKey = 'dc:presence:timer:' . $sessionId;
                if (SharedState::get($timerKey) === getmypid()) {
                    SharedState::del($timerKey);
                }
                $listKey = 'dc:presence:session_clients:' . $sessionId;
                $clients = SharedState::get($listKey);
                if (!is_array($clients)) {
                    $clients = [];
                }
                $stillActive = [];
                $toDrop = [];
                foreach ($clients as $cid) {
                    $ck = 'dc:presence:client_session:' . $cid;
                    if (SharedState::get($ck) !== $sessionId) {
                        continue;
                    }
                    // BUG-B3: responsive == a pong arrived at or after the
                    // session_check ping we sent 15s ago.
                    //
                    // REVIEW-FIX: compare against $pingedAt (the ping THIS closure
                    // sent), not the shared ping-sent key. The 30s health
                    // timer rewrites ping_sent for every client, so a client
                    // that had answered our session_check could still be seen as
                    // "pong older than last ping sent" purely because the health
                    // timer had pinged it 1s ago — and got closed for it. Clients
                    // we did not ping in this round are never candidates.
                    $lastPong = (int) (SharedState::get(self::DC_PONG_KEY_PREFIX . $cid) ?? 0);
                    if (!in_array($cid, $pingedClients, true) || $lastPong >= $pingedAt) {
                        $stillActive[] = ['cid' => $cid, 'pong' => $lastPong];
                    } else {
                        $toDrop[] = $cid;
                    }
                }
                // Keep at most the 2 most-recently-responsive connections per session.
                usort($stillActive, fn ($a, $b) => $b['pong'] <=> $a['pong']);
                foreach (array_slice($stillActive, 2) as $k) {
                    $toDrop[] = $k['cid'];
                }
                foreach ($toDrop as $cid) {
                    SharedState::del(
                        'dc:presence:client_session:' . $cid,
                        self::DC_PONG_KEY_PREFIX . $cid,
                        self::DC_PING_SENT_KEY_PREFIX . $cid
                    );
                    $live = SharedState::get($listKey);
                    if (is_array($live)) {
                        SharedState::set($listKey, array_values(array_filter($live, fn ($c) => $c !== $cid)), self::PRESENCE_SESSION_TTL);
                    }
                    Gateway::closeClient($cid, 'session_pruned');
                    Worker::safeEcho("[dc_presence] pruned non-responsive client {$cid} from session {$sessionId}\n");
                }
            }), [], false);
            SharedState::set($timerKey, getmypid(), self::PRESENCE_SESSION_TTL);
        }
        $clients[] = $client_id;
        SharedState::set($listKey, $clients, self::PRESENCE_SESSION_TTL);
    }

    /**
     * v1 `pty.open` handler (docs/PROTOCOL_V1.md §2.3 + §5; plan step 2.4) —
     * admin-originated C→H, relayed H→A. HUB-SIDE relay only: the hub
     * validates/authorizes, tracks the pty session in the SEPARATE
     * dc:state:ptys Redis HASH (decoupled from the cmd running registry), and
     * relays the v1 envelope to the target host. The actual
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
        // Collision guard + registration in one atomic HSETNX: reuse of an
        // in-flight pty_id would hijack the original session's duplex routing
        // (same rationale as cmd.exec). Migration A2 replaced the separate
        // read-check + whole-map CAS loop — the hash field is created exactly
        // once, so two racing opens on different workers cannot both win, and
        // every other pty session stays untouched.
        if (!SharedState::hSetNx(self::PTYS_REGISTRY_KEY, $pty_id, $entry)) {
            /*
             * REVIEW-FIX (ghost ptys): a field here is removed only by pty.close,
             * and dc:state:ptys has no TTL. Under GlobalData the registry died with
             * the store on any restart; in Redis it persists, so an admin or host
             * that drops mid-session (or a hub killed hard) leaves the field behind
             * and this guard then rejects that pty_id FOREVER — with no way to clear
             * it short of editing Redis by hand.
             *
             * Before refusing, check whether the recorded session is actually still
             * alive. Gateway::isUidOnline() answers via the register service, so it
             * is accurate across all datacentered instances. If neither the host nor
             * the owning admin is connected, the entry is a corpse: reclaim it and
             * let the open proceed. A genuinely in-flight pty is still protected,
             * which is what the collision guard is for.
             */
            $existing = SharedState::hGet(self::PTYS_REGISTRY_KEY, $pty_id);
            $existingHost = is_array($existing) ? (string) ($existing['host'] ?? '') : '';
            $existingOwner = is_array($existing) ? (string) ($existing['for'] ?? '') : '';
            $stale = is_array($existing)
                && ($existingHost === '' || Gateway::isUidOnline($existingHost) != true)
                && ($existingOwner === '' || Gateway::isUidOnline($existingOwner) != true);
            if ($stale) {
                Worker::safeEcho("pty.open reclaiming orphaned pty_id {$pty_id} (host {$existingHost} and owner {$existingOwner} both offline)\n");
                SharedState::hSet(self::PTYS_REGISTRY_KEY, $pty_id, $entry);
            } else {
                self::sendV1Error($client_id, $re, 'bad_request', "pty.open data.pty_id \"{$pty_id}\" is already in use by an open pty");

                return;
            }
        }
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
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $pty_id = isset($data['pty_id']) && is_string($data['pty_id']) ? $data['pty_id'] : '';
        if ($pty_id === '' || !isset($data['data']) || !is_string($data['data'])) {
            self::sendV1Error($client_id, $re, 'bad_request', 'pty.data requires data.pty_id and base64 string data.data');
            return;
        }
        $pty = SharedState::hGet(self::PTYS_REGISTRY_KEY, $pty_id);
        if (!is_array($pty)) {
            // Data racing the close cleanup — drop silently.
            return;
        }
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
        $pty = SharedState::hGet(self::PTYS_REGISTRY_KEY, $pty_id);
        if (!is_array($pty)) {
            // Resize racing the close cleanup — drop silently.
            return;
        }
        if (($_SESSION['uid'] ?? '') !== $pty['for']) {
            self::sendV1Error($client_id, $re, 'forbidden', 'only the pty session owner may resize it');
            return;
        }
        Gateway::sendToUid($pty['host'], json_encode(self::v1Envelope('pty.resize', [
            'pty_id' => $pty_id,
            'cols' => $cols,
            'rows' => $rows
        ])));
        // Keep the registry geometry current. Migration A2: a single-field HSET
        // replaces the whole-map CAS loop — concurrent resizes of OTHER pty
        // sessions can no longer collide with this one; a same-session resize
        // race is last-write-wins, and the entry may already be gone because a
        // close raced us, which hGet returning null above already tolerated.
        if (SharedState::hGet(self::PTYS_REGISTRY_KEY, $pty_id) !== null) {
            $pty['cols'] = $cols;
            $pty['rows'] = $rows;
            SharedState::hSet(self::PTYS_REGISTRY_KEY, $pty_id, $pty);
        }
    }

    /**
     * v1 `pty.close` handler (docs/PROTOCOL_V1.md §2.3 + §5; plan step 2.4)
     * — any→hub→peer, no reply. Either party (the owning admin 'for' or the
     * allocated host 'host') may close; anyone else gets `forbidden`. The
     * close (with the optional exit `code` when the PTY child exited) is
     * relayed to the OTHER party, the entry is removed from the separate
     * dc:state:ptys hash with one HDEL, and a §5 structured
     * audit line records pty_id / who closed / code / timestamp. Unknown
     * pty_id is silently dropped (duplicate close / restart race).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handlePtyClose($client_id, $envelope)
    {
        $re = $envelope['id'];
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $pty_id = isset($data['pty_id']) && is_string($data['pty_id']) ? $data['pty_id'] : '';
        if ($pty_id === '') {
            self::sendV1Error($client_id, $re, 'bad_request', 'pty.close data.pty_id is required');
            return;
        }
        $pty = SharedState::hGet(self::PTYS_REGISTRY_KEY, $pty_id);
        if (!is_array($pty)) {
            // Already cleaned up (duplicate close / restart race) — drop silently.
            return;
        }
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
        // Remove the session's field from the separate ptys hash (one HDEL —
        // the per-field equivalent of the retired whole-map CAS loop).
        SharedState::hDel(self::PTYS_REGISTRY_KEY, $pty_id);
        // §5 structured audit: pty_id / who closed / code / timestamp.
        self::ptyAudit('close', [
            'pty_id' => $pty_id,
            'who' => $sender,
            'who_name' => $_SESSION['name'] ?? '',
            'host' => $pty['host'],
            'scope' => $pty['scope'] ?? '',
            'code' => $relayData['code'] ?? null
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
     * the admin's original envelope id} at one Redis key per relay
     * (dc:state:sysinfo:<relay-id>, JSON value, SYSINFO_TTL)
     * (BusinessWorker
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
            // be routed back from ANY BusinessWorker process. Migration A2:
            // one TTL'd key per relay (dc:state:sysinfo:<relay-id> @ SYSINFO_TTL)
            // replaces the lazily-created GlobalData `sysinfos` map, its CAS
            // whole-map loops, AND the 5-minute reaper Timer — the "KNOWN
            // FOLLOW-UP" (no expiry, leaked entries for never-answering hosts)
            // is what the TTL now solves natively: the entry lapses and the
            // reply that arrives afterwards simply finds nothing and drops.
            SharedState::set(self::SYSINFO_KEY_PREFIX . $relay['id'], [
                'for' => $_SESSION['uid'],
                're' => $re,
                'host' => $hostUid,
                'ts' => time()
            ], self::SYSINFO_TTL);
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
            $sysinfoKey = self::SYSINFO_KEY_PREFIX . $relayId;
            $entry = SharedState::get($sysinfoKey);
            if (!is_array($entry)) {
                // Response racing a restart/TTL expiry — drop silently.
                return;
            }
            if (($_SESSION['uid'] ?? '') !== $entry['host']) {
                self::sendV1Error($client_id, $re, 'forbidden', 'sender is not the host this sysinfo request was addressed to');
                return;
            }
            $replyData = $data;
            // host comes from the authed session, never the payload (legacy
            // msgPhpsysinfo parity: response leg sets host from $_SESSION['uid']).
            $replyData['host'] = intval(str_replace('vps', '', $_SESSION['uid']));
            SharedState::del($sysinfoKey);
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
     * PROTOCOL_V1.md §4; plan step 2.7). Migration A2 moved the cache from the
     * GlobalData per-channel lists (whole-map CAS read-modify-write) to one
     * Redis LIST per channel, dc:chat:msgs:<channel>, trimmed in the same
     * pipeline as the push (rPushLtrim keeps the NEWEST CHAT_HISTORY_MAX=100
     * entries), plus a dc:chat:activity ZSET index scored by last-activity ts.
     * The index makes the 60-second channel reaper Timer (MAJOR-11) and the
     * channel_msgs_channels list / channel_msgs_ts:* keys unnecessary:
     * enumeration and staleness are both one score-range query (see
     * handleChannelList, which sweeps idle tails on read).
     * This is what serves channel.join history and the live tail WITHOUT
     * re-querying the DB; unlike legacy rooms[0]['messages'] it is bounded and
     * evicts (OQ5).
     *
     * RESIDUAL SCALABILITY NOTE (reduced, not eliminated): the per-channel
     * LIST is hard-bounded at CHAT_HISTORY_MAX, and an idled channel's TAIL is
     * reclaimed the next time any client lists channels (the sweep deletes
     * tails outside the CHAT_CHANNEL_IDLE_TTL window and drops them from the
     * index). A channel that is only ever published to — never listed — can
     * still accumulate keys; the DM `to` uid remains unvalidated, so junk
     * dm:* minting is still possible. Bounding that (validate `to`, cap the
     * index) stays the documented Phase 2 follow-up.
     *
     * @param string $channel channel id
     * @param array $message §2.10 channel.message object (channel/from/
     *                       from_name/body/level/ts/msg_id)
     */
    private static function chatCacheAppend($channel, $message)
    {
        /*
         * REVIEW-FIX (decision E): the message LIST now carries an idle TTL,
         * refreshed on every append. Reclamation used to depend ENTIRELY on
         * sweepIdleChatChannels(), which is reachable only from
         * handleChannelList() — so on any deployment where no client sends
         * channel.list (Flag A off, or a client that only joins and publishes)
         * every channel ever published to kept a CHAT_HISTORY_MAX list forever,
         * and dm:* channels are client-mintable. The LIST is the bulk of that
         * footprint; the activity ZSET still needs the sweep, which is why the
         * throttled call below exists.
         */
        SharedState::rPushLtrim(self::CHAT_MSGS_KEY_PREFIX . $channel, $message, self::CHAT_HISTORY_MAX, self::CHAT_CHANNEL_IDLE_TTL);
        SharedState::zAdd(self::CHAT_ACTIVITY_KEY, time(), $channel);
        self::sweepIdleChatChannelsThrottled();
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
     * dc:state:channel_meta Redis registry — explicit channel.create'd
     * channels with {type,topic,created_by,created_at} — and (b) every
     * channel id that has traffic in the dc:chat:activity index (so host:* /
     * job:* log channels appear once something is published to them). The list is
     * filtered by the caller's ACL (chatChannelAllowed), so hosts see only
     * their own channels and bots only chat:*. `members` counts the
     * channel's live Gateway group connections (connection count, not unique
     * uids — documented approximation); `topic` is "" for channels without
     * registry metadata.
     *
     * Read-side sweep (migration A2, replaces the deleted 60s reaper Timer):
     * activity older than CHAT_CHANNEL_IDLE_TTL is dropped from the index and
     * its hot-cache LIST deleted here, on the one call that enumerates.
     *
     * Reply: {channels:[{id,type,topic,members}]} per the frozen §2.10 list.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleChannelList($client_id, $envelope)
    {
        $re = $envelope['id'];
        self::sweepIdleChatChannels();
        $meta = SharedState::hGetAll(self::CHANNEL_META_REGISTRY_KEY);
        $ids = array_keys($meta);
        foreach (SharedState::zRangeByScore(self::CHAT_ACTIVITY_KEY, time() - self::CHAT_CHANNEL_IDLE_TTL, 'inf') as $activeChannel) {
            if (is_string($activeChannel) && !in_array($activeChannel, $ids, true)) {
                $ids[] = $activeChannel;
            }
        }
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
     * Reclaim idle per-channel hot caches (migration A2 — the Redis equivalent
     * of the retired 60-second channel reaper Timer closure). Channels whose
     * dc:chat:activity score fell outside the CHAT_CHANNEL_IDLE_TTL window have
     * their LIST deleted and themselves dropped from the index; the index IS
     * the activity ledger, so there is no separate ts-key set to prune.
     * Idempotent and cheap: one range read plus a delete per stale member, and
     * a no-op when the window is empty.
     */
    /**
     * sweepIdleChatChannels() on a write-path budget.
     *
     * The activity ZSET is one key holding every channel name, so no per-member
     * TTL can bound it — only the sweep can. Hanging that solely off
     * handleChannelList() left it unbounded on write-only deployments, but
     * sweeping on EVERY message would add a zRangeByScore per chat frame. Run it
     * at most once per CHAT_SWEEP_MIN_INTERVAL per process instead: the cost is
     * negligible and reclamation no longer depends on anyone reading.
     *
     * @return void
     */
    private static function sweepIdleChatChannelsThrottled(): void
    {
        $now = time();
        if ($now - self::$lastChatSweepAt < self::CHAT_SWEEP_MIN_INTERVAL) {
            return;
        }
        self::$lastChatSweepAt = $now;
        self::sweepIdleChatChannels();
    }

    private static function sweepIdleChatChannels(): void
    {
        $staleBefore = time() - self::CHAT_CHANNEL_IDLE_TTL;
        $stale = SharedState::zRangeByScore(self::CHAT_ACTIVITY_KEY, 0, $staleBefore);
        foreach ($stale as $channel) {
            if (is_string($channel) && $channel !== '') {
                SharedState::del(self::CHAT_MSGS_KEY_PREFIX . $channel);
            }
        }
        if ($stale !== []) {
            SharedState::zRem(self::CHAT_ACTIVITY_KEY, ...$stale);
        }
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
     * messages from the bounded Redis hot cache ONLY (never a DB query
     * on join, per §4's "hot cache serves channel.join history"; deeper
     * scrollback via msg_id pagination against chat_messages is a later
     * client-driven step). A best-effort channel.presence broadcast follows.
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleChannelJoin($client_id, $envelope)
    {
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
        // LRANGE 0..-1 on the trimmed tail: oldest-first, exactly the order the
        // retired GlobalData list carried (rPushLtrim bounded it on write).
        $history = SharedState::lRange(self::CHAT_MSGS_KEY_PREFIX . $channel, 0, -1);
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
     * dc:state:channel_meta Redis hash (migration A2: a duplicate id is
     * rejected by HSETNX returning false — creation is atomic, so two racing
     * creates cannot both win, replacing the old lazily-created + CAS
     * whole-map loop convention); is rejected with
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
        $created = SharedState::hSetNx(self::CHANNEL_META_REGISTRY_KEY, $channel, [
            'type' => 'chat',
            'topic' => $topic,
            'created_by' => $_SESSION['uid'] ?? '',
            'created_at' => time()
        ]);
        if (!$created) {
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
        $level = $data['level'] ?? 'chat';
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
        $clientCount = 0;
        $sessions = Gateway::getAllClientSessions();
        if (is_array($sessions)) {
            $clientCount = count($sessions);
        }

        $channelCount = count(SharedState::hGetAll(self::CHANNEL_META_REGISTRY_KEY));

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
     * Reads the bot position from Redis dc:presence:bot_state:<location> (falling
     * back to the bot's presence entry dc:presence:client:bot_<location>).
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
        $location = self::BOT_DEFAULT_LOCATION;
        $botState = SharedState::get(self::BOT_STATE_KEY_PREFIX . $location);
        if (!is_array($botState)) {
            $botState = SharedState::get(self::DC_PRESENCE_KEY_PREFIX . 'bot_' . $location);
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
        $level = $data['level'] ?? 'chat';
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
     * (the dc:state:rooms hash) and minus the mandatory gzcompress legacy applies
     * (a client wanting compression uses envelope enc:"gzip" instead).
     *
     * hosts entries: {id (uid str), host_id (int, parsed from the uid the
     * hub itself bound at auth), name, ima, type, ip, online ("Y-m-d H:i:s"),
     * module}. Missing type/ip on older sessions fall back to the
     * dc:state:hosts registry row (vps module only — the registry is keyed
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
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'admin.hosts requires role admin');
            return;
        }
        // Cache-aside (migration A2): one Redis key with a real TTL replaces the
        // old admin_hosts_cache + admin_hosts_cache_ttl GlobalData sibling pair.
        $cached = SharedState::get(self::ADMIN_HOSTS_CACHE_KEY);
        if (is_array($cached)) {
            Gateway::sendToClient($client_id, json_encode([
                'v' => 1,
                're' => $re,
                'ok' => true,
                'data' => $cached
            ]));
            return;
        }
        $registry = SharedState::hGetAll(self::HOSTS_REGISTRY_KEY);
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
                    'name' => $session_data['name'] ?? '',
                    'ima' => 'admin',
                    'img' => $session_data['img'] ?? '',
                    'online' => $session_data['online'] ?? ''
                ];
                continue;
            }
            $uid = (string) $session_data['uid'];
            // host_id from the uid the hub itself bound at auth ("vps<id>"/
            // "qs<id>"/"bot<id>") — never from client-supplied data.
            $host_id = intval(preg_replace('/[^0-9]/', '', $uid));
            $module = $session_data['module'] ?? 'vps';
            // vps-module fallback to the shared hosts registry (vps_masters
            // rows keyed by vps_id) for sessions missing type/ip.
            $row = $module === 'vps' && isset($registry[$host_id]) && is_array($registry[$host_id]) ? $registry[$host_id] : [];
            $hosts[] = [
                'id' => $uid,
                'host_id' => $host_id,
                'name' => $session_data['name'] ?? ($row['vps_name'] ?? ''),
                'ima' => $session_data['ima'] ?? '',
                'type' => $session_data['type'] ?? ($row['vps_type'] ?? ''),
                'ip' => $session_data['ip'] ?? ($row['vps_ip'] ?? ''),
                'online' => $session_data['online'] ?? '',
                'module' => $module
            ];
        }
        $data = ['hosts' => $hosts, 'admins' => $admins];
        SharedState::set(self::ADMIN_HOSTS_CACHE_KEY, $data, self::ADMIN_HOSTS_CACHE_TTL);
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
     * Requires role admin (§2.9/§3). Reads the SharedState dc:state:timers
     * Redis HASH that onWorkerStart populates on the timer-hosting server
     * (myadmin1, worker
     * id 0) at Timer::add() registration time: field name → JSON {interval,
     * timer_id}
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
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'admin.timers requires role admin');
            return;
        }
        $registry = SharedState::hGetAll(self::TIMERS_REGISTRY_KEY);
        $timers = [];
        // Defensive only: Redis HGETALL always replies with an array (empty for a
        // missing key), so this guard can no longer trip — kept to make the shape
        // explicit and to survive a non-SharedState stub.
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
     * Requires role admin (§2.9/§3). Reads the SAME shared running registry
     * both run paths write (migration A2: enumerate the dc:state:running_ids
     * SET, one GET per id; v1 handleCmdExec entries keyed by uuid
     * run_id carrying run_id/id/host/for/command/interact/update_after/rows/
     * cols/started/v; legacy run_command entries keyed by md5($cmd) carrying
     * type/command/id/interact/update_after/host/rows/cols/for) and reshapes
     * every entry to the frozen §2.9 record: {run_id, host (uid), command,
     * interact, update_after, for, rows, cols, started}. Legacy `type` is
     * dropped; run_id falls back to the legacy `id` field / registry key for
     * legacy entries. Ids whose entry has expired (RUNNING_ENTRY_TTL lapsed
     * with a dead agent) are pruned from the index while enumerating.
     *
     * started:0 SENTINEL: only step-2.3 v1 handleCmdExec entries set `started`.
     * A legacy run_command entry (md5-keyed, no `started` field) is reported
     * with started:0, an explicit sentinel meaning "predates v1 started
     * tracking" — NOT "started at unix epoch". Consumers must treat started:0
     * as "start time unknown", not as a real timestamp.
     *
     * READ-ONLY GUARANTEE: beyond pruning ids whose value is already gone,
     * this handler never writes run entries — introspection cannot perturb
     * in-flight run routing (unlike handleCmdExec/handleCmdExit, which mutate
     * the registry).
     *
     * Reply: {ok:true,data:{running:arr<obj>}} ([] when nothing is in flight).
     *
     * @param string $client_id gateway client id
     * @param array $envelope validated v1 request envelope
     */
    public static function handleAdminRunning($client_id, $envelope)
    {
        $re = $envelope['id'];
        if (($_SESSION['ima'] ?? '') !== 'admin') {
            self::sendV1Error($client_id, $re, 'forbidden', 'admin.running requires role admin');
            return;
        }
        $running = [];
        $prune = [];
        foreach (SharedState::sMembers(self::RUNNING_INDEX_KEY) as $run_id) {
            if (!is_string($run_id) || $run_id === '') {
                continue;
            }
            $run = SharedState::get(self::RUNNING_KEY_PREFIX . $run_id);
            if (!is_array($run)) {
                // Entry expired or was deleted without its id leaving the index
                // (crashed worker) — collect for pruning below.
                $prune[] = $run_id;
                continue;
            }
            $run_id = isset($run['run_id']) && is_string($run['run_id']) && $run['run_id'] !== ''
                ? $run['run_id']
                : (isset($run['id']) && is_string($run['id']) && $run['id'] !== '' ? $run['id'] : $run_id);
            $running[] = [
                'run_id' => $run_id,
                'host' => $run['host'] ?? '',
                'command' => $run['command'] ?? '',
                'interact' => !empty($run['interact']),
                'update_after' => !empty($run['update_after']),
                'for' => $run['for'] ?? null,
                'rows' => isset($run['rows']) ? intval($run['rows']) : 0,
                'cols' => isset($run['cols']) ? intval($run['cols']) : 0,
                'started' => isset($run['started']) ? intval($run['started']) : 0
            ];
        }
        if ($prune !== []) {
            SharedState::sRem(self::RUNNING_INDEX_KEY, ...$prune);
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
     * @param array $peerViewport peer viewport data from Redis (x, z, viewDist)
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
     * Redis key for one presence member's record (real client or bot id).
     *
     * @param string $clientId
     * @return string
     */
    private static function dcPresenceKey(string $clientId): string
    {
        return self::DC_PRESENCE_KEY_PREFIX . $clientId;
    }

    /**
     * Index membership maintenance — add/refresh one presence member in BOTH
     * dc:presence:* ZSETs at once (the full-membership index and the
     * recipient-enumeration index), scored by the given last-seen ts.
     *
     * Migration A2 note (replaces seedClientIndex()/casShouldRetry() and every
     * bounded index CAS loop that used them): Redis zAdd CREATES the index on
     * first write, so the GlobalData failure mode those helpers defended — an
     * absent key reads back NULL server-side, and md5(serialize(null)) never
     * equals md5(serialize([])), so cas(absentKey, [], next) livelocked the
     * first join after a cold start at 100% CPU — has no Redis analog. There
     * is no compare step to lose, nothing to seed, and no retry ceiling to
     * bound.
     *
     * @param string        $clientId presence member id (client_id or bot_<loc>)
     * @param int           $ts       last-seen timestamp used as the score
     */
    private static function presenceIndexAdd(string $clientId, int $ts): void
    {
        SharedState::zAdd(self::DC_PRESENCE_INDEX_KEY, $ts, $clientId);
        SharedState::zAdd(self::DC_ACTIVE_INDEX_KEY, $ts, $clientId);
    }

    /**
     * Drop one presence member from BOTH index ZSETs (leave/close/cleanup).
     *
     * @param string $clientId
     */
    private static function presenceIndexRemove(string $clientId): void
    {
        SharedState::zRem(self::DC_PRESENCE_INDEX_KEY, $clientId);
        SharedState::zRem(self::DC_ACTIVE_INDEX_KEY, $clientId);
    }

    /**
     * Stale-eviction sweep for the presence ZSET indexes (migration A2).
     *
     * Members whose score (last-seen ts) is older than the PRESENCE_RECORD_TTL
     * window are removed from both indexes and their record keys deleted. The
     * record keys carry the same TTL natively, so this primarily reclaims the
     * INDEX side — the eventual-consistency backstop for members whose
     * deterministic removal (leave/onClose/cleanup) raced a dead worker.
     *
     * REVIEW-FIX: this used to sweep on PRESENCE_STALE_TTL, the SAME window the
     * missed-keepalive watchdog drops on, and it runs FIRST in that callback —
     * so it evicted every silent client one tick before the watchdog could judge
     * it, and no socket was ever closed. It now sweeps on the longer retention
     * window, leaving the watchdog as the primary path and this as the backstop.
     *
     * No
     * cross-key transaction is required: indexes are advisory membership
     * hints, and every consumer re-reads the record before trusting one, so a
     * window where the index lags a delete is inert.
     *
     * Called from the presence flush (150ms-scale moves) and the 30s health
     * timer, i.e. only while the scene is live.
     */
    private static function sweepPresenceStale(): void
    {
        $staleBefore = time() - self::PRESENCE_RECORD_TTL;
        foreach ([self::DC_PRESENCE_INDEX_KEY, self::DC_ACTIVE_INDEX_KEY] as $indexKey) {
            $stale = SharedState::zRangeByScore($indexKey, 0, $staleBefore);
            if ($stale === []) {
                continue;
            }
            SharedState::zRem($indexKey, ...$stale);
            foreach ($stale as $clientId) {
                if (!is_string($clientId) || $clientId === '') {
                    continue;
                }
                // Bots are swept by their own lifecycle (lock/heartbeat TTL);
                // never delete a bot record from here.
                if (strpos($clientId, 'bot_') === 0) {
                    continue;
                }
                SharedState::del(self::dcPresenceKey($clientId));
            }
        }
    }

    /**
     * Handle dc.presence.join — client entering the datacenter 3D scene.
     *
     * Stores the member's position + metadata at dc:presence:client:<client_id>
     * and indexes it in the dc:presence:* ZSETs, then broadcasts
     * dc.presence.joined to the dc_presence channel so other
     * clients in the scene can render the new avatar.
     *
     * @param string $client_id
     * @param array $envelope v1 envelope with data{x,z,yaw}
     */
    public static function handleDcPresenceJoin($client_id, $envelope)
    {
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
            SharedState::set(self::DC_ROOM_BOUNDS_KEY_PREFIX . self::BOT_DEFAULT_LOCATION, $reportedBounds, self::PRESENCE_SESSION_TTL);
        }

        // Per-client_id key so multiple tabs with same session/uid each get their own presence entry
        $key = self::dcPresenceKey($client_id);
        $now = time();
        $newEntry = [
            'uid' => $uid,
            'name' => $name,
            'x' => $x,
            'z' => $z,
            'yaw' => $yaw,
            'ts' => $now,
            'client_id' => $client_id,
        ];
        // CRIT-9: If cleanup is in progress for this client_id, log and proceed anyway (onClose will clean up after us)
        if ($client_id && SharedState::exists('dc:presence:cleanup:' . $client_id)) {
            Worker::safeEcho("dc.presence.join {$client_id}: cleanup in progress, overwriting anyway\n");
        }
        SharedState::set($key, $newEntry, self::PRESENCE_RECORD_TTL);
        // Index membership is two zAdds — no seed, no CAS loop, no retry
        // ceiling (see presenceIndexAdd()'s migration note for why the
        // GlobalData-era guards for those are gone).
        self::presenceIndexAdd($client_id, $now);

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
        // another worker could have rewritten the key between write and re-read
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
     * have no live dc:presence:client:<id> record), the update is silently
     * ignored.
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
        $data = is_array($envelope['data']) ? $envelope['data'] : [];

        // Contract BOT-BOUNDS on the move path. Deliberately handled BEFORE the
        // 150ms throttle: a bounds report is rare and one-shot, so letting the
        // throttle swallow it would put us straight back to "bounds never
        // arrive". The common no-bounds move pays ONE isset() and performs no
        // extra shared read or write.
        //
        // The <location> key component is the compile-time BOT_DEFAULT_LOCATION
        // constant, never anything the client sent — same as the join path — so
        // there is no key-injection surface here. Validation goes through the
        // shared sanitiseRoomBounds(), and a rejected value leaves whatever
        // bounds are already stored untouched rather than overwriting good
        // bounds with garbage.
        if (isset($data['bounds']) && !empty($_SESSION['uid'])) {
            $reportedBounds = self::sanitiseRoomBounds($data['bounds']);
            if ($reportedBounds !== null) {
                SharedState::set(self::DC_ROOM_BOUNDS_KEY_PREFIX . self::BOT_DEFAULT_LOCATION, $reportedBounds, self::PRESENCE_SESSION_TTL);
            }
        }

        // Per-client move rate limit: 150ms minimum between moves (matching
        // client THROTTLE_MS). The throttle key carries a TTL now, so a worker
        // that dies between moves stops throttling air instead of leaking the
        // key forever.
        $throttleKey = 'dc:presence:move_throttle:' . $client_id;
        $lastMove = SharedState::get($throttleKey);
        if (is_numeric($lastMove) && $lastMove > 0 && (microtime(true) - (float) $lastMove) < 0.15) {
            return; // Throttled - less than 150ms since last move
        }
        SharedState::set($throttleKey, microtime(true), self::PRESENCE_MOVE_TTL);
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
        $key = self::dcPresenceKey($moveClientId);
        $entry = SharedState::get($key);
        if (!$entry || !is_array($entry)) {
            return;  // member not in scene — silent ignore per spec
        }

        // Migration A2: the record is this client's OWN key, so the retired
        // GlobalData CAS (and its two-retry fallback) collapses to a plain
        // SET. Concurrent same-tab moves come from one connection; a
        // cross-worker lost update (two tabs never share a key) at worst
        // rewrites x/z/yaw with a slightly older value, refreshed within
        // 150ms by the next move — the same outcome the old code's documented
        // "fall back to a direct write" branch accepted.
        $newEntry = $entry;
        $newEntry['x'] = $x ?? $entry['x'];
        $newEntry['z'] = $z ?? $entry['z'];
        $newEntry['yaw'] = $yaw ?? $entry['yaw'];
        $newEntry['ts'] = time();
        if (!isset($newEntry['client_id'])) {
            $newEntry['client_id'] = $client_id;
        }
        SharedState::set($key, $newEntry, self::PRESENCE_RECORD_TTL);
        // Refresh the index scores so the move keeps the member inside the
        // staleness sweep window (TTL on the record + score here == liveness).
        self::presenceIndexAdd($moveClientId, $newEntry['ts']);

        // Queue for batched broadcast — stores in Redis so the flush timer on
        // ANY worker can pick it up. Static $moveBatch is process-local; with
        // N BusinessWorker processes the timer could fire on a different
        // process that has an empty batch, silently dropping all moves.
        $batchKey = 'dc:presence:move_batch:' . $moveClientId;
        SharedState::set($batchKey, $newEntry, self::PRESENCE_MOVE_TTL);

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
        self::$moveBatchTimer = \Workerman\Timer::add(0.05, self::safeTimerCallback('flushPresenceBatch', function () {
            self::flushPresenceBatch();
        }), [], false);
    }

    /**
     * Flush the pending dc:presence:move_batch:* entries as one
     * dc.presence.batch_updated
     * event. Shared by handleDcPresenceMove() and moveBot() (BUG-B7).
     *
     * Viewport filtering (BUG-B5) is decided PER RECIPIENT, not globally: the
     * old code set one $hasAnyViewport flag and, as soon as ANY client had a
     * dc:presence:viewport entry, sent only to clients that had viewport data. dc.js
     * reports its viewport only on location switch / GPU-context restore, so
     * every client that had done neither silently received zero movement
     * updates. Now a client with FRESH viewport data gets the filtered subset
     * and a client with no/stale viewport data (older than
     * DC_VIEWPORT_MAX_AGE) gets the unfiltered batch.
     *
     * Note recipients are enumerated from the dc:presence:active index (kept
     * in step with dc:presence:index by join/leave/onClose); when NOBODY has
     * fresh viewport data we fall back to a single group broadcast, which also
     * covers any dc_presence group member missing from that index.
     */
    private static function flushPresenceBatch(): void
    {
        // REVIEW-FIX: release the one-shot slot FIRST. The timer that scheduled us
        // has already fired, so the handle is stale by definition — but it used to
        // be nulled only after the batch read, and
        // scheduleDcPresenceFlush() early-returns while the field is non-null.
        // Any Throwable before that assignment (a store read error is the
        // obvious one) therefore wedged the field non-null forever and NO further
        // presence flush could ever be armed in this worker again — all movement
        // broadcasting for its clients stops permanently, with no bot or player
        // ever recovering. Clearing it up front also means a move that lands
        // during this flush correctly arms the next one.
        self::$moveBatchTimer = null;
        // Crash-safety backstop (A2): reclaim index entries older than the
        // staleness window whose deterministic removal raced a dead worker.
        self::sweepPresenceStale();
        // Read ALL move batch entries from Redis (keys are
        // dc:presence:move_batch:<client_id>), enumerated via the index.
        $batch = [];
        foreach (SharedState::zRange(self::DC_PRESENCE_INDEX_KEY, 0, -1) as $cid) {
            if (!is_string($cid) || $cid === '') {
                continue;
            }
            $decoded = SharedState::get('dc:presence:move_batch:' . $cid);
            // REVIEW-FIX: require an ARRAY. A scalar stored under the batch key
            // (or a corrupt decode) used to reach
            // isInPeerViewport($entry['x'], ...) below as null, which is a
            // TypeError against its float params — a fatal inside the flush
            // timer callback.
            if (is_array($decoded)) {
                $batch[$cid] = $decoded;
            }
        }
        if (empty($batch)) {
            return;
        }

        $vpCutoff = time() - self::DC_VIEWPORT_MAX_AGE;
        $activeClients = SharedState::zRange(self::DC_ACTIVE_INDEX_KEY, 0, -1);

        // Pass 1: split recipients into "has fresh viewport" and "does not".
        $filtered = [];   // cid => visible subset of $batch
        $unfiltered = []; // cids that must receive the whole batch
        foreach ($activeClients as $cid) {
            if (!is_string($cid) || $cid === '') {
                continue;
            }
            // Bots are presence entries, not sockets — never a send target.
            if (strpos($cid, 'bot_') === 0) {
                continue;
            }
            if (!SharedState::get('dc:presence:client_session:' . $cid)) {
                continue;
            }
            $peerVp = SharedState::get('dc:presence:viewport:' . $cid);
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

        // CRIT-7 fix: Clear batch entries after flush
        foreach (array_keys($batch) as $moverCid) {
            SharedState::del('dc:presence:move_batch:' . $moverCid);
        }
    }

    /**
     * Handle dc.presence.leave — client exiting the datacenter 3D scene.
     *
     * Removes the member's dc:presence:client:<client_id> record and index
     * entries, broadcasts dc.presence.left to the dc_presence channel (using
     * client_id so each browser tab is tracked independently), and replies with
     * {ok: true}.
     *
     * @param string $client_id
     * @param array $envelope v1 envelope
     */
    public static function handleDcPresenceLeave($client_id, $envelope)
    {
        $re = $envelope['id'];
        $uid = $_SESSION['uid'] ?? null;
        if (empty($uid)) {
            self::sendV1Error($client_id, $re, 'forbidden', 'dc.presence.leave requires authentication');
            return;
        }
        // Per-client key so each browser tab has its own presence entry
        $key = self::dcPresenceKey($client_id);
        $entry = SharedState::get($key);
        if (!$entry) {
            self::sendV1Error($client_id, $re, 'forbidden', 'dc.presence.leave: member not found');
            return;
        }

        // Atomic delete: one record key + two index members, all exact-key ops.
        SharedState::del($key);

        // Drop ourselves from BOTH index ZSETs first, so the shared last-real-
        // user predicate below sees the scene exactly as it will be after this
        // leave, self included no longer.
        //
        // REVIEW-FIX (index drift): dc.presence.leave used to remove the client
        // from dc_presence_clients ONLY, leaving it in dc_active_clients. The two
        // indexes then disagreed until the socket happened to close, and
        // flushPresenceBatch() enumerates RECIPIENTS from the active index — so a
        // client that had left the scene kept being sent batch_updated events and
        // kept rendering avatars for a scene it was no longer in. Unlike the
        // health-timer drop path this one never calls closeClient(), so onClose()
        // does not clean up behind it. presenceIndexRemove() now drops BOTH
        // indexes, which is exactly what that old comment demanded by hand.
        self::presenceIndexRemove($client_id);

        // Bot Presence System: was this the last real user at this location? If
        // so, the bot for it is cleaned up below. Consolidation: this replaces
        // the inline index count that duplicated Events::hasRealUsersAtLocation()
        // — same index, same 'bot_' prefix skip — with self-exclusion now coming
        // from the presenceIndexRemove() above instead of the old `!== $client_id`
        // test, so the answer is identical to the shipped inline behavior.
        $wasLastRealUser = !self::hasRealUsersAtLocation();

        // REVIEW-FIX: same orphaning as onClose() — once the client is out of
        // the index nothing will ever read or delete a pending batch entry for
        // it. The viewport entry is likewise scene-scoped.
        SharedState::del('dc:presence:move_batch:' . $client_id, 'dc:presence:viewport:' . $client_id);

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
     * Stores viewport data in Redis (dc:presence:viewport:<client_id>, TTL just
     * past DC_VIEWPORT_MAX_AGE so expiry and freshness agree) for use in
     * presence move filtering.
     *
     * @param string $client_id
     * @param array $envelope v1 envelope
     */
    public static function handleDcViewportUpdate($client_id, $envelope)
    {
        // MAJOR-14: require session auth
        if (empty($_SESSION['uid']) || empty($_SESSION['login'])) {
            return;
        }
        $data = is_array($envelope['data']) ? $envelope['data'] : [];
        $vp = $data;  // $data IS the viewport object, not $data['data']
        SharedState::set('dc:presence:viewport:' . $client_id, [
            'x' => (float)($vp['x'] ?? 0),
            'y' => (float)($vp['y'] ?? 0),
            'z' => (float)($vp['z'] ?? 0),
            'dirX' => (float)($vp['dirX'] ?? 0),
            'dirY' => (float)($vp['dirY'] ?? 0),
            'dirZ' => (float)($vp['dirZ'] ?? 0),
            'viewDist' => (float)($vp['viewDist'] ?? 50),
            'ts' => time(),
        ], self::PRESENCE_MOVE_TTL);
    }

    /**
     * Session health timer: sends a ping to every dc_presence client every 30s
     * and drops any that have missed 3+ consecutive pings (>90s since last pong).
     */
    public static function setupSessionHealthTimer()
    {
        \Workerman\Timer::add(30, self::safeTimerCallback('sessionHealth', function () {
            $now = time();

            // Iterate via the presence index ZSET to avoid reading a monolithic
            // presence map; the sweep first reclaims stale members whose
            // deterministic removal raced a dead worker.
            self::sweepPresenceStale();
            $clientList = SharedState::zRange(self::DC_PRESENCE_INDEX_KEY, 0, -1);
            if (empty($clientList)) {
                return;
            }

            // CRIT-9 fix: Three-phase approach to avoid reading newly-written ping timestamps.
            // Phase 1: Snapshot every client's last pong + last ping-sent BEFORE pinging.
            // Phase 2: Send this round's pings (writes ping-sent AFTER the snapshot).
            // Phase 3: Judge staleness from the Phase 1 snapshot, then drop.
            //
            // BUG-B4: Phase 2 used to overwrite dc:presence:ping: (the value
            // Phase 3 tests) for EVERY client on EVERY sweep, so the 90s check
            // could never be true and this watchdog was dead. Ping-send times
            // now live in dc:presence:ping_sent: and staleness is measured only
            // from the last pong RECEIVED (dc:presence:ping:) — see
            // dcPresenceIsStale().
            $threshold = self::PRESENCE_STALE_TTL;  // 3 × 30s missed = stale

            $toDrop = [];  // collect {clientId} entries to drop after the ping phase
            $clientEntries = [];  // clientId => [entry, clientId, lastPong, lastPingSent]

            // Phase 1: Read all entries and their OLD liveness timestamps
            foreach ($clientList as $clientId) {
                if (!is_string($clientId) || $clientId === '') {
                    continue;
                }
                // Bots have a presence entry but no socket — never ping or drop
                // them.
                // REVIEW-FIX: this check has to come BEFORE the stale-entry check
                // below. A bot whose presence entry has gone missing (the window
                // inside cleanupBotForLocation() between deleting the entry and
                // removing the index entry, or a crashed owner) otherwise fell
                // into the socket-drop path: closeClient() on a non-hex
                // "bot_main" id, and the index/presence cleanup ran WITHOUT
                // cleanupBotForLocation(), leaving bot state alive. The owning
                // worker then kept walking a bot that no longer appears in the
                // presence index, so flushPresenceBatch() could never pick its
                // moves up again — a permanently invisible bot with no path back.
                if (strpos($clientId, 'bot_') === 0) {
                    continue;
                }
                $entry = SharedState::get(self::dcPresenceKey($clientId));
                if (!$entry || !is_array($entry)) {
                    $toDrop[] = ['clientId' => $clientId];  // stale entry, mark for cleanup
                    continue;
                }
                $clientEntries[$clientId] = [
                    'entry' => $entry,
                    'clientId' => $clientId,
                    'lastPong' => (int) (SharedState::get(self::DC_PONG_KEY_PREFIX . $clientId) ?? 0),
                    'lastPingSent' => (int) (SharedState::get(self::DC_PING_SENT_KEY_PREFIX . $clientId) ?? 0)
                ];
            }

            // Phase 2: Send pings to all active clients (records the SEND time only)
            foreach ($clientEntries as $clientId => $data) {
                Gateway::sendToClient($clientId, json_encode([
                    'v' => 1, 'op' => 'ping', 'id' => 'keepalive', 'ts' => $now,
                    'data' => new \stdClass()
                ]));
                // REVIEW-FIX: BUG-B4 was only half fixed. Rewriting ping-sent
                // on EVERY sweep reproduced the original defect for the
                // never-ponged branch of dcPresenceIsStale(): Phase 1 always read
                // a value ~30s old, so `lastPingSent < now - 90` could never be
                // true and a client that never pongs at all was immune to the
                // watchdog forever. The ping-sent key must mark the START of the
                // current UNANSWERED streak, so only re-arm it once the previous
                // ping has been answered (or none was ever sent). A client that
                // has an outstanding ping keeps its original send time and is
                // therefore dropped after ~120s of total silence — still well
                // past the 90s threshold, so "pinged but not yet ponged" is never
                // dropped prematurely.
                if ($data['lastPingSent'] === 0 || $data['lastPong'] >= $data['lastPingSent']) {
                    SharedState::set(self::DC_PING_SENT_KEY_PREFIX . $clientId, $now, self::PRESENCE_PING_TTL);
                }
            }

            // Phase 3: Check staleness using the Phase 1 snapshot and drop stale clients
            foreach ($clientEntries as $clientId => $data) {
                if (self::dcPresenceIsStale($data['lastPong'], $data['lastPingSent'], $now, $threshold)) {
                    $toDrop[] = ['clientId' => $clientId];
                }
            }

            // Drop stale clients AFTER the loop (avoids modifying the list during iteration)
            // CRIT-9 fix: Two-phase approach — mark cleanup before removal to prevent orphaned entries
            foreach ($toDrop as $dropInfo) {
                $clientId = $dropInfo['clientId'];
                if (!$clientId) {
                    continue;
                }
                $presenceKey = self::dcPresenceKey($clientId);
                $cleanupKey = 'dc:presence:cleanup:' . $clientId;

                // Phase 1: Mark client as being cleaned up (prevents race with handleDcPresenceJoin)
                // Write a TTL'd sentinel so concurrent joins can detect cleanup-in-progress
                SharedState::set($cleanupKey, $now, self::PRESENCE_MOVE_TTL);

                // CRIT-9: If this client's cleanup has already been handled by onClose, skip
                // Only skip if sentinel is set AND the record is already gone
                // (onClose completed cleanup).
                if (SharedState::get($presenceKey) === null) {
                    // REVIEW-FIX (ghost-index leak): this branch is taken by
                    // EVERY record-less index member that Phase 1 queued (a
                    // stale index entry has no presence record by definition),
                    // and the old `continue` jumped over the loop-tail
                    // presenceIndexRemove() — so ghosts stayed in the
                    // dc:presence:* ZSETs forever, re-queued by every 30s sweep
                    // with unbounded growth. Drop the member from BOTH indexes
                    // first, then release our own cleanup marker (an earlier
                    // fix: the old code leaked the sentinel on this path too)
                    // and skip only the record-delete/closeClient work that
                    // onClose() already did.
                    self::presenceIndexRemove($clientId);
                    SharedState::del($cleanupKey);
                    // NOTE: deliberately NO closeClient() here. A record-less
                    // index member is a ghost whose socket is already gone; the
                    // half-open-socket case is handled on the drop path below,
                    // which is now reachable because PRESENCE_RECORD_TTL outlives
                    // PRESENCE_STALE_TTL (see that constant). Pinned by
                    // EventsV1DcPresenceMultiTabTest::testHealthTimerPrunesGhostIndexEntries.
                    continue;
                }

                // Phase 2: Delete per-client presence record + index entries
                SharedState::del($presenceKey);

                // Clean up session mapping
                $ck = 'dc:presence:client_session:' . $clientId;
                $sessionId = SharedState::get($ck);
                if ($sessionId) {
                    $listKey = 'dc:presence:session_clients:' . $sessionId;
                    $clients = SharedState::get($listKey);
                    if (is_array($clients)) {
                        SharedState::set($listKey, array_values(array_filter($clients, fn ($c) => $c !== $clientId)), self::PRESENCE_SESSION_TTL);
                    }
                    SharedState::del($ck);
                }
                SharedState::del(
                    self::DC_PONG_KEY_PREFIX . $clientId,
                    self::DC_PING_SENT_KEY_PREFIX . $clientId,
                    $cleanupKey
                );
                Gateway::closeClient($clientId, 'missed_keepalive');

                // Remove clientId from both presence index ZSETs (exact-member ZREM).
                self::presenceIndexRemove($clientId);

                Worker::safeEcho("[dc_presence] dropped {$clientId} — missed keepalive\n");
            }
        }));
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
        $bounds = self::sanitiseRoomBounds(SharedState::get(self::DC_ROOM_BOUNDS_KEY_PREFIX . $location));
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
        $positions = [];
        foreach (SharedState::zRange(self::DC_PRESENCE_INDEX_KEY, 0, -1) as $cid) {
            if (!is_string($cid) || strpos($cid, 'bot_') === 0) {
                continue;  // not a real player
            }
            $entry = SharedState::get(self::dcPresenceKey($cid));
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
     * Cached hostname. Identifies which of the three datacentered instances a
     * shared ownership marker belongs to.
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
     * Ownership marker for a process-local resource recorded in shared state:
     * "<host>:<pid>". Host-qualified because pids collide across the three
     * instances that share one Redis. SharedState::lock() tokens already carry
     * the same host:pid:hex shape; this is the human-readable form used in the
     * bot lifecycle log lines so a "[dc_bot] ... owned by" message names the
     * actual instance, not just a number that only means something locally.
     */
    private static function processMarker(): string
    {
        return self::localHostName() . ':' . getmypid();
    }

    /**
     * Redis lock name for one location's bot ownership.
     *
     * @param string $location
     * @return string
     */
    private static function botOwnerLockName(string $location): string
    {
        return 'bot_owner:' . $location;
    }

    /**
     * Spawn a bot avatar for a given datacenter location if one doesn't exist.
     * The bot walks around the datacenter building, simulating a real user.
     * Bot state lives in Redis so multiple BusinessWorkers can access it.
     *
     * Ownership (migration A2): the retired GlobalData dc_bot_timer:<location>
     * owner marker + dc_bot_state heartbeat staleness dance (botOwnerAlive()
     * reading /proc across three hosts sharing one store) is replaced by one
     * SharedState lock: dc:lock:bot_owner:<location> @ BOT_OWNER_LOCK_TTL.
     * Acquiring it IS taking ownership; a crashed owner's lock lapses and the
     * next join takes over, which is exactly what the pid/heartbeat liveness
     * probing emulated — with a real, enforced TTL. The process-local
     * Workerman timer id stays process-local (THE BOT #4 unchanged).
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

        $botId = 'bot_' . $location;
        $botStateKey = self::BOT_STATE_KEY_PREFIX . $location;

        // Contract BOT-BOUNDS: record any freshly reported room bounds first so
        // the spawn position below is computed inside the REAL room.
        $reportedBounds = self::sanitiseRoomBounds($bounds);
        if ($reportedBounds !== null) {
            SharedState::set(self::DC_ROOM_BOUNDS_KEY_PREFIX . $location, $reportedBounds, self::PRESENCE_SESSION_TTL);
        }

        // Take ownership. A null token means a live owner holds the lock (its
        // TTL has not lapsed) — the bot already exists and is being driven, by
        // us or by another instance. Either way, nothing to do here.
        $token = SharedState::lock(self::botOwnerLockName($location), self::BOT_OWNER_LOCK_TTL);
        if ($token === null) {
            if (isset(self::$botTimers[$location]) && !isset(self::$botLockTokens[$location])) {
                // We hold a move timer for a bot we no longer own (our lock
                // lapsed and someone else took over): retire OUR timer only —
                // a Workerman id is meaningless in any other process.
                Timer::del(self::$botTimers[$location]);
                unset(self::$botTimers[$location]);
                Worker::safeEcho("[dc_bot] retiring duplicate bot timer for '{$location}' in ".self::processMarker()." (lock held by another process)\n");
            }
            return;
        }
        self::$botLockTokens[$location] = $token;
        if (isset(self::$botTimers[$location])) {
            // A timer we armed before losing the lock (crash-window takeover
            // that came back to us): drop it, the spawn below re-arms cleanly.
            Timer::del(self::$botTimers[$location]);
            unset(self::$botTimers[$location]);
        }

        $roomBounds = self::dcRoomBounds($location);

        // REVIEW-FIX (decision D): ADOPT a surviving bot state instead of always
        // respawning a fresh one. BOT_STATE_TTL outlives BOT_OWNER_LOCK_TTL by
        // design, so after a takeover (crashed owner, or an owner whose lock
        // lapsed during a stall) the previous bot's identity and position are
        // still in Redis. Rebuilding them from scratch made every handoff a
        // visible teleport AND rename of the same avatar. Keep the existing
        // uid/name/position/target and just re-stamp ts+bounds; only spawn fresh
        // when there is genuinely no state to inherit.
        $existingState = SharedState::get($botStateKey);
        $adopted = is_array($existingState)
            && isset($existingState['name'], $existingState['x'], $existingState['z']);

        if ($adopted) {
            $botState = $existingState;
            $botName = (string) $botState['name'];
            $spawnX = (float) $botState['x'];
            $spawnZ = (float) $botState['z'];
            $botState['uid'] = $botId;
            $botState['client_id'] = $botId;
            $botState['location'] = $location;
            $botState['bounds'] = $roomBounds;
            $botState['ts'] = time();
            // Keep walking toward whatever it was heading for; moveBot() picks a
            // new target on arrival anyway.
            if (!isset($botState['target_x'], $botState['target_z'])) {
                $botState['target_x'] = $spawnX;
                $botState['target_z'] = $spawnZ;
            }
            if (!isset($botState['yaw'])) {
                $botState['yaw'] = lcg_value() * 2 * M_PI;
            }
        } else {
            // Pick a random bot name
            $botName = self::$botNames[array_rand(self::$botNames)] . ' ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 4);

            // Spawn near the joining player (THE BOT #1/#3), clamped inside the room.
            $anchor = null;
            if (is_array($near) && isset($near['x'], $near['z']) && is_numeric($near['x']) && is_numeric($near['z'])) {
                $anchor = ['x' => (float) $near['x'], 'z' => (float) $near['z']];
            } else {
                $anchor = self::randomRealClientPosition();
            }
            [$spawnX, $spawnZ] = self::randomPointNear($anchor, $roomBounds, self::BOT_SPAWN_RADIUS);
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
        }
        Worker::safeEcho('[dc_bot] spawn x=' . $botState['x'] . ' z=' . $botState['z'] . "\n");
        SharedState::set($botStateKey, $botState, self::BOT_STATE_TTL);

        // Write bot presence entry to Redis (same format as real users)
        $presenceKey = self::dcPresenceKey($botId);
        SharedState::set($presenceKey, $botState, self::BOT_STATE_TTL);
        self::presenceIndexAdd($botId, $botState['ts']);

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
        // THE BOT #4: the (process-local) timer id stays process-local; the
        // host:pid:hex token in dc:lock:bot_owner:<location> names the owner
        // instance across all three datacentered hosts.
        self::$botTimers[$location] = $timerId;

        $how = $adopted ? 'adopted' : 'spawned';
        Worker::safeEcho("[dc_bot] {$how} bot '{$botName}' ({$botId}) at location '{$location}' at ({$spawnX}, {$spawnZ}) owned by {$token}\n");
    }

    /**
     * Move the bot for a given location - called every BOT_MOVE_INTERVAL seconds.
     * Implements realistic wandering: picks a target point and walks toward it.
     *
     * Ownership heartbeat (migration A2): every tick renews the location's
     * owner lock and refreshes the state/presence TTLs — that renew IS the
     * BOT_OWNER heartbeat the old BOT_OWNER_HEARTBEAT_MAX_AGE staleness window
     * measured. A renew that fails means the TTL lapsed while this process was
     * stalled and someone else legitimately took the bot over: retire OUR
     * timer, never touch the new owner's state.
     *
     * @param string $location Datacenter location name
     */
    public static function moveBot(string $location = self::BOT_DEFAULT_LOCATION): void
    {
        // Registered directly as ['Events','moveBot'] (an invariant pinned by
        // EventsBotPresenceTest), so the worker-kill guard that other timers get
        // from safeTimerCallback() has to live INSIDE the callback here. Without
        // it a throw on this 0.5s-per-location tick reaches Workerman's loop
        // errorHandler == stopAll(250) and exits the BusinessWorker.
        try {
            self::moveBotInner($location);
        } catch (\Throwable $e) {
            Worker::safeEcho("moveBot error for '{$location}': {$e->getMessage()}\n");
        }
    }

    /**
     * moveBot() body. See moveBot() for why the throw-guard is separate.
     *
     * @param string $location
     * @return void
     */
    private static function moveBotInner(string $location = self::BOT_DEFAULT_LOCATION): void
    {
        if (!FeatureFlags::dcBotPresenceEnabled()) {
            self::cleanupBotForLocation($location);
            return;
        }

        $botId = 'bot_' . $location;
        $botStateKey = self::BOT_STATE_KEY_PREFIX . $location;

        // THE BOT #4 (A2 form): only the lock-owning process drives the bot.
        if (!isset(self::$botTimers[$location])) {
            return; // no timer here; nothing to drive
        }
        $token = self::$botLockTokens[$location] ?? null;
        if ($token !== null && !SharedState::renew(self::botOwnerLockName($location), $token, self::BOT_OWNER_LOCK_TTL)) {
            // REVIEW-FIX (decision D): renew() returns the SAME false for "lost
            // the lock" and "transport is dead", and retiring on the latter turned
            // a ~30s Redis blip into a permanently dead bot — nothing respawns
            // until the next dc.presence.join, and no dc.presence.left is sent, so
            // frontends keep a frozen avatar. Distinguish the two: on transport
            // death skip the tick and keep both the timer and our token, so the
            // bot resumes by itself when the facade heals. We are not double-driving
            // anything by holding on — no other process can have taken the lock,
            // because nobody can reach Redis to take it.
            if (SharedState::transportFailed()) {
                return;
            }
            $token = null;
        }
        if ($token === null) {
            // Genuinely lost (expired + taken over) or never held.
            // Stop driving; the current owner's state keys survive untouched.
            unset(self::$botLockTokens[$location]);
            Timer::del(self::$botTimers[$location]);
            unset(self::$botTimers[$location]);
            Worker::safeEcho("[dc_bot] retiring bot timer for '{$location}' in ".self::processMarker()." (owner lock lost)\n");
            return;
        }

        $botState = SharedState::get($botStateKey);

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
            [$targetX, $targetZ] = self::randomPointNear($anchor, $roomBounds, self::BOT_WANDER_RADIUS);
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

            // Update bot state in Redis (TTL refresh == the heartbeat).
            // Contract note: plan said bot presence EX300; BOT_STATE_TTL=30s here is a
            // deliberate deviation, not an oversight — each BOT_MOVE_INTERVAL=0.5s tick
            // refreshes state+presence while the owner is alive, and the
            // BOT_OWNER_LOCK_TTL=10 owner lock lapses first, so a dead-owner ghost
            // self-clears within ~30s rather than the ~300s EX300 would leave it up. A
            // >30s stall without a refresh tick is impossible while the owner lives
            // (see the moveBot owner renew at :5256).
            SharedState::set($botStateKey, $botState, self::BOT_STATE_TTL);
            SharedState::set(self::dcPresenceKey($botId), $botState, self::BOT_STATE_TTL);
            self::presenceIndexAdd($botId, $botState['ts']);

            // NO per-tick position log here. moveBot() runs on a BOT_MOVE_INTERVAL
            // timer for every location with a bot, so a safeEcho() here is one
            // fflush()ed line per bot per tick, forever — it was the top line in
            // billingd.log once the GateWaySSL drain spam was fixed. The bot's
            // lifecycle is already covered by the spawn / cleanup / ownership logs
            // above, which fire once per event instead of once per tick.

            // Write to batch key so batch timer broadcasts this move
            SharedState::set('dc:presence:move_batch:' . $botId, $botState, self::PRESENCE_MOVE_TTL);

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
     * Cross-process rules kept from THE BOT #4: a Workerman timer id is only
     * valid in the process that created it, so only that process may
     * Timer::del() it; and (A2) the shared ownership lock may only be
     * released by its token holder — a cleanup request from a non-owner
     * deletes state (so the owner's next tick reaps its own timer) but never
     * unlocks another instance's hold.
     *
     * @param string $location Datacenter location name
     */
    public static function cleanupBotForLocation(string $location = self::BOT_DEFAULT_LOCATION): void
    {
        $botId = 'bot_' . $location;
        $botStateKey = self::BOT_STATE_KEY_PREFIX . $location;

        // Stop and remove the timer (ours only).
        if (isset(self::$botTimers[$location])) {
            Timer::del(self::$botTimers[$location]);
            unset(self::$botTimers[$location]);
        }

        $token = self::$botLockTokens[$location] ?? null;
        if ($token !== null) {
            // This process drove the bot: retire the lock token-checked. A
            // failed unlock just means the TTL already lapsed — nothing to do.
            SharedState::unlock(self::botOwnerLockName($location), $token);
            unset(self::$botLockTokens[$location]);
        }

        // Remove bot state (this is what makes a still-running owner process
        // stop next tick and self-reap its timer).
        SharedState::del($botStateKey);

        // Remove bot presence record + index entries.
        SharedState::del(self::dcPresenceKey($botId));
        SharedState::zRem(self::DC_PRESENCE_INDEX_KEY, $botId);
        SharedState::zRem(self::DC_ACTIVE_INDEX_KEY, $botId);

        // Clean up any pending batch entries
        SharedState::del('dc:presence:move_batch:' . $botId);

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
        foreach (SharedState::zRange(self::DC_PRESENCE_INDEX_KEY, 0, -1) as $clientId) {
            // Skip bot client IDs; anything else is a real user.
            if (is_string($clientId) && strpos($clientId, 'bot_') === 0) {
                continue;
            }
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
        self::logStructured('client.close', ['client_id' => $client_id, 'uid' => $_SESSION['uid'] ?? null]);

        // Broadcast dc.presence.left BEFORE cleaning up — proactively notify remaining
        // clients so their avatars disappear immediately instead of waiting for
        // setupSessionHealthTimer (up to 30s later).
        $uid = $_SESSION['uid'] ?? null;
        if ($uid) {
            // Per-client presence key so each browser tab is tracked independently
            $presenceKey = self::dcPresenceKey($client_id);
            $presenceEntry = SharedState::get($presenceKey);
            if ($presenceEntry && is_array($presenceEntry)) {
                self::broadcastDcPresence(
                    'dc.presence.left',
                    ['uid' => $uid, 'clientId' => $client_id],
                    "[{$client_id}] client.close"
                );
                // CRIT-9 fix: Two-phase cleanup — mark before removal so the
                // health timer can detect and skip an in-flight cleanup.
                SharedState::set('dc:presence:cleanup:' . $client_id, time(), self::PRESENCE_MOVE_TTL);

                // Phase 2: delete the record, drop from both indexes, drop the marker.
                SharedState::del($presenceKey);
                self::presenceIndexRemove($client_id);
                SharedState::del('dc:presence:cleanup:' . $client_id);
            }
        }

        // Belt-and-braces: leave the recipient index even if the record was
        // already gone (leave raced close, or a never-joined socket closing).
        SharedState::zRem(self::DC_ACTIVE_INDEX_KEY, $client_id);

        // Clean up dc:presence:client_session and the liveness keys for this client
        $sessionKey = 'dc:presence:client_session:' . $client_id;
        $sessionId = SharedState::get($sessionKey);
        if ($sessionId) {
            $listKey = 'dc:presence:session_clients:' . $sessionId;
            $clients = SharedState::get($listKey);
            if (is_array($clients)) {
                SharedState::set($listKey, array_values(array_filter($clients, fn ($c) => $c !== $client_id)), self::PRESENCE_SESSION_TTL);
            }
            SharedState::del($sessionKey);
        }
        // Unconditional: these are written by the health timer even for clients
        // whose session mapping has already gone away.
        SharedState::del(
            self::DC_PONG_KEY_PREFIX . $client_id,
            self::DC_PING_SENT_KEY_PREFIX . $client_id
        );
        // IDEA-3: clean up viewport data for this client.
        // MAJOR-13: clean up move throttle key for this client.
        // REVIEW-FIX (unbounded growth): dc_move_batch:<client_id> had NO deletion
        // path outside flushPresenceBatch(), and that flush only enumerated the
        // presence index. A client that disconnects in the <=50ms between
        // writing its move batch and the flush is removed from that index first,
        // so its batch key was orphaned in the store forever. With a 150ms move
        // throttle against a 50ms flush the window is hit routinely.
        SharedState::del(
            'dc:presence:viewport:' . $client_id,
            'dc:presence:move_throttle:' . $client_id,
            'dc:presence:move_batch:' . $client_id
        );

        if (isset($_SESSION['uid'])) {
            $clientIds = Gateway::getClientIdByUid($_SESSION['uid']);
            if (count($clientIds) == 1) {
                $logoutMessage = [
                    'type' => 'logout',
                    'id' => $_SESSION['uid'],
                    'time' => date('Y-m-d H:i:s')
                ];
                // Migration A2: each room is its own hash field, so the
                // re-read + CAS retry loop the shared rooms array needed is
                // gone — the HSETs above are per-field atomic and a racing
                // join/leave on ANOTHER room can no longer lose to this one.
                // Same-field RMW here is still last-writer-wins (no CAS): a concurrent
                // join/leave on THIS room can drop the other's member, but the ghost
                // self-heals on next reconcile — parity with join()/say() which were
                // non-CAS at HEAD too, and rooms is legacy/retirement-flagged so
                // locking is deliberately not reintroduced.
                $rooms = SharedState::hGetAll(self::ROOMS_REGISTRY_KEY);
                foreach ($rooms as $roomIdx => $room) {
                    if (is_array($room) && ($key = array_search($_SESSION['uid'], $room['members'])) !== false) {
                        unset($room['members'][$key]);
                        $room['members'] = array_values($room['members']);
                        Gateway::sendToGroup($room['id'], json_encode($logoutMessage));
                        SharedState::hSet(self::ROOMS_REGISTRY_KEY, (string) $roomIdx, $room);
                    }
                }
            }
            /*
             * REVIEW-FIX (ghost ptys): drop pty registry entries this connection
             * owned. dc:state:ptys had NO cleanup here at all and no TTL — it was
             * removed only by an explicit pty.close — so every session that dropped
             * mid-pty leaked a field permanently. pty.open now reclaims a corpse on
             * collision, but that only helps when the same pty_id comes back;
             * without this the hash still grows without bound.
             *
             * Only prune when this is the LAST connection for the uid: another tab
             * of the same admin may still be driving the pty.
             */
            if (count($clientIds) == 1) {
                $ptyFieldsToDrop = [];
                foreach (SharedState::hGetAll(self::PTYS_REGISTRY_KEY) as $ptyId => $ptyEntry) {
                    if (!is_array($ptyEntry)) {
                        continue;
                    }
                    if (($ptyEntry['for'] ?? null) === $_SESSION['uid']
                        || ($ptyEntry['host'] ?? null) === $_SESSION['uid']) {
                        $ptyFieldsToDrop[] = (string) $ptyId;
                    }
                }
                if ($ptyFieldsToDrop !== []) {
                    SharedState::hDel(self::PTYS_REGISTRY_KEY, ...$ptyFieldsToDrop);
                    Worker::safeEcho('onClose: dropped pty registry entries '.implode(', ', $ptyFieldsToDrop).' for '.$_SESSION['uid']."\n");
                }
            }
            if (isset($_SESSION['ima'])) {
                if ($_SESSION['ima'] == 'host') {
                    $id = str_replace('vps', '', $_SESSION['uid']);
                    SharedState::hDel(self::HOSTS_REGISTRY_KEY, (string) $id);
                    SharedState::del(self::ADMIN_HOSTS_CACHE_KEY);
                } else {
                    if (count($clientIds) == 1) {
                        // Send command to stop running any processes that were running and directed at this user
                        $remove = [];
                        foreach (SharedState::sMembers(self::RUNNING_INDEX_KEY) as $run_id) {
                            if (!is_string($run_id) || $run_id === '') {
                                continue;
                            }
                            $run = SharedState::get(self::RUNNING_KEY_PREFIX . $run_id);
                            if (is_array($run) && ($run['for'] ?? null) == $_SESSION['uid']) {
                                $remove[] = $run_id;
                                Gateway::sendToUid($run['host'], json_encode(['type' => 'stop_run', 'id' => $run['id'] ?? $run_id]));
                            }
                        }
                        foreach ($remove as $run_id) {
                            SharedState::del(self::RUNNING_KEY_PREFIX . $run_id);
                            SharedState::sRem(self::RUNNING_INDEX_KEY, $run_id);
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
     * GlobalData→Redis migration (A1): the `processing_queue` lock is a
     * SharedState (Redis) lock with a real 900s TTL. The TTL replaces the old
     * manual stale-reset branch — an abandoned lock now lapses on its own —
     * and refreshProcessingLock()/releaseProcessingLock() extend/remove it
     * token-checked, so a slow chain can never clobber a re-acquired lock.
     *
     * SharedState wraps every command, so a dead transport never throws out of
     * lock(): it returns the same null as contention. transportFailed() tells
     * the two apart, and this timer escalates ONLY the transport death — an
     * operator nudging via Web/trigger_payment.php must get "unavailable", not
     * a silent ok-noop, when the payment chain could not even reach Redis.
     * Three consumers, three containments of that escalation:
     *   - the 30s periodic tick: contained by safeTimerCallback() at the
     *     Timer::add() registration site. Workerman itself does NOT contain it
     *     — Select::tick() safeCall()s the callback, but safeCall() forwards the
     *     Throwable to the loop errorHandler, which Worker::run() installs as
     *     stopAll(250, $e). An unwrapped throw here exits the worker;
     *   - Web/trigger_payment.php: catches \Throwable and answers the
     *     documented "unavailable";
     *   - legacy WS Events::msgPaymentprocess (onMessage): catches locally and
     *     logs, so the adjacent boardctl_queue_timer() nudge still runs.
     */
    public static function processing_queue_timer()
    {
        if (is_null(self::$db)) {
            self::$db = self::createDbConnection();
            if (is_null(self::$db)) {
                return;
            }
        }
        $token = SharedState::lock('processing_queue', 900);
        if ($token === null) {
            if (SharedState::transportFailed()) {
                throw new \RuntimeException('processing_queue_timer: Redis transport failed acquiring the processing_queue lock — the nudge could not run');
            }
            // Held elsewhere — same no-op contract as a lost CAS.
            return;
        }
        self::$processingLockToken = $token;
        // NOTE: For performance, ensure queue_log has a compound index on (history_section, history_new_value).
        // Verified in staging with: SHOW INDEX FROM queue_log WHERE Key_name = 'idx_boardctl_pending';
        // If missing, run: ALTER TABLE queue_log ADD INDEX idx_boardctl_pending (history_section, history_new_value);
        // This index also benefits similar queries at boardctl_queue_timer, boardctl_startup_reap,
        // and processing_queue_reaper.
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

    /**
     * Mark the processing queue lock as still alive.
     *
     * The SharedState lock carries a 900s TTL and processing_queue_timer() can
     * only take it once per lapse. Long-but-healthy chains call this to renew
     * the deadline so their lock does NOT expire mid-run and get stolen. A
     * renew is token-checked: if the TTL lapsed and another holder took the
     * lock, this silently does nothing rather than resurrecting the old hold.
     */
    private static function refreshProcessingLock(): bool
    {
        // Only renew a lock we actually hold; never resurrect a released one.
        if (self::$processingLockToken === null) {
            return false;
        }
        if (SharedState::renew('processing_queue', self::$processingLockToken, 900)) {
            return true;
        }

        /*
         * REVIEW-FIX: the renew result used to be DISCARDED — the one renew site
         * of ten that ignored it. renew() returning false means this chain no
         * longer owns `processing_queue` (the TTL lapsed and the 30s timer
         * re-acquired), and the lock is the ONLY mutual exclusion on the payment
         * queue: dbUpdateWithRetry() updates queue_log by history_id with no
         * `AND history_new_value='pending'` guard, so a second chain re-SELECTs
         * the same still-pending rows and process_payment() runs twice for one
         * history_id. Drop the stale token so releaseProcessingLock() cannot
         * later delete the NEW owner's lock, and report the loss so the caller
         * aborts instead of continuing to process rows it no longer owns.
         */
        Worker::safeEcho("processing_queue lock lost mid-chain (expired or taken) — aborting this chain\n");
        self::$processingLockToken = null;

        return false;
    }

    /**
     * Release the processing queue lock and record last-run time.
     */
    /**
     * Wrap a periodic-timer callback so a throw can never kill the worker.
     *
     * Workerman provides NO containment for a throwing timer callback, despite
     * the name of the method that invokes it: Events\Select::tick() calls
     * safeCall(), and safeCall() catches the Throwable only to hand it to the
     * event loop's errorHandler — which Worker::run() sets to
     * stopAll(250, $exception). So an uncaught throw in ANY timer callback
     * terminates the BusinessWorker; the master respawns it and, if the cause
     * is persistent (e.g. Redis unreachable), the worker crash-loops.
     *
     * Every Timer::add() in onWorkerStart() must therefore route through here.
     * The wrapper logs and swallows, which is the correct contract for a
     * periodic nudge: the next tick retries. Callers that need to OBSERVE the
     * failure (Web/trigger_payment.php, msgPaymentprocess) call the underlying
     * method directly and do their own catching.
     *
     * @param string   $name     timer name, for the log line
     * @param callable $callback the real timer body
     * @return callable safe to hand to Timer::add()
     */
    /**
     * Refresh a run registry entry's TTL on activity.
     *
     * REVIEW-FIX: dc:state:running:<run_id> is written with RUNNING_ENTRY_TTL
     * (3600s) and nothing ever refreshed it, while the GlobalData $global->running
     * map it replaced had NO expiry and was removed only on cmd.exit / msgRan /
     * onClose. So an interactive run (cmd.exec with interact:true — an ssh or
     * shell session) simply stopped working after exactly one hour: every
     * subsequent cmd.stdin, cmd.output, cmd.exit and cmd.kill found no entry and
     * was SILENTLY DROPPED. Keystrokes vanished with no error, host output was
     * discarded, the admin was never told the run ended, the run could no longer
     * be killed, and the onClose stop_run sweep could not see it — so the host
     * kept the child process alive after the admin disconnected.
     *
     * Traffic in either direction is proof the run is alive, so both relay paths
     * extend the window. The TTL is kept (rather than removed) so a genuinely
     * abandoned entry still self-reclaims, which is what bounds the
     * dc:state:running_ids index.
     *
     * @param string $run_id
     * @return void
     */
    private static function touchRun(string $run_id): void
    {
        SharedState::expire(self::RUNNING_KEY_PREFIX . $run_id, self::RUNNING_ENTRY_TTL);
    }

    private static function safeTimerCallback(string $name, callable $callback): callable
    {
        return static function (...$args) use ($name, $callback) {
            try {
                return $callback(...$args);
            } catch (\Throwable $e) {
                Worker::safeEcho("{$name} error: {$e->getMessage()}\n");

                return null;
            }
        };
    }

    private static function releaseProcessingLock()
    {
        if (self::$processingLockToken === null) {
            // Nothing held by this process. An unlock() with no token is the
            // admin force-delete — a double release must never reach it.
            return;
        }
        $token = self::$processingLockToken;
        self::$processingLockToken = null;
        SharedState::unlock('processing_queue', $token);
        SharedState::set(SharedState::PREFIX_STATE.'processing_queue_last', time());
    }

    /**
     * Recover boardctl jobs orphaned by a datacentered restart. A boardctl run is
     * a proc_open ssh child of the TaskWorker process, so a full restart kills it
     * while its queue_log row is still 'processing' — and boardctl_queue_job then
     * refuses to queue a rerun for that asset (duplicate guard). This resets such
     * rows to 'failed' so an operator can re-queue.
     *
     * Called ONLY from the onWorkerStart cold-start gate in
     * Events (SharedState::lock('startup_reap') winning), which fires at most
     * once per STARTUP_REAP_LOCK_TTL window — i.e. on a full restart, and
     * never as a periodic sweep. A long-running job
     * (up to the 6h cap) that survives a reload is therefore never touched. NOT a
     * periodic timer on purpose: a time-based sweep cannot tell a 6h job that is
     * still running apart from one that died, and would kill live jobs.
     */
    public static function boardctl_startup_reap()
    {
        if (is_null(self::$db)) {
            self::$db = self::createDbConnection();
            if (is_null(self::$db)) {
                return;
            }
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
            $rows = self::$db->select('history_id, history_type')->from('queue_log')
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

            /*
             * REVIEW-FIX: also free the per-asset lock this dead runner was
             * holding. Under GlobalData the lock server was an in-process
             * Workerman worker, so `php start.php restart` wiped every lock and a
             * stuck boardctl_asset lock was cleared by the very restart that runs
             * this reap. Redis is external and persists, and nothing else clears
             * these: the cold-start pre-clean covers only dc:lock:vps_host_* for
             * vps_type=11. So a SIGKILLed runner (or a reboot) left
             * dc:lock:boardctl_asset_<id> held for its full 22200s TTL — 6h10m —
             * during which boardctl_queue_timer got null from lock() and silently
             * `continue`d every 15s. The asset looked permanently dead and a
             * restart no longer helped.
             *
             * The token-less unlock() is an UNCONDITIONAL delete, which is
             * normally forbidden — it is correct here precisely because this is
             * the documented admin/stale-cleanup path: we have just proven the
             * holder is gone (no pidfile, or a dead pid) and marked its row failed.
             */
            $parts = explode(':', (string) ($row['history_type'] ?? ''), 2);
            $assetId = isset($parts[1]) ? intval($parts[1]) : intval($row['history_type'] ?? 0);
            if ($assetId > 0) {
                SharedState::unlock('boardctl_asset_'.$assetId);
                Worker::safeEcho("boardctl_startup_reap: released orphaned boardctl_asset_{$assetId} lock (history_id={$historyId})\n");
            } else {
                Worker::safeEcho("boardctl_startup_reap: history_id={$historyId} has unparseable history_type '".($row['history_type'] ?? '')."' — asset lock NOT released\n");
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
            if (is_null(self::$db)) {
                return;
            }
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
            Timer::add(1, self::safeTimerCallback('dbUpdateWithRetry', function () use ($status, $historyId, $onSuccess, $try, $maxTries) {
                self::dbUpdateWithRetry($status, $historyId, $onSuccess, $try, $maxTries);
            }), [], false);
        }
    }

    public static function process_results($results)
    {
        /*
         * Renew the lock's 900s TTL before each result. That TTL is not tied
         * to any bound on how long this chain can run -- dispatchTask() has no
         * timeout, and each result costs a task round trip plus up to 30
         * seconds of dbUpdateWithRetry backoff, so a large batch legitimately
         * exceeds 900s. Without this heartbeat the TTL lapses, the timer
         * steals the lock from a chain that is still working, and a second
         * chain starts alongside it.
         *
         * Heartbeating keeps the TTL lapse meaningful: it now only strands a
         * chain that has genuinely stopped making progress, rather than one
         * that is merely slow. (boardctl solves the same problem by pinning its
         * timeout above a known runner cap; there is no equivalent cap here.)
         */
        if (!self::refreshProcessingLock()) {
            // Ownership is gone (or was never held): another chain legitimately
            // holds the lock and is working these rows. Stop — do NOT touch the
            // work, per the SharedState renew contract. The token has already
            // been cleared, so no unlock is issued here: deleting the lock now
            // would hand a third chain the same rows.
            return;
        }
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
            if (is_null(self::$db)) {
                return;
            }
        }
        // Snapshot of the shared hosts registry once per tick; field names of
        // the Redis hash are the vps ids (migration A2 — the old live
        // $global->hosts reads were whole-map fetches on every check).
        $hostRegistry = SharedState::hGetAll(self::HOSTS_REGISTRY_KEY);
        /*
         * REVIEW-FIX (ghost hosts): reconcile the registry against live
         * connections before trusting it.
         *
         * dc:state:hosts is written once, when a host authenticates, and removed
         * only in onClose(). Under GlobalData that was safe by accident: the store
         * was an in-process Workerman worker, so a kill -9 / OOM / reboot wiped it
         * and onWorkerStart re-seeded from live connections. Redis is external and
         * persists, and the cold-start blanking was (correctly) dropped because
         * several datacentered instances share one Redis — truncating here would
         * delete hosts that are live on ANOTHER instance.
         *
         * So a hub that dies without running onClose leaves every host row behind
         * permanently, and this timer treats that hash as the authority for "which
         * hosts exist" — it would keep dispatching vps_queue_task at decommissioned
         * or offline hosts every 30s, forever.
         *
         * Gateway::isUidOnline() is the right authority instead: it answers through
         * the register service, so it is accurate across all instances (the same
         * idiom handleCmdExec/handlePtyOpen/telemetry already use). Offline rows are
         * pruned as we go, which also stops the hash growing without bound.
         */
        $hostIds = [];
        foreach (array_keys($hostRegistry) as $registryId) {
            if (Gateway::isUidOnline('vps'.$registryId) == true) {
                $hostIds[] = $registryId;
                continue;
            }
            SharedState::hDel(self::HOSTS_REGISTRY_KEY, (string) $registryId);
            SharedState::del(self::ADMIN_HOSTS_CACHE_KEY);
            unset($hostRegistry[$registryId]);
            Worker::safeEcho('vps_queue_timer: pruned offline host '.$registryId." from the hosts registry\n");
        }
        try {
            /*
             * The vpsqueuedone anti-join is what Tasks/vps_queue_task.php has always
             * used to decide which queue entries are still outstanding; this timer was
             * missing it, so it matched every vpsqueue row ever written and re-dispatched
             * vps_queue_task for those hosts every 30 seconds forever. Each dispatch
             * makes GetNewVps retry the host's pending-setup services, which is what
             * kept vps_get_next_ip() - and the invoices description sweep behind it -
             * firing on a loop. Raw SQL rather than the builder because the anti-join
             * needs a second aliased reference to queue_log; query() still hands back
             * the same array of assoc rows the builder did.
             *
             * The vps_id predicate is the loop's own "no vps id in db matching" skip,
             * moved into SQL so those rows are not fetched every 30 seconds. It is
             * deliberately not a bare "vps_id is not null": that is only the skip
             * condition for a numeric history_type. A non-numeric one ("vpsNNN") never
             * joins to a vps row, so it always has a null vps_id, and the loop's else
             * branch reads the host id out of the string instead. Nothing writes those
             * into vpsqueue any more - they only exist under the legacy vpsqueueold
             * section - but matching the loop exactly costs nothing and keeps the
             * branch reachable if that ever changes.
             */
            $results = self::$db->query(
                'select queue_log.*, vps.* from queue_log'
                .' left join vps on vps_id=history_type'
                .' left join queue_log done on done.history_type=queue_log.history_id'
                ." and done.history_section='vpsqueuedone'"
                ." where queue_log.history_section='vpsqueue' and done.history_id is null"
                ." and (vps_id is not null or queue_log.history_type not regexp '^[0-9]+\$')"
            );
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
                    if (in_array($id, $hostIds)) {
                        if (!in_array($id, array_keys($queues))) {
                            $queues[$id] = [];
                        }
                        $queues[$id][] = $row;
                    }
                } else {
                    $id = str_replace('vps', '', $row['history_type']);
                    if (in_array($id, $hostIds)) {
                        if (!in_array($id, array_keys($queues))) {
                            $queues[$id] = [];
                        }
                        $queues[$id][] = $row;
                    }
                }
            }
            if (sizeof($queues) > 0) {
                foreach ($queues as $server_id => $rows) {
                    $server_data = $hostRegistry[$server_id] ?? SharedState::hGet(self::HOSTS_REGISTRY_KEY, (string) $server_id);
                    if (!is_array($server_data)) {
                        $server_data = ['vps_name' => 'server'.$server_id];
                    }
                    //if ($server_id != 467) {
                    //Worker::safeEcho('Wanted To Process Queues For Server '.$server_id.' '.$server_data['vps_name'].PHP_EOL);
                    //continue;
                    //} else {
                    Worker::safeEcho('Processing Queues For Server '.$server_id.' '.$server_data['vps_name'].PHP_EOL);
                    //}
                    /*
                     * GlobalData→Redis migration (A1): per-host dispatch lock via
                     * SharedState. Cross-host parallelism is preserved (per-host
                     * lock keys); this only guards <=1 concurrent command per VPS
                     * host, which is the platform's concurrency contract.
                     *
                     * REVIEW-FIX (decision B): the token is now HANDED DOWN to
                     * vps_queue_task in args['lock_token'], the same way
                     * boardctl_queue_timer hands its hold to the detached runner.
                     *
                     * Previously this comment claimed "a Tasks-side acquire failing
                     * while Events holds the lock is correct behaviour" — but the
                     * Task's first act was to lock the SAME key, so it always got
                     * null, skipped its entire body and returned ''. The dispatch
                     * could never do any work; the timer was dead (identically so
                     * under GlobalData's cas($var, 0, 1), so this is a long-standing
                     * bug the migration faithfully preserved rather than caused).
                     *
                     * Handing the token down also lets the Task RENEW the hold
                     * during its long SOAP work. This side takes the lock and then
                     * waits on an untimed dispatchTask round trip with no renewal of
                     * its own, so without that the TTL could lapse mid-operation and
                     * the next 30s tick would dispatch a duplicate for the same host.
                     *
                     * Release stays here, on BOTH dispatchTask legs (result and
                     * error), so ownership has exactly one owner; the Task only
                     * releases a lock it acquired itself.
                     */
                    $lockName = 'vps_host_'.$server_id;
                    $token = SharedState::lock($lockName, SharedState::VPS_HOST_LOCK_TTL);
                    if ($token !== null) {
                        $releaseLock = function () use ($lockName, $token) {
                            SharedState::unlock($lockName, $token);
                        };
                        self::dispatchTask('vps_queue_task', ['id' => $server_id, 'lock_token' => $token], function ($task_result) use ($server_id, $releaseLock) {
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
            // Migration A2: the whole-map CAS loop becomes one per-key write
            // (legacy md5 key semantics preserved: re-issuing the same command
            // simply refreshes its entry, set() overwrites) plus an index add.
            SharedState::set(self::RUNNING_KEY_PREFIX . $run_id, $json, self::RUNNING_ENTRY_TTL);
            SharedState::sAdd(self::RUNNING_INDEX_KEY, $run_id);
            Gateway::sendToUid($uid, json_encode($json));
            Worker::safeEcho("Sending ".json_encode($json)." to {$uid}".PHP_EOL);
        } else {
            Worker::safeEcho("{$uid} is not online, cant send".PHP_EOL);
            // if they are not online then queue it up for later
        }
    }

    public static function say($from, $is, $to, $content, $from_name)
    {
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
            // Legacy room message log lives on the default room's hash field
            // (migration A2: the positional $rooms[0] of the GlobalData rooms
            // array is the seeded DEFAULT_ROOM_ID entry in dc:state:rooms; the
            // v1 path never reads messages from here — see chatCacheAppend).
            $room = SharedState::hGet(self::ROOMS_REGISTRY_KEY, self::DEFAULT_ROOM_ID);
            if (is_array($room)) {
                if (!isset($room['messages']) || !is_array($room['messages'])) {
                    $room['messages'] = [];
                }
                $room['messages'][] = [
                    'from_id' => $from,
                    'from_name' => $from_name,
                    'content' => nl2br(htmlspecialchars($content)),
                    'time' => date('Y-m-d H:i:s'),
                ];
                /*
                 * REVIEW-FIX: trim this log. Nothing ever read it back and nothing
                 * ever bounded it — the only thing that used to keep it in check was
                 * GlobalData dying on every restart. In Redis dc:state:rooms has no
                 * TTL, so the array grows forever, and because it lives in ONE hash
                 * field every message JSON-decodes the whole history, appends one
                 * entry and re-encodes it: O(n) CPU and O(n) network per message,
                 * unbounded. It is driven by machine traffic too — say() is called
                 * from msgRan for every legacy run completion, not just by humans.
                 *
                 * SharedState.php names this very array as the defect the v1
                 * chat cache was designed to replace; the legacy say() path was
                 * simply left writing to it. Bounded to the same window as the v1
                 * hot cache.
                 */
                if (count($room['messages']) > self::CHAT_HISTORY_MAX) {
                    $room['messages'] = array_slice($room['messages'], -self::CHAT_HISTORY_MAX);
                }
                SharedState::hSet(self::ROOMS_REGISTRY_KEY, self::DEFAULT_ROOM_ID, $room);
            }
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
            $rooms = SharedState::hGetAll(self::ROOMS_REGISTRY_KEY);
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
        if ($_SESSION['login'] === true && $_SESSION['ima'] == 'admin') {
            $message_data = [
                'type' => 'timers',
                //'channel' => ChannelClient::getStatus(),
            ];
            Gateway::sendToCurrentClient(json_encode($message_data));
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
        Worker::safeEcho("[{$client_id}] Got Running Command ".json_encode($message_data).PHP_EOL);
        if ($_SESSION['login'] === true) {
            $id = $message_data['id'];
            $run = SharedState::get(self::RUNNING_KEY_PREFIX . $id);
            if (!is_array($run)) {
                return;
            }
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
        // processing_queue_timer() throws RuntimeException when the Redis
        // transport is dead (see its docblock). This is the legacy WS nudge
        // inside an onMessage dispatch — contain it HERE so an outage cannot
        // kill a BusinessWorker mid-message and, crucially, cannot skip the
        // adjacent boardctl_queue_timer() nudge. The timer's other consumers
        // (Workerman's tick safeCall, trigger_payment's catch) already
        // contain the escalation at their own boundaries.
        try {
            self::processing_queue_timer();
        } catch (\Throwable $e) {
            Worker::safeEcho('msgPaymentprocess: nudge failed: '.$e->getMessage()."\n");
        }
        self::boardctl_queue_timer();
    }

    /**
     * timer function to check for queued boardctl jobs (run-all / recover-bmc-creds).
     *
     * Concurrency model: one job in-flight per asset at a time, but multiple assets
     * may run concurrently (capped only by TaskWorker process count, currently 20).
     * Per-asset locking uses a SharedState (Redis) lock named from the asset id
     * (GlobalData→Redis migration, A1); the mystage queue helper already prevents
     * duplicate pending/processing rows per asset so the lock is mostly
     * belt-and-braces against rare race windows.
     *
     * history_type is encoded as "<action>:<assetId>" — we parse the asset id out
     * for the lock key so different actions on the same asset still serialize.
     * On a successful acquire the ownership token rides to the consumer as
     * args['lock_token'] so the detached runner can release exactly this hold.
     */
    public static function boardctl_queue_timer()
    {
        if (is_null(self::$db)) {
            self::$db = self::createDbConnection();
            if (is_null(self::$db)) {
                return;
            }
        }
        // NOTE: For performance, ensure queue_log has a compound index on (history_section, history_new_value).
        // Verified in staging with: SHOW INDEX FROM queue_log WHERE Key_name = 'idx_boardctl_pending';
        // If missing, run: ALTER TABLE queue_log ADD INDEX idx_boardctl_pending (history_section, history_new_value);
        // This index also benefits similar queries at processing_queue_timer, boardctl_startup_reap,
        // and processing_queue_reaper.
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
            // 22200s = 6hr task cap (boardctl_run_job BOARDCTL_MAX_RUNTIME_SECONDS) + 10min buffer.
            // Must stay >= the runner cap so a legitimately long-running job's lock
            // never lapses mid-run (which would let a duplicate job start); the TTL
            // also replaces the old manual stale force-reset branch entirely.
            $lockName = 'boardctl_asset_'.$assetId;
            $token = SharedState::lock($lockName, 22200);
            if ($token === null) {
                // another job for this asset is already in flight (or Redis unavailable)
                continue;
            }
            try {
                self::$db->update('queue_log')->cols(['history_new_value' => 'processing'])->where('history_id='.intval($row['history_id']))->query();
            } catch (\Throwable $e) {
                Worker::safeEcho("boardctl: failed to mark history_id={$row['history_id']} processing: {$e->getMessage()}\n");
                SharedState::unlock($lockName, $token);
                continue;
            }
            Worker::safeEcho("boardctl spawning task for history_id={$row['history_id']} asset={$assetId} type={$row['history_type']}\n");
            // boardctl_task now only *spawns* a detached runner and returns at
            // once (the runner owns the lock for the job's lifetime — it gets
            // this hold's token via args['lock_token'] and releases token-
            // checked on completion). So on a successful spawn we must NOT
            // release the lock here -- doing so would let a duplicate start. We
            // only release + mark failed when the spawn itself did not happen.
            $taskArgs = array_merge((array) $row, ['lock_token' => $token]);
            self::dispatchTask('boardctl_task', $taskArgs, function ($task_result) use ($row, $lockName, $token) {
                $outer = json_decode((string)$task_result, true);
                $return = is_array($outer) && array_key_exists('return', $outer) ? $outer['return'] : $task_result;
                $decoded = is_string($return) ? json_decode($return, true) : $return;
                if (is_array($decoded) && !empty($decoded['spawned'])) {
                    // Runner launched; it releases $lockName when the job ends.
                    return;
                }
                Worker::safeEcho("boardctl: runner did not spawn for history_id={$row['history_id']}, releasing lock\n");
                try {
                    self::$db->update('queue_log')->cols(['history_new_value' => 'failed'])->where('history_id='.intval($row['history_id']))->query();
                } catch (\Throwable $e) {
                    Worker::safeEcho("boardctl: failed to mark history_id={$row['history_id']} failed: {$e->getMessage()}\n");
                }
                SharedState::unlock($lockName, $token);
            }, function () use ($row, $lockName, $token) {
                try {
                    self::$db->update('queue_log')->cols(['history_new_value' => 'failed'])->where('history_id='.intval($row['history_id']))->query();
                } catch (\Throwable $e) {
                    Worker::safeEcho("boardctl: failed to mark history_id={$row['history_id']} failed: {$e->getMessage()}\n");
                }
                SharedState::unlock($lockName, $token);
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
        //Worker::safeEcho("[{$client_id}] Got Ran Command ".json_encode($message_data).PHP_EOL);
        // indicates both completion of a run process and its final exit code or terminal signal
        // response(s) from a run command
        $id = $message_data['id'];
        $run = SharedState::get(self::RUNNING_KEY_PREFIX . $id);
        if (!is_array($run)) {
            return;
        }
        if (!is_string($run['for'] ?? null)) {
            return;
        }
        $is = substr($run['for'], 0, 1) == '#' ? 'room' : 'client';
        // Migration A2: msgRan's read-modify-write of the whole running map is
        // one key delete + index removal (the v1 cmd.exit equivalent, per-key).
        SharedState::del(self::RUNNING_KEY_PREFIX . $id);
        SharedState::sRem(self::RUNNING_INDEX_KEY, $id);
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
                $uid = 'vps'.$row['vps_id'];
                $_SESSION['uid'] = $uid;
                $_SESSION['module'] = 'vps';
                $_SESSION['name'] = $row['vps_name'];
                $_SESSION['ima'] = $ima;
                $_SESSION['ip'] = $row['vps_ip'];
                $_SESSION['type'] = $row['vps_type'];
                $_SESSION['online'] = date('Y-m-d H:i:s');
                $_SESSION['login'] = true;
                SharedState::hSet(self::HOSTS_REGISTRY_KEY, (string) $row['vps_id'], $row);
                SharedState::del(self::ADMIN_HOSTS_CACHE_KEY);
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
                // Join the default room (migration A2: the hash field
                // DEFAULT_ROOM_ID, the equivalent of the old positional
                // $global->rooms[0]).
                $room = SharedState::hGet(self::ROOMS_REGISTRY_KEY, self::DEFAULT_ROOM_ID);
                if (!is_array($room)) {
                    $room = [
                        'id' => self::DEFAULT_ROOM_ID,
                        'name' => 'General Chat',
                        'img' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a6/Rubik%27s_cube.svg/220px-Rubik%27s_cube.svg.png',
                        'members' => [],
                        'messages' => [],
                    ];
                }
                if (!isset($room['members']) || !is_array($room['members'])) {
                    $room['members'] = [];
                }
                if (!in_array($uid, $room['members'])) {
                    $room['members'][] = $uid;
                }
                SharedState::hSet(self::ROOMS_REGISTRY_KEY, self::DEFAULT_ROOM_ID, $room);
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
