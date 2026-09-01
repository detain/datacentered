<?php

/**
 * SharedState — cross-process shared state and distributed locks on Redis.
 *
 * Facade over phpredis (\Redis) for the state the platform previously kept in
 * GlobalData. It replaces the retired \GlobalData\Client as the hub's shared
 * memory: shared registries, per-entity CAS-style locks (with real TTLs — the
 * GlobalData client had none, which is why stale-lock reapers exist), chat hot
 * caches, feature flags and presence/session state.
 *
 * KEY-NAMESPACE CONTRACT (enforced — every method guards it):
 *   dc:lock:      distributed locks (STRING token values, TTL mandatory; the
 *                 vps_host_<id>:request observability siblings live here too
 *                 as ordinary JSON set() values with the family's TTL 900)
 *   dc:state:     shared registries (Redis HASHES: field = entry id,
 *                 value = JSON) — the GlobalData `hosts`/`rooms`/`timers`
 *                 style arrays map here one hash per registry
 *   dc:chat:      chat hot cache (LIST / ZSET / SET — last-N message tails,
 *                 channel member sets)
 *   dc:flag:      feature flags (GlobalData ws_new_handling / ws_legacy_compat)
 *   dc:presence:  presence / session / per-client state (STRING JSON values,
 *                 TTL-capable)
 *
 * Values are ALWAYS JSON-encoded/decoded by this facade: callers pass and
 * receive plain PHP arrays/scalars, never serialize(). The single exception is
 * the lock token itself, stored raw because the Lua compare-and-delete scripts
 * string-compare it against ARGV.
 *
 * FAIL-SAFE: BOTH Redis failure modes degrade to the same documented
 * fail-safe returns —
 *   (1) no resolvable client: no $redis global, USE_REDIS off, or the connect
 *       failed, and
 *   (2) a live-but-dead handle: any client command that THROWS (a Redis
 *       restart leaves the shared \Redis object alive while its socket is
 *       gone, and phpredis raises instead of quietly failing). Every command
 *       dispatch is wrapped, so a transport exception NEVER escapes into
 *       timers/Tasks.
 * On either mode reads return null/[]/false, writes return false, locks
 * return null, and each mode logs its reason ONCE per process via
 * Worker::safeEcho (independent memos: a no-client line never mutes the
 * first transport-throw reason, and inside a dead window the transport line
 * already spoke, so no misleading "no client" line follows it).
 * Callers therefore need no try/catch around Redis availability; a bad lock
 * result simply means "someone else holds it (or Redis is down)" — the same
 * contract the GlobalData cas() callers already branch on. Because the facade
 * swallows what used to propagate, callers that must tell "lock held
 * elsewhere" from "transport dead" (FeatureFlags, Events::processing_queue_timer
 * → trigger_payment) can ask transportFailed().
 *
 * RECOVERY: neither failure mode is sticky. A transport throw or a connect
 * failure marks the facade transport-dead for REPROBE_INTERVAL (30s); during
 * the window client() answers null without touching the handle that threw.
 * The first call after the window clears the mark and re-probes. The shared
 * $redis handle is PINGed first: phpredis re-handshakes internally on builds
 * that support it, so a PING that answers resumes the global immediately and
 * clears the failure streak. Because a build whose non-persistent handle can
 * NEVER re-handshake must not strand the process, consecutive failed re-probe
 * PINGs are counted: after SHARED_HANDLE_GRACE_PROBES (2) the facade stops
 * preferring the shared handle for the rest of the process (it is still
 * never closed, replaced, or re-PINGed — just skipped) and self-heals
 * through its OWN fresh lazy connect instead. The facade's own fallback
 * handle gets the same treatment: PING-verified on re-probe, and a dead one
 * dropped and replaced by one fresh connect attempt within the same window.
 * Recovery is therefore bounded on every path — the shared handle within
 * ~REPROBE_INTERVAL when it self-heals, else a fresh internal handle within
 * ~2 windows of Redis returning — with no process restart. A failing probe
 * re-marks for another window; only a successful shared PING or a
 * setClient()/reset() re-injection re-arms the shared-handle streak.
 *
 * Guards stay LOUD: a key outside dc:* or a bad lock argument throws
 * InvalidArgumentException BEFORE any client resolution, even while the
 * transport is dead — namespace misuse is a programmer error, not an outage.
 *
 * CONNECTION: reuses the process-wide $redis global when present (the wider
 * MyAdmin app and every start_*.php worker share it — never replaced, never
 * closed) while it is in re-probe grace; once deprioritized it is also never
 * re-PINGed, and the facade serves from its OWN lazily-connected handle
 * (same USE_REDIS/REDIS_HOST/REDIS_PORT idiom as start_task.php /
 * start_web.php: 2s timeout, no auth). SharedState::setClient()/reset() exist
 * for test injection, SharedState::setTestClock() pins the re-probe clock,
 * and SharedState::setConnectFactory() stands in for the lazy fallback
 * connect — all test seams only.
 *
 * Phase 2 of the GlobalData→Redis migration: lock call sites (A1) and the
 * remaining Events.php state — registries, presence, chat cache, bot
 * ownership — migrated onto this facade (A2); Tasks/Web/FeatureFlags follow
 * in later waves.
 *
 * @see Applications/Chat/FeatureFlags.php   same loading/style conventions
 * @see Applications/Chat/start_task.php     the $redis init idiom
 * @see tests/SharedStateTest.php            behavior pinned offline (InMemoryRedis)
 */
final class SharedState
{
    /** Distributed locks (SET NX EX + Lua compare-and-delete). */
    public const PREFIX_LOCK = 'dc:lock:';

    /** Shared registries as Redis HASHES (field = entry id, value = JSON). */
    public const PREFIX_STATE = 'dc:state:';

    /** Chat hot cache (LIST/ZSET/SET). */
    public const PREFIX_CHAT = 'dc:chat:';

    /** Feature flags. */
    public const PREFIX_FLAG = 'dc:flag:';

    /** Presence/session/per-client state (STRING JSON, TTL-capable). */
    public const PREFIX_PRESENCE = 'dc:presence:';

    /**
     * Seconds a transport-death mark stays in force before client() re-probes.
     * Bounds both the throw storm a dead handle would otherwise cause (one
     * failed command per window, not one per call) and the outage itself:
     * recovery is automatic within a few windows of Redis returning — ~1
     * window when the shared handle re-handshakes, ~2 windows before the
     * deprioritized global yields to the facade's own fresh connection.
     */
    public const REPROBE_INTERVAL = 30;

