<?php

use PHPUnit\Framework\TestCase;

// TestBootstrap declares the suite-wide seams (the \Channel\Client tripwire, the
// recording TestTimer loop, the InMemoryRedis double) and predefines
// GLOBALDATA_IP. FeatureFlags itself no longer needs any of that — it goes
// through SharedState now — but the bootstrap must still load first because
// PHPUnit shares one process across the whole suite and sibling suites still
// depend on those seams. SharedState is required explicitly so this suite
// runs standalone.
require_once __DIR__.'/TestBootstrap.php';
require_once __DIR__.'/../Applications/Chat/SharedState.php';
require_once __DIR__.'/../Applications/Chat/FeatureFlags.php';

/**
 * Minimal in-file Redis double for the flag surface (shared with no other
 * suite by design — tests/TestBootstrap.php belongs to the shared doubles).
 *
 * Implements only the phpredis methods SharedState exercises for flags:
 * GET (missing key => false, never null), SET (values coerced to strings,
 * options array honoured for the nx flag with exact true/false replies),
 * DEL (variadic). Every SET call records the options array it received so
 * tests can prove flags are written with NO TTL (they persist). Anything
 * beyond this surface must never be reached by FeatureFlags — calling an
 * unimplemented method fatals loudly, which is the point of keeping it small.
 */
class FakeFlagRedis
{
    /** @var array<string,string> raw keyspace (phpredis stores strings) */
    public $data = [];

    /** @var array<string,array|null> options seen on the last SET per key */
    public $setOpts = [];

