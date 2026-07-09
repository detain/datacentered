<?php

/**
 * Test seam for Events::handleAuthHello() — the v1 `auth.hello` token/session
 * auth handler added in WS-revamp Phase 2 step 2.2 (docs/AUTH_DESIGN.md §§4–5,
 * docs/PROTOCOL_V1.md §2.1/§3).
 *
 * Same Gateway-stub technique as EventsV1RouterTest: the shared
 * tests/V1TestSupport.php declares a lightweight fake \GatewayWorker\Lib\Gateway
 * *before* Events.php loads, so the composer autoloader never pulls the real
 * gateway transport; every reply, close, session write, uid bind and group join
 * is captured for assertion.
 *
 * The DB seam is a fluent fake injected into the public static Events::$db.
 * handleAuthHello() reads its identity rows through the real Workerman-MySQL
 * query-builder chain (select/from/where/bindValues/row|query); FakeAuthDb
 * mirrors just that chain and returns rows from an in-memory dataset keyed by
 * table + bound primary key. The REAL handleAuthHello() logic runs end to end
 * (token hashing, hash_equals, rotation grace, IP checks, role branching) —
 * nothing is reimplemented here.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake Gateway seam, then requires FeatureFlags + Events.
    require_once __DIR__.'/V1TestSupport.php';

    /**
     * Reused in-memory GlobalData client (mirrors FeatureFlagsTest /
     * EventsV1RouterTest). Needed both to flip Flag A ON via GlobalData and to
     * back the $global->hosts CAS map the host+vps success path updates.
     */
    if (!class_exists('AuthFakeGlobalDataClient')) {
        class AuthFakeGlobalDataClient extends \GlobalData\Client
        {
            public $store = [];

            public function __construct()
            {
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

            /** Minimal CAS used by the host+vps success path's hosts-map update. */
            public function cas($key, $old, $new)
            {
                $current = $this->store[$key] ?? null;
                if ($current === $old) {
                    $this->store[$key] = $new;
                    return true;
                }
                return false;
            }
        }
    }

    /**
     * Fluent fake of \Workerman\MySQL\Connection covering exactly the builder
     * chain handleAuthHello() uses. It records the target table and the bound
     * value, then row()/query() return the matching dataset row.
     *
     * Dataset shape: ['<table>' => ['<keycol>' => ['<keyval>' => <row array>]]].
     * The admin path uses query() with a session_id bind against 'sessions'.
     */
    class FakeAuthDb
    {
        /** @var array */
        public $data;
        /** @var string|null */
        private $table;
        /** @var array */
        private $binds = [];
        /** @var bool force a throw to exercise the DB-error branch */
        public $throwOnFetch = false;

        public function __construct(array $data)
        {
            $this->data = $data;
        }

        public function select($cols = '*')
        {
            $this->table = null;
            $this->binds = [];
            return $this;
        }

        public function from($table)
        {
            $this->table = $table;
            return $this;
        }

        public function leftJoin($table, $cond = null)
        {
            return $this;
        }

        public function where($cond)
        {
            return $this;
        }

        public function bindValues(array $bind_values)
        {
            $this->binds = array_merge($this->binds, $bind_values);
            return $this;
        }

        /** Host/bot path: single-row primary-key lookup. */
        public function row($query = '', $params = null, $fetchmode = null)
        {
            if ($this->throwOnFetch) {
                throw new \RuntimeException('simulated DB failure');
            }
            return $this->lookup() ?? false;
        }

        /** Admin path: query() returns a list of rows. */
        public function query($query = '', $params = null, $fetchmode = null)
        {
            if ($this->throwOnFetch) {
                throw new \RuntimeException('simulated DB failure');
            }
            $row = $this->lookup();
            return $row === null ? [] : [$row];
        }

        private function lookup()
        {
            $table = $this->table;
            if (!isset($this->data[$table])) {
                return null;
            }
            // The dataset for each table is keyed by its lookup column; find the
            // bound value that matches that key column.
            foreach ($this->data[$table] as $keyCol => $rowsByKey) {
                foreach ($this->binds as $bindKey => $bindVal) {
                    if (isset($rowsByKey[(string) $bindVal])) {
                        return $rowsByKey[(string) $bindVal];
                    }
                }
            }
            return null;
        }
    }

    /**
     * Tests for Events::handleAuthHello() and the dispatchV1 auth_required gate
     * (WS-revamp Phase 2 step 2.2). Scope is strictly the NEW auth code.
     */
    class EventsV1AuthHelloTest extends TestCase
    {
        private const REMOTE = '203.0.113.10';

        protected function setUp(): void
        {
            $this->resetState();
            $_SERVER['REMOTE_ADDR'] = self::REMOTE;
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
            $_SESSION = [];
            \Events::$db = null;
            unset($GLOBALS['global']);

            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        /** Flip Flag A ON via an injected in-memory GlobalData client. */
        private function flagAOn(): AuthFakeGlobalDataClient
        {
            $client = new AuthFakeGlobalDataClient();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 1;
            $client->store['hosts'] = [];
            $GLOBALS['global'] = $client;
            return $client;
        }

        /** Inject a fluent fake DB with the given dataset. */
        private function injectDb(array $data): FakeAuthDb
        {
            $db = new FakeAuthDb($data);
            \Events::$db = $db;
            return $db;
        }

        private function sha(string $token): string
        {
            return hash('sha256', $token);
        }

        /** Drive handleAuthHello via the public dispatchV1 entry (auth.hello op). */
        private function authHello(array $data, int $client = 1, string $id = 'req-auth'): void
        {
            \Events::dispatchV1($client, [
                'v' => 1, 'id' => $id, 'op' => 'auth.hello', 'ts' => 1719700000, 'data' => $data
            ]);
        }

        private function sent(): array
        {
            return \GatewayWorker\Lib\Gateway::$sent;
        }

        private function closed(): array
        {
            return \GatewayWorker\Lib\Gateway::$closed;
        }

        /** Decode the single reply; assert exactly one was sent. */
        private function singleReply(): array
        {
            $sent = $this->sent();
            $this->assertCount(1, $sent, 'expected exactly one reply on the wire');
            $decoded = json_decode($sent[0]['message'], true);
            $this->assertIsArray($decoded);
            return $decoded;
        }

        /** Assert the last reply is an auth error with $code + a close happened. */
        private function assertAuthError(string $code, int $client = 1): array
        {
            $reply = $this->singleReply();
            $this->assertFalse($reply['ok'], "reply must be ok:false for code {$code}");
            $this->assertSame($code, $reply['error']['code']);
            $this->assertContains($client, $this->closed(), "client {$client} must be closed on {$code}");
            $this->assertEmpty($_SESSION['v1_authed'] ?? null, 'v1_authed must NOT be set on error');
            return $reply;
        }

        // ================================================================
        // Host success
        // ================================================================

        public function testHostSuccessReturnsWelcomeAndAuthsSession(): void
        {
            $this->flagAOn();
            $token = 'dchost_'.str_repeat('a', 64);
            $this->injectDb(['vps_masters' => ['vps_id' => ['1234' => [
                'vps_id' => 1234,
                'vps_name' => 'host-alpha',
                'vps_ip' => self::REMOTE,
                'vps_type' => 7,
                'vps_token_hash' => $this->sha($token),
                'vps_token_prev_hash' => null,
                'vps_token_prev_expires' => null
            ]]]]);

            $this->authHello(['role' => 'host', 'host_id' => 1234, 'token' => $token, 'module' => 'vps']);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            $this->assertSame('req-auth', $reply['re']);
            $this->assertSame(1234, $reply['data']['host_id']);
            $this->assertSame('vps1234', $reply['data']['uid']);
            $this->assertSame('host-alpha', $reply['data']['name']);
            $this->assertArrayHasKey('hub_time', $reply['data']);
            $this->assertArrayHasKey('timers', $reply['data']);
            $this->assertArrayHasKey('session', $reply['data']);

            $this->assertSame([], $this->closed(), 'success must NOT close the connection');
            $this->assertTrue($_SESSION['v1_authed']);
            $this->assertSame('vps1234', $_SESSION['uid']);
            $this->assertSame('host', $_SESSION['ima']);

            // The host+vps success path CAS-updates the shared hosts map.
            $this->assertArrayHasKey(1234, $GLOBALS['global']->store['hosts']);
        }

        /**
         * The auth gate lets an AUTHENTICATED client through: after a successful
         * host auth.hello, a subsequent ping on the same connection ($_SESSION
         * persists in-process) gets the frozen pong, not auth_required.
         */
        public function testAuthedHostPingGetsPong(): void
        {
            $this->flagAOn();
            $token = 'dchost_'.str_repeat('b', 64);
            $this->injectDb(['vps_masters' => ['vps_id' => ['1' => [
                'vps_id' => 1, 'vps_name' => 'h', 'vps_ip' => self::REMOTE, 'vps_type' => 1,
                'vps_token_hash' => $this->sha($token)
            ]]]]);

            $this->authHello(['role' => 'host', 'host_id' => 1, 'token' => $token]);
            $this->assertTrue($_SESSION['v1_authed']);

            \GatewayWorker\Lib\Gateway::$sent = [];
            \Events::dispatchV1(1, ['v' => 1, 'id' => 'p1', 'op' => 'ping', 'ts' => 1, 'data' => []]);

            $this->assertCount(1, $this->sent());
            $this->assertSame(
                '{"v":1,"re":"p1","ok":true,"data":{}}',
                $this->sent()[0]['message'],
                'authed client ping must get the frozen pong'
            );
            $this->assertSame([], $this->closed());
        }

        // ================================================================
        // Bot success
        // ================================================================

        public function testBotSuccessWithNullIpSkipsIpCheck(): void
        {
            $this->flagAOn();
            $token = 'dcbot_'.str_repeat('c', 64);
            // Source IP intentionally does NOT match; bot_ip NULL means no pin.
            $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
            $this->injectDb(['ws_bots' => ['bot_name' => ['deploybot' => [
                'bot_id' => 5,
                'bot_name' => 'deploybot',
                'bot_ip' => null,
                'bot_enabled' => 1,
                'bot_token_hash' => $this->sha($token)
            ]]]]);

            $this->authHello(['role' => 'bot', 'host_id' => 'bot:deploybot', 'token' => $token]);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok'], 'bot with NULL bot_ip must auth despite non-matching source IP');
            $this->assertSame('bot5', $reply['data']['uid']);
            $this->assertSame([], $this->closed());
            $this->assertTrue($_SESSION['v1_authed']);
        }

        public function testBotSuccessWithPinnedIpRequiresMatch(): void
        {
            $this->flagAOn();
            $token = 'dcbot_'.str_repeat('d', 64);
            $this->injectDb(['ws_bots' => ['bot_id' => ['9' => [
                'bot_id' => 9,
                'bot_name' => 'pinnedbot',
                'bot_ip' => self::REMOTE,
                'bot_enabled' => 1,
                'bot_token_hash' => $this->sha($token)
            ]]]]);

            $this->authHello(['role' => 'bot', 'host_id' => 9, 'token' => $token]);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok'], 'bot with matching pinned IP must auth');
            $this->assertTrue($_SESSION['v1_authed']);
        }

        public function testBotPinnedIpMismatchHardFails(): void
        {
            $this->flagAOn();
            $token = 'dcbot_'.str_repeat('e', 64);
            $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
            $this->injectDb(['ws_bots' => ['bot_id' => ['9' => [
                'bot_id' => 9, 'bot_name' => 'pinnedbot', 'bot_ip' => self::REMOTE,
                'bot_enabled' => 1, 'bot_token_hash' => $this->sha($token)
            ]]]]);

            $this->authHello(['role' => 'bot', 'host_id' => 9, 'token' => $token]);
            $this->assertAuthError('ip_mismatch');
        }

        // ================================================================
        // Admin success
        // ================================================================

        public function testAdminSuccessWithValidSession(): void
        {
            $this->flagAOn();
            $this->injectDb(['sessions' => ['session_id' => ['sess-xyz' => [
                'account_id' => 77,
                'account_lid' => 'adminuser',
                'account_value' => null
            ]]]]);

            $this->authHello(['role' => 'admin', 'session' => 'sess-xyz']);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            $this->assertSame(77, $reply['data']['uid']);
            $this->assertSame('adminuser', $reply['data']['name']);
            $this->assertArrayHasKey('hub_time', $reply['data']);
            $this->assertSame([], $this->closed());
            $this->assertTrue($_SESSION['v1_authed']);
            $this->assertSame('admin', $_SESSION['ima']);
        }

        // ================================================================
        // Error codes
        // ================================================================

        public function testUnknownHost(): void
        {
            $this->flagAOn();
            $this->injectDb(['vps_masters' => ['vps_id' => []]]);
            $this->authHello(['role' => 'host', 'host_id' => 999, 'token' => 'x']);
            $this->assertAuthError('unknown_host');
        }

        public function testNoTokenIssuedWhenHashNull(): void
        {
            $this->flagAOn();
            $this->injectDb(['vps_masters' => ['vps_id' => ['3' => [
                'vps_id' => 3, 'vps_name' => 'h3', 'vps_ip' => self::REMOTE,
                'vps_token_hash' => null
            ]]]]);
            $this->authHello(['role' => 'host', 'host_id' => 3, 'token' => 'anything']);
            $this->assertAuthError('no_token_issued');
        }

        public function testBadTokenWhenWrong(): void
        {
            $this->flagAOn();
            $token = 'dchost_'.str_repeat('f', 64);
            $this->injectDb(['vps_masters' => ['vps_id' => ['4' => [
                'vps_id' => 4, 'vps_name' => 'h4', 'vps_ip' => self::REMOTE,
                'vps_token_hash' => $this->sha($token)
            ]]]]);
            $this->authHello(['role' => 'host', 'host_id' => 4, 'token' => 'WRONG-TOKEN']);
            $this->assertAuthError('bad_token');
        }

        public function testIpMismatchHardFailWithValidToken(): void
        {
            $this->flagAOn();
            $token = 'dchost_'.str_repeat('g', 64);
            $_SERVER['REMOTE_ADDR'] = '198.51.100.200';
            $this->injectDb(['vps_masters' => ['vps_id' => ['5' => [
                'vps_id' => 5, 'vps_name' => 'h5', 'vps_ip' => self::REMOTE,
                'vps_token_hash' => $this->sha($token)
            ]]]]);
            $this->authHello(['role' => 'host', 'host_id' => 5, 'token' => $token]);
            $this->assertAuthError('ip_mismatch');
        }

        /**
         * NEW (step 2.2 review fix, item 3): a HOST row with an empty stored IP
         * is an anomalous state that hard-fails ip_mismatch — it must NOT silently
         * skip the IP check the way a NULL-bot_ip bot does. Token is valid here.
         */
        public function testHostWithEmptyStoredIpHardFailsIpMismatch(): void
        {
            $this->flagAOn();
            $token = 'dchost_'.str_repeat('h', 64);
            $this->injectDb(['vps_masters' => ['vps_id' => ['6' => [
                'vps_id' => 6, 'vps_name' => 'h6', 'vps_ip' => '',
                'vps_token_hash' => $this->sha($token)
            ]]]]);
            $this->authHello(['role' => 'host', 'host_id' => 6, 'token' => $token]);
            $this->assertAuthError('ip_mismatch');
        }

        public function testBotDisabled(): void
        {
            $this->flagAOn();
            $token = 'dcbot_'.str_repeat('i', 64);
            $this->injectDb(['ws_bots' => ['bot_id' => ['2' => [
                'bot_id' => 2, 'bot_name' => 'offbot', 'bot_ip' => null,
                'bot_enabled' => 0, 'bot_token_hash' => $this->sha($token)
            ]]]]);
            $this->authHello(['role' => 'bot', 'host_id' => 2, 'token' => $token]);
            $this->assertAuthError('bot_disabled');
        }

        public function testAdminBadSessionWhenInvalid(): void
        {
            $this->flagAOn();
            $this->injectDb(['sessions' => ['session_id' => []]]);
            $this->authHello(['role' => 'admin', 'session' => 'does-not-exist']);
            $this->assertAuthError('bad_session');
        }

        public function testAdminMissingSessionIsBadSession(): void
        {
            $this->flagAOn();
            $this->injectDb(['sessions' => ['session_id' => []]]);
            $this->authHello(['role' => 'admin']);
            $this->assertAuthError('bad_session');
        }

        /**
         * NEW (step 2.2 review fix, item 2): admin username/password is not a
         * defined v1 credential — it must be rejected with the distinct
         * unsupported_credential code (not bad_session), so clients know to switch
         * to session auth rather than retry the same MD5 shape.
         */
        public function testAdminUsernamePasswordIsUnsupportedCredential(): void
        {
            $this->flagAOn();
            $this->injectDb(['sessions' => ['session_id' => []]]);
            $this->authHello(['role' => 'admin', 'username' => 'root', 'password' => 'hunter2']);
            $this->assertAuthError('unsupported_credential');
        }

        public function testBadRole(): void
        {
            $this->flagAOn();
            $this->injectDb(['vps_masters' => ['vps_id' => []]]);
            $this->authHello(['role' => 'wizard']);
            $reply = $this->singleReply();
            $this->assertFalse($reply['ok']);
            $this->assertSame('bad_request', $reply['error']['code']);
            $this->assertContains(1, $this->closed());
            $this->assertEmpty($_SESSION['v1_authed'] ?? null);
        }

        // ================================================================
        // Rotation grace window (AUTH_DESIGN §6)
        // ================================================================

        public function testPrevTokenAcceptedWithinGraceWindow(): void
        {
            $this->flagAOn();
            $current = 'dchost_'.str_repeat('1', 64);
            $prev = 'dchost_'.str_repeat('2', 64);
            $this->injectDb(['vps_masters' => ['vps_id' => ['10' => [
                'vps_id' => 10, 'vps_name' => 'h10', 'vps_ip' => self::REMOTE,
                'vps_token_hash' => $this->sha($current),
                'vps_token_prev_hash' => $this->sha($prev),
                'vps_token_prev_expires' => date('Y-m-d H:i:s', time() + 3600)
            ]]]]);

            // Present the PREVIOUS token while still within grace -> success.
            $this->authHello(['role' => 'host', 'host_id' => 10, 'token' => $prev]);
            $reply = $this->singleReply();
            $this->assertTrue($reply['ok'], 'prev token within grace window must be accepted');
            $this->assertTrue($_SESSION['v1_authed']);
        }

        public function testPrevTokenRejectedAfterGraceExpiry(): void
        {
            $this->flagAOn();
            $current = 'dchost_'.str_repeat('3', 64);
            $prev = 'dchost_'.str_repeat('4', 64);
            $this->injectDb(['vps_masters' => ['vps_id' => ['11' => [
                'vps_id' => 11, 'vps_name' => 'h11', 'vps_ip' => self::REMOTE,
                'vps_token_hash' => $this->sha($current),
                'vps_token_prev_hash' => $this->sha($prev),
                'vps_token_prev_expires' => date('Y-m-d H:i:s', time() - 60)
            ]]]]);

            // Present the PREVIOUS token AFTER expiry -> bad_token.
            $this->authHello(['role' => 'host', 'host_id' => 11, 'token' => $prev]);
            $this->assertAuthError('bad_token');
        }

        // ================================================================
        // auth_required gate + dormancy (dispatchV1 wrapping handleAuthHello)
        // ================================================================

        /** auth.hello is the one op permitted pre-auth: it reaches the handler. */
        public function testAuthHelloAllowedAsFirstOp(): void
        {
            $this->flagAOn();
            $token = 'dchost_'.str_repeat('5', 64);
            $this->injectDb(['vps_masters' => ['vps_id' => ['12' => [
                'vps_id' => 12, 'vps_name' => 'h12', 'vps_ip' => self::REMOTE,
                'vps_token_hash' => $this->sha($token)
            ]]]]);
            // No prior auth in $_SESSION; auth.hello must NOT be gated.
            $this->authHello(['role' => 'host', 'host_id' => 12, 'token' => $token]);
            $reply = $this->singleReply();
            $this->assertTrue($reply['ok'], 'auth.hello must be allowed as the first op');
        }

        /**
         * With Flag A OFF, auth.hello itself is fully dormant — dispatchV1
         * early-returns before any handler runs: no DB lookup, no reply, no close.
         * (A throwOnFetch DB proves the handler was never reached.)
         */
        public function testAuthHelloDormantWhenFlagAOff(): void
        {
            // Flag A OFF: inject a client WITHOUT ws_new_handling set.
            $client = new AuthFakeGlobalDataClient();
            $GLOBALS['global'] = $client;
            $db = $this->injectDb(['vps_masters' => ['vps_id' => ['13' => [
                'vps_id' => 13, 'vps_name' => 'h13', 'vps_ip' => self::REMOTE,
                'vps_token_hash' => $this->sha('t')
            ]]]]);
            $db->throwOnFetch = true; // would blow up if the handler ran

            $this->authHello(['role' => 'host', 'host_id' => 13, 'token' => 't']);

            $this->assertCount(0, $this->sent(), 'Flag A OFF: auth.hello must produce no reply');
            $this->assertCount(0, $this->closed(), 'Flag A OFF: auth.hello must not close');
            $this->assertEmpty($_SESSION['v1_authed'] ?? null);
        }

        // ================================================================
        // enc:"gzip" decode on the auth.hello path (WS-revamp step 2.6)
        // ================================================================

        /** base64(gzcompress(json_encode(x))) — the §0 b64gz / enc:"gzip" form. */
        private function b64gz($value): string
        {
            return base64_encode(gzcompress(json_encode($value)));
        }

        /**
         * A VALID gzip-encoded auth.hello envelope must decode BEFORE the auth
         * handler reads data.role/host_id/token, and authenticate successfully —
         * proving the enc:"gzip" decode happens for the auth.hello path too.
         */
        public function testAuthHelloGzipEnvelopeSucceeds(): void
        {
            $this->flagAOn();
            $token = 'dchost_'.str_repeat('g', 64);
            $this->injectDb(['vps_masters' => ['vps_id' => ['1234' => [
                'vps_id' => 1234,
                'vps_name' => 'host-gzip',
                'vps_ip' => self::REMOTE,
                'vps_type' => 7,
                'vps_token_hash' => $this->sha($token)
            ]]]]);

            $data = ['role' => 'host', 'host_id' => 1234, 'token' => $token, 'module' => 'vps'];
            \Events::dispatchV1(1, [
                'v' => 1, 'id' => 'req-auth', 'op' => 'auth.hello', 'ts' => 1719700000,
                'enc' => 'gzip', 'data' => $this->b64gz($data)
            ]);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok'], 'gzip auth.hello must authenticate');
            $this->assertSame('vps1234', $reply['data']['uid']);
            $this->assertTrue($_SESSION['v1_authed']);
            $this->assertSame([], $this->closed(), 'gzip auth success must NOT close');
        }

        /**
         * A MALFORMED gzip auth.hello envelope. DOCUMENTS CURRENT BEHAVIOR (per
         * the step-2.6 review's noted asymmetry, which is explicitly NOT a spec
         * violation): dispatchV1() replies bad_request via sendV1Error but, unlike
         * every OTHER auth.hello failure path (which calls Gateway::closeClient),
         * the gzip-decode-failure branch does NOT close the connection.
         *
         * This test asserts that current behavior precisely: bad_request reply +
         * NO close + session left unauthenticated. If a later step decides to
         * close here for symmetry, this assertion is the tripwire to update.
         */
        public function testAuthHelloMalformedGzipRepliesBadRequestButDoesNotClose(): void
        {
            $this->flagAOn();
            $db = $this->injectDb(['vps_masters' => ['vps_id' => ['1' => [
                'vps_id' => 1, 'vps_name' => 'h', 'vps_ip' => self::REMOTE,
                'vps_token_hash' => $this->sha('t')
            ]]]]);
            $db->throwOnFetch = true; // the handler must never be reached (decode fails first)

            \Events::dispatchV1(1, [
                'v' => 1, 'id' => 'req-auth', 'op' => 'auth.hello', 'ts' => 1719700000,
                'enc' => 'gzip', 'data' => '!!!not valid base64!!!'
            ]);

            $reply = $this->singleReply();
            $this->assertFalse($reply['ok']);
            $this->assertSame('bad_request', $reply['error']['code'], 'malformed gzip auth.hello replies bad_request');
            // CURRENT BEHAVIOR (documented asymmetry): the connection is NOT closed
            // on the gzip-decode-failure branch, unlike other auth.hello failures.
            $this->assertSame([], $this->closed(), 'CURRENT: malformed-gzip auth.hello does NOT close (asymmetry vs other auth failures)');
            $this->assertEmpty($_SESSION['v1_authed'] ?? null, 'session stays unauthenticated');
        }
    }
}