    /**
     * TTL (seconds) for the `vps_host_<id>` lock family — the per-host mutex
     * that serializes commands against one VPS host. Events and the Tasks all
     * contend on the same key, so the value lives here, in the one file both
     * sides require_once.
     *
     * REVIEW-FIX: was a hardcoded 900 at every call site. 900s satisfied the
     * documented ops rule ("match or exceed the old GlobalData reap window")
     * but NOT the physically necessary one: the TTL must exceed the longest
     * single blocking call it guards. HyperV `GetVMList` runs under
     * default_socket_timeout = 1200s (Tasks/async_hyperv_get_list.php,
     * Tasks/hyperv_cleanupresources.php, Applications/Chat/start_task.php), and
     * renew() can only fire BETWEEN blocking calls — never during one — so a
     * slow host lost its lock ~300s before its SOAP call even gave up, and the
     * 30s hyperv_queue_timer then issued a second command to a host still
     * mid-GetVMList. 1300 > 1200 keeps the lock alive across the worst-case
     * single call with 100s of headroom.
     *
     * INVARIANT: keep this strictly greater than default_socket_timeout. If
     * that ini value is ever raised, raise this with it.
     */
    public const VPS_HOST_LOCK_TTL = 1300;

    /**
     * Consecutive failed re-probe PINGs tolerated from the shared $redis
     * handle before the facade stops preferring it for the rest of the
     * process (never closed, replaced, or re-PINGed — just skipped) and
     * self-heals through its own fresh lazy connect. Two windows keep the
     * common case (phpredis re-handshakes: the F1b experiment on phpredis
     * 5.3.7 saw the shared handle answer PING again after a server restart)
     * on the shared connection, while bounding the outage on builds where a
     * non-persistent handle can never recover.
     */
    public const SHARED_HANDLE_GRACE_PROBES = 2;

    /** @var bool has the missing-ext-redis deployment error been announced in this process? */
    private static $loggedMissingExtension = false;

    /** Every key written through this facade must start with one of these. */
    private static $prefixes = [
        self::PREFIX_LOCK,
        self::PREFIX_STATE,
        self::PREFIX_CHAT,
        self::PREFIX_FLAG,
        self::PREFIX_PRESENCE,
    ];

    /** Release a lock only while the caller still owns it (token compare). */
    private const UNLOCK_SCRIPT = "if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0";

    /** Extend a lock's TTL only while the caller still owns it. */
    private const RENEW_SCRIPT = "if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('PEXPIRE',KEYS[1],ARGV[2]) end return 0";

    /** Seconds-to-live guard for lock()/renew(): a lock without a TTL is the GlobalData SPOF we are removing. */
    private const MIN_LOCK_TTL = 1;

    /** Connect timeout in seconds, matching the existing $redis init idiom. */
    private const CONNECT_TIMEOUT = 2;

    /**
     * Lazily-created fallback Redis client (used only when no $redis global
     * exists in this process). May hold a test double via setClient().
     *
     * @var \Redis|null
     */
    private static $client = null;

    /**
     * Transport-dead cooldown deadline (unix seconds), 0 while healthy. Set by
     * a command throw OR a connect failure; client() short-circuits to null
     * until it elapses, then clears it and re-probes. Replaces the old sticky
     * $connectFailed memo, which could never recover without a restart.
     *
     * @var int
     */
    private static $deadUntil = 0;

    /** @var bool first no-client failure logged; keeps timer-driven fail-safe paths quiet */
    private static $loggedUnavailable = false;

    /**
     * First transport-death reason logged — a SEPARATE memo from
     * $loggedUnavailable so a null-client line logged earlier in the process
     * can never mute the first genuine transport-throw reason (different
     * diagnoses; m1). reset()/setClient() re-arm.
     *
     * @var bool
     */
    private static $loggedTransport = false;

    /**
     * Consecutive failed re-probe PINGs against $GLOBALS['redis']. At
     * SHARED_HANDLE_GRACE_PROBES the shared handle is deprioritized for the
     * rest of the process (see client()); a successful PING, setClient(), or
     * reset() re-arms it. Never gates anything when no global exists.
     *
     * @var int
     */
    private static $sharedPingFailures = 0;

    /** @var int|null test-only clock override; see setTestClock() */
    private static $testNow = null;

    /**
     * TEST SEAM ONLY — factory standing in for the lazy fallback connect in
     * openFallbackConnection(); production leaves it null. See
     * setConnectFactory(). Cleared by reset() like every other memo.
     *
     * @var callable|null
     */
    private static $connectFactory = null;

