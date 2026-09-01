<?php

use PHPUnit\Framework\TestCase;

// Declares InMemoryRedis (no socket can be opened to REDIS_HOST in tests)
// before SharedState is loaded. SharedState is also require_once'd by
// Events.php, but this suite must run standalone like FeatureFlagsTest does.
require_once __DIR__.'/TestBootstrap.php';
require_once __DIR__.'/../Applications/Chat/SharedState.php';

/**
 * Duck-typed double whose GET always throws — the phpredis RedisException a
 * server restart leaves behind when the shared handle's object is alive but
 * its socket is gone. Counts facade reaches so the dead-window short-circuit
 * ("fail-safe WITHOUT re-hitting the throwing handle") is provable.
 */
class ThrowingGetRedis
{
    /** @var int number of get() calls the facade actually made */
    public $getCalls = 0;

    /** @var int number of ping() calls (fallback re-probe verification) */
    public $pingCalls = 0;

    public function get($key)
    {
        $this->getCalls++;

        throw new \RedisException('simulated: connection lost on get');
    }

    public function ping()
    {
        $this->pingCalls++;

        return true;
    }
}

/**
 * Shared-handle double for the REPROBE path: a REAL \Redis subclass so the
 * `instanceof \Redis` preference branch accepts it, answering PING without a
 * socket and reading/writing a raw string keyspace.
 *
 * SIGNATURES: phpredis 5.x declares these methods untyped, but 6.x declares
 * real (non-tentative) types — `Redis::get(string $key): mixed` and
 * `Redis::set/ping(): Redis|string|bool` — so plain untyped overrides are a
 * hard fatal there, not a deprecation. CI runs 6.x while dev boxes are still
 * on 5.3.7, so every override below is written to bind against BOTH:
 *   - params: a lone variadic is compatible with any parent parameter list;
 *   - return: the narrowest type the double really produces, which stays
 *     covariant with `mixed`, with `Redis|string|bool`, and with no parent
 *     return type at all.
 * Do not "simplify" these back to `get($key)` — that is what broke CI.
 */
class LiveHandleRedis extends \Redis
{
    /** @var array<string,string> raw keyspace (phpredis hands back strings) */
    public $data = [];

    /** @var int PINGs received — recovery must probe once per window, then stop */
    public $pings = 0;

    public function ping(...$args): bool
    {
        $this->pings++;

        return true;
    }

    public function get(...$args): string|false
    {
        $key = $args[0];

        return array_key_exists($key, $this->data) ? $this->data[$key] : false;
    }

    public function set(...$args): bool
    {
        $this->data[$args[0]] = (string) $args[1];

        return true;
    }
}

/**
 * A shared handle whose PING AND GET keep throwing (like an unconnected
 * \Redis: "Redis server went away") — proves a failed re-probe re-marks for
 * another window without ever reaching the dead fallback handle, and lets a
 * test open the FIRST dead window by running a command straight through the
 * preferred global.
 */
class DeadHandleRedis extends \Redis
{
    /** @var int PING attempts */
    public $pings = 0;

    public function ping(...$args): bool
    {
        $this->pings++;

        throw new \RedisException('simulated: server still down on PING');
    }

    public function get(...$args): string|false
    {
        throw new \RedisException('simulated: server still down on GET');
    }
}

/**
 * The self-heal the deprioritized shared handle hands traffic to: a duck-
 * typed fallback the facade adopts through the test connect factory. NOT a
 * \Redis subclass — adoption must ride the same injectable path production
 * lazy-connects, so tests can decide per-attempt what the fresh handle is.
 */
class FallbackFactoryRedis
{
    /** @var array<string,string> raw JSON-string keyspace it serves */
    public $data = [];

    public function ping()
    {
        return true;
    }

    public function get($key)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : false;
    }

    public function set($key, $value, $opts = null)
    {
        $this->data[$key] = (string) $value;

        return true;
    }
}

/**
 * A fallback the facade adopted earlier and that has since gone stale: every
 * command AND its verification PING throw. The re-probe must PING-fail it,
 * DROP the reference (never close), and let one fresh connect serve.
 */
class DeadFallbackRedis
{
    /** @var int */
    public $getCalls = 0;

    /** @var int */
    public $pingCalls = 0;

    public function ping()
    {
        $this->pingCalls++;

        throw new \RedisException('simulated: stale fallback connection lost on PING');
    }

    public function get($key)
    {
        $this->getCalls++;

        throw new \RedisException('simulated: stale fallback connection lost on GET');
    }
}

/**
 * Tests for Applications/Chat/SharedState.php (GlobalData→Redis migration).
 *
 * NOTE: this docblock used to say "Phase 1 — the facade ships dormant; no
 * production call site uses it yet". That is long out of date and actively
 * misleading: Applications/Chat/Events.php, Applications/Chat/FeatureFlags.php,
 * Web/trigger_payment.php, scripts/boardctl_runner.php and six Tasks/ files all
 * depend on this facade now. Treat this suite as load-bearing.
 *
 * Everything runs against the InMemoryRedis double injected through
 * SharedState::setClient(), whose semantics (SET NX/EX replies, EXISTS
 * has-no-NULL-trap, WRONGTYPE, LTRIM clamping, TTL eviction on a controllable
 * clock, native re-implementations of the two lock Lua scripts) mirror the
 * real server where the migration depends on them.
 *
 * @see tests/TestBootstrap.php  InMemoryRedis
 */
class SharedStateTest extends TestCase
{
    /** @var InMemoryRedis */
    private $redis;

    /** @var string|null sink file, set only by the fail-safe-log test */
    private $logSinkFile = null;

    /** @var string|null the process error_log ini value captured before redirecting to the sink */
    private $previousErrorLog = null;

    /** @var array|null snapshot of Workerman's static worker registry, neutralized while the sink is open */
    private $workersSnapshot = null;

    /** @var mixed previous value of Workerman's public static $outputStream (write target of safeEcho) */
    private $previousOutputStream = null;

    /** @var resource|null the fopen() handle installed as Worker::$outputStream while the sink is open */
    private $logSinkStream = null;

    protected function setUp(): void
    {
        // $GLOBALS['redis'] must not leak in from another suite: the facade
        // prefers it over any injected client, so start every test from the
        // "no shared connection" baseline.
        unset($GLOBALS['redis']);
        SharedState::reset();
        $this->redis = new InMemoryRedis();
        SharedState::setClient($this->redis);
    }

    protected function tearDown(): void
    {
        $this->stopLogSink();
        SharedState::reset();
        unset($GLOBALS['redis']);
    }

    /**
     * Open a capture file that BOTH destinations of SharedState::log() can
     * reach, so the once-guard assertions hold no matter what order the suite
     * ran the earlier tests in (ported from the green FeatureFlagsTest sink —
     * see tests/FeatureFlagsTest.php::startLogSink() for the full rationale):
     *
     *   1. error_log() branch — taken when log()'s process-sticky
     *      `static $workers` memo first computed against an EMPTY worker
     *      registry (the standalone truth). Captured by pointing the
     *      error_log ini at the sink file.
     *   2. Worker::safeEcho() branch — taken once a sibling suite
     *      (e.g. BusinessWorkerBootstrapTest, whose BusinessWorker ctor
     *      registers into Worker::$workers for the whole process) populated
     *      the registry BEFORE log()'s memo first computed. The memo is then
     *      permanently non-empty — emptying the registry now cannot reset it —
     *      and safeEcho() fwrite()s to Worker::$outputStream, bypassing
     *      error_log AND output buffering. Captured by swapping that public
     *      static onto the same sink file.
     *
     * Emptying the registry is belt-and-suspenders: it covers the case where
     * the memo has NOT yet computed when this test runs, forcing it to take
     * route 1. Every edit is reversed by stopLogSink() (tearDown), so no other
     * suite sees a mutated worker registry or stdout stream.
     */
    private function startLogSink(): void
    {
        $this->logSinkFile = tempnam(sys_get_temp_dir(), 'sharedstate-log-');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logSinkFile);

