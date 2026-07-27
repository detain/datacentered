<?php

/**
 * Test seam for Events::handleDcPresenceJoin(), handleDcPresenceMove(),
 * handleDcPresenceLeave() — the dc.presence.* v1 ops added in
 * WS-revamp Phase 2 step 2.9 (dc.md §§6–10).
 *
 * Same Gateway-stub technique as EventsV1AuthHelloTest: the shared
 * tests/V1TestSupport.php declares a lightweight fake \GatewayWorker\Lib\Gateway
 * *before* Events.php loads, so the composer autoloader never pulls the real
 * gateway transport; every reply, close, session write, uid bind and group join
 * is captured for assertion.
 *
 * Channel::publish() is faked via FakeChannelClient (declared before Events.php
 * so the alias is in place when Events.php loads). The fake captures published
 * messages for assertion.
 *
 * Presence data (join/move/leave) is stored exclusively in GlobalData
 * ($global->dc_presence[$uid]), so no FakeAuthDb is needed — auth is verified
 * via the existing v1_authed session flag and $_SESSION['uid'] set by
 * handleAuthHello().
 *
 * Because the handlers don't exist yet, all tests in this class are expected
 * to FAIL until Steps 7–10 are completed.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake Gateway seam, then requires FeatureFlags + Events.
    require_once __DIR__.'/V1TestSupport.php';

    /**
     * In-memory stand-in for \Channel\Client (the Workerman channel pub/sub
     * client used to broadcast presence events to other processes/sessions).
     * Declared BEFORE Events.php loads so the class alias redirect is in place
     * when Channel::publish() is first called.
     *
     * Captures every publish call for test assertion:
     *   $published[] = ['channel' => <group>, 'message' => <json string>]
     */
    if (!class_exists('Channel\Client')) {
        class_alias(stdClass::class, 'Channel\Client');
    }

    class FakeChannelClient
    {
        /** @var array<int,array{channel:string,message:string}> */
        public static $published = [];

        public static function publish($channel, $message)
        {
            self::$published[] = ['channel' => $channel, 'message' => $message];
            return true;
        }

        /** Reset capture array between tests. */
        public static function reset(): void
        {
            self::$published = [];
        }
    }

    /**
     * Note: \Channel\Client is autoloaded by composer from
     * vendor/workerman/channel/src/Client.php. We cannot class_alias over it.
     * The real publish() would try to connect to the Channel server (port 3333)
     * which is not running in the test environment, so any channel broadcast
     * calls are effectively no-ops. Test assertions on channel broadcasts
     * (testJoinBroadcastsToChannel etc.) will fail until the handlers are
     * implemented and the channel broadcast is wired up in Events.php.
     *
     * To make Channel::publish() testable in future, Events.php would need to
     * use a injectable channel seam (e.g. a static $channelClient property on
     * Events that defaults to \Channel\Client but can be replaced in tests).
     */
    // Intentionally commented out — Channel\Client is autoloaded:
    // class_alias(FakeChannelClient::class, 'Channel\Client');

    /**
     * In-memory GlobalData client for dc.presence tests.
     * Uses its own class name to avoid collision with
     * AuthFakeGlobalDataClient defined in EventsV1AuthHelloTest.php
     * (which has a different __get return-by-value vs our &__get).
     *
     * Needed to:
     *   (1) Flip Flag A ON via GlobalData (ws_new_handling = 1)
     *   (2) Back the $global->dc_presence hash that join/move/leave update
     *       via &__get returning $this->store['dc_presence'] by reference
     */
    if (!class_exists('AuthFakeGlobalDataClientForDcPresence')) {
        class AuthFakeGlobalDataClientForDcPresence extends \GlobalData\Client
        {
            /** @var array<string,mixed> */
            public $store = [];

            /**
             * Override constructor — real GlobalData\Client needs an address but
             * our in-memory fake does not.
             */
            public function __construct()
            {
            }

            /**
             * Returns $this->store[$key] by reference — both reads and writes via
             * $global->dc_presence[$uid] and direct $global->store['dc_presence'][$uid]
             * hit the SAME underlying array (no public property to shadow __get).
             *
             * IMPORTANT: do NOT auto-create missing keys. Auto-creation makes
             * isset() return true for a key that was never set (e.g. ws_new_handling
             * would appear "set" as [] via __get auto-init, breaking Flag A because
             * (bool)([]) is false). The handler's own guard pattern works without
             * __get auto-creation (it does isset first, then = [] if needed).
             */
            public function &__get($key)
            {
                // Handle per-uid keys like 'dc_presence:admin77'
                if (strpos($key, 'dc_presence:') === 0) {
                    if (!isset($this->store[$key]) || !is_array($this->store[$key])) {
                        $this->store[$key] = [];
                    }
                    return $this->store[$key];
                }
                if (array_key_exists($key, $this->store)) {
                    return $this->store[$key];
                }
                // Key never stored — return a static dummy by reference so that
                // indexed assignments ($arr[$idx] = $val) don't Fatal error.
                // The handler pre-populates store['dc_presence'] in flagAOnAuthed()
                // so this dummy is only hit for genuinely unknown keys.
                static $dummy = [];
                return $dummy;
            }

            public function __set($key, $value)
            {
                $this->store[$key] = $value;
            }

            /**
             * __isset returns true when the key exists and is not null.
             * Using array_key_exists + null check (not isset which returns false
             * for null AND is fooled by auto-created [] in __get).
             */
            public function __isset($key)
            {
                // Handle per-uid keys like 'dc_presence:admin77'
                if (strpos($key, 'dc_presence:') === 0) {
                    return isset($this->store[$key]);
                }
                return array_key_exists($key, $this->store) && $this->store[$key] !== null;
            }

            public function __unset($key)
            {
                // Handle per-uid keys like 'dc_presence:admin77'
                if (strpos($key, 'dc_presence:') === 0) {
                    unset($this->store[$key]);
                    return;
                }
                unset($this->store[$key]);
            }

            /** Minimal CAS used by the host+vps success path's hosts-map update. */
            public function cas($key, $old, $new)
            {
                $current = $this->store[$key] ?? null;
                // Handle comparison for arrays (old dc_presence behavior)
                if (is_array($current) && is_array($old)) {
                    // For the old monolithic key, compare by value (same array contents)
                    if ($current === $old) {
                        $this->store[$key] = $new;
                        return true;
                    }
                    return false;
                }
                // For per-uid keys, reference comparison is fine
                if ($current === $old) {
                    $this->store[$key] = $new;
                    return true;
                }
                return false;
            }
        }
    }

    /**
     * Tests for Events dc.presence.* v1 ops: join, move, leave.
     * All tests require Flag A ON and a valid v1-authed session (set by
     * handleAuthHello() via the auth.hello op).
     */
    class EventsV1DcPresenceTest extends TestCase
    {
        private const REMOTE = '203.0.113.10';
        private const CLIENT_ID = 1;
        private const UID = 'admin77';
        private const CHANNEL = 'dc_presence';

        protected function setUp(): void
        {
            $this->resetState();
            $_SERVER['REMOTE_ADDR'] = self::REMOTE;
            // Wire the injectable Channel seam so Channel::publish() calls
            // in Events handlers are captured by FakeChannelClient for assertion.
            \Events::$channelClient = [FakeChannelClient::class, 'publish'];
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
            \GatewayWorker\Lib\Gateway::$left = [];
            $_SESSION = [];
            \Events::$db = null;
            \Events::$channelClient = null;
            FakeChannelClient::reset();
            unset($GLOBALS['global']);

            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        /** Flip Flag A ON and set a valid v1-authed admin session. */
        private function flagAOnAuthed(): AuthFakeGlobalDataClientForDcPresence
        {
            $client = new AuthFakeGlobalDataClientForDcPresence();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 1;
            $client->store['hosts'] = [];
            // Pre-populate dc_presence via the store so the handler's $global->dc_presence
            // reads/writes hit this same array through &__get.
            $client->store['dc_presence'] = [];
            // uid index for the timer that broadcasts full presence state
            $client->store['dc_presence_uids'] = [self::UID];
            $GLOBALS['global'] = $client;

            // Simulate successful auth.hello: set the session flags that
            // dispatchV1() checks for the auth_required gate.
            $_SESSION['v1_authed'] = true;
            $_SESSION['uid'] = self::UID;
            $_SESSION['name'] = self::UID;
            $_SESSION['ima'] = 'admin';

            return $client;
        }

        /** Dispatch a v1 envelope as the given client (default=1). */
        private function dispatch(int $clientId, array $envelope): void
        {
            \Events::dispatchV1($clientId, $envelope);
        }

        /** Shorthand: send dc.presence.<op> with the given data + request id. */
        private function presenceOp(string $op, array $data, int $clientId = self::CLIENT_ID, string $id = 'req-presence'): void
        {
            $this->dispatch($clientId, [
                'v' => 1,
                'id' => $id,
                'op' => $op,
                'ts' => time(),
                'data' => $data,
            ]);
        }

        private function sent(): array
        {
            return \GatewayWorker\Lib\Gateway::$sent;
        }

        private function joined(): array
        {
            return \GatewayWorker\Lib\Gateway::$joined;
        }

        private function left(): array
        {
            return \GatewayWorker\Lib\Gateway::$left;
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

        /** Extract published messages for a given channel group. */
        private function publishedIn(string $channel): array
        {
            return array_values(array_filter(
                FakeChannelClient::$published,
                fn($p) => $p['channel'] === $channel
            ));
        }

        // ====================================================================
        // dc.presence.join
        // ====================================================================

        /**
         * join stores the member's position + metadata in $global->dc_presence[$uid].
         * We verify via the same accessor path the implementation uses:
         * $global->{'dc_presence:' . $uid} (through the &__get fake which returns
         * $this->store['dc_presence:admin77'] by reference).
         */
        public function testJoinAddsMemberToGlobalData(): void
        {
            $global = $this->flagAOnAuthed();

            $this->presenceOp('dc.presence.join', [
                'x' => 10.5,
                'z' => -3.25,
                'yaw' => 1.57,
            ]);

            // Verify via the per-uid key that the implementation uses:
            // $global->{'dc_presence:' . $uid} which the fake resolves via &__get
            // returning $this->store['dc_presence:admin77'] by reference.
            $entry = $global->{'dc_presence:' . self::UID};
            $this->assertIsArray($entry);
            $this->assertArrayHasKey(self::UID, $entry);
            $this->assertSame(10.5, $entry['x']);
            $this->assertSame(-3.25, $entry['z']);
            $this->assertSame(1.57, $entry['yaw']);
            $this->assertArrayHasKey('ts', $entry);
            $this->assertSame(self::UID, $entry['uid']);
        }

        /**
         * join broadcasts a dc.presence.joined event to the dc_presence channel.
         */
        public function testJoinBroadcastsToChannel(): void
        {
            $this->flagAOnAuthed();

            $this->presenceOp('dc.presence.join', [
                'x' => 0.0,
                'z' => 0.0,
                'yaw' => 0.0,
            ]);

            $published = $this->publishedIn(self::CHANNEL);
            $this->assertNotEmpty($published, 'dc.presence.join must broadcast to dc_presence channel');

            $msg = json_decode($published[0]['message'], true);
            $this->assertSame('dc.presence.joined', $msg['op']);
            $this->assertSame(self::UID, $msg['data']['uid']);
            // JSON encodes 0.0 as 0 (no fractional part); use delta comparison for floats.
            $this->assertEqualsWithDelta(0.0, $msg['data']['x'], 0.001);
            $this->assertEqualsWithDelta(0.0, $msg['data']['z'], 0.001);
            $this->assertEqualsWithDelta(0.0, $msg['data']['yaw'], 0.001);
        }

        /**
         * join replies with {ok: true, re: <id>} to the sender.
         */
        public function testJoinRepliesSuccessToClient(): void
        {
            $this->flagAOnAuthed();

            $this->presenceOp('dc.presence.join', ['x' => 1.0, 'z' => 2.0, 'yaw' => 0.5], self::CLIENT_ID, 'join-req');

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok'], 'join reply must be ok:true');
            $this->assertSame('join-req', $reply['re']);
        }

        // ====================================================================
        // dc.presence.move
        // ====================================================================

        /**
         * move updates the member's x/z/yaw in $global->{'dc_presence:' . $uid}.
         */
        public function testMoveUpdatesMemberPosition(): void
        {
            $global = $this->flagAOnAuthed();

            // Pre-condition: member must have joined first (via &__get same array as store).
            $global->{'dc_presence:' . self::UID} = [
                'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0, 'ts' => time(),
            ];

            $this->presenceOp('dc.presence.move', [
                'x' => 99.9,
                'z' => -77.7,
                'yaw' => 3.14,
            ]);

            // Handler writes to $global->{'dc_presence:' . $uid} via &__get → same array as store.
            $entry = $global->{'dc_presence:' . self::UID};
            $this->assertSame(99.9, $entry['x']);
            $this->assertSame(-77.7, $entry['z']);
            $this->assertSame(3.14, $entry['yaw']);
        }

        /**
         * move broadcasts a dc.presence.updated event to the dc_presence channel.
         */
        public function testMoveBroadcastsUpdatedPosition(): void
        {
            $global = $this->flagAOnAuthed();
            // Pre-populate with the same shape the join handler would have stored.
            $global->{'dc_presence:' . self::UID} = [
                'uid' => self::UID, 'name' => self::UID,
                'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0, 'ts' => time(),
            ];

            $this->presenceOp('dc.presence.move', [
                'x' => 11.0, 'z' => 22.0, 'yaw' => 1.0,
            ]);

            $published = $this->publishedIn(self::CHANNEL);
            $this->assertNotEmpty($published, 'dc.presence.move must broadcast to dc_presence channel');

            $msg = json_decode($published[0]['message'], true);
            $this->assertSame('dc.presence.updated', $msg['op']);
            $this->assertSame(self::UID, $msg['data']['uid']);
            // JSON encodes floats as integers when no fractional part; use delta comparison.
            $this->assertEqualsWithDelta(11.0, $msg['data']['x'], 0.001);
            $this->assertEqualsWithDelta(22.0, $msg['data']['z'], 0.001);
            $this->assertEqualsWithDelta(1.0, $msg['data']['yaw'], 0.001);
        }

        /**
         * move silently ignores a client that has not yet called join
         * (no error reply, no broadcast, no crash).
         */
        public function testMoveSilentlyIgnoresUnjoinedMember(): void
        {
            $global = $this->flagAOnAuthed();
            // Ensure no prior join entry exists — per-uid key is unset, handler sees empty.
            unset($global->{'dc_presence:' . self::UID});

            $this->presenceOp('dc.presence.move', [
                'x' => 5.0, 'z' => 5.0, 'yaw' => 0.0,
            ]);

            $this->assertEmpty($this->sent(), 'move for unjoined member must send no reply');
            $this->assertEmpty($this->publishedIn(self::CHANNEL), 'move for unjoined member must not broadcast');
        }

        // ====================================================================
        // dc.presence.leave
        // ====================================================================

        /**
         * leave removes the member entry from $global->{'dc_presence:' . $uid}.
         */
        public function testLeaveRemovesMember(): void
        {
            $global = $this->flagAOnAuthed();
            $global->{'dc_presence:' . self::UID} = [
                'x' => 1.0, 'z' => 2.0, 'yaw' => 0.5, 'ts' => time(),
            ];

            $this->presenceOp('dc.presence.leave', []);

            // Handler removes via $global->{'dc_presence:' . $uid} → __unset removes key.
            $this->assertArrayNotHasKey(self::UID, $global->{'dc_presence:' . self::UID} ?? []);
        }

        /**
         * leave broadcasts a dc.presence.left event to the dc_presence channel.
         */
        public function testLeaveBroadcastsRemoval(): void
        {
            $global = $this->flagAOnAuthed();
            $global->{'dc_presence:' . self::UID} = [
                'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0, 'ts' => time(),
            ];

            $this->presenceOp('dc.presence.leave', []);

            $published = $this->publishedIn(self::CHANNEL);
            $this->assertNotEmpty($published, 'dc.presence.leave must broadcast to dc_presence channel');

            $msg = json_decode($published[0]['message'], true);
            $this->assertSame('dc.presence.left', $msg['op']);
            $this->assertSame(self::UID, $msg['data']['uid']);
        }

        /**
         * leave replies with {ok: true, re: <id>} to the sender.
         */
        public function testLeaveRepliesSuccessToClient(): void
        {
            $global = $this->flagAOnAuthed();
            $global->{'dc_presence:' . self::UID} = [
                'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0, 'ts' => time(),
            ];

            $this->presenceOp('dc.presence.leave', [], self::CLIENT_ID, 'leave-req');

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok'], 'leave reply must be ok:true');
            $this->assertSame('leave-req', $reply['re']);
        }

        // ====================================================================
        // Auth & Flag A gates
        // ====================================================================

        /**
         * Without a v1-authed session, any presence op is rejected with
         * auth_required and the connection is closed (same gate as all other
         * post-auth.hello ops).
         */
        public function testPresenceOpsRequireAuth(): void
        {
            $this->flagAOnAuthed();
            // Clear the auth flag that flagAOnAuthed set.
            $_SESSION['v1_authed'] = false;
            // Note: uid is still needed for the handler to identify the member.

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0]);

            $sent = $this->sent();
            $this->assertNotEmpty($sent, 'unauthenticated presence op must produce a reply');

            $reply = json_decode($sent[0]['message'], true);
            $this->assertFalse($reply['ok']);
            $this->assertSame('auth_required', $reply['error']['code']);
            $this->assertContains(self::CLIENT_ID, \GatewayWorker\Lib\Gateway::$closed);
        }

        /**
         * With Flag A OFF, dc.presence.* ops are fully dormant — no handler
         * runs, no reply, no broadcast. Same dormancy contract as all other
         * new-handling ops under Flag A off.
         */
        public function testPresenceOpsAreDormantWhenFlagAIsOff(): void
        {
            // Flag A OFF: inject a client WITHOUT ws_new_handling set.
            $client = new AuthFakeGlobalDataClientForDcPresence();
            $GLOBALS['global'] = $client;

            // Set a fake session to simulate an authenticated client — but Flag A
            // off means dispatchV1 early-returns before even reading v1_authed.
            $_SESSION['v1_authed'] = true;
            $_SESSION['uid'] = self::UID;

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0]);

            $this->assertEmpty($this->sent(), 'Flag A OFF: presence ops must produce no reply');
            $this->assertEmpty($this->publishedIn(self::CHANNEL), 'Flag A OFF: presence ops must not broadcast');
            // Member must NOT have been added to dc_presence per-uid key.
            $this->assertArrayNotHasKey(self::UID, $GLOBALS['global']->{'dc_presence:' . self::UID} ?? []);
        }
    }
}