    // -----------------------------------------------------------------------
    // Client lifecycle
    // -----------------------------------------------------------------------
    /**
     * Resolve the Redis client: the process-wide $redis global while it is
     * a live \Redis AND still in grace (never replaced, never closed —
     * MyAdmin shares it), else the facade's own lazily-connected fallback,
     * else a test-injected double.
     *
     * While the transport-dead window is open (a command threw or a connect
     * failed within the last REPROBE_INTERVAL seconds) this answers null
     * WITHOUT touching the handle, so a broken connection is probed at most
     * once per window instead of once per call. The first call after the
     * window clears the mark and re-probes:
     *
     *   - Shared \Redis in grace: PING it. A PING that answers resumes the
     *     global as-is and clears the failure streak. A failing PING counts;
     *     while any grace remains (SHARED_HANDLE_GRACE_PROBES) the next
     *     window re-probes the global — no fresh socket is opened while the
     *     shared handle may still be in recovery.
     *   - Streak exhausted: the global is deprioritized for the rest of this
     *     process — never closed, replaced, or re-PINGed — and recovery runs
     *     on the facade's own handle: PING-verify the existing fallback,
     *     dropping (not closing) a dead one, then one PING-guarded lazy
     *     connect per window. So the self-heal never depends on the shared
     *     handle itself recovering.
     *   - No shared handle at all: the injected/lazy connect path resolves.
     *
     * @return \Redis|null null when no client is resolvable or the transport is dead
     */
    public static function client()
    {
        global $redis;
        if (self::$deadUntil !== 0) {
            if (self::now() < self::$deadUntil) {
                // Dead window: fail-safe without re-hitting the throwing handle.
                return null;
            }
            // Window elapsed — clear and re-probe once.
            self::$deadUntil = 0;
            if ($redis instanceof \Redis) {
                if (self::sharedHandleInGrace()) {
                    $reason = 'shared $redis handle answered PING with false';
                    try {
                        if (self::pingHealthy($redis)) {
                            // The shared handle re-handshook: resume it as-is
                            // and clear the streak — preference is restored.
                            self::$sharedPingFailures = 0;
                            self::rearmOutageLogs();

                            return $redis;
                        }
                    } catch (\Throwable $e) {
                        $reason = $e->getMessage();
                    }
                    self::$sharedPingFailures++;
                    self::markTransportDead('reprobe', $reason);

                    return null;
                }
                // Grace exhausted: recover on the facade's OWN handle. The
                // global is skipped, never closed or reassigned.
                self::discardDeadFallbackClient();
                if (self::$client !== null) {
                    return self::$client;
                }
                if (self::fallbackConnectPossible()) {
                    return self::openFallbackConnection();
                }

                // No shared handle trusted, nothing to reconnect through:
                // the deprioritized global IS a dead transport — keep the
                // window (and transportFailed()) honest for the few callers
                // that distinguish death from a never-configured STATE.
                self::markTransportDead('reprobe', 'shared $redis handle deprioritized; no fallback connection available');

                return null;
            }
            // No shared handle to probe: fall back to injected/lazy resolution.
        }
        if ($redis instanceof \Redis && self::sharedHandleInGrace()) {
            return $redis;
        }
        if (self::$client !== null) {
            return self::$client;
        }
        if (!self::fallbackConnectPossible()) {
            // Not configured is a STATE, not a failed attempt — do not mark
            // the dead window, or defining USE_REDIS/REDIS_HOST/REDIS_PORT
            // later in the process would be permanently ignored (F8). Only a
            // real connect failure below starts the cooldown.
            return null;
        }

        return self::openFallbackConnection();
    }

    /**
     * Does the shared $redis global still earn preference over the facade's
     * own connection? False once SHARED_HANDLE_GRACE_PROBES consecutive
     * re-probe PINGs failed — deprioritized for the process (a successful
     * PING, setClient(), or reset() re-arms it).
     *
     * @return bool
     */
    private static function sharedHandleInGrace(): bool
    {
        return self::$sharedPingFailures < self::SHARED_HANDLE_GRACE_PROBES;
    }

    /**
     * @return bool whether a fallback connect can even be attempted (real
     *              config present, or the test factory installed)
     */
    private static function fallbackConnectPossible(): bool
    {
        return self::$connectFactory !== null || self::redisConfigured();
    }

    /**
     * Re-probe the facade's own handle with its own PING. A live-but-dead
     * fallback is dropped — never closed, mirroring reset() — so the caller
     * opens a fresh connection in the same window instead of handing back a
     * handle that would just throw. Only self::$client is touched; the
     * shared global is never closed, replaced, or reassigned.
     *
     * @return void
     */
    private static function discardDeadFallbackClient(): void
    {
        if (self::$client === null) {
            return;
        }
        try {
            if (self::pingHealthy(self::$client)) {
                return;
            }
        } catch (\Throwable $e) {
            // Dead like the throw says — drop it below.
        }
        self::$client = null;
    }

    /**
     * Does this client answer PING like a healthy Redis connection?
     *
     * REVIEW-FIX: the probes used to accept `ping() !== false`, which treats
     * ANY truthy reply as healthy — including the client object itself. A
     * handle stranded in pipeline/multi mode returns $this from every command,
     * so `!== false` re-blessed a handle on which no command actually executes,
     * and the documented 30s self-heal could never fire. Require a real PONG:
     * phpredis returns bool true on current builds and the '+PONG' / 'PONG'
     * string on older ones; nothing else qualifies.
     *
     * @param object $client
     * @return bool
     */
    private static function pingHealthy($client): bool
    {
        $reply = $client->ping();

        return $reply === true
            || (is_string($reply) && ($reply === '+PONG' || $reply === 'PONG'));
    }

    /**
     * Open and adopt the facade's OWN Redis handle (the shared global is not
     * read, closed, or reassigned here). Production lazy-connects with the
     * existing start_task.php / start_web.php idiom (2s timeout, no auth —
     * trusted LAN); the test-only factory (setConnectFactory) may stand in.
     * The candidate is adopted only after its own PING answers, so fallback
     * use is PING-guarded; a refused, throwing, or PING-silent candidate
     * marks the dead window — one connect attempt per window, not per call.
     *
     * @return \Redis|null the fresh client, or null after marking dead
     */
    private static function openFallbackConnection()
    {
        $fromFactory = self::$connectFactory !== null;
        try {
            if ($fromFactory) {
                $candidate = (self::$connectFactory)();
                if ($candidate === null) {
                    self::markTransportDead('connect', 'connect factory returned no client');

                    return null;
                }
            } else {
                $candidate = new \Redis();
                if (!$candidate->connect(REDIS_HOST, REDIS_PORT, self::CONNECT_TIMEOUT)) {
                    self::markTransportDead('connect', REDIS_HOST.':'.REDIS_PORT.' refused');

                    return null;
                }
            }
            // No auth() — matches the existing init idiom (trusted LAN).
            if (!self::pingHealthy($candidate)) {
                self::markTransportDead('connect', ($fromFactory ? 'factory' : REDIS_HOST.':'.REDIS_PORT).' client answered PING with false');

                return null;
            }
            self::$client = $candidate;
            // A fresh PING-verified handle IS a recovery — re-arm the outage
            // memos here too, not only on the shared-handle path.
            self::rearmOutageLogs();

            return self::$client;
        } catch (\Throwable $e) {
            self::markTransportDead('connect', $e->getMessage());

            return null;
        }
    }

