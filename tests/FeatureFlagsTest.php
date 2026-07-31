<?php

use PHPUnit\Framework\TestCase;

// Declares the OFFLINE \GlobalData\Client (so FeatureFlags' lazy fallback can
// never open a socket to GLOBALDATA_IP:2207) and predefines GLOBALDATA_IP so it
// never requires the production config.settings.php either. Must come before
// anything can autoload the real \GlobalData\Client.
require_once __DIR__.'/TestBootstrap.php';
require_once __DIR__.'/../Applications/Chat/FeatureFlags.php';

/**
 * In-memory stand-in for \GlobalData\Client.
 *
 * Thin alias over the suite-wide InMemoryGlobalData double (tests/TestBootstrap.php)
 * so the flag logic is exercised against the SAME faithful get/set/isset/unset
 * and md5-based cas() semantics every other test uses. It extends
 * \GlobalData\Client (transitively) so FeatureFlags' `instanceof` gate passes,
 * and it never touches the network — complementary to the fail-safe tests
 * below, which exercise the genuine "no client / unreachable" path.
 */
class InMemoryGlobalDataClient extends InMemoryGlobalData
{
}

/**
 * Tests for Applications/Chat/FeatureFlags.php (WS-revamp Phase 0.5, plan B8).
 *
 * Two layers:
 *   1. Fail-safe layer — with NO usable GlobalData client (the real state in a
 *      CLI/PHPUnit run, where GlobalData is unreachable), flags MUST report
 *      today's behavior: Flag A OFF, Flag B ON. This is the core "ship dormant"
 *      guarantee and is the most important thing proven here.
 *   2. Logic layer — injecting an in-memory client via the process-global
 *      $global, we prove global toggle, per-host override precedence, override
 *      clearing, and Flag B toggling all behave as documented.
 */
class FeatureFlagsTest extends TestCase
{
    /**
     * Reset the injected client and FeatureFlags' cached lazy client before and
     * after every test so tests never leak state into one another (and, if a
     * real GlobalData were ever reachable here, so we never leave residue).
     */
    protected function setUp(): void
    {
        $this->resetFlagState();
    }

    protected function tearDown(): void
    {
        $this->resetFlagState();
    }

