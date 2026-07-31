<?php

/**
 * Multi-tab presence + session-health tests.
 *
 * Presence is keyed per WebSocket CONNECTION, not per uid, so several browser
 * tabs sharing one mystage session each get their own avatar. This file covers
 * that separation plus the two liveness mechanisms that key off client_id:
 * trackSessionClient()'s duplicate-session prune and setupSessionHealthTimer()'s
 * three-phase keepalive sweep.
 *
 * WHAT CHANGED HERE AND WHY
 *
 *  - client_id fixtures were ints (100 / 200). A gateway client_id is a 20-char
 *    hex STRING produced by Context::addressToClientId()
 *    (bin2hex(pack('NnN',…))). The A1 crash-loop (102 fatals / 155 worker
 *    restarts) was an int/string confusion — `int $client_id` on
 *    trackSessionClient() TypeErrors on every real id — so int fixtures are
 *    exactly the thing that must not exist here. All ids now come from
 *    dc_client_id().
 *
 *  - The broadcast transport was asserted through a MultiTabFakeChannelClient
 *    installed into Events::$channelClient in setUp(). Because the seam was
 *    ALWAYS installed, the file stayed green while production published to the
 *    dead \Channel\Client transport (BUG-A3, no subscriber / wrong port /
 *    service absent). The seam is gone; assertions are on
 *    Gateway::sendToGroup('dc_presence', …), and the dead transport is a
 *    recording tripwire that every test checks was never touched.
 *
 *  - The three "health timer" tests did not run the health timer. Two of them
 *    only asserted that fixtures the test itself had just written were readable;
 *    the third RE-IMPLEMENTED the drop logic inside the test body and asserted
 *    its own copy. Timer::add() used to throw outside a worker, so that was the
 *    only way to get green — TestTimer now injects a recording
 *    Workerman\Events\EventInterface, so setupSessionHealthTimer() is registered
 *    and FIRED for real here.
 *
 *  - The file header promised "auth.welcome includes clientId in response" but
 *    no test existed, and there IS no auth.welcome op: an auth reply correlates
 *    by re + ok and carries NO op. Covered for real now.
 *
 * @see Events::handleDcPresenceJoin()
 * @see Events::handleDcPresenceLeave()
 * @see Events::onClose()
 * @see Events::trackSessionClient()
 * @see Events::setupSessionHealthTimer()
 */

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__.'/V1TestSupport.php';

    class EventsV1DcPresenceMultiTabTest extends TestCase
    {
        use DcTransportAssertions;

        private const UID = 77;
        private const NAME = 'adminuser';
        private const SESSION = 'mystage-session-7f3c';

        /** @var string tab A's 20-char hex connection id */
        private string $tabA;

        /** @var string tab B's 20-char hex connection id (same session/uid) */
        private string $tabB;

        /** @var string tab C's 20-char hex connection id (same session/uid) */
        private string $tabC;

        protected function setUp(): void
        {
            // Distinct gateway connection ids on the same gateway address — the
            // real multi-tab shape.
            $this->tabA = dc_client_id(1001);
            $this->tabB = dc_client_id(1002);
            $this->tabC = dc_client_id(1003);
            $this->resetState();
            $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        }

        protected function tearDown(): void
        {
            $this->resetState();
        }

        private function resetState(): void
        {
            \GatewayWorker\Lib\Gateway::reset();
            \Channel\Client::reset();
            \GlobalData\Client::resetConstructed();
            TestTimer::install();
            $_SESSION = [];
            \Events::$db = null;
            \Events::$channelClient = null;   // production configuration
            \Events::$moveBatch = [];
            \Events::$moveBatchTimer = null;
            unset($GLOBALS['global']);

            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        /**
         * Flag A ON, Flag C (bot presence) OFF, authenticated admin session, and
         * the GlobalData indexes seeded the way a live worker's are.
         */
        private function authedSession(array $seed = []): InMemoryGlobalData
        {
            $global = new InMemoryGlobalData(array_merge([
                FeatureFlags::VAR_NEW_HANDLING => 1,
                FeatureFlags::VAR_DC_BOT_PRESENCE => 0,
                'hosts' => [],
                'running' => [],
                'rooms' => [],
                'dc_presence_clients' => [],
                'dc_active_clients' => [],
            ], $seed));
            $GLOBALS['global'] = $global;

            $_SESSION['v1_authed'] = true;
            $_SESSION['uid'] = self::UID;
            $_SESSION['name'] = self::NAME;
            $_SESSION['ima'] = 'admin';
            $_SESSION['login'] = true;

            return $global;
        }

        private function presenceOp(string $op, array $data, string $clientId, string $id = 'req-multitab'): void
        {
            \Events::dispatchV1($clientId, [
                'v' => 1, 'id' => $id, 'op' => $op, 'ts' => time(), 'data' => $data,
            ]);
        }

        private function entry(string $clientId, float $x = 0.0, float $z = 0.0, float $yaw = 0.0): array
        {
            return [
                'uid' => self::UID, 'name' => self::NAME,
                'x' => $x, 'z' => $z, 'yaw' => $yaw,
                'ts' => time(), 'client_id' => $clientId,
            ];
        }

        // ====================================================================
        // join: per-connection separation
        // ====================================================================

        /**
         * Two tabs, one uid: two independent entries at
         * dc_presence:client:<hex>, nothing at dc_presence:<uid>.
         */
        public function testJoinCreatesPerConnectionEntriesNotPerUid(): void
        {
            $global = $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 10.5, 'z' => -3.25, 'yaw' => 1.57], $this->tabA);
            $this->presenceOp('dc.presence.join', ['x' => 20.5, 'z' => -13.25, 'yaw' => 2.57], $this->tabB);

            $entryA = $global->raw('dc_presence:client:'.$this->tabA);
            $entryB = $global->raw('dc_presence:client:'.$this->tabB);
            $this->assertIsArray($entryA);
            $this->assertIsArray($entryB);

            $this->assertSame(10.5, $entryA['x'], 'each tab keeps its own position');
            $this->assertSame(20.5, $entryB['x']);
            $this->assertSame(self::UID, $entryA['uid'], 'both tabs report the same uid');
            $this->assertSame(self::UID, $entryB['uid']);
            $this->assertSame($this->tabA, $entryA['client_id']);
            $this->assertSame($this->tabB, $entryB['client_id']);

            $this->assertNotContains(
                'dc_presence:'.self::UID,
                $global->keys(),
                'a per-uid presence key would collapse the two tabs into one avatar'
            );
            $this->assertDeadChannelTransportUnused('multi-tab join:');
        }

        public function testJoinAddsBothConnectionsToBothIndexes(): void
        {
            $global = $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0], $this->tabA);
            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0], $this->tabB);

            $this->assertSame([$this->tabA, $this->tabB], $global->raw('dc_presence_clients'));
            $this->assertSame([$this->tabA, $this->tabB], $global->raw('dc_active_clients'));
        }

        /** Re-joining from the same connection must not duplicate index entries. */
        public function testRejoinFromSameConnectionIsIdempotentInIndexes(): void
        {
            $global = $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 1.0, 'z' => 1.0, 'yaw' => 0.0], $this->tabA);
            $this->presenceOp('dc.presence.join', ['x' => 2.0, 'z' => 2.0, 'yaw' => 0.0], $this->tabA);

            $this->assertSame([$this->tabA], $global->raw('dc_presence_clients'));
            $this->assertSame([$this->tabA], $global->raw('dc_active_clients'));
            $this->assertSame(2.0, $global->raw('dc_presence:client:'.$this->tabA)['x'], 'the entry is overwritten');
        }

        /**
         * The joined broadcast identifies the CONNECTION with camelCase clientId
         * (the frontend keys avatars on it) and goes out on the Gateway group.
         */
        public function testJoinBroadcastCarriesHexClientIdOverGatewayGroup(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0], $this->tabA);

            $events = $this->presenceGroupEvents('dc.presence.joined');
            $this->assertCount(1, $events);
            $this->assertIsV1Event($events[0], 'dc.presence.joined');
            $this->assertSame($this->tabA, $events[0]['data']['clientId']);
            $this->assertIsString($events[0]['data']['clientId'], 'clientId must stay a string on the wire');
            $this->assertArrayNotHasKey('client_id', $events[0]['data']);
            $this->assertDeadChannelTransportUnused('multi-tab join broadcast:');
        }

        // ====================================================================
        // move: only the sending connection's entry changes
        // ====================================================================

        public function testMoveUpdatesOnlyTheSendingConnection(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA, $this->tabB],
                'dc_active_clients' => [$this->tabA, $this->tabB],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                'dc_presence:client:'.$this->tabB => $this->entry($this->tabB, 100.0, 100.0, 3.14),
            ]);

            $this->presenceOp('dc.presence.move', [
                'x' => 50.5, 'z' => -25.25, 'yaw' => 1.57, 'clientId' => $this->tabA,
            ], $this->tabA);

            $entryA = $global->raw('dc_presence:client:'.$this->tabA);
            $entryB = $global->raw('dc_presence:client:'.$this->tabB);

            $this->assertSame(50.5, $entryA['x']);
            $this->assertSame(-25.25, $entryA['z']);
            $this->assertSame(1.57, $entryA['yaw']);

            $this->assertSame(100.0, $entryB['x'], 'the other tab must be untouched');
            $this->assertSame(100.0, $entryB['z']);
            $this->assertSame(3.14, $entryB['yaw']);
        }

        /** Batch keys are per connection, so two tabs coalesce into one event. */
        public function testEachConnectionGetsItsOwnBatchKeyAndOneSharedFlush(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA, $this->tabB],
                'dc_active_clients' => [$this->tabA, $this->tabB],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                'dc_presence:client:'.$this->tabB => $this->entry($this->tabB),
            ]);

            $this->presenceOp('dc.presence.move', ['x' => 10.0, 'z' => 20.0, 'yaw' => 0.5], $this->tabA);
            $this->presenceOp('dc.presence.move', ['x' => 30.0, 'z' => 40.0, 'yaw' => 1.0], $this->tabB);

            $batchA = json_decode((string) $global->raw('dc_move_batch:'.$this->tabA), true);
            $batchB = json_decode((string) $global->raw('dc_move_batch:'.$this->tabB), true);
            $this->assertIsArray($batchA);
            $this->assertIsArray($batchB);
            $this->assertEqualsWithDelta(10.0, $batchA['x'], 0.001);
            $this->assertEqualsWithDelta(30.0, $batchB['x'], 0.001);

            $armed = TestTimer::withInterval(0.05);
            $this->assertCount(1, $armed, 'both tabs share ONE armed flush timer');

            TestTimer::run($armed[0]['id']);

            $events = $this->presenceGroupEvents('dc.presence.batch_updated');
            $this->assertCount(1, $events);
            $this->assertSame(
                [$this->tabA, $this->tabB],
                array_keys($events[0]['data']),
                'the batch is keyed by hex client_id, one entry per tab'
            );
        }

        // ====================================================================
        // leave / onClose: tab isolation
        // ====================================================================

        public function testLeaveRemovesOnlyTheLeavingConnection(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA, $this->tabB],
                'dc_active_clients' => [$this->tabA, $this->tabB],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                'dc_presence:client:'.$this->tabB => $this->entry($this->tabB, 100.0, 100.0, 3.14),
            ]);

            $this->presenceOp('dc.presence.leave', [], $this->tabB, 'leave-tab-b');

            $this->assertNull($global->raw('dc_presence:client:'.$this->tabB));
            $this->assertSame([$this->tabA], $global->raw('dc_presence_clients'));

            $entryA = $global->raw('dc_presence:client:'.$this->tabA);
            $this->assertIsArray($entryA, 'the surviving tab keeps its avatar');
            $this->assertSame($this->tabA, $entryA['client_id']);

            $events = $this->presenceGroupEvents('dc.presence.left');
            $this->assertCount(1, $events);
            $this->assertSame($this->tabB, $events[0]['data']['clientId']);
            $this->assertSame(self::UID, $events[0]['data']['uid'], 'uid is shared; clientId disambiguates');
            $this->assertDeadChannelTransportUnused('multi-tab leave:');
        }

        public function testOnCloseRemovesOnlyOwnConnectionAndAnnouncesIt(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA, $this->tabB],
                'dc_active_clients' => [$this->tabA, $this->tabB],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                'dc_presence:client:'.$this->tabB => $this->entry($this->tabB, 100.0, 100.0, 3.14),
            ]);
            // Two live connections for this uid: onClose must not take the
            // single-connection logout branch.
            \GatewayWorker\Lib\Gateway::$uidClientIds[self::UID] = [$this->tabA, $this->tabB];

            \Events::onClose($this->tabA);

            $this->assertNull($global->raw('dc_presence:client:'.$this->tabA));
            $this->assertIsArray(
                $global->raw('dc_presence:client:'.$this->tabB),
                'the other tab survives a sibling disconnect'
            );
            $this->assertSame([$this->tabB], $global->raw('dc_presence_clients'));
            $this->assertSame([$this->tabB], $global->raw('dc_active_clients'));

            $events = $this->presenceGroupEvents('dc.presence.left');
            $this->assertCount(1, $events);
            $this->assertSame($this->tabA, $events[0]['data']['clientId']);
            $this->assertDeadChannelTransportUnused('multi-tab onClose:');
        }

        // ====================================================================
        // setupSessionHealthTimer — ACTUALLY RUN (was never invoked before)
        // ====================================================================

        /** Register the 30s health timer and return its recorded timer id. */
        private function armHealthTimer(): int
        {
            \Events::setupSessionHealthTimer();
            $armed = TestTimer::withInterval(30.0);
            $this->assertCount(1, $armed, 'setupSessionHealthTimer must register exactly one 30s timer');
            $this->assertTrue($armed[0]['persistent'], 'the health sweep repeats');
            return $armed[0]['id'];
        }

        /**
         * The sweep pings every connection in dc_presence_clients, keyed by hex
         * client_id, and records the SEND time under dc_ping_sent: (never
         * dc_ping:, which is reserved for pongs RECEIVED — BUG-B3).
         */
        public function testHealthTimerPingsEveryConnectionAndRecordsSendTime(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA, $this->tabB],
                'dc_active_clients' => [$this->tabA, $this->tabB],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                'dc_presence:client:'.$this->tabB => $this->entry($this->tabB),
                \Events::DC_PONG_KEY_PREFIX.$this->tabA => time(),
                \Events::DC_PONG_KEY_PREFIX.$this->tabB => time(),
            ]);

            TestTimer::run($this->armHealthTimer());

            $pinged = [];
            foreach (\GatewayWorker\Lib\Gateway::$sent as $entry) {
                $msg = json_decode($entry['message'], true);
                if (($msg['op'] ?? null) === 'ping' && ($msg['id'] ?? null) === 'keepalive') {
                    $pinged[] = (string) $entry['client_id'];
                }
            }
            $this->assertSame([$this->tabA, $this->tabB], $pinged, 'every live connection is pinged, by hex id');

            $this->assertGreaterThan(0, $global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA));
            $this->assertGreaterThan(0, $global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabB));
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed, 'responsive clients are never dropped');
            $this->assertIsArray($global->raw('dc_presence:client:'.$this->tabA));
        }

        /**
         * BUG-B4: the watchdog drops a connection whose last PONG is older than
         * 90s — and only that one. Phase 2 used to overwrite the very value
         * Phase 3 tests, so the 90s check could never be true and this watchdog
         * was dead code.
         */
        public function testHealthTimerDropsOnlyTheConnectionWhosePongIsStale(): void
        {
            $now = time();
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA, $this->tabB],
                'dc_active_clients' => [$this->tabA, $this->tabB],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                'dc_presence:client:'.$this->tabB => $this->entry($this->tabB),
                \Events::DC_PONG_KEY_PREFIX.$this->tabA => $now - 200,  // silent for 200s
                \Events::DC_PING_SENT_KEY_PREFIX.$this->tabA => $now - 30,
                \Events::DC_PONG_KEY_PREFIX.$this->tabB => $now - 5,    // answering
                \Events::DC_PING_SENT_KEY_PREFIX.$this->tabB => $now - 30,
                'dc_client_session:'.$this->tabA => self::SESSION,
                'dc_session_clients:'.self::SESSION => [$this->tabA, $this->tabB],
            ]);

            TestTimer::run($this->armHealthTimer());

            $this->assertSame(
                [$this->tabA],
                \GatewayWorker\Lib\Gateway::$closed,
                'exactly the stale connection is closed'
            );
            $this->assertNull($global->raw('dc_presence:client:'.$this->tabA));
            $this->assertSame([$this->tabB], $global->raw('dc_presence_clients'));
            $this->assertIsArray($global->raw('dc_presence:client:'.$this->tabB));

            // Dropped connection's liveness + session bookkeeping is cleared.
            $this->assertNull($global->raw(\Events::DC_PONG_KEY_PREFIX.$this->tabA));
            $this->assertNull($global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA));
            $this->assertNull($global->raw('dc_client_session:'.$this->tabA));
            $this->assertSame([$this->tabB], $global->raw('dc_session_clients:'.self::SESSION));
            $this->assertNull($global->raw('dc_cleanup:'.$this->tabA), 'the cleanup sentinel is released');
        }

        /**
         * BUG-B4 (the important half): a connection that has been PINGED but has
         * not yet had time to PONG must NEVER be dropped. This is the case the
         * old single-key scheme could not express — writing the ping-send time
         * into dc_ping: made "answered promptly" and "never answered" identical.
         */
        public function testHealthTimerNeverDropsAConnectionThatWasJustPinged(): void
        {
            $now = time();
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA],
                'dc_active_clients' => [$this->tabA],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                // Freshly connected: pinged 1s ago, no pong yet.
                \Events::DC_PING_SENT_KEY_PREFIX.$this->tabA => $now - 1,
            ]);

            TestTimer::run($this->armHealthTimer());

            $this->assertSame(
                [],
                \GatewayWorker\Lib\Gateway::$closed,
                'a client pinged 1s ago has not had 90s to answer and must survive'
            );
            $this->assertIsArray($global->raw('dc_presence:client:'.$this->tabA));
            $this->assertSame([$this->tabA], $global->raw('dc_presence_clients'));
        }

        /**
         * dc_ping_sent: marks the START of the current UNANSWERED streak, so a
         * connection with an outstanding ping must keep its original send time —
         * the sweep may only re-arm it once the previous ping has been answered.
         *
         * Rewriting it on every sweep (the earlier half-fix) reproduced BUG-B4
         * for the never-ponged branch of dcPresenceIsStale(): the Phase 1
         * snapshot was then always ~30s old, `lastPingSent < now - 90` could
         * never be true, and a client that never pongs at ALL was immune to the
         * watchdog forever.
         */
        public function testHealthTimerDoesNotRearmPingSentWhileAPingIsOutstanding(): void
        {
            $now = time();
            $streakStart = $now - 40;
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA],
                'dc_active_clients' => [$this->tabA],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                // Pinged 40s ago, never answered: the streak began at $streakStart.
                \Events::DC_PING_SENT_KEY_PREFIX.$this->tabA => $streakStart,
            ]);

            TestTimer::run($this->armHealthTimer());

            $this->assertSame(
                $streakStart,
                $global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA),
                'dc_ping_sent must keep marking the start of the unanswered streak, otherwise the '
                .'90s watchdog can never fire for a client that never pongs'
            );
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed, '40s of silence is under the 90s threshold');
        }

        /** Once the previous ping HAS been answered, the next sweep re-arms dc_ping_sent. */
        public function testHealthTimerRearmsPingSentAfterAPongArrived(): void
        {
            $now = time();
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA],
                'dc_active_clients' => [$this->tabA],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                \Events::DC_PING_SENT_KEY_PREFIX.$this->tabA => $now - 40,
                \Events::DC_PONG_KEY_PREFIX.$this->tabA => $now - 38,   // answered
            ]);

            TestTimer::run($this->armHealthTimer());

            $this->assertGreaterThanOrEqual(
                $now,
                $global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA),
                'an answered ping means this sweep starts a NEW streak'
            );
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed);
        }

        /**
         * A connection that has NEVER ponged is dropped once its outstanding ping
         * has been unanswered for longer than the threshold — the second half of
         * the BUG-B4 fix. Without it such a client survives forever.
         */
        public function testHealthTimerEventuallyDropsAConnectionThatNeverPongs(): void
        {
            $now = time();
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA],
                'dc_active_clients' => [$this->tabA],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                // Pinged 200s ago, never answered, and no pong has EVER arrived.
                \Events::DC_PING_SENT_KEY_PREFIX.$this->tabA => $now - 200,
            ]);

            TestTimer::run($this->armHealthTimer());

            $this->assertSame(
                [$this->tabA],
                \GatewayWorker\Lib\Gateway::$closed,
                'a connection with a >90s outstanding ping and no pong ever must be dropped'
            );
            $this->assertNull($global->raw('dc_presence:client:'.$this->tabA));
        }

        /** A never-pinged connection is likewise never dropped. */
        public function testHealthTimerNeverDropsANeverPingedConnection(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA],
                'dc_active_clients' => [$this->tabA],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
            ]);

            TestTimer::run($this->armHealthTimer());

            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed);
            $this->assertIsArray($global->raw('dc_presence:client:'.$this->tabA));
        }

        /** Bots have a presence entry but no socket: never pinged, never dropped. */
        public function testHealthTimerSkipsBotEntries(): void
        {
            $botId = 'bot_'.\Events::BOT_DEFAULT_LOCATION;
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA, $botId],
                'dc_active_clients' => [$this->tabA, $botId],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                'dc_presence:client:'.$botId => ['uid' => $botId, 'x' => 0, 'z' => 0, 'yaw' => 0, 'client_id' => $botId],
                \Events::DC_PONG_KEY_PREFIX.$this->tabA => time(),
            ]);

            TestTimer::run($this->armHealthTimer());

            foreach (\GatewayWorker\Lib\Gateway::$sent as $entry) {
                $this->assertNotSame($botId, (string) $entry['client_id'], 'a bot has no socket to ping');
            }
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed);
            $this->assertIsArray($global->raw('dc_presence:client:'.$botId), 'the bot avatar survives the sweep');
        }

        /**
         * PRODUCTION BUG pinned (Applications/Chat/Events.php — not editable here).
         *
         * Phase 1 of the sweep queues every index entry whose presence record is
         * missing: `$toDrop[] = ['clientId' => $clientId]; // stale entry, mark
         * for cleanup`. But the drop loop then hits
         *
         *     if ($global->{'dc_cleanup:'.$clientId} && $global->$presenceKey === null) {
         *         unset($global->{'dc_cleanup:'.$clientId});
         *         continue;
         *     }
         *
         * and a stale index entry has a null presence key BY DEFINITION, so it
         * always takes that `continue` — jumping over the CAS that would remove
         * it from dc_presence_clients. The "stale entry, mark for cleanup" branch
         * is therefore unreachable: such ids stay in the index forever, are
         * re-queued on every 30s sweep, and the index grows without bound (every
         * sweep and every flushPresenceBatch() then iterates them).
         *
         * FIX (for the Events.php owner): in that branch, still run the
         * dc_presence_clients CAS removal before continuing — skip only the
         * closeClient()/presence-key work that onClose already did.
         *
         * This test pins today's behaviour so the leak stays visible; flip the
         * two assertions marked BUG when the fix lands.
         */
        public function testHealthTimerDoesNotYetPruneGhostIndexEntries(): void
        {
            $ghost = dc_client_id(9999);
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->tabA, $ghost],
                'dc_active_clients' => [$this->tabA, $ghost],
                'dc_presence:client:'.$this->tabA => $this->entry($this->tabA),
                \Events::DC_PONG_KEY_PREFIX.$this->tabA => time(),
            ]);

            TestTimer::run($this->armHealthTimer());

            // BUG: should be [$this->tabA] — the ghost is never removed.
            $this->assertSame(
                [$this->tabA, $ghost],
                $global->raw('dc_presence_clients'),
                'PRODUCTION BUG: an index entry with no presence record is queued for cleanup but '
                .'the drop loop `continue`s past the index CAS, so dc_presence_clients grows forever'
            );
            // BUG: a ghost should arguably also be closed; today it is not.
            $this->assertNotContains($ghost, \GatewayWorker\Lib\Gateway::$closed);

            // What IS correct today: the cleanup sentinel is released rather than leaked.
            $this->assertNull(
                $global->raw('dc_cleanup:'.$ghost),
                'the dc_cleanup sentinel must not be leaked on the skip path'
            );
            // And the healthy connection is untouched.
            $this->assertIsArray($global->raw('dc_presence:client:'.$this->tabA));
            $this->assertNotContains($this->tabA, \GatewayWorker\Lib\Gateway::$closed);
        }

        // ====================================================================
        // trackSessionClient — duplicate-session prune
        // ====================================================================

        private function trackSessionClient(string $clientId, string $sessionId): void
        {
            $method = new ReflectionMethod(\Events::class, 'trackSessionClient');
            $method->setAccessible(true);
            $method->invoke(null, $clientId, $sessionId);
        }

        /**
         * A second connection for a known session pings the existing ones and
         * records the SEND time under dc_ping_sent:.
         */
        public function testSecondConnectionForSessionPingsExistingConnections(): void
        {
            $global = $this->authedSession([
                'dc_client_session:'.$this->tabA => self::SESSION,
                'dc_session_clients:'.self::SESSION => [$this->tabA],
            ]);

            $this->trackSessionClient($this->tabB, self::SESSION);

            $pinged = [];
            foreach (\GatewayWorker\Lib\Gateway::$sent as $entry) {
                $msg = json_decode($entry['message'], true);
                if (($msg['id'] ?? null) === 'session_check') {
                    $pinged[] = (string) $entry['client_id'];
                    $this->assertSame('session_duplicate', $msg['data']['reason']);
                    $this->assertSame(2, $msg['data']['count'], 'count includes the new connection');
                }
            }
            $this->assertSame([$this->tabA], $pinged, 'only the pre-existing connection is challenged');
            $this->assertGreaterThan(0, $global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA));
            $this->assertNull(
                $global->raw(\Events::DC_PONG_KEY_PREFIX.$this->tabA),
                'sending a ping must not fabricate a pong'
            );

            $this->assertSame(
                [$this->tabA, $this->tabB],
                $global->raw('dc_session_clients:'.self::SESSION),
                'the new connection joins the session list'
            );
            $this->assertSame(self::SESSION, $global->raw('dc_client_session:'.$this->tabB));
        }

        /**
         * BUG-B2: the prune timer is a ONE-SHOT 15s timer with [] args.
         * The old call passed `false` as $args (a TypeError — bool is never
         * ?array) and left $persistent at its default TRUE, leaking a repeating
         * timer per session.
         */
        public function testSessionPruneTimerIsOneShotWithArrayArgs(): void
        {
            $this->authedSession([
                'dc_client_session:'.$this->tabA => self::SESSION,
                'dc_session_clients:'.self::SESSION => [$this->tabA],
            ]);

            $this->trackSessionClient($this->tabB, self::SESSION);

            $armed = TestTimer::withInterval(15.0);
            $this->assertCount(1, $armed, 'exactly one prune timer per duplicate session');
            $this->assertFalse($armed[0]['persistent'], 'the prune timer must NOT repeat');
            $this->assertSame([], $armed[0]['args'], 'Timer::add $args must be an array, never false');
        }

        /**
         * dc_timer:<sessionId> is an OWNER PID marker, never a raw timer id.
         *
         * Workerman timer ids are per-PROCESS and a duplicate-session connection
         * lands on whichever of the 5 BusinessWorkers the Gateway picked, so
         * Timer::del($idFromAnotherProcess) deletes an unrelated timer in THIS
         * process (realistically: this worker's bot move timer or a pending
         * presence flush). The real id stays in the process-local
         * self::$sessionPruneTimers — the same hazard class as THE BOT #4.
         */
        public function testSessionPruneTimerMarkerIsTheOwningPidNotATimerId(): void
        {
            $global = $this->authedSession([
                'dc_client_session:'.$this->tabA => self::SESSION,
                'dc_session_clients:'.self::SESSION => [$this->tabA],
            ]);

            $this->trackSessionClient($this->tabB, self::SESSION);

            $marker = $global->raw('dc_timer:'.self::SESSION);
            $this->assertSame(getmypid(), $marker, 'the shared marker must be the OWNING PID');

            $armed = TestTimer::withInterval(15.0);
            $this->assertCount(1, $armed);
            $this->assertNotSame(
                $armed[0]['id'],
                $marker,
                'the raw (process-local) timer id must NOT be published to GlobalData'
            );

            $local = new ReflectionProperty(\Events::class, 'sessionPruneTimers');
            $local->setAccessible(true);
            $this->assertSame(
                [self::SESSION => $armed[0]['id']],
                $local->getValue(),
                'the real timer id lives in the process-local static'
            );
        }

        /** Re-arming for the same session deletes the previous prune timer (MAJOR-5). */
        public function testRearmingSessionPruneDeletesThePreviousTimer(): void
        {
            $this->authedSession([
                'dc_client_session:'.$this->tabA => self::SESSION,
                'dc_session_clients:'.self::SESSION => [$this->tabA],
            ]);

            $this->trackSessionClient($this->tabB, self::SESSION);
            $firstTimerId = TestTimer::withInterval(15.0)[0]['id'];

            $this->trackSessionClient($this->tabC, self::SESSION);

            $this->assertContains(
                $firstTimerId,
                TestTimer::deleted(),
                'the previous one-shot must be deleted so a session cannot accumulate prune timers'
            );
            $armed = TestTimer::withInterval(15.0);
            $this->assertCount(1, $armed, 'exactly one live prune timer per session');
            $this->assertNotSame($firstTimerId, $armed[0]['id']);
        }

        /**
         * The prune keeps at most the 2 most-recently-responsive connections per
         * session and closes the rest.
         *
         * Four connections share the session; A/B/C are challenged by
         * trackSessionClient() and all answer, D (the newcomer) was not
         * challenged so it is never a drop candidate. Ranked by last pong:
         * D, A, B, C — so B and C fall outside the 2-connection cap.
         */
        public function testSessionPruneKeepsTwoMostRecentlyResponsiveConnections(): void
        {
            $global = $this->authedSession([
                'dc_client_session:'.$this->tabA => self::SESSION,
                'dc_client_session:'.$this->tabB => self::SESSION,
                'dc_client_session:'.$this->tabC => self::SESSION,
                'dc_session_clients:'.self::SESSION => [$this->tabA, $this->tabB, $this->tabC],
            ]);

            $fourth = dc_client_id(1004);
            $this->trackSessionClient($fourth, self::SESSION);

            // The prune judges responsiveness against the ping IT just sent, so
            // the pongs have to land at/after that moment.
            $pingedAt = $global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA);
            $this->assertIsInt($pingedAt);
            $global->seed([
                \Events::DC_PONG_KEY_PREFIX.$this->tabA => $pingedAt + 3,
                \Events::DC_PONG_KEY_PREFIX.$this->tabB => $pingedAt + 2,
                \Events::DC_PONG_KEY_PREFIX.$this->tabC => $pingedAt + 1,
                \Events::DC_PONG_KEY_PREFIX.$fourth => $pingedAt + 9,
            ]);

            TestTimer::run(TestTimer::withInterval(15.0)[0]['id']);

            $this->assertSame(
                [$this->tabB, $this->tabC],
                \GatewayWorker\Lib\Gateway::$closed,
                'only the 2 most-recently-responsive connections survive the cap'
            );
            $this->assertNull($global->raw('dc_client_session:'.$this->tabB));
            $this->assertNull($global->raw('dc_client_session:'.$this->tabC));
            $this->assertSame(
                [$this->tabA, $fourth],
                $global->raw('dc_session_clients:'.self::SESSION),
                'the session list keeps exactly the survivors'
            );
        }

        /**
         * BUG-B3: a connection that was pinged and stayed silent is dropped; a
         * connection that was never pinged in this round is left alone.
         */
        public function testSessionPruneDropsSilentButKeepsUnpingedConnections(): void
        {
            $global = $this->authedSession([
                'dc_client_session:'.$this->tabA => self::SESSION,
                'dc_session_clients:'.self::SESSION => [$this->tabA],
            ]);

            // Challenges A; B is the newcomer and is never challenged.
            $this->trackSessionClient($this->tabB, self::SESSION);
            // A stays silent (no pong written).

            TestTimer::run(TestTimer::withInterval(15.0)[0]['id']);

            $this->assertSame([$this->tabA], \GatewayWorker\Lib\Gateway::$closed);
            $this->assertSame(
                self::SESSION,
                $global->raw('dc_client_session:'.$this->tabB),
                'a connection that was never challenged must never be pruned'
            );
            $this->assertNull($global->raw(\Events::DC_PONG_KEY_PREFIX.$this->tabA));
            $this->assertNull($global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA));
        }

        /**
         * A connection that ANSWERED the session_check must survive even after
         * the 30s health sweep has re-pinged it and moved dc_ping_sent forward.
         *
         * The prune must compare the pong against the $pingedAt captured when IT
         * pinged, not against the shared dc_ping_sent: key — otherwise a client
         * that answered promptly is closed purely because an unrelated timer
         * pinged it again 1s before the prune ran.
         */
        public function testSessionPruneKeepsAnsweringConnectionWhenHealthTimerRepingsAfterwards(): void
        {
            $global = $this->authedSession([
                'dc_client_session:'.$this->tabA => self::SESSION,
                'dc_session_clients:'.self::SESSION => [$this->tabA],
            ]);

            $this->trackSessionClient($this->tabB, self::SESSION);
            $pingedAt = $global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA);

            // A answers the session_check…
            $global->seed([\Events::DC_PONG_KEY_PREFIX.$this->tabA => $pingedAt]);
            // …then the 30s health sweep pings it again, moving dc_ping_sent on.
            $global->seed([\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA => $pingedAt + 5]);

            TestTimer::run(TestTimer::withInterval(15.0)[0]['id']);

            $this->assertSame(
                [],
                \GatewayWorker\Lib\Gateway::$closed,
                'answering the session_check must protect the connection regardless of later pings'
            );
            $this->assertSame(self::SESSION, $global->raw('dc_client_session:'.$this->tabA));
        }

        /**
         * $sessionId is client-supplied (auth.hello data.session) and is
         * concatenated into GlobalData KEY names, so a malformed/oversized value
         * must be refused outright rather than allowed to grow the shared store.
         */
        public function testMalformedSessionIdIsRejectedAndWritesNothing(): void
        {
            $global = $this->authedSession();

            $this->trackSessionClient($this->tabA, str_repeat('A', 200));
            $this->trackSessionClient($this->tabB, "bad key\n<script>");
            $this->trackSessionClient($this->tabC, '');

            foreach ($global->keys() as $key) {
                $this->assertStringStartsNotWith(
                    'dc_session_clients:',
                    $key,
                    'a malformed session id must not create a GlobalData key'
                );
                $this->assertStringStartsNotWith('dc_client_session:', $key);
            }
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sent);
        }

        // ====================================================================
        // auth reply shape (Task 5) — there is no "auth.welcome" op
        // ====================================================================

        /**
         * The auth.hello success reply is a CORRELATED REPLY: {v, re, ok, data}
         * with NO `op`, and data.clientId is the connection's 20-char hex id.
         *
         * The old file header claimed "auth.welcome includes clientId in
         * response". There is no auth.welcome op — AUTH_DESIGN's "auth.error"
         * and "auth.welcome" names are diagram labels, not ops; the wire shape is
         * the generic §1 reply. Asserting an op-shaped welcome would pass only
         * against fiction, so this asserts the real thing, including that a hex
         * client_id survives the whole handler (trackSessionClient's A1 crash
         * lived on exactly this path).
         */
        public function testAdminAuthReplyIsCorrelatedAndCarriesHexClientId(): void
        {
            $global = $this->authedSession();
            $_SESSION = [];   // auth.hello must populate the session itself

            \Events::$db = new class {
                public function select($cols = '*')
                {
                    return $this;
                }

                public function from($t)
                {
                    return $this;
                }

                public function leftJoin($t, $c = null)
                {
                    return $this;
                }

                public function where($c)
                {
                    return $this;
                }

                public function bindValues(array $b)
                {
                    return $this;
                }

                public function query($q = '', $p = null, $f = null)
                {
                    return [[
                        'account_id' => EventsV1DcPresenceMultiTabTest::authUid(),
                        'account_lid' => 'adminuser',
                        'account_value' => null,
                    ]];
                }
            };

            \Events::dispatchV1($this->tabA, [
                'v' => 1, 'id' => 'auth-1', 'op' => 'auth.hello', 'ts' => time(),
                'data' => ['role' => 'admin', 'session' => self::SESSION],
            ]);

            $replies = $this->messagesToClient($this->tabA);
            $this->assertCount(1, $replies, 'exactly one auth reply');
            $reply = $replies[0];

            // THE contract: correlate by re + ok, and NO op.
            $this->assertIsV1Reply($reply, 'auth-1');
            $this->assertArrayNotHasKey('op', $reply, 'there is no auth.welcome op — replies carry re + ok only');

            $this->assertSame($this->tabA, $reply['data']['clientId'], 'the hex client_id is echoed verbatim');
            $this->assertIsString($reply['data']['clientId']);
            $this->assertSame(self::UID, $reply['data']['uid']);
            $this->assertSame('adminuser', $reply['data']['name']);
            $this->assertArrayHasKey('session', $reply['data'], 'hub-assigned session token');
            $this->assertArrayHasKey('hub_time', $reply['data']);
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed, 'a successful auth must not close');

            // The hex id must reach the group joins and the session tracker intact.
            $this->assertContains(
                ['client_id' => $this->tabA, 'group' => 'dc_presence'],
                \GatewayWorker\Lib\Gateway::$joined,
                'the connection is joined to the dc_presence group at auth (this is what makes '
                .'Gateway::sendToGroup the working presence transport)'
            );
            $this->assertContains(
                ['client_id' => $this->tabA, 'group' => 'admins'],
                \GatewayWorker\Lib\Gateway::$joined
            );
            $this->assertSame(self::SESSION, $global->raw('dc_client_session:'.$this->tabA));
            $this->assertTrue($_SESSION['v1_authed']);
        }

        /** Exposed for the anonymous DB double above. */
        public static function authUid(): int
        {
            return self::UID;
        }

        // ====================================================================
        // Auth gate / Flag A dormancy
        // ====================================================================

        public function testPresenceOpsRequireAuth(): void
        {
            $this->authedSession();
            $_SESSION['v1_authed'] = false;

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0], $this->tabA, 'join-req');

            $replies = $this->messagesToClient($this->tabA);
            $this->assertCount(1, $replies);
            $this->assertIsV1Reply($replies[0], 'join-req', false);
            $this->assertSame('auth_required', $replies[0]['error']['code']);
            $this->assertContains($this->tabA, \GatewayWorker\Lib\Gateway::$closed);
        }

        /**
         * Flag A must be set to 0 EXPLICITLY to be dormant — an unset
         * ws_new_handling reads ON (commit 9eabb50). The previous version of
         * this test simply omitted the variable and therefore asserted dormancy
         * while the handler ran.
         */
        public function testPresenceOpsAreDormantWhenFlagAIsExplicitlyOff(): void
        {
            $global = $this->authedSession([FeatureFlags::VAR_NEW_HANDLING => 0]);

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0], $this->tabA);

            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sent, 'Flag A OFF: no reply');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sentToGroup, 'Flag A OFF: no broadcast');
            $this->assertNull($global->raw('dc_presence:client:'.$this->tabA));
            $this->assertDeadChannelTransportUnused('flag A off:');
        }
    }
}