    /**
     * Is the facade currently distrustful of the Redis transport? True from a
     * command throw (or connect failure) until the next command's re-probe
     * succeeds — i.e. for as long as client() can answer null for reasons
     * OTHER than "never had a client". While this reads true, NO facade
     * result is authoritative: a null lock() is "transport dead", not "held
     * elsewhere"; a null get() is "unreadable", not "unset".
     *
     * Most call sites never need it — the fail-safe return already routes
     * them down the same branch a lost CAS used to. It exists for the few
     * callers whose contract distinguishes the two: FeatureFlags (unset vs
     * unreachable have OPPOSITE Flag A defaults) and
     * Events::processing_queue_timer (a trigger_payment nudge must answer
     * "unavailable", not silently no-op, when the lock attempt hit a dead
     * transport).
     *
     * @return bool
     */
    public static function transportFailed(): bool
    {
        /*
         * REVIEW-FIX: this returned `self::$deadUntil !== 0`, which is only
         * cleared inside client(). A caller that reads this WITHOUT first
         * performing a facade operation therefore got true forever — long after
         * the window elapsed and Redis came back — contradicting the class
         * contract that death is timed, not sticky. Every current caller happens
         * to run an operation first (FeatureFlags::readFlag, processing_queue_timer,
         * boardctl_runner), so it was latent; make the accessor honest so the next
         * caller does not inherit a trap.
         */
        return self::$deadUntil !== 0 && self::now() < self::$deadUntil;
    }

    /**
     * TEST SEAM ONLY — production must never call this. Pin the wall clock the
     * transport-dead window is measured against (null restores time()).
     * Recovery bookkeeping is pure timestamp arithmetic, so tests can drive a
     * full dead → elapsed → re-probe timeline without sleeping. Cleared by
     * reset() like every other memo.
     *
     * @param int|null $now unix seconds, or null for the real clock
     * @return void
     */
    public static function setTestClock(?int $now): void
    {
        self::$testNow = $now;
    }

    /**
     * TEST SEAM ONLY — production must never call this. Install a factory
     * standing in for the lazy fallback connect (openFallbackConnection):
     * invoked ONLY when resolution reaches the facade's own connect path and
     * returning the client to adopt — null simulates a refused connect, a
     * throw simulates a transport error, and a duck-typed double (it must
     * answer ping(), the facade PING-guards adoption) lets recovery tests
     * drive the deprioritized-global → fresh-internal-handle timeline
     * without defining USE_REDIS constants or opening sockets. Pass null to
     * restore the real connect. Cleared by reset() like every other memo.
     *
     * @param callable|null $factory fn(): \Redis|object|null
     * @return void
     */
    public static function setConnectFactory(?callable $factory): void
    {
        self::$connectFactory = $factory;
    }

    /**
     * Inject a client (or null to un-inject). Test seam only; production code
     * must rely on client() resolving $GLOBALS['redis'] / USE_REDIS. A fresh
     * injection also clears the transport-dead mark, the once-log memos, and
     * the shared-handle distrust: the caller replaced the handle, so the old
     * distrust says nothing about the new one.
     *
     * @param \Redis|null $client a phpredis \Redis or a duck-typed test double
     * @return void
     */
    public static function setClient($client): void
    {
        self::$client = $client;
        self::$deadUntil = 0;
        self::$loggedUnavailable = false;
        self::$loggedTransport = false;
        self::$sharedPingFailures = 0;
    }

    /**
     * Forget the resolved client and every memo — the dead-window timestamp,
     * both once-log flags, the shared-handle failure streak, and the test
     * clock/factory. Test seam only — drops the facade's reference WITHOUT
     * closing a shared connection.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$client = null;
        self::$deadUntil = 0;
        self::$loggedUnavailable = false;
        self::$loggedTransport = false;
        self::$loggedMissingExtension = false;
        self::$sharedPingFailures = 0;
        self::$testNow = null;
        self::$connectFactory = null;
    }

    // -----------------------------------------------------------------------
    // Plain string values (JSON-encoded)
    // -----------------------------------------------------------------------

    /**
     * Read and JSON-decode a value.
     *
     * @param string $key full key, must carry a dc:* prefix
     * @return mixed null when absent, unreadable, or Redis is unavailable
     */
    public static function get(string $key)
    {
        self::guardKey($key, true);

        return self::attempt('get', static function ($client) use ($key) {
            $raw = $client->get($key);
            if ($raw === false) {
                return null;
            }

            return json_decode($raw, true);
        }, null);
    }

    /**
     * JSON-encode and store a value, optionally expiring after $ttl seconds.
     *
     * @param string $key
     * @param mixed  $value any JSON-serializable PHP value
     * @param int    $ttl   seconds; 0 = persist
     * @return bool
     */
    public static function set(string $key, $value, int $ttl = 0): bool
    {
        self::guardKey($key);
        $json = self::encode($value, 'set');
        if ($json === false) {
            return false;
        }

        return self::attempt('set', static function ($client) use ($key, $json, $ttl) {
            if ($ttl > 0) {
                return (bool) $client->set($key, $json, ['ex' => $ttl]);
            }

            return (bool) $client->set($key, $json);
        }, false);
    }

    /**
     * Seed a value only when the key does not exist yet (SET NX) — the Redis
     * replacement for GlobalData add(). Redis exists-semantics have no
     * NULL-vs-empty trap: a stored "" or [] blocks the seed exactly like any
     * other value.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl seconds; 0 = persist
     * @return bool true only when this call seeded the key
     */
    public static function add(string $key, $value, int $ttl = 0): bool
    {
        self::guardKey($key);
        $json = self::encode($value, 'add');
        if ($json === false) {
            return false;
        }
        $opts = ['nx'];
        if ($ttl > 0) {
            $opts['ex'] = $ttl;
        }

        return self::attempt('add', static function ($client) use ($key, $json, $opts) {
            return $client->set($key, $json, $opts) === true;
        }, false);
    }

    /**
     * Delete keys. Guarded BEFORE any removal so one bad key aborts the whole
     * call rather than half-applying.
     *
     * @param string ...$keys
     * @return void
     */
    public static function del(string ...$keys): void
    {
        foreach ($keys as $key) {
            // del() is the ONE primitive allowed into dc:lock:, because
            // stale/admin lock cleanup is a documented path (cold-start pre-clean,
            // boardctl_startup_reap). Every other primitive is refused — see
            // guardKey().
            self::guardKey($key, true);
        }
        if ($keys === []) {
            return;
        }
        self::attempt('del', static function ($client) use ($keys) {
            $client->del(...$keys);

            return null;
        }, null);
    }

