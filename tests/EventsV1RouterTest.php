<?php

/**
 * Test seam for Events::dispatchV1().
 *
 * The fake \GatewayWorker\Lib\Gateway seam (which captures every reply/close so
 * the real gateway transport is never loaded under PHPUnit) lives in the shared
 * tests/V1TestSupport.php, required below before Events.php loads. It is shared
 * with EventsV1AuthHelloTest so the class is declared exactly once when PHPUnit
 * loads every test file into the same process.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the fake Gateway, then requires FeatureFlags.php + Events.php.
    require_once __DIR__.'/V1TestSupport.php';

    /**
     * In-memory stand-in for \GlobalData\Client — same technique as
     * FeatureFlagsTest's InMemoryGlobalDataClient: extend the real class so
     * FeatureFlags' `instanceof \GlobalData\Client` gate passes, but back every
     * variable with a plain array so nothing touches the network or a Workerman
     * timer. Toggling $global->ws_new_handling here flips Flag A deterministically.
     */
    class V1FakeGlobalDataClient extends \GlobalData\Client
    {
        /** @var array<string,mixed> */
        public $store = [];

        public function __construct()
        {
            // No parent::__construct — no servers, no socket, no timer.
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
     * Tests for the protocol-v1 envelope router added in WS-revamp Phase 2 step
     * 2.1: Events::isV1Envelope() (detection) and Events::dispatchV1() (routing +
     * Flag-A gating). Scope is strictly the NEW code from this step.
     *
     * ⛔ Core invariant proven here (plan CRITICAL INVARIANT / B8):
     *   - legacy {"type":...} messages are NEVER detected as v1 envelopes, so the
     *     legacy dispatch path is byte-unchanged regardless of Flag A;
     *   - v1 handling is dormant unless Flag A is explicitly ON;
     *   - with Flag A ON, ping round-trips to the exact frozen pong shape and any
     *     other op yields a clean not_implemented error (no crash, no legacy touch).
     */
    class EventsV1RouterTest extends TestCase
    {
        protected function setUp(): void
        {
            $this->resetState();
        }

        protected function tearDown(): void
        {
            $this->resetState();
        }

        private function resetState(): void
        {
            \GatewayWorker\Lib\Gateway::$sent = [];
            \GatewayWorker\Lib\Gateway::$closed = [];
            \GatewayWorker\Lib\Gateway::$sessions = [];
            \GatewayWorker\Lib\Gateway::$bound = [];
            \GatewayWorker\Lib\Gateway::$joined = [];

            // v1 auth state lives in the GatewayWorker $_SESSION superglobal;
            // clear it so no test leaks an authed state into the next.
            $_SESSION = [];

            // Clear any injected DB and process-global client.
            \Events::$db = null;
            unset($GLOBALS['global']);

            // Reset FeatureFlags' private static lazy client so each test re-resolves.
            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        /** Inject an in-memory GlobalData client as the process-wide $global. */
        private function injectClient(): V1FakeGlobalDataClient
        {
            $client = new V1FakeGlobalDataClient();
            $GLOBALS['global'] = $client;
            return $client;
        }

        /** Turn Flag A (ws_new_handling) ON globally via the injected client. */
        private function flagAOn(): V1FakeGlobalDataClient
        {
            $client = $this->injectClient();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 1;
            return $client;
        }

        /** Invoke the private Events::isV1Envelope() via reflection. */
        private function isV1Envelope($messageData): bool
        {
            $ref = new ReflectionMethod('Events', 'isV1Envelope');
            $ref->setAccessible(true);
            return (bool) $ref->invoke(null, $messageData);
        }

        /** All messages captured by the fake Gateway during this test. */
        private function sent(): array
        {
            return \GatewayWorker\Lib\Gateway::$sent;
        }

        /** All client_ids closeClient() was called with during this test. */
        private function closed(): array
        {
            return \GatewayWorker\Lib\Gateway::$closed;
        }

        /** Decode the single reply the router sent; fail if not exactly one. */
        private function singleReply(): array
        {
            $sent = $this->sent();
            $this->assertCount(1, $sent, 'expected exactly one reply on the wire');
            $decoded = json_decode($sent[0]['message'], true);
            $this->assertIsArray($decoded, 'reply must be valid JSON object');
            return $decoded;
        }

        // ------------------------------------------------------------------
        // (a) Legacy detection: legacy shapes are NEVER seen as v1 envelopes,
        //     regardless of Flag A. isV1Envelope() is pure — Flag A does not
        //     enter into detection at all, so the legacy path is untouched.
        // ------------------------------------------------------------------

        /**
         * The minimal legacy ping ({"type":"ping"}) — the exact shape that
         * triggers msgPing — must not be detected as a v1 envelope.
         */
        public function testLegacyPingIsNotV1Envelope(): void
        {
            $this->assertFalse($this->isV1Envelope(['type' => 'ping']));
        }

        /**
         * A representative spread of legacy message shapes (dispatched today by
         * their `type` key) must all be rejected as non-v1.
         */
        public function testAssortedLegacyShapesAreNotV1(): void
        {
            $legacy = [
                ['type' => 'ping'],
                ['type' => 'pong'],
                ['type' => 'login', 'ima' => 'host', 'name' => 'h1', 'module' => 'vps', 'room_id' => 1],
                ['type' => 'login', 'ima' => 'admin', 'session_id' => 'abc'],
                ['type' => 'run', 'command' => 'ls', 'id' => 'md5hash'],
                ['type' => 'running', 'id' => 'x', 'stdout' => 'out'],
                ['type' => 'ran', 'id' => 'x', 'term' => null],
                ['type' => 'bandwidth', 'content' => []],
                ['type' => 'say', 'from' => 'a', 'is' => 'room', 'content' => 'hi'],
            ];
            foreach ($legacy as $msg) {
                $this->assertFalse(
                    $this->isV1Envelope($msg),
                    'legacy message must not be detected as v1: '.json_encode($msg)
                );
            }
        }

        /**
         * Detection is flag-independent: a legacy ping is still not-v1 whether
         * Flag A is ON or OFF. (isV1Envelope never consults FeatureFlags; this
         * documents/locks that the routing decision in onMessage is unaffected.)
         */
        public function testLegacyDetectionIsIndependentOfFlagA(): void
        {
            $legacyPing = ['type' => 'ping'];

            // Flag A OFF (no client).
            $this->assertFalse($this->isV1Envelope($legacyPing));

            // Flag A ON.
            $this->flagAOn();
            $this->assertFalse($this->isV1Envelope($legacyPing));
        }

        /**
         * Malformed / partial v1-looking shapes must be rejected — the detector
         * requires the full envelope so it can never mistake something for v1 and
         * accidentally divert a legacy message.
         */
        public function testPartialOrMalformedEnvelopesAreNotV1(): void
        {
            $notV1 = [
                ['v' => 1, 'op' => 'ping', 'ts' => 1, 'data' => []],            // no id
                ['v' => 1, 'id' => 'a', 'ts' => 1, 'data' => []],              // no op
                ['v' => 1, 'id' => 'a', 'op' => 'ping', 'data' => []],         // no ts
                ['v' => 1, 'id' => 'a', 'op' => 'ping', 'ts' => 1],            // no data
                ['v' => 2, 'id' => 'a', 'op' => 'ping', 'ts' => 1, 'data' => []], // wrong version
                ['v' => '1', 'id' => 'a', 'op' => 'ping', 'ts' => 1, 'data' => []], // v not int
                ['v' => 1, 'id' => 'a', 'op' => '', 'ts' => 1, 'data' => []],  // empty op
                ['v' => 1, 'id' => '', 'op' => 'ping', 'ts' => 1, 'data' => []], // empty id
                ['v' => 1, 'id' => 'a', 'op' => 'ping', 'ts' => '1', 'data' => []], // ts not int
                ['v' => 1, 'id' => 'a', 'op' => 'ping', 'ts' => 1, 'data' => 'x'], // data not obj
                ['op' => 'ping', 'type' => 'ping'],                            // legacy w/ op-ish
            ];
            foreach ($notV1 as $msg) {
                $this->assertFalse(
                    $this->isV1Envelope($msg),
                    'incomplete/invalid envelope must be rejected: '.json_encode($msg)
                );
            }
        }

        /** A fully-formed v1 ping envelope IS detected as v1. */
        public function testWellFormedV1PingIsDetected(): void
        {
            $this->assertTrue($this->isV1Envelope(
                ['v' => 1, 'id' => 'uuid-1', 'op' => 'ping', 'ts' => 1719700000, 'data' => []]
            ));
        }

        // ------------------------------------------------------------------
        // (b) v1 ping/pong round-trips only when Flag A is ON.
        // ------------------------------------------------------------------

        /**
         * Flag A ON + UNauthenticated client: a v1 ping is NOT a pong anymore.
         *
         * Step 2.2 retrofitted the PROTOCOL_V1.md §2.1/§3 ordering rule: any op
         * other than auth.hello before successful v1 auth => error.code
         * "auth_required" + close. `ping` is explicitly covered by that rule
         * (§2.1 diff note), so a pre-auth ping now yields an auth_required error
         * reply AND a closeClient — NOT the frozen pong, NOT not_implemented.
         *
         * (The happy pong path — an AUTHENTICATED client pinging — is proven in
         * EventsV1AuthHelloTest::testAuthedHostPingGetsPong, which exercises the
         * real handleAuthHello() to reach the authed state first.)
         */
        public function testV1PingWhenUnauthenticatedRepliesAuthRequiredAndCloses(): void
        {
            $this->flagAOn();

            \Events::dispatchV1(42, [
                'v' => 1, 'id' => 'req-123', 'op' => 'ping', 'ts' => 1719700000, 'data' => []
            ]);

            $reply = $this->singleReply();
            $this->assertSame(1, $reply['v']);
            $this->assertSame('req-123', $reply['re']);
            $this->assertFalse($reply['ok']);
            $this->assertSame('auth_required', $reply['error']['code']);
            $this->assertArrayNotHasKey('data', $reply, 'error reply must not carry a data payload');

            $this->assertSame([42], $this->closed(), 'unauthenticated ping must close the connection');
            $this->assertSame(42, $this->sent()[0]['client_id'], 'reply must target the originating client');
        }

        /**
         * Flag A OFF (client present but flag unset): dispatchV1 is dormant — no
         * reply, no side effects. This is the adoption-boundary case: GlobalData
         * reachable, flag simply not turned on.
         */
        public function testV1PingIsDormantWhenFlagAOffWithClient(): void
        {
            $this->injectClient(); // client present, ws_new_handling NOT set -> OFF

            \Events::dispatchV1(42, [
                'v' => 1, 'id' => 'req-123', 'op' => 'ping', 'ts' => 1719700000, 'data' => []
            ]);

            $this->assertCount(0, $this->sent(), 'Flag A OFF must produce no reply');
        }

        /**
         * Flag A explicitly OFF (ws_new_handling = 0): still dormant.
         */
        public function testV1PingIsDormantWhenFlagAExplicitlyZero(): void
        {
            $client = $this->injectClient();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 0;

            \Events::dispatchV1(1, [
                'v' => 1, 'id' => 'id-0', 'op' => 'ping', 'ts' => 1, 'data' => []
            ]);

            $this->assertCount(0, $this->sent());
        }

        // ------------------------------------------------------------------
        // (c) Dormant-by-default: no flag set / GlobalData unreachable.
        // ------------------------------------------------------------------

        /**
         * With NO usable GlobalData client at all (the real PHPUnit/CLI state,
         * same fail-safe condition FeatureFlagsTest exercises), a v1 ping produces
         * NO reply — deploying the router is a runtime no-op. This is the core
         * ship-dormant guarantee.
         */
        public function testV1PingDormantByDefaultWhenGlobalDataUnavailable(): void
        {
            // No $global injected; FeatureFlags lazy-connect fails/throws -> OFF.
            \Events::dispatchV1(9, [
                'v' => 1, 'id' => 'id-x', 'op' => 'ping', 'ts' => 1, 'data' => []
            ]);

            $this->assertCount(0, $this->sent(), 'dormant-by-default must produce no reply');
        }

        /**
         * Even a non-ping op is fully inert when GlobalData is unavailable — the
         * flag gate short-circuits before any op handling, so nothing (not even a
         * not_implemented error) is emitted.
         */
        public function testUnimplementedOpDormantByDefault(): void
        {
            \Events::dispatchV1(9, [
                'v' => 1, 'id' => 'id-y', 'op' => 'cmd.exec', 'ts' => 1, 'data' => []
            ]);

            $this->assertCount(0, $this->sent());
        }

        // ------------------------------------------------------------------
        // (d) Pre-auth ordering gate with Flag A ON (PROTOCOL_V1.md §2.1/§3).
        //
        // Step 2.2 changed 2.1's behavior: a non-auth op from an unauthenticated
        // client no longer returns not_implemented — the auth_required gate fires
        // FIRST (before op dispatch), so any such op errors auth_required + close.
        // ------------------------------------------------------------------

        /**
         * Flag A ON + UNauthenticated + a non-auth op (cmd.exec) ⇒ a clean
         * ok:false reply with error.code "auth_required" AND a closeClient — the
         * ordering gate short-circuits before the op is ever looked at, so the op
         * never reaches its (would-be) not_implemented branch.
         */
        public function testNonAuthOpWhenUnauthenticatedRepliesAuthRequiredAndCloses(): void
        {
            $this->flagAOn();

            \Events::dispatchV1(7, [
                'v' => 1, 'id' => 'req-999', 'op' => 'cmd.exec', 'ts' => 1719700000, 'data' => []
            ]);

            $reply = $this->singleReply();
            $this->assertSame(1, $reply['v']);
            $this->assertSame('req-999', $reply['re']);
            $this->assertFalse($reply['ok']);
            $this->assertIsArray($reply['error']);
            $this->assertSame('auth_required', $reply['error']['code']);
            $this->assertArrayHasKey('message', $reply['error']);
            $this->assertArrayNotHasKey('data', $reply, 'error reply must not carry a data payload');

            $this->assertSame([7], $this->closed(), 'a pre-auth non-auth op must close the connection');
        }

        /**
         * The auth_required reply targets the originating client id, and closes
         * that same client. (auth.hello itself is the ONE op allowed pre-auth, so
         * a different op is used here to trigger the gate.)
         */
        public function testAuthRequiredReplyTargetsOriginatingClient(): void
        {
            $this->flagAOn();
            \Events::dispatchV1(555, [
                'v' => 1, 'id' => 'r', 'op' => 'admin.hosts', 'ts' => 1, 'data' => []
            ]);
            $this->assertSame(555, $this->sent()[0]['client_id']);
            $this->assertSame([555], $this->closed());
        }
    }
}