    public function get($key)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : false;
    }

    public function set($key, $value, $options = null)
    {
        $nx = false;
        if (is_array($options)) {
            foreach ($options as $name => $opt) {
                $flag = strtolower(is_int($name) ? (string) $opt : $name);
                if ($flag === 'nx') {
                    $nx = true;
                }
            }
        }
        if ($nx && array_key_exists($key, $this->data)) {
            return false;
        }
        $this->data[$key] = (string) $value;
        $this->setOpts[$key] = $options;

        return true;
    }

    public function del(...$keys)
    {
        $deleted = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->data)) {
                unset($this->data[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }
}

/**
 * A Redis whose every command throws — the genuine "unreachable/broken"
 * transport. Since the transport-recovery hardening, SharedState wraps the
 * throw into its fail-safe return and marks itself transport-dead;
 * FeatureFlags::guardTransport()/guardWrite() detect that signal and re-raise
 * into the class's catch (\Throwable) branches, which hold the documented
 * fail-safe defaults. Observable contract unchanged: throwing commands still
 * yield A OFF, B/C ON, writers false.
 */
class ThrowingFlagRedis
{
    public function get($key)
    {
        throw new \RuntimeException('Redis is unreachable (simulated get failure)');
    }

    public function set($key, $value, $options = null)
    {
        throw new \RuntimeException('Redis is unreachable (simulated set failure)');
    }

    public function del(...$keys)
    {
        throw new \RuntimeException('Redis is unreachable (simulated del failure)');
    }
}

/**
 * Tests for Applications/Chat/FeatureFlags.php (WS-revamp Phase 0.5, plan B8),
 * ported from the retired GlobalData backend to the SharedState Redis facade.
 *
 * Two layers:
 *   1. Fail-safe layer — with NO usable Redis (client() resolves null, or
 *      every command throws), flags MUST report the fail-safe direction:
 *      Flag A OFF, Flags B/C ON, writers false. This is the core "ship
 *      dormant" guarantee and is the most important thing proven here.
 *   2. Logic layer — injecting a FakeFlagRedis through SharedState::setClient()
 *      (with reset() in setUp/tearDown), we prove global toggle, per-host
 *      override precedence, override clearing, and Flag B/C toggling all
 *      behave as documented, under the dc:flag: namespace and with no TTL.
 *   3. Logging layer — fail-safe log lines are emitted at most once per
 *      accessor AND per writer (logFailSafeOnce guard), so hot reads and
 *      looping operator scripts can never flood stderr while Redis is down.
 */
class FeatureFlagsTest extends TestCase
{
    /** @var FakeFlagRedis the double injected by setUp() for logic-layer tests */
    private $redis;

    /** @var string|null sink file, set only by the once-guard test */
    private $logSinkFile = null;

    /** @var string|null the process error_log ini value captured before redirecting to the sink */
    private $previousErrorLog = null;

    /** @var array|null snapshot of Workerman's static worker registry, neutralized while the sink is open */
    private $workersSnapshot = null;

    /** @var mixed previous value of Workerman's public static $outputStream (write target of safeEcho) */
    private $previousOutputStream = null;

    /** @var resource|null the fopen() handle installed as Worker::$outputStream while the sink is open */
    private $logSinkStream = null;

    /**
     * Every test starts from "no shared connection and no injected client":
     * a leaked $GLOBALS['redis'] or leftover facade memo from another suite
     * must never decide flag behavior here. Logic-layer tests then get the
     * FakeFlagRedis; fail-safe tests call withoutRedis() to drop it again.
     */
    protected function setUp(): void
    {
        unset($GLOBALS['redis']);
        SharedState::reset();
        $this->redis = new FakeFlagRedis();
        SharedState::setClient($this->redis);
    }

    protected function tearDown(): void
    {
        $this->stopLogSink();
        SharedState::reset();
        unset($GLOBALS['redis']);
    }

    /** Drop the injected double so SharedState resolves the null client. */
    private function withoutRedis(): void
    {
        SharedState::reset();
    }

    /** Re-inject the setUp double after a withoutRedis() excursion. */
    private function withRedis(): void
    {
        SharedState::setClient($this->redis);
    }

    // ----------------------------------------------------------------------
    // Layer 1: fail-safe / default behavior (the ship-dormant guarantee, B8)
    // ----------------------------------------------------------------------

    /**
     * With no usable Redis (client() resolves null), useNewHandling() must
     * return false for a null host — i.e. the legacy path stays active.
     */
    public function testUseNewHandlingDefaultsOffWhenRedisUnavailable(): void
    {
        $this->withoutRedis();

        $this->assertFalse(
            FeatureFlags::useNewHandling(),
            'Flag A must default OFF when Redis is unavailable (null host)'
        );
    }

    /**
     * Same guarantee for arbitrary host ids with no override present: OFF.
     */
    public function testUseNewHandlingDefaultsOffForArbitraryHostsWhenUnavailable(): void
    {
        $this->withoutRedis();

        foreach (['host123', 'otherhost', '10.0.0.5', 42, 'web-node-a'] as $hostId) {
            $this->assertFalse(
                FeatureFlags::useNewHandling($hostId),
                "Flag A must default OFF for host '{$hostId}' when Redis is unavailable"
            );
        }
    }

    /**
     * With no usable Redis, legacyCompatEnabled() must return true —
     * legacy compat stays ON, exactly today's behavior.
     */
    public function testLegacyCompatDefaultsOnWhenRedisUnavailable(): void
    {
        $this->withoutRedis();

        $this->assertTrue(
            FeatureFlags::legacyCompatEnabled(),
            'Flag B must default ON when Redis is unavailable'
        );
    }

    /**
     * Explicitly exercise the exception path: a Redis whose commands all throw
     * (mimicking a broken/dropped connection) must be swallowed and yield the
     * fail-safe defaults — including the write side, which fails closed.
     */
    public function testExceptionDuringReadIsSwallowedToFailSafeDefaults(): void
    {
        SharedState::setClient(new ThrowingFlagRedis());

        $this->assertFalse(
            FeatureFlags::useNewHandling('anyhost'),
            'A throwing Redis client must make Flag A default OFF (per-host read)'
        );
        $this->assertFalse(
            FeatureFlags::useNewHandling(),
            'A throwing Redis client must make Flag A (global) default OFF'
        );
        $this->assertTrue(
            FeatureFlags::legacyCompatEnabled(),
            'A throwing Redis client must make Flag B default ON'
        );
        $this->assertTrue(
            FeatureFlags::dcBotPresenceEnabled(),
            'A throwing Redis client must make Flag C default ON'
        );
        $this->assertFalse(FeatureFlags::setNewHandling(null, true), 'writers fail closed on a throwing client');
        $this->assertFalse(FeatureFlags::setLegacyCompat(false), 'writers fail closed on a throwing client');
        $this->assertFalse(FeatureFlags::setDcBotPresence(true), 'writers fail closed on a throwing client');
        $this->assertFalse(FeatureFlags::clearNewHandlingOverride('host123'), 'clear fails closed on a throwing client');
    }

    /**
     * Writers must fail closed (return false) when Redis is unavailable
     * rather than throw — belt-and-suspenders for operator tooling.
     */
    public function testWritersReturnFalseWhenRedisUnavailable(): void
    {
        $this->withoutRedis();

        $this->assertFalse(FeatureFlags::setNewHandling(null, true));
        $this->assertFalse(FeatureFlags::setLegacyCompat(false));
        $this->assertFalse(FeatureFlags::setDcBotPresence(true));
        // clearNewHandlingOverride returns false only when no client; still no throw.
        $this->assertFalse(FeatureFlags::clearNewHandlingOverride('host123'));
    }

    // ----------------------------------------------------------------------
    // Layer 2: toggle / override logic (in-memory Redis double injected)
    // ----------------------------------------------------------------------

    public function testTogglingFlagAGloballyWorks(): void
    {
        // Starts ON (no key set, new handling is the default).
        $this->assertTrue(FeatureFlags::useNewHandling());

        $this->assertTrue(FeatureFlags::setNewHandling(null, true));
        $this->assertTrue(
            FeatureFlags::useNewHandling(),
            'After setNewHandling(null, true), global Flag A must read ON'
        );
        $this->assertTrue(
            FeatureFlags::useNewHandling('some_host_without_override'),
            'Hosts without an override must inherit the global ON value'
        );

        // Turn back off.
        $this->assertTrue(FeatureFlags::setNewHandling(null, false));
        $this->assertFalse(FeatureFlags::useNewHandling());
        $this->assertSame('0', $this->redis->data[FeatureFlags::VAR_NEW_HANDLING], 'flag persists as JSON int 0 on Redis');
    }

    /**
     * With a usable Redis and NO dc:flag:ws_new_handling key set, Flag A
     * reads ON.
     *
     * This is the shipped contract — useNewHandling() ends with
     * "$globalDefault === null ? true : (bool) $globalDefault", and its own
     * @return tag says "true when unset (new handling is the default)" (the
     * v1 handler was enabled by default in commit 9eabb50). The class-level
     * docblock once claimed "missing = 0 (OFF)"; that drift was reconciled in
     * the SharedState migration by matching the header to the code, and this
     * test pins the resulting behavior so the drift cannot be mistaken for a
     * regression in either direction.
     */
    public function testUnsetFlagAReadsOnWhenRedisIsUsable(): void
    {
        $this->assertTrue(
            FeatureFlags::useNewHandling(),
            'unset Flag A + usable Redis => Flag A ON (new handling is the default)'
        );
        $this->assertTrue(
            FeatureFlags::useNewHandling('host_without_override'),
            'a host with no override inherits the unset-global default, i.e. ON'
        );
    }

    public function testPerHostOverridePrecedence(): void
    {
        // Global explicitly OFF (unset would mean ON — see
        // testUnsetFlagAReadsOnWhenRedisIsUsable), plus a per-host override ON.
        $this->assertTrue(FeatureFlags::setNewHandling(null, false));
        $this->assertTrue(FeatureFlags::setNewHandling('host123', true));

        $this->assertTrue(
            FeatureFlags::useNewHandling('host123'),
            'Overridden host must read ON regardless of global'
        );
        $this->assertFalse(
            FeatureFlags::useNewHandling('otherhost'),
            'Non-overridden host must inherit the explicit global OFF'
        );
        $this->assertFalse(
            FeatureFlags::useNewHandling(),
            'Null host (global) must remain OFF'
        );
        $this->assertSame(0, SharedState::get(FeatureFlags::VAR_NEW_HANDLING));
        $this->assertSame(
            '1',
            $this->redis->data[FeatureFlags::hostVar('host123')],
            'the override lives under its own dc:flag: key'
        );
    }

    public function testPerHostOverrideCanForceOffWhileGlobalOn(): void
    {
        // Global ON, but one host explicitly overridden OFF.
        $this->assertTrue(FeatureFlags::setNewHandling(null, true));
        $this->assertTrue(FeatureFlags::setNewHandling('host123', false));

        $this->assertFalse(
            FeatureFlags::useNewHandling('host123'),
            'Host overridden OFF must read OFF even when global is ON'
        );
        $this->assertTrue(
            FeatureFlags::useNewHandling('otherhost'),
            'Non-overridden host must still inherit global ON'
        );
    }

    public function testClearingOverrideRevertsToGlobal(): void
    {
        // Global explicitly OFF, host overridden ON, then cleared -> reverts to global OFF.
        FeatureFlags::setNewHandling(null, false);
        FeatureFlags::setNewHandling('host123', true);
        $this->assertTrue(FeatureFlags::useNewHandling('host123'));

        $this->assertTrue(FeatureFlags::clearNewHandlingOverride('host123'));
        $this->assertArrayNotHasKey(
            FeatureFlags::hostVar('host123'),
            $this->redis->data,
            'clear must DEL the override key, not zero it'
        );
        $this->assertFalse(
            FeatureFlags::useNewHandling('host123'),
            'After clearing override, host must inherit current global (OFF)'
        );

        // Now flip global ON: cleared host must track it.
        FeatureFlags::setNewHandling(null, true);
        $this->assertTrue(
            FeatureFlags::useNewHandling('host123'),
            'After clearing override, host must inherit current global (ON)'
        );
    }

    public function testClearingNonexistentOverrideIsNoOpAndSucceeds(): void
    {
        FeatureFlags::setNewHandling(null, false);
        $this->assertTrue(
            FeatureFlags::clearNewHandlingOverride('never_set_host'),
            'Clearing an absent override must succeed (idempotent)'
        );
        $this->assertFalse(FeatureFlags::useNewHandling('never_set_host'));
    }

    public function testFlagBToggling(): void
    {
        // Default ON when unset.
        $this->assertTrue(FeatureFlags::legacyCompatEnabled());

        $this->assertTrue(FeatureFlags::setLegacyCompat(false));
        $this->assertFalse(
            FeatureFlags::legacyCompatEnabled(),
            'After setLegacyCompat(false), Flag B must read OFF'
        );
        $this->assertSame(0, SharedState::get(FeatureFlags::VAR_LEGACY_COMPAT));

        $this->assertTrue(FeatureFlags::setLegacyCompat(true));
        $this->assertTrue(
            FeatureFlags::legacyCompatEnabled(),
            'After setLegacyCompat(true), Flag B must read ON again'
        );
    }

    // ----------------------------------------------------------------------
    // hostVar name normalization (now producing full dc:flag: keys)
    // ----------------------------------------------------------------------

    public function testHostVarNormalizesUnsafeCharacters(): void
    {
        $this->assertSame('dc:flag:ws_new_handling_host_10_0_0_5', FeatureFlags::hostVar('10.0.0.5'));
        $this->assertSame('dc:flag:ws_new_handling_host_web_node_a', FeatureFlags::hostVar('web-node-a'));
        $this->assertSame('dc:flag:ws_new_handling_host_42', FeatureFlags::hostVar(42));
        $this->assertSame('dc:flag:ws_new_handling_host_abc_DEF_9', FeatureFlags::hostVar('abc DEF!9'));
    }

    /**
     * Two host ids that normalize to the same safe suffix must share an
     * override slot — documents the (intended) collision behavior of hostVar().
     */
    public function testHostVarCollisionForEquivalentNormalizedIds(): void
    {
        // Global explicitly OFF so a true result can ONLY come from the shared
        // override — without this the unset-global default (ON) would make this
        // test pass whether or not the collision happens.
        FeatureFlags::setNewHandling(null, false);
        FeatureFlags::setNewHandling('10.0.0.5', true);
        $this->assertTrue(
            FeatureFlags::useNewHandling('10-0-0-5'),
            'Ids normalizing to the same key suffix intentionally share an override'
        );
        $this->assertFalse(
            FeatureFlags::useNewHandling('10_0_0_6'),
            'a host that normalizes differently must NOT pick up the override'
        );
    }

    // ----------------------------------------------------------------------
    // Namespace / persistence hygiene of the migrated keys
    // ----------------------------------------------------------------------

    /**
     * Every key FeatureFlags writes must live in the SharedState dc:flag:
     * namespace (the guardKey contract made real) and must be written with
     * NO TTL — feature flags persist until an operator toggles them.
     */
    public function testAllFlagWritesUseDcFlagNamespaceAndPersistWithoutTtl(): void
    {
        $this->assertTrue(FeatureFlags::setNewHandling(null, true));
        $this->assertTrue(FeatureFlags::setNewHandling('host123', true));
        $this->assertTrue(FeatureFlags::setLegacyCompat(true));
        $this->assertTrue(FeatureFlags::setDcBotPresence(true));

        $keys = array_keys($this->redis->data);
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertStringStartsWith('dc:flag:', $key, "flag key '{$key}' escaped the dc:flag: namespace");
            $this->assertNull(
                $this->redis->setOpts[$key] ?? null,
                "flag key '{$key}' was written with SET options (a TTL) — flags must persist"
            );
        }
        $this->assertSame(
            [
                FeatureFlags::VAR_NEW_HANDLING,
                FeatureFlags::hostVar('host123'),
                FeatureFlags::VAR_LEGACY_COMPAT,
                FeatureFlags::VAR_DC_BOT_PRESENCE,
            ],
            $keys,
            'the exact key set FeatureFlags writes, in write order'
        );
    }

    // ----------------------------------------------------------------------
    // Transport hygiene: the flag reader must never reach for GlobalData
    // ----------------------------------------------------------------------

    /**
     * The migrated FeatureFlags must NEVER construct a \GlobalData\Client,
     * require the production settings file, or reference the retired
     * transport at all. On the pre-migration baseline, every flag read
     * without an injected $global fell through to
     * `new \GlobalData\Client(GLOBALDATA_IP.':2207')` — one leaked socket
     * per read against the PRODUCTION GlobalData server, plus the whole
     * config.settings.php constant surface dragged into the process.
     *
     * The offline \GlobalData\Client test stub was deleted in migration wave
     * 5.1, so nothing pre-declares that class any more. What still matters: a
     * flag accessor must never pull the real socket-opening client in (it stays
     * out of get_included_files AND is absent from the process without an
     * autoload trigger), and the source-level pins below keep the lazy fallback
     * from coming back.
     */
    public function testFlagAccessorsNeverConstructGlobalDataClientOrRequireProductionSettings(): void
    {
        $this->withoutRedis();

        // Exercise every accessor through the null-client fail-safe path.
        FeatureFlags::useNewHandling();
        FeatureFlags::useNewHandling('host123');
        FeatureFlags::legacyCompatEnabled();
        FeatureFlags::dcBotPresenceEnabled();
        FeatureFlags::setNewHandling(null, true);
        FeatureFlags::setLegacyCompat(false);
        FeatureFlags::setDcBotPresence(true);
        FeatureFlags::clearNewHandlingOverride('host123');

        $loaded = get_included_files();
        $this->assertNotContains(
            '/home/my/include/config/config.settings.php',
            $loaded,
            'the production settings file must never be pulled into the test process'
        );

        // Structural guarantee that still holds suite-wide: the REAL socket-
        // opening client was never autoloaded by a flag read.
        $this->assertSame(
            [],
            array_values(array_filter(
                $loaded,
                static fn (string $f) => str_contains($f, 'workerman/globaldata/src/Client.php')
            )),
            'the real socket-opening \\GlobalData\\Client must never be loaded in tests'
        );

        // Wave 5.1 removed the offline stub, so \GlobalData\Client is no longer
        // pre-declared. Probed WITHOUT an autoload trigger it must resolve to
        // nothing in memory right now. This holds today (the vendor class exists
        // but no flag read pulls it in) and after wave 4.2 deletes the
        // workerman/globaldata package entirely (the class is then simply absent).
        $this->assertFalse(
            class_exists('GlobalData\Client', false),
            '\\GlobalData\\Client must not be present in the process without an autoload '
            .'trigger now that the offline test stub is gone — a flag accessor that pulled it '
            .'in would open a live socket'
        );

        // Source-level pin: the migrated reader carries no GlobalData wiring
        // whatsoever — no client reference, no GLOBALDATA_IP, no settings require.
        $source = (string) file_get_contents(__DIR__.'/../Applications/Chat/FeatureFlags.php');
        $this->assertStringNotContainsString('\\GlobalData\\Client', $source);
        $this->assertStringNotContainsString('GLOBALDATA_IP', $source);
        $this->assertStringNotContainsString('config.settings.php', $source);
    }

    /**
     * Flag C (DC bot presence) fails SAFE to ON when Redis is unreachable,
     * and reads/writes correctly through the injected double. Flag C gates
     * bot spawn/move/cleanup in Events.php, so its default is load-bearing.
     */
    public function testDcBotPresenceFlag(): void
    {
        $this->withoutRedis();
        $this->assertTrue(
            FeatureFlags::dcBotPresenceEnabled(),
            'Flag C must default ON when Redis is unavailable'
        );
        $this->assertFalse(
            FeatureFlags::setDcBotPresence(false),
            'writers fail closed (return false) with no usable Redis'
        );

        $this->withRedis();
        $this->assertTrue(FeatureFlags::dcBotPresenceEnabled(), 'unset dc_bot_presence => ON');

        $this->assertTrue(FeatureFlags::setDcBotPresence(false));
        $this->assertSame('0', $this->redis->data[FeatureFlags::VAR_DC_BOT_PRESENCE]);
        $this->assertSame(0, SharedState::get(FeatureFlags::VAR_DC_BOT_PRESENCE));
        $this->assertFalse(FeatureFlags::dcBotPresenceEnabled());

        $this->assertTrue(FeatureFlags::setDcBotPresence(true));
        $this->assertTrue(FeatureFlags::dcBotPresenceEnabled());
    }

    // ----------------------------------------------------------------------
    // Layer 3: fail-safe logging once-guard (stderr flood protection)
    // ----------------------------------------------------------------------

    /**
     * Clear FeatureFlags' private per-method log guard so this test observes
     * the once-behavior from a pristine process state — earlier tests in the
     * shared PHPUnit process already spent the guards otherwise.
     */
    private function resetFailSafeLogged(): void
    {
        $property = new \ReflectionProperty(FeatureFlags::class, 'failSafeLogged');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    /**
     * Open a capture file that BOTH destinations of FeatureFlags::log() can
     * reach, so the once-guard assertions hold no matter what order the suite
     * ran the earlier tests in:
     *
     *   1. error_log() branch — taken when log()'s memoized Worker::getAllWorkers()
     *      is empty (the standalone truth: no worker constructed yet). Captured by
     *      pointing the error_log ini at the sink file.
     *   2. Worker::safeEcho() branch — taken once a sibling suite has constructed a
     *      Worker (the ctor registers it in the static $workers array for the whole
     *      process) BEFORE log()'s function-local static memo first computed.
     *      safeEcho fwrite()s to the process-owned Worker::$outputStream, which
     *      bypasses error_log AND output buffering. Captured by swapping that public
     *      static to the same sink file and, belt-and-suspenders, emptying the
     *      registry so a memo that has not computed yet still takes route 1.
     *
     * Both edits are restored by stopLogSink() in tearDown, so no other suite
     * sees a mutated worker registry or stdout stream.
     */
    private function startLogSink(): void
    {
        $this->logSinkFile = tempnam(sys_get_temp_dir(), 'featureflags-log-');
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

    /** @return array<int,string> non-empty lines written to the sink (each error_log() call is one line plus its timestamp prefix) */
    private function logSinkLines(): array
    {
        $raw = (string) file_get_contents((string) $this->logSinkFile);

        return array_values(array_filter(
            explode("\n", $raw),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /** @param array<int,string> $lines */
    private function countLinesContaining(array $lines, string $needle): int
    {
        return count(array_filter(
            $lines,
            static fn (string $line): bool => str_contains($line, $needle)
        ));
    }

    /**
     * The once-guard is per ACCESSOR and covers writers too: two failing
     * useNewHandling() reads must produce exactly ONE line, the separate
     * legacyCompatEnabled() failure gets its own single line, and each failing
     * writer (setNewHandling x2, setLegacyCompat x2, setDcBotPresence x2,
     * clearNewHandlingOverride x2 against a throwing Redis — which also pins
     * their false return) logs at most once. Without the guard an operator
     * script looping a setter against broken Redis floods stderr one line per
     * call.
     *
     * Scoping note: with the throwing client injected, the first read marks the
     * SharedState facade transport-dead, so SharedState itself contributes at
     * most one 'SharedState::' line to the sink (its own once-guard). Every
     * assertion here counts only lines carrying FeatureFlags' distinct
     * 'FeatureFlags::' message prefix and stays immune to that foreign traffic.
     */
    public function testFailSafeLoggingIsOncePerAccessorForReadersAndWriters(): void
    {
        $this->resetFailSafeLogged();
        $this->startLogSink();

        SharedState::setClient(new ThrowingFlagRedis());

        // Two failing Flag A reads — only the first may log.
        $this->assertFalse(FeatureFlags::useNewHandling());
        $this->assertFalse(FeatureFlags::useNewHandling());

        // One failing Flag B read — its own accessor, its own single line.
        $this->assertTrue(FeatureFlags::legacyCompatEnabled());

        // Two failing writes per writer — each must still fail closed (return
        // false) and log exactly once, never once per call.
        $this->assertFalse(FeatureFlags::setNewHandling(null, true));
        $this->assertFalse(FeatureFlags::setNewHandling(null, true));
        $this->assertFalse(FeatureFlags::setLegacyCompat(false));
        $this->assertFalse(FeatureFlags::setLegacyCompat(false));
        $this->assertFalse(FeatureFlags::setDcBotPresence(true));
        $this->assertFalse(FeatureFlags::setDcBotPresence(true));
        $this->assertFalse(FeatureFlags::clearNewHandlingOverride('host123'));
        $this->assertFalse(FeatureFlags::clearNewHandlingOverride('host123'));

        $lines = $this->logSinkLines();
        $flagLines = array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_contains($line, 'FeatureFlags::')
        ));

        $this->assertSame(
            1,
            $this->countLinesContaining($lines, 'FeatureFlags::useNewHandling'),
            'useNewHandling() must log its fail-safe exactly once per process, not once per call'
        );
        $this->assertSame(
            1,
            $this->countLinesContaining($lines, 'FeatureFlags::legacyCompatEnabled'),
            'legacyCompatEnabled() has an independent guard and must log exactly once'
        );
        $this->assertSame(
            1,
            $this->countLinesContaining($lines, 'FeatureFlags::setNewHandling'),
            'setNewHandling() must return false on a throwing client AND log exactly once for two calls'
        );
        $this->assertSame(
            1,
            $this->countLinesContaining($lines, 'FeatureFlags::setLegacyCompat'),
            'setLegacyCompat() must return false on a throwing client AND log exactly once for two calls'
        );
        $this->assertSame(
            1,
            $this->countLinesContaining($lines, 'FeatureFlags::setDcBotPresence'),
            'setDcBotPresence() must return false on a throwing client AND log exactly once for two calls'
        );
        $this->assertSame(
            1,
            $this->countLinesContaining($lines, 'FeatureFlags::clearNewHandlingOverride'),
            'clearNewHandlingOverride() must return false on a throwing client AND log exactly once for two calls'
        );
        $this->assertCount(
            6,
            $flagLines,
            'exactly one FeatureFlags fail-safe line per distinct method (2 readers + 4 writers) — no flooding, no swallowing'
        );
    }
}