    /**
     * @param string $key
     * @return bool false when absent OR Redis is unavailable
     */
    /**
     * Refresh a key's TTL without rewriting its value (Redis EXPIRE).
     *
     * @param string $key
     * @param int    $ttlSeconds must be positive; a non-positive TTL is ignored
     *                           rather than deleting the key
     * @return bool true when the TTL was applied
     */
    public static function expire(string $key, int $ttlSeconds): bool
    {
        self::guardKey($key);
        if ($ttlSeconds < 1) {
            return false;
        }

        return (bool) self::attempt('expire', static function ($client) use ($key, $ttlSeconds) {
            return $client->expire($key, $ttlSeconds);
        }, false);
    }

    public static function exists(string $key): bool
    {
        self::guardKey($key, true);

        return self::attempt('exists', static fn ($client) => (bool) $client->exists($key), false);
    }

    // -----------------------------------------------------------------------
    // Distributed locks — SET NX EX + token-checked release/renew
    // -----------------------------------------------------------------------

    /**
     * Acquire a lock. The token ties every release/renew to its owner so a
     * slow holder can never delete a lock a re-acquirer took after expiry.
     *
     * @param string $name       lock name (dc:lock: prefix applied here)
     * @param int    $ttlSeconds mandatory positive TTL — no unbounded locks
     * @return string|null the ownership token, or null when held/unavailable
     */
    /**
     * Key for the human-readable "what is this lock doing" sibling of a lock.
     *
     * REVIEW-FIX: these lived at `dc:lock:<name>:request`, inside the one
     * namespace whose type and TTL invariants the lock protocol depends on. They
     * are plain observability strings written with set(), not tokens, so any
     * lock-inventory or admin sweep over `dc:lock:*` counted them as held locks —
     * and it was one dropped `:request` suffix away from a set() clobbering a live
     * token. They belong in `dc:state:`, and guardKey() now enforces that by
     * refusing `dc:lock:` for every primitive except del().
     *
     * @param string $lockName the lock this describes (unprefixed)
     * @return string
     */
    public static function requestKey(string $lockName): string
    {
        return self::PREFIX_STATE.$lockName.':request';
    }

    public static function lock(string $name, int $ttlSeconds)
    {
        self::guardLockArgs($name, $ttlSeconds);
        $key = self::PREFIX_LOCK.$name;
        $token = gethostname().':'.getmypid().':'.bin2hex(random_bytes(8));

        return self::attempt('lock', static function ($client) use ($key, $token, $ttlSeconds) {
            // Raw token, not JSON: the Lua scripts compare GET against ARGV.
            $seeded = $client->set($key, $token, ['nx', 'ex' => $ttlSeconds]);

            return $seeded === true ? $token : null;
        }, null);
    }

    /**
     * Release a lock.
     *
     * With a $token the delete is owner-checked via Lua (a wrong or stale
     * token releases nothing). With null it is an UNCONDITIONAL delete — an
     * admin/stale-cleanup override only, never on a normal completion path.
     *
     * @param string      $name
     * @param string|null $token the value lock() returned
     * @return bool true when the lock was actually removed
     */
    public static function unlock(string $name, ?string $token = null): bool
    {
        if ($name === '') {
            throw new \InvalidArgumentException('SharedState::unlock requires a lock name');
        }
        $key = self::PREFIX_LOCK.$name;
        if ($token === null) {
            return self::attempt('unlock', static fn ($client) => (bool) $client->del($key), false);
        }

        return self::attempt('unlock', static fn ($client) => (bool) $client->eval(self::UNLOCK_SCRIPT, [$key, $token], 1), false);
    }

    /**
     * Extend a lock's TTL, but only while still owned by $token.
     *
     * @param string $name
     * @param string $token
     * @param int    $ttlSeconds
     * @return bool false when not owner, absent, or unavailable
     */
    public static function renew(string $name, string $token, int $ttlSeconds): bool
    {
        self::guardLockArgs($name, $ttlSeconds);
        $key = self::PREFIX_LOCK.$name;

        return self::attempt('renew', static fn ($client) => (bool) $client->eval(
            self::RENEW_SCRIPT,
            [$key, $token, (string) ($ttlSeconds * 1000)],
            1
        ), false);
    }

    // -----------------------------------------------------------------------
    // Hash wrappers (registries) — JSON values
    // -----------------------------------------------------------------------

    /**
     * @param string $key   registry hash key (dc:* prefix required)
     * @param string $field entry id
     * @param mixed  $value
     * @return void
     */
    public static function hSet(string $key, string $field, $value): void
    {
        self::guardKey($key);
        $json = self::encode($value, 'hSet');
        if ($json === false) {
            return;
        }
        self::attempt('hSet', static function ($client) use ($key, $field, $json) {
            $client->hSet($key, $field, $json);

            return null;
        }, null);
    }

    /**
     * @param string $key
     * @param string $field
     * @param mixed  $value
     * @return bool true only when this call created the field
     */
    public static function hSetNx(string $key, string $field, $value): bool
    {
        self::guardKey($key);
        $json = self::encode($value, 'hSetNx');
        if ($json === false) {
            return false;
        }

        return self::attempt('hSetNx', static fn ($client) => (bool) $client->hSetNx($key, $field, $json), false);
    }

    /**
     * @param string $key
     * @param string $field
     * @return mixed null when field absent or Redis unavailable
     */
    public static function hGet(string $key, string $field)
    {
        self::guardKey($key, true);

        return self::attempt('hGet', static function ($client) use ($key, $field) {
            $raw = $client->hGet($key, $field);
            if ($raw === false) {
                return null;
            }

            return json_decode($raw, true);
        }, null);
    }

    /**
     * @param string $key
     * @return array<string,mixed> field => decoded value ([] when absent/unavailable)
     */
    public static function hGetAll(string $key): array
    {
        self::guardKey($key, true);

        return self::attempt('hGetAll', static function ($client) use ($key) {
            $raw = $client->hGetAll($key);
            if (!is_array($raw)) {
                return [];
            }
            $out = [];
            foreach ($raw as $field => $value) {
                $out[$field] = json_decode($value, true);
            }

            return $out;
        }, []);
    }

    /**
     * @param string $key
     * @param string ...$fields
     * @return void
     */
    public static function hDel(string $key, string ...$fields): void
    {
        self::guardKey($key);
        if ($fields === []) {
            return;
        }
        self::attempt('hDel', static function ($client) use ($key, $fields) {
            $client->hDel($key, ...$fields);

            return null;
        }, null);
    }