    private function resetFlagState(): void
    {
        // Clear any process-global client injected by a logic-layer test.
        unset($GLOBALS['global']);

        // Clear FeatureFlags' private static lazy client so it re-resolves.
        $ref = new ReflectionClass(FeatureFlags::class);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /** Inject an in-memory client as the process-wide $global. */
    private function injectClient(): InMemoryGlobalDataClient
    {
        $client = new InMemoryGlobalDataClient();
        $GLOBALS['global'] = $client;
        return $client;
    }

    // ----------------------------------------------------------------------
    // Layer 1: fail-safe / default behavior (the ship-dormant guarantee, B8)
    // ----------------------------------------------------------------------

    /**
     * With no usable GlobalData (unset $global, and a lazy connect that either
     * cannot resolve or throws), useNewHandling() must return false for a null
     * host — i.e. the legacy path stays active.
     */
    public function testUseNewHandlingDefaultsOffWhenGlobalDataUnavailable(): void
    {
        $this->assertFalse(
            FeatureFlags::useNewHandling(),
            'Flag A must default OFF when GlobalData is unavailable (null host)'
        );
    }

    /**
     * Same guarantee for arbitrary host ids with no override present: OFF.
     */
    public function testUseNewHandlingDefaultsOffForArbitraryHostsWhenUnavailable(): void
    {
        foreach (['host123', 'otherhost', '10.0.0.5', 42, 'web-node-a'] as $hostId) {
            $this->assertFalse(
                FeatureFlags::useNewHandling($hostId),
                "Flag A must default OFF for host '{$hostId}' when GlobalData is unavailable"
            );
        }
    }

    /**
     * With no usable GlobalData, legacyCompatEnabled() must return true —
     * legacy compat stays ON, exactly today's behavior.
     */
    public function testLegacyCompatDefaultsOnWhenGlobalDataUnavailable(): void
    {
        $this->assertTrue(
            FeatureFlags::legacyCompatEnabled(),
            'Flag B must default ON when GlobalData is unavailable'
        );
    }

    /**
     * Explicitly exercise the exception path: a $global whose property access
     * throws (mimicking the real \GlobalData\Client raising in a non-Workerman
     * CLI environment) must be swallowed and yield the fail-safe defaults.
     */
    public function testExceptionDuringReadIsSwallowedToFailSafeDefaults(): void
    {
        $GLOBALS['global'] = new class extends \GlobalData\Client {
            public function __construct()
            {
            }
            public function __isset($key): bool
            {
                throw new \RuntimeException('Timer can only be used in workerman running environment');
            }
            public function __get($key)
            {
                throw new \RuntimeException('boom');
            }
        };

        $this->assertFalse(
            FeatureFlags::useNewHandling('anyhost'),
            'A throwing GlobalData client must make Flag A default OFF'
        );
        $this->assertFalse(
            FeatureFlags::useNewHandling(),
            'A throwing GlobalData client must make Flag A (global) default OFF'
        );
        $this->assertTrue(
            FeatureFlags::legacyCompatEnabled(),
            'A throwing GlobalData client must make Flag B default ON'
        );
    }

    /**
     * Writers must fail closed (return false) when GlobalData is unavailable
     * rather than throw — belt-and-suspenders for operator tooling.
     */
    public function testWritersReturnFalseWhenGlobalDataUnavailable(): void
    {
        // No $global injected, and (in this env) no reachable lazy client.
        $this->assertFalse(FeatureFlags::setNewHandling(null, true));
        $this->assertFalse(FeatureFlags::setLegacyCompat(false));
        // clearNewHandlingOverride returns false only when no client; still no throw.
        $this->assertFalse(FeatureFlags::clearNewHandlingOverride('host123'));
    }

    // ----------------------------------------------------------------------
    // Layer 2: toggle / override logic (in-memory injected client)
    // ----------------------------------------------------------------------

    public function testTogglingFlagAGloballyWorks(): void
    {
        $this->injectClient();

        // Starts ON (no var set, new handling is the default).
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
    }

    /**
     * With a usable client and NO ws_new_handling variable set, Flag A reads ON.
     *
     * This is the shipped contract — useNewHandling() ends with
     * `isset($global->$var) ? (bool) $global->$var : true`, and its own
     * @return tag says "true when unset (new handling is the default)" (the v1
     * handler was enabled by default in commit 9eabb50). NOTE: FeatureFlags.php's
     * CLASS-level docblock still claims "ws_new_handling … missing = 0 (OFF)" and
     * describes State 1 "Dormant (A=OFF, B=ON)" as the default — that header is
     * STALE and contradicts both the code and the method docblock. Pinned here so
     * the drift cannot be mistaken for a regression.
     */
    public function testUnsetFlagAReadsOnWhenGlobalDataIsUsable(): void
    {
        $this->injectClient();

        $this->assertTrue(
            FeatureFlags::useNewHandling(),
            'unset ws_new_handling + usable GlobalData => Flag A ON (new handling is the default)'
        );
        $this->assertTrue(
            FeatureFlags::useNewHandling('host_without_override'),
            'a host with no override inherits the unset-global default, i.e. ON'
        );
    }

    public function testPerHostOverridePrecedence(): void
    {
        $global = $this->injectClient();

        // Global explicitly OFF (unset would mean ON — see
        // testUnsetFlagAReadsOnWhenGlobalDataIsUsable), plus a per-host override ON.
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
        $this->assertSame(0, $global->store[FeatureFlags::VAR_NEW_HANDLING]);
    }

    public function testPerHostOverrideCanForceOffWhileGlobalOn(): void
    {
        $this->injectClient();

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
        $this->injectClient();

        // Global explicitly OFF, host overridden ON, then cleared -> reverts to global OFF.
        FeatureFlags::setNewHandling(null, false);
        FeatureFlags::setNewHandling('host123', true);
        $this->assertTrue(FeatureFlags::useNewHandling('host123'));

        $this->assertTrue(FeatureFlags::clearNewHandlingOverride('host123'));
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
        $this->injectClient();
        FeatureFlags::setNewHandling(null, false);
        $this->assertTrue(
            FeatureFlags::clearNewHandlingOverride('never_set_host'),
            'Clearing an absent override must succeed (idempotent)'
        );
        $this->assertFalse(FeatureFlags::useNewHandling('never_set_host'));
    }

    public function testFlagBToggling(): void
    {
        $this->injectClient();

        // Default ON when unset.
        $this->assertTrue(FeatureFlags::legacyCompatEnabled());

        $this->assertTrue(FeatureFlags::setLegacyCompat(false));
        $this->assertFalse(
            FeatureFlags::legacyCompatEnabled(),
            'After setLegacyCompat(false), Flag B must read OFF'
        );

        $this->assertTrue(FeatureFlags::setLegacyCompat(true));
        $this->assertTrue(
            FeatureFlags::legacyCompatEnabled(),
            'After setLegacyCompat(true), Flag B must read ON again'
        );
    }

    // ----------------------------------------------------------------------
    // hostVar name normalization
    // ----------------------------------------------------------------------

    public function testHostVarNormalizesUnsafeCharacters(): void
    {
        $this->assertSame('ws_new_handling_host_10_0_0_5', FeatureFlags::hostVar('10.0.0.5'));
        $this->assertSame('ws_new_handling_host_web_node_a', FeatureFlags::hostVar('web-node-a'));
        $this->assertSame('ws_new_handling_host_42', FeatureFlags::hostVar(42));
        $this->assertSame('ws_new_handling_host_abc_DEF_9', FeatureFlags::hostVar('abc DEF!9'));
    }

    /**
     * Two host ids that normalize to the same safe name must share an override
     * slot — documents the (intended) collision behavior of hostVar().
     */
    public function testHostVarCollisionForEquivalentNormalizedIds(): void
    {
        $this->injectClient();
        // Global explicitly OFF so a true result can ONLY come from the shared
        // override — without this the unset-global default (ON) would make this
        // test pass whether or not the collision happens.
        FeatureFlags::setNewHandling(null, false);
        FeatureFlags::setNewHandling('10.0.0.5', true);
        $this->assertTrue(
            FeatureFlags::useNewHandling('10-0-0-5'),
            'Ids normalizing to the same var name intentionally share an override'
        );
        $this->assertFalse(
            FeatureFlags::useNewHandling('10_0_0_6'),
            'a host that normalizes differently must NOT pick up the override'
        );
    }

    // ----------------------------------------------------------------------
    // Socket hygiene: the flag reader must never touch the network in tests
    // ----------------------------------------------------------------------

    /**
     * The lazy-fallback path (no $global injected) must not build a real
     * \GlobalData\Client. On baseline it did: FeatureFlags::globalData()
     * required /home/my/include/config/config.settings.php to learn
     * GLOBALDATA_IP (216.158.226.14) and then constructed a live client, which
     * opens a TCP socket to :2207 on first property access — one leaked socket
     * per flag read, against the PRODUCTION GlobalData server.
     *
     * tests/TestBootstrap.php closes that off two ways (predefined GLOBALDATA_IP
     * + an offline \GlobalData\Client stub). This test proves the first one:
     * with GLOBALDATA_IP already defined, the settings file is never required.
     */
    public function testLazyFallbackNeverRequiresProductionSettingsFile(): void
    {
        $this->assertTrue(
            defined('GLOBALDATA_IP'),
            'TestBootstrap must predefine GLOBALDATA_IP so FeatureFlags skips the config.settings.php require'
        );
        $this->assertSame('127.0.0.1', GLOBALDATA_IP, 'tests must never point at the production GlobalData host');

        // Exercise the lazy path with no $global injected.
        FeatureFlags::useNewHandling();
        FeatureFlags::legacyCompatEnabled();
        FeatureFlags::dcBotPresenceEnabled();

        $loaded = get_included_files();
        $this->assertNotContains(
            '/home/my/include/config/config.settings.php',
            $loaded,
            'the production settings file must never be pulled into the test process'
        );

        // And the structural guarantee: the REAL GlobalData client — the only
        // code in the tree that calls stream_socket_client() for :2207 — was
        // never autoloaded, because tests/TestBootstrap.php declared
        // \GlobalData\Client first. No test in this process can open that socket.
        $this->assertSame(
            [],
            array_values(array_filter(
                $loaded,
                static fn(string $f) => str_contains($f, 'workerman/globaldata/src/Client.php')
            )),
            'the real socket-opening \\GlobalData\\Client must never be loaded in tests'
        );
        $ref = new ReflectionClass(\GlobalData\Client::class);
        $this->assertStringEndsWith(
            'tests/TestBootstrap.php',
            (string) $ref->getFileName(),
            '\\GlobalData\\Client must resolve to the offline test stub'
        );
        $this->assertFalse(
            $ref->hasMethod('getConnection'),
            'the offline stub has no connection machinery at all'
        );
    }

    /**
     * Flag C (DC bot presence) fails SAFE to ON when GlobalData is unreachable,
     * and reads/writes correctly through an injected client. Flag C gates bot
     * spawn/move/cleanup in Events.php, so its default is load-bearing.
     */
    public function testDcBotPresenceFlag(): void
    {
        $this->assertTrue(
            FeatureFlags::dcBotPresenceEnabled(),
            'Flag C must default ON when GlobalData is unavailable'
        );
        $this->assertFalse(
            FeatureFlags::setDcBotPresence(false),
            'writers fail closed (return false) with no usable GlobalData'
        );

        $global = $this->injectClient();
        $this->assertTrue(FeatureFlags::dcBotPresenceEnabled(), 'unset dc_bot_presence => ON');

        $this->assertTrue(FeatureFlags::setDcBotPresence(false));
        $this->assertSame(0, $global->store[FeatureFlags::VAR_DC_BOT_PRESENCE]);
        $this->assertFalse(FeatureFlags::dcBotPresenceEnabled());

        $this->assertTrue(FeatureFlags::setDcBotPresence(true));
        $this->assertTrue(FeatureFlags::dcBotPresenceEnabled());
    }
}