        // Mirror log()'s own routing condition exactly: it only ever considers
        // the safeEcho branch when the class is ALREADY loaded (no autoload).
        if (class_exists('\Workerman\Worker', false)) {
            $registry = new \ReflectionProperty(\Workerman\Worker::class, 'workers');
            $registry->setAccessible(true);
            $this->workersSnapshot = $registry->getValue();
            $registry->setValue(null, []);

            $stream = new \ReflectionProperty(\Workerman\Worker::class, 'outputStream');
            $this->previousOutputStream = $stream->getValue();
            $handle = fopen($this->logSinkFile, 'a');
            $this->logSinkStream = $handle === false ? null : $handle;
            if ($this->logSinkStream !== null) {
                $stream->setValue(null, $this->logSinkStream);
            }
        }
    }

    /** Undo every capture installed by startLogSink() and remove the temp file. */
    private function stopLogSink(): void
    {
        if ($this->workersSnapshot !== null) {
            $registry = new \ReflectionProperty(\Workerman\Worker::class, 'workers');
            $registry->setAccessible(true);
            $registry->setValue(null, $this->workersSnapshot);
            $this->workersSnapshot = null;
        }
        if ($this->logSinkStream !== null || $this->previousOutputStream !== null) {
            $stream = new \ReflectionProperty(\Workerman\Worker::class, 'outputStream');
            $stream->setValue(null, $this->previousOutputStream);
            if ($this->logSinkStream !== null) {
                fclose($this->logSinkStream);
                $this->logSinkStream = null;
            }
            $this->previousOutputStream = null;
        }
        if ($this->logSinkFile !== null) {
            ini_set('error_log', (string) $this->previousErrorLog);
            @unlink($this->logSinkFile);
            $this->logSinkFile = null;
            $this->previousErrorLog = null;
        }
    }

    /** Lock keys are namespaced by the facade; handy for raw-state asserts. */
    private function rawLock(string $name)
    {
        return $this->redis->get(SharedState::PREFIX_LOCK.$name);
    }

    // -----------------------------------------------------------------------
    // JSON round-trip
    // -----------------------------------------------------------------------

    /**
     * REVIEW-FIX (decision G): the suite must never be able to reach a REAL Redis.
     *
     * The GlobalData-era suite asserted this explicitly
     * (assertSame('127.0.0.1', GLOBALDATA_IP, 'tests must never point at the
     * production GlobalData host')). That guard was dropped in the port with no
     * Redis equivalent, leaving safety resting on the mere ABSENCE of a USE_REDIS
     * define — an implicit, unasserted property. If any test (or a sibling suite
     * that pulls in config.settings.php) ever defines USE_REDIS/REDIS_HOST, then
     * SharedState::client() will lazily connect and the suite will start reading
     * and WRITING a real Redis — plausibly production's, since REDIS_HOST comes
     * from the shared settings file and is a routable address.
     *
     * Restored here as a hard assertion on the two things that gate the lazy
     * connect: the config constants, and the connect factory seam.
     */
    public function testSuiteCannotReachARealRedis(): void
    {
        SharedState::setClient(null);
        SharedState::reset();
        unset($GLOBALS['redis']);

        $this->assertFalse(defined('USE_REDIS'), 'USE_REDIS must not be defined in the test process');
        $this->assertFalse(defined('REDIS_HOST'), 'REDIS_HOST must not be defined in the test process');
        $this->assertFalse(defined('REDIS_PORT'), 'REDIS_PORT must not be defined in the test process');

        // With no double injected, no global handle and no factory, the facade must
        // resolve nothing rather than dialling a socket.
        $this->assertNull(
            SharedState::client(),
            'with nothing injected the facade must resolve no client, never lazy-connect to a real server'
        );
        $this->assertFalse(
            SharedState::transportFailed(),
            'an unconfigured store is a STATE, not a transport failure'
        );
    }

    public function testJsonRoundTripForArraysAndScalars(): void
    {
        $cases = [
            'array' => ['hosts' => ['a' => 1, 'b' => [2, 3]], 'flag' => true],
            'string' => 'general chat ✓',
            'int' => 42,
            'float' => 3.5,
            'bool_false' => false,
            'empty_array' => [],
            'null' => null,
        ];
        foreach ($cases as $label => $value) {
            $key = SharedState::PREFIX_STATE.'roundtrip:'.$label;
            $this->assertTrue(SharedState::set($key, $value), "set must succeed for {$label}");
            $this->assertSame($value, SharedState::get($key), "roundtrip mismatch for {$label}");
        }
    }

    public function testGetMissingKeyReturnsNull(): void
    {
        $this->assertNull(SharedState::get(SharedState::PREFIX_PRESENCE.'nope'));
    }

    public function testSetWithTtlExpiresOnTheClock(): void
    {
        $key = SharedState::PREFIX_PRESENCE.'session:42';
        $this->assertTrue(SharedState::set($key, ['uid' => 42], 30));
        $this->assertSame(['uid' => 42], SharedState::get($key));

        $this->redis->fastForward(29);
        $this->assertSame(['uid' => 42], SharedState::get($key), 'must survive inside the TTL');

        $this->redis->fastForward(2);
        $this->assertNull(SharedState::get($key), 'must expire past the TTL');
        $this->assertFalse(SharedState::exists($key));
    }

    // -----------------------------------------------------------------------
    // add() — SET NX, incl. the historical NULL-vs-empty trap analog
    // -----------------------------------------------------------------------

    /**
     * GlobalData add()/cas() treated a stored NULL like an absent key while an
     * empty array was present — the mismatch behind real CAS livelocks. Redis
     * has no such trap: ANY stored value (even "" or []) blocks SET NX, and
     * that is what add() must model. Pin it so migrated seeders rely on real
     * exists-semantics, never on the old fuzziness.
     */
    public function testAddSeedsOnlyWhenAbsentAndNeverClobbers(): void
    {
        $key = SharedState::PREFIX_STATE.'running';

        $this->assertTrue(SharedState::add($key, []), 'first add must seed the key');
        $this->assertFalse(SharedState::add($key, ['poison']), 'add must report failure once seeded');
        $this->assertSame([], SharedState::get($key), 'a losing add must not clobber the winner');
    }

    public function testAddTreatsEmptyAndNullValuesAsPresent(): void
    {
        foreach ([['label' => 'empty_string', 'seed' => ''], ['label' => 'zero', 'seed' => 0], ['label' => 'json_null', 'seed' => null]] as $case) {
            $key = SharedState::PREFIX_STATE.'trap:'.$case['label'];
            $this->assertTrue(SharedState::set($key, $case['seed']));
            $this->assertFalse(
                SharedState::add($key, 'replacement'),
                "stored '{$case['label']}' is PRESENT to Redis — add() must refuse to overwrite it"
            );
            $this->assertSame($case['seed'], SharedState::get($key), 'the stored value must survive a losing add');
        }
    }

    public function testAddWithTtl(): void
    {
        $key = SharedState::PREFIX_STATE.'seed:ttl';
        $this->assertTrue(SharedState::add($key, 'v', 10));
        $this->assertFalse(SharedState::add($key, 'w', 10));
        $this->redis->fastForward(11);
        $this->assertTrue(SharedState::add($key, 'w'), 'the seed must be re-takeable after expiry');
        $this->assertSame('w', SharedState::get($key));
    }

    // -----------------------------------------------------------------------
    // Locks
    // -----------------------------------------------------------------------

    public function testLockTokenShapeIsHostPidHex(): void
    {
        $token = SharedState::lock('sync_hyperv', 60);
        $this->assertNotNull($token);
        $parts = explode(':', $token);
        $this->assertCount(3, $parts, 'token = hostname:pid:random-hex');
        $this->assertSame(gethostname(), $parts[0]);
        $this->assertSame((string) getmypid(), $parts[1]);
        $this->assertSame(1, preg_match('/^[0-9a-f]{16}$/', $parts[2]), 'random suffix must be 16 hex chars');
        $this->assertSame($token, $this->rawLock('sync_hyperv'), 'token is stored raw (Lua string-compares it)');
    }

    public function testLockAcquireContendReleaseByOwner(): void
    {
        $token = SharedState::lock('boardctl_asset_7', 120);
        $this->assertNotNull($token, 'uncontended acquire must yield a token');

        $this->assertNull(SharedState::lock('boardctl_asset_7', 120), 'a second holder must not acquire');

        $this->assertTrue(SharedState::unlock('boardctl_asset_7', $token), 'owner release must succeed');
        // raw double access = phpredis semantics: a MISS is false, not null.
        $this->assertFalse($this->rawLock('boardctl_asset_7'), 'release must remove the lock key');

        $this->assertNotNull(SharedState::lock('boardctl_asset_7', 120), 'the lock must be acquirable again');
    }

    public function testWrongTokenReleaseIsRefusedAndKeepsTheLock(): void
    {
        $token = SharedState::lock('processing_queue', 30);
        $this->assertNotNull($token);

        $this->assertFalse(SharedState::unlock('processing_queue', 'someone-elses-token'), 'non-owner release must be refused');
        $this->assertSame($token, $this->rawLock('processing_queue'), 'the lock must remain held with its original token');

        $this->assertTrue(SharedState::unlock('processing_queue', $token), 'the real owner can still release');
    }

    public function testUnlockWithoutTokenIsTheAdminForceDelete(): void
    {
        $token = SharedState::lock('stale_asset', 30);
        $this->assertNotNull($token, 'precondition: a stale holder took the lock');

        $this->assertTrue(SharedState::unlock('stale_asset'), 'null-token unlock deletes unconditionally');
        $this->assertFalse($this->rawLock('stale_asset'));
        $this->assertFalse(SharedState::unlock('stale_asset'), 'force-unlocking an absent lock reports nothing deleted');
    }

    public function testLockExpiresOnTheClockSoHoldersCannotLeakForever(): void
    {
        $token = SharedState::lock('vps_host_9', 30);
        $this->assertNotNull($token);
        $this->assertNull(SharedState::lock('vps_host_9', 30));

        $this->redis->fastForward(31);
        $this->assertNotNull(
            SharedState::lock('vps_host_9', 30),
            'a dead holder lock must be re-acquirable after its TTL (no stale-lock reapers needed)'
        );
    }

    public function testRenewIsOwnerOnlyAndExtendsTheTtl(): void
    {
        $token = SharedState::lock('map_queue', 20);
        $this->assertNotNull($token);

        $this->assertFalse(SharedState::renew('map_queue', 'not-the-owner', 20), 'renew with the wrong token must fail');
        $this->assertNotNull($this->rawLock('map_queue'), 'a failed renew must leave the lock itself alone');

        $this->redis->fastForward(15);
        $this->assertTrue(SharedState::renew('map_queue', $token, 20), 'owner renew must succeed');

        $this->redis->fastForward(10); // t=25: past the original 20s TTL, inside the renewed window
        $this->assertNotNull($this->rawLock('map_queue'), 'renew must have extended the deadline');
        $this->assertNull(SharedState::lock('map_queue', 5), 'lock must still be held, not expired');

        $this->redis->fastForward(11); // t=36: past renewal deadline (15+20=35)
        $this->assertFalse(SharedState::renew('map_queue', $token, 20), 'renew after expiry must fail');
        $this->assertNotNull(SharedState::lock('map_queue', 5), 'lock must be re-acquirable after the extended TTL lapses');
    }

    public function testRenewOnAbsentLockFails(): void
    {
        $this->assertFalse(SharedState::renew('never_locked', 'whatever', 10));
    }

    public function testLockArgumentsAreGuarded(): void
    {
        foreach ([[
            'name' => '',
            'ttl' => 30,
            'why' => 'an empty lock name would collide at the dc:lock: root',
        ], [
            'name' => 'job',
            'ttl' => 0,
            'why' => 'a zero-TTL lock reintroduces the no-expiry GlobalData SPOF',
        ], [
            'name' => 'job',
            'ttl' => -5,
            'why' => 'a negative TTL is nonsense',
        ]] as $case) {
            try {
                SharedState::lock($case['name'], $case['ttl']);
                $this->fail("lock('{$case['name']}', {$case['ttl']}) must throw — {$case['why']}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('SharedState', $e->getMessage());
            }
        }
    }

    // -----------------------------------------------------------------------
    // Prefix guard — protect the shared DB0 namespaces
    // -----------------------------------------------------------------------

    public function testKeysOutsideTheDcNamespaceThrow(): void
    {
        $offenders = [
            'myadmin_session:abc',          // another app's namespace
            'cache:queue',                  // looks close, is not ours
            'DC:LOCK:job',                  // case-sensitive prefixes
            'dc:unknown:job',               // a dc: root nobody owns
            'dc:',                          // bare root
        ];
        $calls = [
            'get' => fn (string $k) => SharedState::get($k),
            'set' => fn (string $k) => SharedState::set($k, 1),
            'add' => fn (string $k) => SharedState::add($k, 1),
            'exists' => fn (string $k) => SharedState::exists($k),
            'del' => fn (string $k) => SharedState::del($k),
            'hSet' => fn (string $k) => SharedState::hSet($k, 'f', 1),
            'hSetNx' => fn (string $k) => SharedState::hSetNx($k, 'f', 1),
            'hGet' => fn (string $k) => SharedState::hGet($k, 'f'),
            'hGetAll' => fn (string $k) => SharedState::hGetAll($k),
            'hDel' => fn (string $k) => SharedState::hDel($k, 'f'),
            'hIncr' => fn (string $k) => SharedState::hIncr($k, 'f'),
            'rPushLtrim' => fn (string $k) => SharedState::rPushLtrim($k, 'v', 10),
            'lRange' => fn (string $k) => SharedState::lRange($k, 0, -1),
            'sAdd' => fn (string $k) => SharedState::sAdd($k, 'm'),
            'sRem' => fn (string $k) => SharedState::sRem($k, 'm'),
            'sMembers' => fn (string $k) => SharedState::sMembers($k),
            'zAdd' => fn (string $k) => SharedState::zAdd($k, 1, 'm'),
            'zRemRangeByScore' => fn (string $k) => SharedState::zRemRangeByScore($k, 0, 1),
            'zRange' => fn (string $k) => SharedState::zRange($k, 0, -1),
            'zRangeByScore' => fn (string $k) => SharedState::zRangeByScore($k, 0, 1),
            'zRem' => fn (string $k) => SharedState::zRem($k, 'm'),
        ];
        foreach ($calls as $method => $invoke) {
            foreach ($offenders as $key) {
                try {
                    $invoke($key);
                    $this->fail("SharedState::{$method}('{$key}') must throw (key escapes the dc:* contract)");
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString($key, $e->getMessage());
                }
            }
        }
    }

    public function testPrefixGuardFiresEvenWithNoClientAndBeforeAnyWrite(): void
    {
        SharedState::reset(); // force the fail-safe client path
        try {
            SharedState::set('other:app:key', 'payload');
            $this->fail('the guard must be fail-fast (throw), not fail-safe (return false)');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('dc:*', $e->getMessage());
        }
    }

    public function testEveryFacadeWriteStaysInsideTheDcNamespace(): void
    {
        // Drive one of everything, then dump the raw keyspace.
        SharedState::set(SharedState::PREFIX_PRESENCE.'p1', ['x' => 1]);
        SharedState::add(SharedState::PREFIX_STATE.'seeded', ['a']);
        SharedState::lock('anything', 30);
        SharedState::hSet(SharedState::PREFIX_STATE.'hosts', 'h1', ['ip' => '10.0.0.1']);
        SharedState::hIncr(SharedState::PREFIX_STATE.'counters', 'processed', 1);
        SharedState::rPushLtrim(SharedState::PREFIX_CHAT.'room_1', ['id' => 1], 3);
        SharedState::sAdd(SharedState::PREFIX_CHAT.'members:room_1', 'uid:7');
        SharedState::zAdd(SharedState::PREFIX_PRESENCE.'last_seen', time(), 'uid:7');
        SharedState::set(SharedState::PREFIX_FLAG.'ws_new_handling', 1);

        $keys = $this->redis->allKeys();
        $this->assertNotEmpty($keys);
        $stray = array_values(array_filter($keys, function ($key) {
            foreach (['dc:lock:', 'dc:state:', 'dc:chat:', 'dc:flag:', 'dc:presence:'] as $prefix) {
                if (strpos($key, $prefix) === 0) {
                    return false;
                }
            }

            return true;
        }));
        $this->assertSame([], $stray, 'the facade must never write outside dc:* — shared DB0 belongs to MyAdmin too');
    }

    // -----------------------------------------------------------------------
    // Hash registries
    // -----------------------------------------------------------------------

    public function testHashRegistryRoundTripAndDelete(): void
    {
        $registry = SharedState::PREFIX_STATE.'hosts';
        SharedState::hSet($registry, '7', ['ip' => '10.0.0.7', 'active' => true]);
        SharedState::hSet($registry, '8', ['ip' => '10.0.0.8', 'active' => false]);

        $this->assertSame(['ip' => '10.0.0.7', 'active' => true], SharedState::hGet($registry, '7'));
        $this->assertNull(SharedState::hGet($registry, 'nope'), 'missing field reads as null');

        $all = SharedState::hGetAll($registry);
        // Numeric-string fields come back as int keys — PHP array-key coercion,
        // which real phpredis exhibits identically; normalize for the assert.
        $this->assertSame(['7', '8'], array_map('strval', array_keys($all)), 'fields must come back keyed');
        $this->assertSame('10.0.0.8', $all['8']['ip']);

        SharedState::hDel($registry, '7');
        $this->assertNull(SharedState::hGet($registry, '7'));
        $this->assertSame(['8'], array_map('strval', array_keys(SharedState::hGetAll($registry))));
    }

    public function testHSetNxSeedsFieldOnce(): void
    {
        $registry = SharedState::PREFIX_STATE.'timers';
        $this->assertTrue(SharedState::hSetNx($registry, 'queue', ['interval' => 30]));
        $this->assertFalse(SharedState::hSetNx($registry, 'queue', ['interval' => 1]), 'second seed must lose');
        $this->assertSame(['interval' => 30], SharedState::hGet($registry, 'queue'));
    }

    public function testHIncrCountsAtomically(): void
    {
        $registry = SharedState::PREFIX_STATE.'metrics';
        $this->assertSame(1, SharedState::hIncr($registry, 'processed'));
        $this->assertSame(6, SharedState::hIncr($registry, 'processed', 5));
        $this->assertSame(4, SharedState::hIncr($registry, 'processed', -2));
        $this->assertSame(4, SharedState::hGet($registry, 'processed'), 'counter value must round-trip as int');
    }

    // -----------------------------------------------------------------------
    // Bounded lists, sets, zsets
    // -----------------------------------------------------------------------

    public function testRPushLtrimBoundsTheListToNewestEntries(): void
    {
        $list = SharedState::PREFIX_CHAT.'room_1';
        for ($i = 1; $i <= 5; $i++) {
            SharedState::rPushLtrim($list, ['id' => $i], 3);
        }
        $this->assertCount(3, SharedState::lRange($list, 0, -1), 'max bound must hold');
        $this->assertSame(
            [['id' => 3], ['id' => 4], ['id' => 5]],
            SharedState::lRange($list, 0, -1),
            'the NEWEST max entries must be retained'
        );
        $tail = SharedState::lRange($list, -1, -1);
        $this->assertSame([['id' => 5]], $tail, 'negative indexes must follow redis semantics');
    }

    public function testRPushLtrimRejectsUnboundedMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SharedState::rPushLtrim(SharedState::PREFIX_CHAT.'room_1', 'v', 0);
    }

    public function testSetWrappersRoundTripJsonMembers(): void
    {
        $set = SharedState::PREFIX_CHAT.'members:room_1';
        $this->assertSame(2, SharedState::sAdd($set, 'uid:7', ['nested' => true]));
        $this->assertSame(0, SharedState::sAdd($set, 'uid:7'), 'duplicate member adds nothing');

        // SET order is unspecified (in Redis and in the double) — assert
        // membership, not sequence; ===/in_array with is_array check keeps
        // the decoded nested value exact.
        $members = SharedState::sMembers($set);
        $this->assertCount(2, $members);
        $this->assertContains('uid:7', $members);
        $this->assertTrue(
            in_array(['nested' => true], $members, true),
            'members must survive the JSON trip intact'
        );

        $this->assertSame(1, SharedState::sRem($set, 'uid:7'));
        $this->assertSame(0, SharedState::sRem($set, 'uid:7'));
        $this->assertCount(1, SharedState::sMembers($set));
    }

    public function testZSetWrappersRangeAndScorePrune(): void
    {
        $zset = SharedState::PREFIX_PRESENCE.'last_seen';
        $this->assertTrue(SharedState::zAdd($zset, 100, 'uid:1'));
        $this->assertTrue(SharedState::zAdd($zset, 200, 'uid:2'));
        $this->assertTrue(SharedState::zAdd($zset, 300, 'uid:3'));

        $this->assertSame(['uid:1', 'uid:2', 'uid:3'], SharedState::zRange($zset, 0, -1), 'range must be score-ordered');
        $this->assertSame(['uid:2'], SharedState::zRange($zset, 1, 1));

        $this->assertSame(2, SharedState::zRemRangeByScore($zset, 0, 200), 'prune must remove the stale window inclusive');
        $this->assertSame(['uid:3'], SharedState::zRange($zset, 0, -1));
    }

    // -----------------------------------------------------------------------
    // Fail-safe behavior with no client
    // -----------------------------------------------------------------------

    public function testAllOperationsFailSafeWhenClientIsNull(): void
    {
        SharedState::reset();
        unset($GLOBALS['redis']); // no shared connection, no USE_REDIS in tests

        $this->assertNull(SharedState::get(SharedState::PREFIX_STATE.'x'));
        $this->assertFalse(SharedState::set(SharedState::PREFIX_STATE.'x', 1));
        $this->assertFalse(SharedState::add(SharedState::PREFIX_STATE.'x', 1));
        $this->assertFalse(SharedState::exists(SharedState::PREFIX_STATE.'x'));
        SharedState::del(SharedState::PREFIX_STATE.'x'); // void, must not throw

        $this->assertNull(SharedState::lock('anything', 30), 'lock() fail-safe is "did not acquire"');
        $this->assertFalse(SharedState::unlock('anything', 'token'));
        $this->assertFalse(SharedState::unlock('anything'));
        $this->assertFalse(SharedState::renew('anything', 'token', 30));

        $this->assertNull(SharedState::hGet(SharedState::PREFIX_STATE.'x', 'f'));
        $this->assertSame([], SharedState::hGetAll(SharedState::PREFIX_STATE.'x'));
        $this->assertFalse(SharedState::hSetNx(SharedState::PREFIX_STATE.'x', 'f', 1));
        SharedState::hSet(SharedState::PREFIX_STATE.'x', 'f', 1); // void, must not throw
        SharedState::hDel(SharedState::PREFIX_STATE.'x', 'f'); // void, must not throw
        $this->assertSame(0, SharedState::hIncr(SharedState::PREFIX_STATE.'x', 'f'));

        $this->assertSame([], SharedState::lRange(SharedState::PREFIX_CHAT.'x', 0, -1));
        SharedState::rPushLtrim(SharedState::PREFIX_CHAT.'x', 'v', 3); // void, must not throw
        $this->assertSame(0, SharedState::sAdd(SharedState::PREFIX_CHAT.'x', 'm'));
        $this->assertSame(0, SharedState::sRem(SharedState::PREFIX_CHAT.'x', 'm'));
        $this->assertSame([], SharedState::sMembers(SharedState::PREFIX_CHAT.'x'));
        $this->assertFalse(SharedState::zAdd(SharedState::PREFIX_PRESENCE.'x', 1, 'm'));
        $this->assertSame(0, SharedState::zRemRangeByScore(SharedState::PREFIX_PRESENCE.'x', 0, 1));
        $this->assertSame([], SharedState::zRange(SharedState::PREFIX_PRESENCE.'x', 0, -1));
        $this->assertSame([], SharedState::zRangeByScore(SharedState::PREFIX_PRESENCE.'x', 0, 'inf'));
        $this->assertSame(0, SharedState::zRem(SharedState::PREFIX_PRESENCE.'x', 'm'), 'zRem fail-safe is "nothing removed"');
    }

    // -----------------------------------------------------------------------
    // Client resolution
    // -----------------------------------------------------------------------

    public function testGlobalsRedisTakesPrecedenceAndIsNeverReplaced(): void
    {
        // An unconnected \Redis is enough to prove precedence + identity:
        // client() must hand back THIS instance, and the injected double must
        // not shadow it (the facade never swaps out a connection MyAdmin shares).
        $shared = new \Redis();
        $GLOBALS['redis'] = $shared;
        try {
            $this->assertSame($shared, SharedState::client());
        } finally {
            unset($GLOBALS['redis']);
        }
        $this->assertSame($this->redis, SharedState::client(), 'with no $GLOBALS[redis], setClient() is honored');
    }

    public function testResetForgetsAnInjectedClient(): void
    {
        SharedState::reset();
        $this->assertNull(SharedState::client());
    }

    public function testClientSurvivesReusedAcrossCallsWithoutReplacement(): void
    {
        $first = SharedState::client();
        $this->assertSame($first, SharedState::client());
    }

    // -----------------------------------------------------------------------
    // Reviewer follow-ups (F1–F7)
    // -----------------------------------------------------------------------

    /**
     * F3: the lock-argument guards run fail-fast, before the client is ever
     * resolved, on the two paths that only lock() covered originally.
     */
    public function testUnlockAndRenewArgumentsAreGuarded(): void
    {
        try {
            SharedState::unlock('', 'token');
            $this->fail("unlock('') must throw — an empty name would target the bare dc:lock: root");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unlock', $e->getMessage());
        }

        try {
            SharedState::renew('', 'token', 10);
            $this->fail("renew('', ...) must throw — same empty-name collision");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('SharedState', $e->getMessage());
        }

        try {
            SharedState::renew('job', 'token', 0);
            $this->fail('renew() with a zero TTL must throw — it would resurrect the no-expiry GlobalData SPOF');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('SharedState', $e->getMessage());
        }
    }

    /**
     * F4: del() guards EVERY key before removing ANY, so one un-namespaced
     * key aborts the whole call rather than half-applying.
     */
    public function testDelIsAllOrNothingAndRemovesEveryKey(): void
    {
        $a = SharedState::PREFIX_STATE.'del:a';
        $b = SharedState::PREFIX_STATE.'del:b';
        SharedState::set($a, 'keep');
        SharedState::set($b, 'also-keep');

        try {
            SharedState::del($a, 'bogus:key');
            $this->fail('a stray un-prefixed key must abort the whole del before anything is removed');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('bogus:key', $e->getMessage());
        }
        $this->assertSame('keep', SharedState::get($a), 'the rejected del must leave the first key intact');
        $this->assertSame('also-keep', SharedState::get($b), '...and the second valid key too');

        SharedState::del($a, $b);
        $this->assertNull(SharedState::get($a), 'the happy path removes every key passed');
        $this->assertNull(SharedState::get($b));
    }

    /**
     * F5: the no-client fail-safe logs ONCE per resolution cycle, then goes
     * silent for the rest of the process (timers would otherwise flood), and
     * logs again once SharedState is reset. SharedState::log() routes to
     * error_log() OR Worker::safeEcho() depending on a process-sticky memo of
     * the worker registry, so startLogSink() captures BOTH destinations — the
     * assertions hold regardless of which sibling suite ran before this one.
     */
    public function testUnavailableIsLoggedOnceThenAgainAfterReset(): void
    {
        $marker = 'Redis unavailable (no client)';
        $this->startLogSink();

        unset($GLOBALS['redis']);
        SharedState::reset(); // clear the injected client + the once-per-process log memo

        SharedState::get(SharedState::PREFIX_STATE.'logonce');
        SharedState::get(SharedState::PREFIX_STATE.'logonce');
        $this->assertSame(
            1,
            substr_count((string) @file_get_contents($this->logSinkFile), $marker),
            'the first no-client failure logs; every later one this cycle stays silent'
        );

        SharedState::reset(); // a fresh cycle must be allowed to speak up again
        SharedState::get(SharedState::PREFIX_STATE.'logonce');
        $this->assertSame(
            2,
            substr_count((string) @file_get_contents($this->logSinkFile), $marker),
            'after reset the next failure logs once again'
        );
    }

    /**
     * F1: phpredis ZADD returns int 0 when it only updates an existing member's
     * score. A heartbeat re-add is still a success and must read back true, with
     * the new score persisted in place (never a duplicate member).
     */
    public function testZAddReAddUpdatesScoreAndReturnsTrue(): void
    {
        $zset = SharedState::PREFIX_PRESENCE.'heartbeat';
        $member = json_encode('uid:1');

        $this->assertTrue(SharedState::zAdd($zset, 100, 'uid:1'), 'first add succeeds');
        $this->assertSame(100.0, $this->redis->data[$zset]['value'][$member], 'precondition: raw score stored');

        $this->assertTrue(SharedState::zAdd($zset, 250, 'uid:1'), 'a score-update re-add must still report success');
        $this->assertSame(250.0, $this->redis->data[$zset]['value'][$member], 'the raw score must be updated');
        $this->assertSame(['uid:1'], SharedState::zRange($zset, 0, -1), 'a re-add updates in place, never duplicates');
    }

    /**
     * F2: ZRANGEBYSCORE is inclusive on both ends and accepts the open-range
     * spellings Redis does ('-inf', 'inf', '+inf').
     */
    public function testZRangeByScoreIsInclusiveAndAcceptsInfinities(): void
    {
        $zset = SharedState::PREFIX_PRESENCE.'active';
        SharedState::zAdd($zset, 10, 'uid:a');
        SharedState::zAdd($zset, 20, 'uid:b');
        SharedState::zAdd($zset, 30, 'uid:c');

        $this->assertSame(['uid:a', 'uid:b', 'uid:c'], SharedState::zRangeByScore($zset, 10, 30), 'inclusive on both bounds');
        $this->assertSame(['uid:b'], SharedState::zRangeByScore($zset, 20, 20), 'a degenerate exact-score window');
        $this->assertSame(['uid:a', 'uid:b'], SharedState::zRangeByScore($zset, '-inf', 20), '-inf opens the lower bound');
        $this->assertSame(['uid:b', 'uid:c'], SharedState::zRangeByScore($zset, 20, 'inf'), 'inf opens the upper bound');
        $this->assertSame(['uid:a', 'uid:b', 'uid:c'], SharedState::zRangeByScore($zset, '-inf', '+inf'), 'both infinities return everything');
        $this->assertSame([], SharedState::zRangeByScore($zset, 100, 200), 'a window above the range matches nothing');
    }

    /**
     * F2: ZREM reports how many named members it actually deleted, dropping the
     * key when it empties, and returns 0 once the members are already gone.
     */
    public function testZRemRemovesMembersAndReportsCount(): void
    {
        $zset = SharedState::PREFIX_PRESENCE.'leaving';
        SharedState::zAdd($zset, 1, 'uid:x');
        SharedState::zAdd($zset, 2, 'uid:y');

        $this->assertSame(2, SharedState::zRem($zset, 'uid:x', 'uid:y'), 'both present members are removed and counted');
        $this->assertSame([], SharedState::zRange($zset, 0, -1), 'the emptied zset is gone');
        $this->assertSame(0, SharedState::zRem($zset, 'uid:x'), 'a repeat removal of an absent member counts nothing');
    }

    /**
     * F7: equal scores must order by member name lexicographically (Redis), not
     * by insertion order — ZRANGE and ZRANGEBYSCORE share the guarantee.
     */
    public function testZRangeOrdersByScoreThenLexicographicallyOnTies(): void
    {
        $zset = SharedState::PREFIX_PRESENCE.'ties';
        SharedState::zAdd($zset, 10, 'bbb');
        SharedState::zAdd($zset, 10, 'aaa');
        SharedState::zAdd($zset, 5, 'ccc');

        $this->assertSame(['ccc', 'aaa', 'bbb'], SharedState::zRange($zset, 0, -1), 'score asc, then member lex on ties');
        $this->assertSame(['aaa', 'bbb'], SharedState::zRangeByScore($zset, 10, 10), 'the same tie order across the score-range read');
    }

    // -----------------------------------------------------------------------
    // Transport death & timed recovery — a throwing command must degrade to
    // the SAME fail-safe values the no-client path documents, mark the facade
    // dead for REPROBE_INTERVAL, short-circuit within the window without
    // re-hitting the broken handle, and self-heal via a PINGed shared handle
    // once the window elapses. Clock pinned through setTestClock() so the
    // timeline is deterministic without sleeping.
    // -----------------------------------------------------------------------

    /** Pinned test-clock epoch; the facade arithmetic only ever uses offsets. */
    private const T0 = 1757000000;

    /** Put a throwing-GET handle in place and pin the clock at T0. */
    private function deadTransport(ThrowingGetRedis $throwing): void
    {
        SharedState::setClient($throwing);
        SharedState::setTestClock(self::T0);
    }

    public function testTransportThrowDegradesToFailSafeAndIsNotRetriedInsideTheWindow(): void
    {
        $this->startLogSink();
        $throwing = new ThrowingGetRedis();
        $this->deadTransport($throwing);
        $key = SharedState::PREFIX_STATE.'transport';

        // The throwing read itself: swallowed, documented fail-safe null,
        // transport marked dead, one log line.
        $this->assertNull(SharedState::get($key), 'a throwing GET must degrade to the same fail-safe null the no-client path returns');
        $this->assertSame(1, $throwing->getCalls, 'precondition: the command reached the handle once');
        $this->assertTrue(SharedState::transportFailed(), 'the throw must mark the transport dead');

        // Inside the dead window every op takes the same documented fail-safe
        // branch AND client() short-circuits before the handle: no re-hit, no
        // second throw escaping, no new command on the broken socket.
        $this->assertNull(SharedState::get($key));
        $this->assertFalse(SharedState::set($key, 'x'));
        $this->assertFalse(SharedState::add($key, 'x'));
        $this->assertFalse(SharedState::exists($key));
        SharedState::del($key); // void, must not throw
        $this->assertNull(SharedState::lock('anything', 30));
        $this->assertFalse(SharedState::unlock('anything', 'token'));
        $this->assertFalse(SharedState::renew('anything', 'token', 30));
        $this->assertNull(SharedState::hGet(SharedState::PREFIX_STATE.'hosts', 'f'));
        $this->assertSame([], SharedState::hGetAll(SharedState::PREFIX_STATE.'hosts'));
        $this->assertSame(0, SharedState::hIncr(SharedState::PREFIX_STATE.'counters', 'c'));
        $this->assertSame([], SharedState::lRange(SharedState::PREFIX_CHAT.'room', 0, -1));
        $this->assertSame(0, SharedState::sAdd(SharedState::PREFIX_CHAT.'room', 'm'));
        $this->assertSame([], SharedState::zRange(SharedState::PREFIX_PRESENCE.'idx', 0, -1));
        $this->assertFalse(SharedState::zAdd(SharedState::PREFIX_PRESENCE.'idx', 1, 'm'));

        $this->assertSame(1, $throwing->getCalls, 'the dead window must short-circuit BEFORE touching the throwing handle');

        $contents = (string) @file_get_contents($this->logSinkFile);
        $this->assertSame(
            1,
            substr_count($contents, 'Redis transport failed'),
            'the transport death logs exactly once, not once per fail-safe call'
        );
        $this->assertSame(
            0,
            substr_count($contents, 'Redis unavailable (no client)'),
            'short-circuited calls are suppressed as transport-dead, not mislabelled "no client" — the transport line owns this cycle'
        );
    }

    public function testNamespaceAndLockGuardsStayLoudWhileTransportIsDead(): void
    {
        $throwing = new ThrowingGetRedis();
        $this->deadTransport($throwing);
        $this->assertNull(SharedState::get(SharedState::PREFIX_STATE.'transport'), 'precondition: transport marked dead');

        // Misuse must throw BEFORE any client resolution even in the dead
        // window — an outage never silences a programmer error.
        $offenders = [
            'get' => static fn () => SharedState::get('bogus:key'),
            'set' => static fn () => SharedState::set('bogus:key', 1),
            'lock-empty-name' => static fn () => SharedState::lock('', 30),
            'lock-zero-ttl' => static fn () => SharedState::lock('job', 0),
            'unlock-empty-name' => static fn () => SharedState::unlock(''),
            'renew-zero-ttl' => static fn () => SharedState::renew('job', 'token', 0),
            'rPushLtrim-unbounded' => static fn () => SharedState::rPushLtrim(SharedState::PREFIX_CHAT.'room', 'v', 0),
        ];
        foreach ($offenders as $label => $invoke) {
            try {
                $invoke();
                $this->fail("SharedState::{$label} must keep throwing while the transport is dead");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('SharedState', $e->getMessage());
            }
        }

        $this->assertSame(1, $throwing->getCalls, 'the guards fire before the client is ever resolved');
    }

    public function testReprobeRecoversThroughSharedHandleAnsweringPing(): void
    {
        $throwing = new ThrowingGetRedis();
        $this->deadTransport($throwing);
        $key = SharedState::PREFIX_STATE.'transport:recovered';
        $this->assertNull(SharedState::get($key), 'precondition: transport dead at T0');

        // Redis comes back and the shared global handle survives (phpredis
        // re-handshakes internally once the server returns).
        $handle = new LiveHandleRedis();
        $handle->data[$key] = json_encode('recovered');
        $GLOBALS['redis'] = $handle;

        try {
            // One second short of the window: still fail-safe, still no probe.
            SharedState::setTestClock(self::T0 + SharedState::REPROBE_INTERVAL - 1);
            $this->assertNull(SharedState::get($key), 'the dead window holds until it fully elapses');
            $this->assertSame(0, $handle->pings, 'no liveness probe before the window elapses');
            $this->assertSame(1, $throwing->getCalls, 'the dead handle stays untouched inside the window');

            // Window elapsed: client() re-probes the shared handle ONCE and resumes from it.
            SharedState::setTestClock(self::T0 + SharedState::REPROBE_INTERVAL);
            $this->assertSame('recovered', SharedState::get($key), 'a PING that answers resumes reads through the shared handle');
            $this->assertFalse(SharedState::transportFailed(), 'a successful re-probe clears the dead mark');
            $this->assertSame(1, $handle->pings, 'exactly one probe for the elapsed window');
            $this->assertSame(1, $throwing->getCalls, 'the recovery rides the shared handle, never the dead double');

            // Recovery is durable: later ops skip the probe entirely and writes flow.
            $other = SharedState::PREFIX_STATE.'transport:after-recovery';
            $this->assertTrue(SharedState::set($other, 'written'), 'writes resume through the PINGed handle');
            $this->assertSame('written', SharedState::get($other));
            $this->assertSame(1, $handle->pings, 'no per-call PING once healthy — the probe is a recovery affordance, not overhead');
        } finally {
            unset($GLOBALS['redis']);
        }
    }

    public function testFailedReprobeReMarksDeadAndALaterWindowStillRecovers(): void
    {
        $throwing = new ThrowingGetRedis();
        $this->deadTransport($throwing);
        $key = SharedState::PREFIX_STATE.'transport:patient';
        $this->assertNull(SharedState::get($key), 'precondition: transport dead at T0 (marked by the throwing double)');

        $dead = new DeadHandleRedis();
        $GLOBALS['redis'] = $dead;
        try {
            // Redis still down at the first window: the PING throws, the mark
            // is re-set, and NOTHING escapes.
            SharedState::setTestClock(self::T0 + SharedState::REPROBE_INTERVAL);
            $this->assertNull(SharedState::get($key), 'a throwing re-probe stays fail-safe');
            $this->assertSame(1, $dead->pings, 'the re-probe went to the shared handle');
            $this->assertSame(1, $throwing->getCalls, 'the dead fallback is not consulted within the grace windows — no fresh socket while the shared handle may still recover');
            $this->assertTrue(SharedState::transportFailed(), 'the failed probe re-marks the transport dead');

            // Still inside the SECOND window: short-circuit, no extra probe.
            SharedState::setTestClock(self::T0 + 2 * SharedState::REPROBE_INTERVAL - 1);
            $this->assertNull(SharedState::get($key));
            $this->assertSame(1, $dead->pings, 'one probe per elapsed window, never per call');

            // Redis returns before the next window: the dead mark was timed,
            // not sticky — recovery happens without a process restart.
            $live = new LiveHandleRedis();
            $live->data[$key] = json_encode('back');
            $GLOBALS['redis'] = $live;
            SharedState::setTestClock(self::T0 + 2 * SharedState::REPROBE_INTERVAL);
            $this->assertSame('back', SharedState::get($key), 'a later window heals a transport that was dead across two failures');
            $this->assertFalse(SharedState::transportFailed());
            $this->assertSame(1, $live->pings);
        } finally {
            unset($GLOBALS['redis']);
        }
    }

    public function testFreshClientInjectionClearsTransportDeathImmediately(): void
    {
        $throwing = new ThrowingGetRedis();
        $this->deadTransport($throwing);
        $this->assertNull(SharedState::get(SharedState::PREFIX_STATE.'transport'));
        $this->assertTrue(SharedState::transportFailed(), 'precondition: transport dead');

        // setClient replaces the handle, so the old distrust says nothing
        // about the new one: the mark must not survive the injection.
        SharedState::setClient($this->redis);
        $this->assertFalse(SharedState::transportFailed(), 'injecting a client clears the dead mark without waiting for the window');

        $key = SharedState::PREFIX_STATE.'transport:fresh';
        $this->assertTrue(SharedState::set($key, 'healthy'));
        $this->assertSame('healthy', SharedState::get($key));
        $this->assertSame(1, $throwing->getCalls, 'traffic flows to the injected client only');
        $this->assertSame(0, $throwing->pingCalls, 'an injected handle is trusted without a verification PING');
    }

    // -----------------------------------------------------------------------
    // C1 — bounded prefer-fallback: self-heal must not depend on the shared
    // handle's own re-handshake. Two consecutive failed global PING windows
    // deprioritize $GLOBALS['redis'] for the process (never closed, never
    // replaced, never re-PINGed) and the facade heals through its OWN fresh
    // connect, PING-guarded at adoption. Timeline driven by setTestClock;
    // the connect outcome injected by setConnectFactory (test seam).
    // -----------------------------------------------------------------------

    public function testSharedHandleDeprioritizedAfterTwoFailedWindowsAndLazyFallbackServesReads(): void
    {
        SharedState::reset(); // no fallback handle: resolution must reach the lazy connect
        SharedState::setTestClock(self::T0);

        $dead = new DeadHandleRedis();
        $GLOBALS['redis'] = $dead;

        $key = SharedState::PREFIX_STATE.'c1';
        $fallback = new FallbackFactoryRedis();
        $fallback->data[$key] = json_encode('served-by-fallback');
        $factoryCalls = 0;
        SharedState::setConnectFactory(function () use ($fallback, &$factoryCalls) {
            $factoryCalls++;

            return $fallback;
        });

        // The window opens THROUGH the preferred global: its GET throws.
        $this->assertNull(SharedState::get($key), 'a command on the shared global degrades fail-safe and marks the window');
        $this->assertSame(0, $dead->pings, 'the first window opened on the command, not on a PING');

        // Grace window 1: the shared PING fails once; no fresh socket yet.
        SharedState::setTestClock(self::T0 + SharedState::REPROBE_INTERVAL);
        $this->assertNull(SharedState::get($key));
        $this->assertSame(1, $dead->pings, 'window 1 re-probes the shared handle');
        $this->assertSame(0, $factoryCalls, 'the lazy connect is NOT consulted within the grace windows');

        // Grace window 2: the SECOND consecutive failure exhausts grace — and
        // still spends its window on the shared handle (the fallback opens
        // only on the NEXT elapsed window, once the global is deprioritized).
        SharedState::setTestClock(self::T0 + 2 * SharedState::REPROBE_INTERVAL);
        $this->assertNull(SharedState::get($key));
        $this->assertSame(2, $dead->pings, 'window 2 re-probes the shared handle once more');
        $this->assertSame(0, $factoryCalls, 'the grace-losing PING never reaches the fallback');
        $this->assertTrue(SharedState::transportFailed(), 'the failed probe re-marks the transport dead');

        // Window 3, deprioritized: the global is skipped entirely and the
        // facade's own fresh handle SERVES the read — self-heal independent
        // of any phpredis re-handshake.
        SharedState::setTestClock(self::T0 + 3 * SharedState::REPROBE_INTERVAL);
        $this->assertSame('served-by-fallback', SharedState::get($key), 'after 2 failed global PINGs the lazy fallback path serves reads');
        $this->assertFalse(SharedState::transportFailed(), 'the fresh internal handle heals the transport — no restart');
        $this->assertSame(2, $dead->pings, 'a deprioritized global is never PINGed again');
        $this->assertSame(1, $factoryCalls, 'one connect attempt for the elapsed window');

        // Durable: later traffic rides the adopted handle — no reconnect churn,
        // and the shared global is left exactly as it was (never closed/reassigned).
        $other = SharedState::PREFIX_STATE.'c1-after';
        $this->assertTrue(SharedState::set($other, 'written'), 'writes resume through the fallback');
        $this->assertSame('written', SharedState::get($other));
        $this->assertSame(2, $dead->pings);
        $this->assertSame(1, $factoryCalls);
        $this->assertSame($dead, $GLOBALS['redis'], 'the shared handle object itself is never replaced or closed');
    }

    public function testFallbackPingFailureDropsTheOldHandleAndAFreshOneServesTheSameWindow(): void
    {
        // A fallback the facade adopted earlier can itself go stale once the
        // global is deprioritized. The re-probe must PING-verify it, DROP
        // (never close) the dead one, and open a fresh candidate — all inside
        // the elapsed window, one attempt each.
        $stale = new DeadFallbackRedis();
        SharedState::reset();
        SharedState::setTestClock(self::T0);
        SharedState::setClient($stale); // a fallback adopted earlier
        $dead = new DeadHandleRedis();
        $GLOBALS['redis'] = $dead;

        $key = SharedState::PREFIX_STATE.'c1-drop';
        $fresh = new FallbackFactoryRedis();
        $fresh->data[$key] = json_encode('served-by-fresh');
        $factoryCalls = 0;
        SharedState::setConnectFactory(function () use ($fresh, &$factoryCalls) {
            $factoryCalls++;

            return $fresh;
        });

        // Outage rides the preferred global; two grace windows spend the
        // streak on shared PINGs — the stale fallback is never touched.
        $this->assertNull(SharedState::get($key), 'precondition: the dead global opens the window');
        SharedState::setTestClock(self::T0 + SharedState::REPROBE_INTERVAL);
        $this->assertNull(SharedState::get($key));
        $this->assertSame(1, $dead->pings);
        SharedState::setTestClock(self::T0 + 2 * SharedState::REPROBE_INTERVAL);
        $this->assertNull(SharedState::get($key));
        $this->assertSame(2, $dead->pings);
        $this->assertSame(0, $factoryCalls);
        $this->assertSame(0, $stale->getCalls, 'the grace windows never touch the fallback');

        // Deprioritized window: verification PING fails on the stale handle,
        // it is dropped, and one fresh connect serves THIS window.
        SharedState::setTestClock(self::T0 + 3 * SharedState::REPROBE_INTERVAL);
        $this->assertSame('served-by-fresh', SharedState::get($key), 'the dead fallback is replaced in the same window it fails verification');
        $this->assertSame(1, $stale->pingCalls, 'the stale handle was PING-verified once before the drop');
        $this->assertSame(0, $stale->getCalls, 'a dropped handle receives no further commands');
        $this->assertSame(1, $factoryCalls, 'one fresh connect for the elapsed window');
        $this->assertFalse(SharedState::transportFailed(), 'recovery through the replacement is final — the drop alone does not re-mark the window');

        // Durable on the replacement.
        $this->assertSame('served-by-fresh', SharedState::get($key));
        $this->assertSame(1, $factoryCalls);
        $this->assertSame(0, $stale->getCalls);
    }

    public function testSharedPingSuccessWithinGraceClearsTheStreak(): void
    {
        // "If the global PING succeeds again sooner, resume it immediately and
        // clear the counter": one failure banks nothing permanent — a later
        // outage gets its OWN two full grace windows before deprioritization.
        SharedState::reset();
        SharedState::setTestClock(self::T0);
        $GLOBALS['redis'] = new DeadHandleRedis();

        $key = SharedState::PREFIX_STATE.'c1-clear';
        $fallback = new FallbackFactoryRedis();
        $fallback->data[$key] = json_encode('should-not-be-needed');
        $factoryCalls = 0;
        SharedState::setConnectFactory(function () use ($fallback, &$factoryCalls) {
            $factoryCalls++;

            return $fallback;
        });

        $this->assertNull(SharedState::get($key), 'precondition: the dead global opens the window');

        // Window 1: fail (streak 1 of 2).
        SharedState::setTestClock(self::T0 + SharedState::REPROBE_INTERVAL);
        $this->assertNull(SharedState::get($key));

        // Window 2: Redis is back — the shared PING answers, the streak
        // clears, traffic resumes on the SAME global.
        $live = new LiveHandleRedis();
        $live->data[$key] = json_encode('resumed-global');
        $GLOBALS['redis'] = $live;
        SharedState::setTestClock(self::T0 + 2 * SharedState::REPROBE_INTERVAL);
        $this->assertSame('resumed-global', SharedState::get($key), 'a successful re-probe inside grace resumes the shared handle at once');
        $this->assertSame(0, $factoryCalls, 'recovery on the global never opens a fallback socket');

        // A SECOND outage now: the cleared streak buys two fresh grace windows,
        // so the fallback must stay unconsulted until the third elapsed window.
        $deadAgain = new DeadHandleRedis();
        $GLOBALS['redis'] = $deadAgain;
        $this->assertNull(SharedState::get($key), 'precondition: the second outage marks the transport dead');

        SharedState::setTestClock(self::T0 + 3 * SharedState::REPROBE_INTERVAL);
        $this->assertNull(SharedState::get($key));
        $this->assertSame(1, $deadAgain->pings, 'outage 2 gets its own grace window 1');
        $this->assertSame(0, $factoryCalls);

        SharedState::setTestClock(self::T0 + 4 * SharedState::REPROBE_INTERVAL);
        $this->assertNull(SharedState::get($key));
        $this->assertSame(2, $deadAgain->pings, 'outage 2 gets its own grace window 2');
        $this->assertSame(0, $factoryCalls, 'the streak from outage 1 did not shorten outage 2 grace');

        SharedState::setTestClock(self::T0 + 5 * SharedState::REPROBE_INTERVAL);
        $this->assertSame('should-not-be-needed', SharedState::get($key), 'only after TWO fresh consecutive failures is the global deprioritized');
        $this->assertSame(1, $factoryCalls);
        $this->assertSame(2, $deadAgain->pings, 'deprioritized from here on');
    }

    /**
     * m1: the no-client line and the transport line carry DIFFERENT diagnoses
     * and use separate once-guards — a process that logged "no client" first
     * (Redis absent at boot) must STILL state the first genuine transport
     * reason when the transport later exists and dies.
     */
    public function testTransportReasonSurvivesAnEarlierNullClientLog(): void
    {
        $this->startLogSink();

        SharedState::reset(); // no client, no global, no factory: STATE, not a throw
        $this->assertNull(SharedState::get(SharedState::PREFIX_STATE.'m1'), 'precondition: no-client fail-safe');
        $this->assertNull(SharedState::get(SharedState::PREFIX_STATE.'m1'), 'the no-client reason already spoke — silence after');

        // Redis becomes configured-but-dead mid-process: the transport throw
        // must not inherit the no-client memo.
        $GLOBALS['redis'] = new DeadHandleRedis();
        $this->assertNull(SharedState::get(SharedState::PREFIX_STATE.'m1'), 'the throwing global degrades fail-safe');

        $contents = (string) @file_get_contents($this->logSinkFile);
        $this->assertSame(1, substr_count($contents, 'Redis unavailable (no client)'), 'the no-client line stays once-guarded');
        $this->assertSame(
            1,
            substr_count($contents, 'Redis transport failed'),
            'the transport reason logs once on its OWN guard despite the earlier no-client line'
        );
        $this->assertStringContainsString('simulated: server still down on GET', $contents, 'the genuine throw reason must survive');
    }
}