    /**
     * Atomic registry counter. Values stored through hIncr must only be
     * incremented here (a JSON object in the same field breaks HINCRBY, which
     * is also true against real Redis — this mirrors, not masks, that).
     *
     * @param string $key
     * @param string $field
     * @param int    $by
     * @return int the new value (0 when Redis is unavailable — indistinguishable
     *             by design from "counter at zero after failure"; lock() first
     *             if the return value is decision-critical)
     */
    public static function hIncr(string $key, string $field, int $by = 1): int
    {
        self::guardKey($key);

        return self::attempt('hIncr', static fn ($client) => (int) $client->hIncrBy($key, $field, $by), 0);
    }

    // -----------------------------------------------------------------------
    // List / Set / ZSet wrappers (for later migration phases) — JSON values
    // -----------------------------------------------------------------------

    /**
     * Append and bound a list in one pipeline: RPUSH + LTRIM -max..-1, so the
     * hot cache (chat tails) can never grow unbounded — the defect the
     * untrimmed GlobalData rooms[0]['messages'] array shipped with (OQ5).
     * The bound keeps the NEWEST max entries; a naive `LTRIM 0 max-1` would
     * keep the OLDEST and turn every last-N tail into a first-N museum.
     *
     * @param string $listKey
     * @param mixed  $value
     * @param int    $max   retained element count (newest kept)
     * @return void
     */
    public static function rPushLtrim(string $listKey, $value, int $max, int $ttlSeconds = 0): void
    {
        self::guardKey($listKey);
        if ($max < 1) {
            throw new \InvalidArgumentException("SharedState::rPushLtrim requires max >= 1, got {$max}");
        }
        $json = self::encode($value, 'rPushLtrim');
        if ($json === false) {
            return;
        }
        self::attempt('rPushLtrim', static function ($client) use ($listKey, $json, $max, $ttlSeconds) {
            $pipelineMode = defined('\Redis::PIPELINE') ? \Redis::PIPELINE : 2;
            $client->multi($pipelineMode);
            /*
             * REVIEW-FIX: this is the facade's ONLY pipeline, and $client is
             * usually the process-wide $redis global that the whole app shares.
             * If anything between multi() and exec() throws, attempt() swallows
             * it and the handle is left in PIPELINE mode — in which phpredis
             * returns $this from EVERY subsequent command. That state is quietly
             * catastrophic: set() reports true for writes that never happened,
             * exists() reports true for absent keys, and the re-probe's PING
             * returns the object rather than false, so the handle is re-blessed
             * as healthy forever (restart-only recovery).
             *
             * In practice PIPELINE buffers client-side, so rPush/lTrim do not
             * touch the socket and cannot throw, and a killed connection makes
             * exec() itself throw AFTER phpredis has already left pipeline mode
             * — I could not reach the stuck state on phpredis 5.3.7. This
             * finally is defence-in-depth for other/older builds: discard()
             * leaves the handle usable no matter how we exit.
             */
            try {
                $client->rPush($listKey, $json);
                $client->lTrim($listKey, -$max, -1);
                if ($ttlSeconds > 0) {
                    // Refreshed on every append, so the list lives $ttlSeconds
                    // past the LAST message rather than forever. Without this the
                    // only reclamation was a read-time sweep that a deployment
                    // with no channel.list traffic never triggers.
                    $client->expire($listKey, $ttlSeconds);
                }
                $client->exec();
            } catch (\Throwable $e) {
                try {
                    $client->discard();
                } catch (\Throwable $ignored) {
                    // Already out of pipeline mode (or the handle is gone) —
                    // either way the original throw is what matters.
                }

                throw $e;
            }

            return null;
        }, null);
    }

    /**
     * @param string $key
     * @param int    $start redis index semantics (negative counts from the end)
     * @param int    $stop
     * @return array<int,mixed> decoded elements
     */
    public static function lRange(string $key, int $start, int $stop): array
    {
        self::guardKey($key, true);

        return self::attempt('lRange', static fn ($client) => self::decodeList($client->lRange($key, $start, $stop)), []);
    }

    /**
     * @param string $key
     * @param mixed  ...$members any JSON-serializable values
     * @return int number of members newly added
     */
    public static function sAdd(string $key, ...$members): int
    {
        self::guardKey($key);

        return self::attempt('sAdd', static function ($client) use ($key, $members) {
            $added = 0;
            foreach ($members as $member) {
                $json = self::encode($member, 'sAdd');
                if ($json === false) {
                    continue;
                }
                $added += (int) $client->sAdd($key, $json);
            }

            return $added;
        }, 0);
    }

    /**
     * @param string $key
     * @param mixed  ...$members
     * @return int number of members actually removed
     */
    public static function sRem(string $key, ...$members): int
    {
        self::guardKey($key);

        return self::attempt('sRem', static function ($client) use ($key, $members) {
            $removed = 0;
            foreach ($members as $member) {
                $json = self::encode($member, 'sRem');
                if ($json === false) {
                    continue;
                }
                $removed += (int) $client->sRem($key, $json);
            }

            return $removed;
        }, 0);
    }

    /**
     * @param string $key
     * @return array<int,mixed> decoded members
     */
    public static function sMembers(string $key): array
    {
        self::guardKey($key, true);

        return self::attempt('sMembers', static fn ($client) => self::decodeList($client->sMembers($key)), []);
    }

    /**
     * @param string    $key
     * @param float|int $score
     * @param mixed     $value
     * @return bool true when the command succeeded (add or score update)
     */
    public static function zAdd(string $key, $score, $value): bool
    {
        self::guardKey($key);
        $json = self::encode($value, 'zAdd');
        if ($json === false) {
            return false;
        }

        return self::attempt('zAdd', static function ($client) use ($key, $score, $json) {
            // phpredis ZADD returns int 1 for a NEW member and int 0 when it only
            // updates an existing member's score — both are command success. Casting
            // with (bool) would report a heartbeat re-add as failure, so compare
            // strictly against the false error reply instead (F1).
            return $client->zAdd($key, $score, $json) !== false;
        }, false);
    }

