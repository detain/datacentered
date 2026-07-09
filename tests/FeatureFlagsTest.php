<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../Applications/Chat/FeatureFlags.php';

/**
 * In-memory stand-in for \GlobalData\Client.
 *
 * The real client opens a TCP socket to GLOBALDATA_IP:2207 and (under the CLI
 * SAPI) registers a Workerman timer on first property access — neither of which
 * is available in a PHPUnit run. This fake extends the real class so it passes
 * FeatureFlags' `instanceof \GlobalData\Client` gate, but backs every variable
 * with a plain array so get/set/isset/unset behave deterministically and never
 * touch the network. It lets us prove the toggle/override *logic* without a
 * live GlobalData server, complementary to the fail-safe tests below which
 * exercise the genuine "no client / unreachable" path.
 */
class InMemoryGlobalDataClient extends \GlobalData\Client
{
    /** @var array<string,mixed> */
    public $store = [];

    public function __construct()
    {
        // Intentionally do NOT call parent::__construct — no servers, no socket.
    }

    public function __get($key)
    {
        return $this->store[$key] ?? null;
    }

    public function __set($key, $value)
    {
        $this->store[$key] = $value;
    }

    public function __isset($key)
    {
        return isset($this->store[$key]);
    }

    public function __unset($key)
    {
        unset($this->store[$key]);
    }
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

        // Starts OFF (no var set).
        $this->assertFalse(FeatureFlags::useNewHandling());

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

    public function testPerHostOverridePrecedence(): void
    {
        $this->injectClient();

        // Global stays OFF; set a per-host override ON.
        $this->assertTrue(FeatureFlags::setNewHandling('host123', true));

        $this->assertTrue(
            FeatureFlags::useNewHandling('host123'),
            'Overridden host must read ON regardless of global'
        );
        $this->assertFalse(
            FeatureFlags::useNewHandling('otherhost'),
            'Non-overridden host must inherit global OFF'
        );
        $this->assertFalse(
            FeatureFlags::useNewHandling(),
            'Null host (global) must remain OFF'
        );
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

        // Global OFF, host overridden ON, then cleared -> reverts to global OFF.
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
        FeatureFlags::setNewHandling('10.0.0.5', true);
        $this->assertTrue(
            FeatureFlags::useNewHandling('10-0-0-5'),
            'Ids normalizing to the same var name intentionally share an override'
        );
    }
}