    /**
     * Score-range removal — the hot-cache/TTL sweeper for ordered data
     * (e.g. prune presence entries scored by last-seen timestamp).
     *
     * @param string           $key
     * @param float|int|string $min inclusive lower score bound
     * @param float|int|string $max inclusive upper score bound
     * @return int number of members removed
     */
    public static function zRemRangeByScore(string $key, $min, $max): int
    {
        self::guardKey($key);

        return self::attempt('zRemRangeByScore', static fn ($client) => (int) $client->zRemRangeByScore($key, $min, $max), 0);
    }

    /**
     * @param string $key
     * @param int    $start rank (0 = lowest score; negative counts from the end)
     * @param int    $stop
     * @return array<int,mixed> decoded members
     */
    public static function zRange(string $key, int $start, int $stop): array
    {
        self::guardKey($key, true);

        return self::attempt('zRange', static fn ($client) => self::decodeList($client->zRange($key, $start, $stop)), []);
    }

    /**
     * Score-range enumeration — the derived read for ordered indexes whose
     * membership IS their recency (ZRANGEBYSCORE): "channels active in the
     * last hour" or "presence members seen since the staleness cutoff" are
     * one call, no side index to keep in sync. Bounds are inclusive; 'inf'
     * / '-inf' are accepted for open ranges exactly like the Redis command.
     *
     * @param string           $key
     * @param float|int|string $min inclusive lower score bound
     * @param float|int|string $max inclusive upper score bound
     * @return array<int,mixed> decoded members, ascending score order
     */
    public static function zRangeByScore(string $key, $min, $max): array
    {
        self::guardKey($key, true);

        return self::attempt('zRangeByScore', static fn ($client) => self::decodeList($client->zRangeByScore($key, $min, $max)), []);
    }

    /**
     * Remove named members from an ordered index (leave/close paths). The
     * score-range sweeper stays the crash-safety backstop for entries whose
     * removal raced a dead worker, but the deterministic paths delete exactly
     * the member they are done with.
     *
     * @param string $key
     * @param mixed  ...$members any JSON-serializable values
     * @return int number of members actually removed
     */
    public static function zRem(string $key, ...$members): int
    {
        self::guardKey($key);
        if ($members === []) {
            return 0;
        }

        return self::attempt('zRem', static function ($client) use ($key, $members) {
            $removed = 0;
            foreach ($members as $member) {
                $json = self::encode($member, 'zRem');
                if ($json === false) {
                    continue;
                }
                $removed += (int) $client->zRem($key, $json);
            }

            return $removed;
        }, 0);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The facade's single guarded command dispatcher: resolve the client, run
     * the command, and degrade to the SAME documented fail-safe value on
     * either failure mode — no client (logged once per process) or a throwing
     * transport (mark dead + logged once per process). Guards (guardKey,
     * guardLockArgs, rPushLtrim's max check) stay OUTSIDE this helper's
     * try/catch — they are called by each public method BEFORE dispatch, so
     * namespace misuse keeps throwing even when the transport is dead.
     *
     * @param string   $operation method name, for the once-log
     * @param callable $command   receives the resolved client, returns the method value
     * @param mixed    $failSafe  value returned on any unavailability
     * @return mixed the command's value, or $failSafe
     */
    private static function attempt(string $operation, callable $command, $failSafe)
    {
        $client = self::client();
        if ($client === null) {
            if (!self::transportFailed()) {
                // Genuinely no client. Inside a dead window the transport line
                // already carries the real reason — a "no client" log there
                // would be both duplicate and misleading.
                self::logUnavailableOnce($operation);
            }

            return $failSafe;
        }
        try {
            return $command($client);
        } catch (\Throwable $e) {
            // Transport death, not a guard violation (those threw before this
            // point): the promise is that NO command exception escapes.
            self::markTransportDead($operation, $e->getMessage());

            return $failSafe;
        }
    }

    /**
     * Mark the transport dead: client() short-circuits to the fail-safe for
     * REPROBE_INTERVAL seconds (one dead-handle probe per window, not per
     * call), then re-probes. This replaced the old sticky $connectFailed memo
     * that kept workers fail-safe forever after a boot-time refusal.
     *
     * @param string $operation
     * @param string $reason
     * @return void
     */
    private static function markTransportDead(string $operation, string $reason): void
    {
        self::$deadUntil = self::now() + self::REPROBE_INTERVAL;
        self::logTransportOnce($operation, $reason);
    }

    /**
     * Wall clock for the dead-window arithmetic — the test seam (setTestClock)
     * exists so recovery timelines run deterministically without sleeping.
     *
     * @return int unix seconds
     */
    private static function now(): int
    {
        return self::$testNow ?? \time();
    }

    /**
     * Namespace guard: throw before touching Redis when a key escapes dc:*.
     * Shared DB0 also holds MyAdmin caches — an unguarded write would let one
     * typo clobber another subsystem's namespace.
     *
     * @param string $key
     * @return void
     * @throws \InvalidArgumentException
     */
    /**
     * JSON-encode a value for storage, loudly.
     *
     * REVIEW-FIX: every call site did a bare `json_encode()` and silently
     * returned/skipped on false. GlobalData stored PHP `serialize()` over its own
     * socket protocol, which is BYTE-TRANSPARENT — arbitrary binary and latin1
     * round-tripped fine. JSON is strict UTF-8, so this migration narrowed what
     * can be stored, and the failure was invisible: a v1 chat body carrying a lone
     * 0x80 byte (a legacy-encoded paste, or any non-UTF-8 client) was dropped by
     * rPushLtrim() while zAdd() still marked the channel active, so history and
     * the live tail silently lost messages with nothing logged anywhere.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE keeps the write (replacing the offending bytes)
     * instead of losing it, and anything still unencodable is logged rather than
     * dropped in silence.
     *
     * @param mixed  $value
     * @param string $operation for the log line
     * @return string|false
     */
    private static function encode($value, string $operation)
    {
        $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            self::log("SharedState::{$operation}: value could not be JSON-encoded (".json_last_error_msg().") — the write was DROPPED\n");

            return false;
        }

        return $json;
    }

    private static function guardKey(string $key, bool $allowLockNamespace = false): void
    {
        /*
         * REVIEW-FIX: the guard was prefix-only and PREFIX_LOCK was in the allowed
         * set, so set()/hSet()/rPushLtrim()/sAdd()/zAdd() all accepted `dc:lock:*`
         * — the one namespace the lock protocol's correctness depends on.
         *   set('dc:lock:processing_queue', 1)  overwrites a live token, after
         *     which the real owner's renew()/unlock() fail and the lock survives to
         *     its full TTL.
         *   hSet('dc:lock:vps_host_5', …)       converts the key to a HASH with NO
         *     TTL, so SET NX can never succeed on it again — that lock family is
         *     starved PERMANENTLY, with no expiry to heal it.
         * Refused for every WRITE primitive. Reads (get/exists/hGet/hGetAll/
         * lRange/sMembers/zRange/zRangeByScore) and del() are allowed: reads
         * cannot corrupt a token or retype a key and are genuinely useful for
         * observability, and stale/admin lock cleanup via del() is a documented
         * path (cold-start pre-clean, boardctl_startup_reap). The lock primitives
         * build their own keys and never come through here.
         */
        if (!$allowLockNamespace && strpos($key, self::PREFIX_LOCK) === 0) {
            throw new \InvalidArgumentException(
                "SharedState key '{$key}' is in the reserved lock namespace ("
                .self::PREFIX_LOCK.'); use lock()/renew()/unlock() for locks, '
                .'SharedState::requestKey() for a lock\'s observability sibling'
            );
        }
        foreach (self::$prefixes as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return;
            }
        }
        throw new \InvalidArgumentException(
            "SharedState key '{$key}' is outside the dc:* namespace; keys must start with one of: "
            .implode(', ', self::$prefixes)
        );
    }

    /**
     * @param string $name
     * @param int    $ttlSeconds
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function guardLockArgs(string $name, int $ttlSeconds): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('SharedState locks require a name');
        }
        if ($ttlSeconds < self::MIN_LOCK_TTL) {
            throw new \InvalidArgumentException(
                'SharedState locks require a positive TTL (the no-TTL GlobalData lock is the SPOF this replaces), got '.$ttlSeconds
            );
        }
    }

    /**
     * @return bool whether a lazy connect is even possible in this process
     */
    private static function redisConfigured(): bool
    {
        $configured = defined('USE_REDIS')
            && USE_REDIS === true
            && defined('REDIS_HOST')
            && defined('REDIS_PORT');

        if ($configured && !class_exists('\Redis')) {
            /*
             * REVIEW-FIX: this is a DEPLOYMENT error, not a runtime state, and it
             * used to be indistinguishable from "Redis intentionally disabled" —
             * the facade just returned null forever and every lock, registry,
             * flag and presence read silently degraded to its fail-safe. A hub
             * running with no cross-process coordination at all, quietly, is far
             * worse than one that says so.
             *
             * composer.json now declares ext-redis in require (so `composer install`
             * refuses a host without it), but that only guards INSTALL time — an
             * extension disabled after the fact, or a --ignore-platform-reqs
             * install, still reaches this code path. So the runtime check stays,
             * and it is loud.
             */
            self::logConfigErrorOnce();

            return false;
        }

        return $configured;
    }

    /**
     * Announce a missing phpredis extension once per process.
     *
     * @return void
     */
    private static function logConfigErrorOnce(): void
    {
        if (self::$loggedMissingExtension) {
            return;
        }
        self::$loggedMissingExtension = true;
        self::log(
            "SharedState: USE_REDIS is on but the phpredis extension (ext-redis) is NOT loaded — "
            ."ALL cross-process state (locks, registries, feature flags, presence) is running fail-safe/degraded. "
            ."Install php-redis on this host.\n"
        );
    }

    /**
     * @param array|false $raw list/set/zset members straight from phpredis
     * @return array<int,mixed>
     */
    private static function decodeList($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            $out[] = json_decode($value, true);
        }

        return $out;
    }

    /**
     * First unavailable-client failure per process, then silence — timers hit
     * these every tick and a log line per tick buries everything else.
     *
     * @param string $operation
     * @return void
     */
    private static function logUnavailableOnce(string $operation): void
    {
        if (self::$loggedUnavailable) {
            return;
        }
        self::$loggedUnavailable = true;
        self::log("SharedState::{$operation}: Redis unavailable (no client) — returning fail-safe value; further failures are silent this process\n");
    }

    /**
     * First transport-death log per cycle then silence — same rationale as
     * logUnavailableOnce() (timer-driven paths must not flood) but a SEPARATE
     * memo: the two lines report different diagnoses, and a no-client line
     * logged earlier in the process (Redis absent at boot, configured-and-
     * died later) must never mute the first genuine transport-throw reason.
     * reset()/setClient() re-arms.
     *
     * @param string $operation
     * @param string $reason
     * @return void
     */
    /**
     * Re-arm the once-per-outage log memos after a successful recovery.
     *
     * REVIEW-FIX: $loggedUnavailable / $loggedTransport were only ever cleared by
     * reset() and setClient(), both of which are test-only seams. In production a
     * BusinessWorker runs for days or weeks, so the FIRST transient blip consumed
     * the memo and every later outage — including a multi-hour one, during which
     * every lock fails, all v1 traffic blackholes and payment nudges no-op —
     * produced ZERO log output. The docblocks described a per-CYCLE line, which
     * was unreachable.
     *
     * Clearing the memos when the transport verifiably recovers restores the
     * documented intent: one line per outage, not one line per process, with no
     * flooding while an outage persists (the memo still suppresses within a
     * single outage).
     *
     * @return void
     */
    private static function rearmOutageLogs(): void
    {
        self::$loggedUnavailable = false;
        self::$loggedTransport = false;
    }

    private static function logTransportOnce(string $operation, string $reason): void
    {
        if (self::$loggedTransport) {
            return;
        }
        self::$loggedTransport = true;
        self::log("SharedState::{$operation}: Redis transport failed ({$reason}) — returning fail-safe values; next re-probe in ".self::REPROBE_INTERVAL."s; further failures are silent this process\n");
    }

    /**
     * Process-safe log helper: uses Worker::safeEcho() inside worker
     * processes, error_log() elsewhere (web/CLI contexts).
     *
     * @param string $message
     * @return void
     */
    private static function log($message)
    {
        try {
            static $workers = null;
            if ($workers === null) {
                $workers = class_exists('\Workerman\Worker', false) ? \Workerman\Worker::getAllWorkers() : [];
            }
            if (!empty($workers)) {
                \Workerman\Worker::safeEcho($message);
                return;
            }
        } catch (\Throwable $e) {
            // fall through to error_log
        }
        error_log(rtrim($message, "\n"));
    }
}
