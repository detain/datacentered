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
 *  - SharedState migration (Phase 5.2): the whole presence fixture surface moved
 *    from the retired shared-variable store to the SharedState Redis facade,
 *    seeded through the SAME facade methods production writes with so every
 *    assertion also proves the JSON encode/decode round-trip. Shapes per the
 *    production writes (Applications/Chat/Events.php):
 *      dc:presence:client:<id>       STRING JSON record, EX PRESENCE_STALE_TTL
 *      dc:presence:index /  :active  ZSETs scored by last-seen ts
 *                                     (presenceIndexAdd → zAdd)
 *      dc:presence:ping: / ping_sent: STRING JSON ints (last pong RECEIVED /
 *                                     last ping SENT), EX PRESENCE_PING_TTL
 *      dc:presence:client_session:<cid>  STRING (the session id)
 *      dc:presence:session_clients:<sid> STRING JSON-encoding a PHP array —
 *                                     a STRING, NOT a Redis LIST
 *      dc:presence:timer:<sid>       STRING holding the OWNING PID, never a
 *                                     raw Workerman timer id
 *    The flat dc_presence_clients / dc_active_clients index arrays the fixtures
 *    used to hold are ZSETs now, so membership assertions read zRange order:
 *    ascending last-seen score, ties broken lexicographically by member.
 *
 * @see Events::handleDcPresenceJoin()
 * @see Events::handleDcPresenceLeave()
 * @see Events::onClose()
 * @see Events::trackSessionClient()
 * @see Events::setupSessionHealthTimer()
 * @see Applications/Chat/SharedState.php
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

        /**
         * Session/cleanup-scoped SharedState keys that trackSessionClient(),
         * onClose() and setupSessionHealthTimer() build inline (Events.php has
         * no constants for these) — pinned to the exact production names.
         * All are dc:presence: STRINGS written through SharedState::set.
         */
        private const CLIENT_SESSION_KEY_PREFIX = 'dc:presence:client_session:';
        private const SESSION_CLIENTS_KEY_PREFIX = 'dc:presence:session_clients:';
        private const SESSION_TIMER_KEY_PREFIX = 'dc:presence:timer:';
        private const MOVE_BATCH_KEY_PREFIX = 'dc:presence:move_batch:';
        private const CLEANUP_KEY_PREFIX = 'dc:presence:cleanup:';

        /** @var string tab A's 20-char hex connection id */
        private string $tabA;

        /** @var string tab B's 20-char hex connection id (same session/uid) */
        private string $tabB;

        /** @var string tab C's 20-char hex connection id (same session/uid) */
        private string $tabC;

        /** @var InMemoryRedis the double injected through SharedState::setClient() */
        private InMemoryRedis $redis;

        protected function setUp(): void
        {
            // Distinct gateway connection ids on the same gateway address — the
            // real multi-tab shape.
            $this->tabA = dc_client_id(1001);
            $this->tabB = dc_client_id(1002);
            $this->tabC = dc_client_id(1003);

            // FeatureFlagsTest/SharedStateTest injection discipline: a leaked
            // $GLOBALS['redis'] or a leftover facade memo from another suite
            // must never decide behaviour here, so drop both, then inject a
            // FRESH double (fresh keyspace AND fresh controllable clock).
            unset($GLOBALS['redis']);
            \SharedState::reset();
            $this->redis = new InMemoryRedis();
            \SharedState::setClient($this->redis);

            $this->resetState();
            $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        }

        protected function tearDown(): void
        {
            $this->resetState();
            \SharedState::setClient(null);
            \SharedState::reset();
            unset($GLOBALS['redis']);
        }

        private function resetState(): void
        {
            \GatewayWorker\Lib\Gateway::reset();
            \Channel\Client::reset();
            TestTimer::install();
            $_SESSION = [];
            \Events::$db = null;
            \Events::$channelClient = null;   // production configuration
            \Events::$moveBatchTimer = null;  // the flush slot is process-local static
        }

        /**
         * Flag A ON, Flag C (bot presence) OFF, authenticated admin session.
         *
         * Flags are written through SharedState under the exact FeatureFlags
         * VAR_* keys — an explicit value, never an omission: unset Flag A reads
         * ON, so dormancy only exists as a value an operator wrote (commit
         * 9eabb50). Registries the retired store kept as whole maps (hosts,
         * running, rooms) need no seeding here — Redis absence IS empty, which
         * is precisely the NULL-vs-empty trap the migration removed.
         *
         * @param array<string,int> $flagOverrides full dc:flag: keys => 0/1
         */
        private function authedSession(array $flagOverrides = []): void
        {
            $flags = array_merge([
                FeatureFlags::VAR_NEW_HANDLING => 1,
                FeatureFlags::VAR_DC_BOT_PRESENCE => 0,
            ], $flagOverrides);
            foreach ($flags as $key => $value) {
                $this->assertTrue(
                    \SharedState::set($key, $value),
                    "fixture: flag '{$key}' seeded with {$value}"
                );
            }

            $_SESSION['v1_authed'] = true;
            $_SESSION['uid'] = self::UID;
            $_SESSION['name'] = self::NAME;
            $_SESSION['ima'] = 'admin';
            $_SESSION['login'] = true;
        }

        // --------------------------------------------------------------------
        // Fixture seeding — exactly the shapes the production writers emit
        // --------------------------------------------------------------------

        /**
         * Seed a live scene member the way handleDcPresenceJoin writes one:
         * the STRING JSON record under EX PRESENCE_STALE_TTL plus membership in
         * BOTH index ZSETs scored by the record's ts (presenceIndexAdd).
         */
        private function seedPresenceMember(string $clientId, array $record): void
        {
            $this->assertTrue(\SharedState::set(
                \Events::DC_PRESENCE_KEY_PREFIX.$clientId,
                $record,
                \Events::PRESENCE_STALE_TTL
            ), 'fixture: presence record for '.$clientId);
            $this->seedIndexMembership($clientId, (int) $record['ts']);
        }

        /** Seed one member into both presence index ZSETs at $score — no record. */
        private function seedIndexMembership(string $clientId, int $score): void
        {
            $this->assertTrue(\SharedState::zAdd(\Events::DC_PRESENCE_INDEX_KEY, $score, $clientId));
            $this->assertTrue(\SharedState::zAdd(\Events::DC_ACTIVE_INDEX_KEY, $score, $clientId));
        }

        /** Seed the liveness stamps the way the pong path / pingers write them. */
        private function seedLiveness(string $clientId, ?int $lastPong = null, ?int $lastPingSent = null): void
        {
            if ($lastPong !== null) {
                $this->assertTrue(\SharedState::set(
                    \Events::DC_PONG_KEY_PREFIX.$clientId,
                    $lastPong,
                    \Events::PRESENCE_PING_TTL
                ), 'fixture: last pong for '.$clientId);
            }
            if ($lastPingSent !== null) {
                $this->assertTrue(\SharedState::set(
                    \Events::DC_PING_SENT_KEY_PREFIX.$clientId,
                    $lastPingSent,
                    \Events::PRESENCE_PING_TTL
                ), 'fixture: last ping sent for '.$clientId);
            }
        }

        /** Seed one connection→session STRING (trackSessionClient's shape). */
        private function seedClientSession(string $clientId, string $sessionId): void
        {
            $this->assertTrue(\SharedState::set(
                self::CLIENT_SESSION_KEY_PREFIX.$clientId,
                $sessionId,
                \Events::PRESENCE_SESSION_TTL
            ), 'fixture: client_session for '.$clientId);
        }

        /**
         * Seed the session→clients STRING that JSON-encodes a PHP array —
         * trackSessionClient writes a plain facade STRING here, NOT a Redis LIST.
         *
         * That value is read-modify-written (get → merge → set) with NO CAS:
         * concurrent joins/prunes on the SAME session are last-writer-wins and
         * one writer can drop another's member. This is the accepted trade-off,
         * identical to the rooms registry — see the Events.php ~:5519 note (a
         * dropped member self-heals on next reconcile; locking is deliberately
         * not reintroduced for these legacy same-field maps).
         *
         * @param array<int,string> $clientIds
         */
        private function seedSessionClients(string $sessionId, array $clientIds): void
        {
            $this->assertTrue(\SharedState::set(
                self::SESSION_CLIENTS_KEY_PREFIX.$sessionId,
                $clientIds,
                \Events::PRESENCE_SESSION_TTL
            ), 'fixture: session_clients for '.$sessionId);
        }

        // --------------------------------------------------------------------
        // Reads through the same facade the production readers use
        // --------------------------------------------------------------------

        /** @return mixed the decoded presence record, null when absent */
        private function presenceRecord(string $clientId)
        {
            return \SharedState::get(\Events::DC_PRESENCE_KEY_PREFIX.$clientId);
        }

        /** @return array<int,string> index ZSET members in Redis order */
        private function indexMembers(string $indexKey): array
        {
            return \SharedState::zRange($indexKey, 0, -1);
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
         * dc:presence:client:<hex>, nothing at dc:presence:client:<uid>.
         */
        public function testJoinCreatesPerConnectionEntriesNotPerUid(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 10.5, 'z' => -3.25, 'yaw' => 1.57], $this->tabA);
            $this->presenceOp('dc.presence.join', ['x' => 20.5, 'z' => -13.25, 'yaw' => 2.57], $this->tabB);

            $entryA = $this->presenceRecord($this->tabA);
            $entryB = $this->presenceRecord($this->tabB);
            $this->assertIsArray($entryA);
            $this->assertIsArray($entryB);

            $this->assertSame(10.5, $entryA['x'], 'each tab keeps its own position');
            $this->assertSame(20.5, $entryB['x']);
            $this->assertSame(self::UID, $entryA['uid'], 'both tabs report the same uid');
            $this->assertSame(self::UID, $entryB['uid']);
            $this->assertSame($this->tabA, $entryA['client_id']);
            $this->assertSame($this->tabB, $entryB['client_id']);

            $this->assertNotContains(
                \Events::DC_PRESENCE_KEY_PREFIX.self::UID,
                $this->redis->allKeys(),
                'a per-uid presence key would collapse the two tabs into one avatar'
            );
            $this->assertDeadChannelTransportUnused('multi-tab join:');
        }

        public function testJoinAddsBothConnectionsToBothIndexes(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0], $this->tabA);
            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0], $this->tabB);

            $this->assertSame([$this->tabA, $this->tabB], $this->indexMembers(\Events::DC_PRESENCE_INDEX_KEY));
            $this->assertSame([$this->tabA, $this->tabB], $this->indexMembers(\Events::DC_ACTIVE_INDEX_KEY));
        }

        /** Re-joining from the same connection must not duplicate index entries. */
        public function testRejoinFromSameConnectionIsIdempotentInIndexes(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 1.0, 'z' => 1.0, 'yaw' => 0.0], $this->tabA);
            $this->presenceOp('dc.presence.join', ['x' => 2.0, 'z' => 2.0, 'yaw' => 0.0], $this->tabA);

            $this->assertSame([$this->tabA], $this->indexMembers(\Events::DC_PRESENCE_INDEX_KEY));
            $this->assertSame([$this->tabA], $this->indexMembers(\Events::DC_ACTIVE_INDEX_KEY));
            // Whole-valued floats (2.0) round-trip through SharedState's JSON
            // encoding as ints — production re-reads them with (float) casts, so
            // compare the numeric value, not the accidental scalar type.
            $this->assertSame(2.0, (float) $this->presenceRecord($this->tabA)['x'], 'the entry is overwritten');
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
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            $this->seedPresenceMember($this->tabB, $this->entry($this->tabB, 100.0, 100.0, 3.14));

            $this->presenceOp('dc.presence.move', [
                'x' => 50.5, 'z' => -25.25, 'yaw' => 1.57, 'clientId' => $this->tabA,
            ], $this->tabA);

            $entryA = $this->presenceRecord($this->tabA);
            $entryB = $this->presenceRecord($this->tabB);

            $this->assertSame(50.5, $entryA['x']);
            $this->assertSame(-25.25, $entryA['z']);
            $this->assertSame(1.57, $entryA['yaw']);

            // (float) casts: whole-valued coordinates (100.0) come back as ints
            // through the facade's JSON round-trip; see the rejoin test note.
            $this->assertSame(100.0, (float) $entryB['x'], 'the other tab must be untouched');
            $this->assertSame(100.0, (float) $entryB['z']);
            $this->assertSame(3.14, $entryB['yaw']);
        }

        /** Batch keys are per connection, so two tabs coalesce into one event. */
        public function testEachConnectionGetsItsOwnBatchKeyAndOneSharedFlush(): void
        {
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            $this->seedPresenceMember($this->tabB, $this->entry($this->tabB));

            $this->presenceOp('dc.presence.move', ['x' => 10.0, 'z' => 20.0, 'yaw' => 0.5], $this->tabA);
            $this->presenceOp('dc.presence.move', ['x' => 30.0, 'z' => 40.0, 'yaw' => 1.0], $this->tabB);

            // The facade decodes the JSON, so the batch entry arrives as an array —
            // no manual json_decode around a raw string any more.
            $batchA = \SharedState::get(self::MOVE_BATCH_KEY_PREFIX.$this->tabA);
            $batchB = \SharedState::get(self::MOVE_BATCH_KEY_PREFIX.$this->tabB);
            $this->assertIsArray($batchA);
            $this->assertIsArray($batchB);
            $this->assertSame(10.0, (float) $batchA['x']);   // JSON int/float cast, see rejoin test note
            $this->assertSame(30.0, (float) $batchB['x']);

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
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            $this->seedPresenceMember($this->tabB, $this->entry($this->tabB, 100.0, 100.0, 3.14));

            $this->presenceOp('dc.presence.leave', [], $this->tabB, 'leave-tab-b');

            $this->assertNull($this->presenceRecord($this->tabB));
            $this->assertSame([$this->tabA], $this->indexMembers(\Events::DC_PRESENCE_INDEX_KEY));
            $this->assertSame(
                [$this->tabA],
                $this->indexMembers(\Events::DC_ACTIVE_INDEX_KEY),
                'leave must drop BOTH index ZSETs (the reviewer fix against recipient drift)'
            );

            $entryA = $this->presenceRecord($this->tabA);
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
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            $this->seedPresenceMember($this->tabB, $this->entry($this->tabB, 100.0, 100.0, 3.14));
            // Two live connections for this uid: onClose must not take the
            // single-connection logout branch.
            \GatewayWorker\Lib\Gateway::$uidClientIds[self::UID] = [$this->tabA, $this->tabB];

            \Events::onClose($this->tabA);

            $this->assertNull($this->presenceRecord($this->tabA));
            $this->assertIsArray(
                $this->presenceRecord($this->tabB),
                'the other tab survives a sibling disconnect'
            );
            $this->assertSame([$this->tabB], $this->indexMembers(\Events::DC_PRESENCE_INDEX_KEY));
            $this->assertSame([$this->tabB], $this->indexMembers(\Events::DC_ACTIVE_INDEX_KEY));

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
         * The sweep pings every connection in the presence index, keyed by hex
         * client_id, and records the SEND time under dc:presence:ping_sent:
         * (never dc:presence:ping:, which is reserved for pongs RECEIVED — BUG-B3).
         */
        public function testHealthTimerPingsEveryConnectionAndRecordsSendTime(): void
        {
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            $this->seedPresenceMember($this->tabB, $this->entry($this->tabB));
            $this->seedLiveness($this->tabA, time());
            $this->seedLiveness($this->tabB, time());

            TestTimer::run($this->armHealthTimer());

            $pinged = [];
            foreach (\GatewayWorker\Lib\Gateway::$sent as $entry) {
                $msg = json_decode($entry['message'], true);
                if (($msg['op'] ?? null) === 'ping' && ($msg['id'] ?? null) === 'keepalive') {
                    $pinged[] = (string) $entry['client_id'];
                }
            }
            $this->assertSame([$this->tabA, $this->tabB], $pinged, 'every live connection is pinged, by hex id');

            $this->assertGreaterThan(0, \SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA));
            $this->assertGreaterThan(0, \SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabB));
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed, 'responsive clients are never dropped');
            $this->assertIsArray($this->presenceRecord($this->tabA));
        }

        /**
         * REVIEW-FIX (missed-keepalive watchdog was unreachable in production).
         *
         * testHealthTimerDropsOnlyTheConnectionWhosePongIsStale above seeds a
         * STALE pong next to a FRESH index score, which cannot happen in
         * production: touchPresence() writes the presence record and BOTH index
         * scores from the same pong timestamp, so a client that goes quiet ages
         * its pong and its index score together. That fixture divergence is why
         * the watchdog stayed green while being dead.
         *
         * Modelled faithfully here — record ts, index score and last pong all
         * 200s old — the ordering inside the health callback used to guarantee
         * the client was never judged: sweepPresenceStale() runs FIRST and used
         * the same PRESENCE_STALE_TTL window with an INCLUSIVE bound, while the
         * drop test is strict, so the member was evicted from the index (and its
         * record deleted) one tick before it could ever be dropped. The socket
         * was left open forever — the exact leak this watchdog exists to close.
         *
         * The fix splits retention (PRESENCE_RECORD_TTL) from the drop threshold
         * (PRESENCE_STALE_TTL), so a silent client survives long enough to be
         * seen, judged and closed.
         */
        public function testHealthTimerClosesSilentConnectionWhoseIndexScoreAgedWithItsPong(): void
        {
            $now = time();
            $silentFor = 200;   // > PRESENCE_STALE_TTL (90), < PRESENCE_RECORD_TTL (270)
            $this->authedSession();

            // Exactly what touchPresence() leaves behind for a client whose last
            // pong was $silentFor seconds ago: record + both index scores stamped
            // from that same moment, record retained for PRESENCE_RECORD_TTL.
            $record = $this->entry($this->tabA);
            $record['ts'] = $now - $silentFor;
            $this->assertTrue(\SharedState::set(
                \Events::DC_PRESENCE_KEY_PREFIX.$this->tabA,
                $record,
                \Events::PRESENCE_RECORD_TTL
            ), 'fixture: retained presence record');
            $this->seedIndexMembership($this->tabA, $now - $silentFor);
            $this->seedLiveness($this->tabA, $now - $silentFor, $now - 30);

            // A healthy peer, so a bug that drops everything is distinguishable
            // from the fix.
            $this->seedPresenceMember($this->tabB, $this->entry($this->tabB));
            $this->seedLiveness($this->tabB, $now - 5, $now - 30);

            $this->assertGreaterThan(
                \Events::PRESENCE_STALE_TTL,
                \Events::PRESENCE_RECORD_TTL,
                'retention must outlive the drop threshold or the watchdog is unreachable'
            );

            TestTimer::run($this->armHealthTimer());

            $this->assertContains(
                $this->tabA,
                \GatewayWorker\Lib\Gateway::$closed,
                'a half-open socket must actually be closed — not silently swept out of the index'
            );
            $this->assertNotContains(
                $this->tabB,
                \GatewayWorker\Lib\Gateway::$closed,
                'the responsive peer is untouched'
            );
            $this->assertNull($this->presenceRecord($this->tabA), 'the dropped record is removed');
            $this->assertSame([$this->tabB], $this->indexMembers(\Events::DC_PRESENCE_INDEX_KEY));
            $this->assertSame([$this->tabB], $this->indexMembers(\Events::DC_ACTIVE_INDEX_KEY));
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
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            $this->seedPresenceMember($this->tabB, $this->entry($this->tabB));
            $this->seedLiveness($this->tabA, $now - 200, $now - 30);  // silent for 200s
            $this->seedLiveness($this->tabB, $now - 5, $now - 30);    // answering
            $this->seedClientSession($this->tabA, self::SESSION);
            $this->seedSessionClients(self::SESSION, [$this->tabA, $this->tabB]);

            TestTimer::run($this->armHealthTimer());

            $this->assertSame(
                [$this->tabA],
                \GatewayWorker\Lib\Gateway::$closed,
                'exactly the stale connection is closed'
            );
            $this->assertNull($this->presenceRecord($this->tabA));
            $this->assertSame([$this->tabB], $this->indexMembers(\Events::DC_PRESENCE_INDEX_KEY));
            $this->assertSame([$this->tabB], $this->indexMembers(\Events::DC_ACTIVE_INDEX_KEY));
            $this->assertIsArray($this->presenceRecord($this->tabB));

            // Dropped connection's liveness + session bookkeeping is cleared.
            $this->assertNull(\SharedState::get(\Events::DC_PONG_KEY_PREFIX.$this->tabA));
            $this->assertNull(\SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA));
            $this->assertNull(\SharedState::get(self::CLIENT_SESSION_KEY_PREFIX.$this->tabA));
            $this->assertSame([$this->tabB], \SharedState::get(self::SESSION_CLIENTS_KEY_PREFIX.self::SESSION));
            $this->assertNull(
                \SharedState::get(self::CLEANUP_KEY_PREFIX.$this->tabA),
                'the cleanup sentinel is released'
            );
        }

        /**
         * BUG-B4 (the important half): a connection that has been PINGED but has
         * not yet had time to PONG must NEVER be dropped. This is the case the
         * old single-key scheme could not express — writing the ping-send time
         * into dc:presence:ping: made "answered promptly" and "never answered"
         * identical.
         */
        public function testHealthTimerNeverDropsAConnectionThatWasJustPinged(): void
        {
            $now = time();
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            // Freshly connected: pinged 1s ago, no pong yet.
            $this->seedLiveness($this->tabA, null, $now - 1);

            TestTimer::run($this->armHealthTimer());

            $this->assertSame(
                [],
                \GatewayWorker\Lib\Gateway::$closed,
                'a client pinged 1s ago has not had 90s to answer and must survive'
            );
            $this->assertIsArray($this->presenceRecord($this->tabA));
            $this->assertSame([$this->tabA], $this->indexMembers(\Events::DC_PRESENCE_INDEX_KEY));
        }

        /**
         * dc:presence:ping_sent: marks the START of the current UNANSWERED
         * streak, so a connection with an outstanding ping must keep its
         * original send time — the sweep may only re-arm it once the previous
         * ping has been answered.
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
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            // Pinged 40s ago, never answered: the streak began at $streakStart.
            $this->seedLiveness($this->tabA, null, $streakStart);

            TestTimer::run($this->armHealthTimer());

            $this->assertSame(
                $streakStart,
                \SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA),
                'dc:presence:ping_sent: must keep marking the start of the unanswered streak, otherwise '
                .'the 90s watchdog can never fire for a client that never pongs'
            );
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed, '40s of silence is under the 90s threshold');
        }

        /** Once the previous ping HAS been answered, the next sweep re-arms the ping-sent stamp. */
        public function testHealthTimerRearmsPingSentAfterAPongArrived(): void
        {
            $now = time();
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            $this->seedLiveness($this->tabA, $now - 38, $now - 40);   // answered

            TestTimer::run($this->armHealthTimer());

            $this->assertGreaterThanOrEqual(
                $now,
                \SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA),
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
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            // Pinged 200s ago, never answered, and no pong has EVER arrived.
            $this->seedLiveness($this->tabA, null, $now - 200);

            TestTimer::run($this->armHealthTimer());

            $this->assertSame(
                [$this->tabA],
                \GatewayWorker\Lib\Gateway::$closed,
                'a connection with a >90s outstanding ping and no pong ever must be dropped'
            );
            $this->assertNull($this->presenceRecord($this->tabA));
        }

        /** A never-pinged connection is likewise never dropped. */
        public function testHealthTimerNeverDropsANeverPingedConnection(): void
        {
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));

            TestTimer::run($this->armHealthTimer());

            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed);
            $this->assertIsArray($this->presenceRecord($this->tabA));
        }

        /** Bots have a presence entry but no socket: never pinged, never dropped. */
        public function testHealthTimerSkipsBotEntries(): void
        {
            $botId = 'bot_'.\Events::BOT_DEFAULT_LOCATION;
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            $this->seedPresenceMember($botId, [
                'uid' => $botId, 'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                'ts' => time(), 'client_id' => $botId,
            ]);
            $this->seedLiveness($this->tabA, time());

            TestTimer::run($this->armHealthTimer());

            foreach (\GatewayWorker\Lib\Gateway::$sent as $entry) {
                $this->assertNotSame($botId, (string) $entry['client_id'], 'a bot has no socket to ping');
            }
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed);
            $this->assertIsArray($this->presenceRecord($botId), 'the bot avatar survives the sweep');
        }

        /**
         * Ghost-index pruning (fixes the leak this test used to pin).
         *
         * Phase 1 of the sweep queues every index member whose presence record
         * is missing: `$toDrop[] = ['clientId' => $clientId]; // stale entry,
         * mark for cleanup`. The drop loop's record-null branch used to run
         *
         *     SharedState::del($cleanupKey);
         *     continue;
         *
         * and that `continue` jumped over the loop-tail presenceIndexRemove(),
         * so a stale index entry — which has no presence record BY DEFINITION —
         * stayed in the dc:presence:* ZSETs forever, was re-queued on every 30s
         * sweep, and grew both index ZSETs without bound.
         *
         * FIXED in Events.php: the branch now drops the ghost from BOTH indexes
         * before releasing the cleanup sentinel, skipping only the record-delete/
         * closeClient() work an already-completed onClose() did.
         *
         * The ghost is seeded with a FRESH score on purpose: the TTL-native
         * sweepPresenceStale() backstop (90s window) cannot reclaim it, so this
         * run proves the health-timer drop loop is the mechanism doing the prune.
         */
        public function testHealthTimerPrunesGhostIndexEntries(): void
        {
            $ghost = dc_client_id(9999);
            $this->authedSession();
            $this->seedPresenceMember($this->tabA, $this->entry($this->tabA));
            $this->seedLiveness($this->tabA, time());
            // Index member with NO presence record, scored fresh so the
            // TTL-native sweepPresenceStale() backstop cannot reclaim it first.
            $this->seedIndexMembership($ghost, time());

            TestTimer::run($this->armHealthTimer());

            // FIXED: the ghost is gone from BOTH indexes; the live tab survives.
            $this->assertSame(
                [$this->tabA],
                $this->indexMembers(\Events::DC_PRESENCE_INDEX_KEY),
                'an index member with no presence record must be pruned from the presence index by the drop loop'
            );
            $this->assertSame(
                [$this->tabA],
                $this->indexMembers(\Events::DC_ACTIVE_INDEX_KEY),
                'presenceIndexRemove() drops BOTH indexes, so no ghost lingers in the active index either'
            );
            // A ghost has no socket by definition — closing one is not (and was
            // never) part of the prune; only the index membership is reclaimed.
            $this->assertNotContains($ghost, \GatewayWorker\Lib\Gateway::$closed);

            // Unchanged from the pinned version: the cleanup sentinel is released.
            $this->assertNull(
                \SharedState::get(self::CLEANUP_KEY_PREFIX.$ghost),
                'the dc:presence:cleanup: sentinel must not be leaked on the skip path'
            );
            // And the healthy connection is untouched.
            $this->assertIsArray($this->presenceRecord($this->tabA));
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
         * records the SEND time under dc:presence:ping_sent:.
         */
        public function testSecondConnectionForSessionPingsExistingConnections(): void
        {
            $this->authedSession();
            $this->seedClientSession($this->tabA, self::SESSION);
            $this->seedSessionClients(self::SESSION, [$this->tabA]);

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
            $this->assertGreaterThan(0, \SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA));
            $this->assertNull(
                \SharedState::get(\Events::DC_PONG_KEY_PREFIX.$this->tabA),
                'sending a ping must not fabricate a pong'
            );

            $this->assertSame(
                [$this->tabA, $this->tabB],
                \SharedState::get(self::SESSION_CLIENTS_KEY_PREFIX.self::SESSION),
                'the new connection joins the session list'
            );
            $this->assertSame(self::SESSION, \SharedState::get(self::CLIENT_SESSION_KEY_PREFIX.$this->tabB));
        }

        /**
         * BUG-B2: the prune timer is a ONE-SHOT 15s timer with [] args.
         * The old call passed `false` as $args (a TypeError — bool is never
         * ?array) and left $persistent at its default TRUE, leaking a repeating
         * timer per session.
         */
        public function testSessionPruneTimerIsOneShotWithArrayArgs(): void
        {
            $this->authedSession();
            $this->seedClientSession($this->tabA, self::SESSION);
            $this->seedSessionClients(self::SESSION, [$this->tabA]);

            $this->trackSessionClient($this->tabB, self::SESSION);

            $armed = TestTimer::withInterval(15.0);
            $this->assertCount(1, $armed, 'exactly one prune timer per duplicate session');
            $this->assertFalse($armed[0]['persistent'], 'the prune timer must NOT repeat');
            $this->assertSame([], $armed[0]['args'], 'Timer::add $args must be an array, never false');
        }

        /**
         * dc:presence:timer:<sessionId> is an OWNER PID marker, never a raw
         * timer id.
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
            $this->authedSession();
            $this->seedClientSession($this->tabA, self::SESSION);
            $this->seedSessionClients(self::SESSION, [$this->tabA]);

            $this->trackSessionClient($this->tabB, self::SESSION);

            $marker = \SharedState::get(self::SESSION_TIMER_KEY_PREFIX.self::SESSION);
            $this->assertSame(getmypid(), $marker, 'the shared marker must be the OWNING PID');

            $armed = TestTimer::withInterval(15.0);
            $this->assertCount(1, $armed);
            $this->assertNotSame(
                $armed[0]['id'],
                $marker,
                'the raw (process-local) timer id must NOT be published to the shared store'
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
            $this->authedSession();
            $this->seedClientSession($this->tabA, self::SESSION);
            $this->seedSessionClients(self::SESSION, [$this->tabA]);

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
            $this->authedSession();
            $this->seedClientSession($this->tabA, self::SESSION);
            $this->seedClientSession($this->tabB, self::SESSION);
            $this->seedClientSession($this->tabC, self::SESSION);
            $this->seedSessionClients(self::SESSION, [$this->tabA, $this->tabB, $this->tabC]);

            $fourth = dc_client_id(1004);
            $this->trackSessionClient($fourth, self::SESSION);

            // The prune judges responsiveness against the ping IT just sent, so
            // the pongs have to land at/after that moment.
            $pingedAt = \SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA);
            $this->assertIsInt($pingedAt);
            $this->seedLiveness($this->tabA, $pingedAt + 3);
            $this->seedLiveness($this->tabB, $pingedAt + 2);
            $this->seedLiveness($this->tabC, $pingedAt + 1);
            $this->seedLiveness($fourth, $pingedAt + 9);

            TestTimer::run(TestTimer::withInterval(15.0)[0]['id']);

            $this->assertSame(
                [$this->tabB, $this->tabC],
                \GatewayWorker\Lib\Gateway::$closed,
                'only the 2 most-recently-responsive connections survive the cap'
            );
            $this->assertNull(\SharedState::get(self::CLIENT_SESSION_KEY_PREFIX.$this->tabB));
            $this->assertNull(\SharedState::get(self::CLIENT_SESSION_KEY_PREFIX.$this->tabC));
            $this->assertSame(
                [$this->tabA, $fourth],
                \SharedState::get(self::SESSION_CLIENTS_KEY_PREFIX.self::SESSION),
                'the session list keeps exactly the survivors'
            );
        }

        /**
         * BUG-B3: a connection that was pinged and stayed silent is dropped; a
         * connection that was never pinged in this round is left alone.
         */
        public function testSessionPruneDropsSilentButKeepsUnpingedConnections(): void
        {
            $this->authedSession();
            $this->seedClientSession($this->tabA, self::SESSION);
            $this->seedSessionClients(self::SESSION, [$this->tabA]);

            // Challenges A; B is the newcomer and is never challenged.
            $this->trackSessionClient($this->tabB, self::SESSION);
            // A stays silent (no pong written).

            TestTimer::run(TestTimer::withInterval(15.0)[0]['id']);

            $this->assertSame([$this->tabA], \GatewayWorker\Lib\Gateway::$closed);
            $this->assertSame(
                self::SESSION,
                \SharedState::get(self::CLIENT_SESSION_KEY_PREFIX.$this->tabB),
                'a connection that was never challenged must never be pruned'
            );
            $this->assertNull(\SharedState::get(\Events::DC_PONG_KEY_PREFIX.$this->tabA));
            $this->assertNull(\SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA));
        }

        /**
         * A connection that ANSWERED the session_check must survive even after
         * the 30s health sweep has re-pinged it and moved the ping-sent stamp on.
         *
         * The prune must compare the pong against the $pingedAt captured when IT
         * pinged, not against the shared dc:presence:ping_sent: key — otherwise
         * a client that answered promptly is closed purely because an unrelated
         * timer pinged it again 1s before the prune ran.
         */
        public function testSessionPruneKeepsAnsweringConnectionWhenHealthTimerRepingsAfterwards(): void
        {
            $this->authedSession();
            $this->seedClientSession($this->tabA, self::SESSION);
            $this->seedSessionClients(self::SESSION, [$this->tabA]);

            $this->trackSessionClient($this->tabB, self::SESSION);
            $pingedAt = \SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->tabA);

            // A answers the session_check…
            $this->seedLiveness($this->tabA, $pingedAt);
            // …then the 30s health sweep pings it again, moving ping_sent on.
            $this->seedLiveness($this->tabA, null, $pingedAt + 5);

            TestTimer::run(TestTimer::withInterval(15.0)[0]['id']);

            $this->assertSame(
                [],
                \GatewayWorker\Lib\Gateway::$closed,
                'answering the session_check must protect the connection regardless of later pings'
            );
            $this->assertSame(
                self::SESSION,
                \SharedState::get(self::CLIENT_SESSION_KEY_PREFIX.$this->tabA)
            );
        }

        /**
         * $sessionId is client-supplied (auth.hello data.session) and is
         * concatenated into SharedState KEY names, so it is fenced twice:
         *
         *  1. Events' own shape guard must refuse malformed/oversized values
         *     outright, writing NOTHING to the shared store — not even a
         *     namespaced key — so one client can never grow the keyspace with
         *     megabyte or arbitrary-byte key components.
         *  2. SharedState's namespace guard makes every non-dc:* key throw
         *     InvalidArgumentException BEFORE touching Redis. That guard is why
         *     the pre-migration flat key names (dc_presence_clients,
         *     dc_client_session:…) are now illegal writes, and it is what keeps
         *     a key-building bug inside Events out of the other tenants of the
         *     shared DB0.
         */
        public function testMalformedSessionIdIsRejectedAndWritesNothing(): void
        {
            $this->authedSession();

            $this->trackSessionClient($this->tabA, str_repeat('A', 200));
            $this->trackSessionClient($this->tabB, "bad key\n<script>");
            $this->trackSessionClient($this->tabC, '');

            foreach ($this->redis->allKeys() as $key) {
                $this->assertFalse(
                    str_starts_with($key, 'dc:presence:'),
                    "a malformed session id must not create a shared-store key, got '{$key}'"
                );
            }
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sent, 'a rejected session must not ping anyone');
            // A rejected session id returns before Events' prune branch
            // (Events::trackSessionClient guards at ~:1819, the 15s one-shot
            // Timer::add is at ~:1881), so no prune/health timer is ever armed.
            $this->assertSame([], TestTimer::withInterval(15.0), 'a rejected session arms no prune timer');

            // Guard 2a: Events' key builders compose inside the guarded namespace.
            $keyBuilder = new ReflectionMethod(\Events::class, 'dcPresenceKey');
            $keyBuilder->setAccessible(true);
            $this->assertSame(
                \Events::DC_PRESENCE_KEY_PREFIX.$this->tabA,
                $keyBuilder->invoke(null, $this->tabA),
                "Events' presence key builder is DC_PRESENCE_KEY_PREFIX + connection id"
            );
            foreach ([
                \Events::DC_PRESENCE_KEY_PREFIX,
                \Events::DC_PONG_KEY_PREFIX,
                \Events::DC_PING_SENT_KEY_PREFIX,
                self::CLIENT_SESSION_KEY_PREFIX,
                self::SESSION_CLIENTS_KEY_PREFIX,
                self::SESSION_TIMER_KEY_PREFIX,
                self::MOVE_BATCH_KEY_PREFIX,
                self::CLEANUP_KEY_PREFIX,
            ] as $prefix) {
                $this->assertStringStartsWith(
                    \SharedState::PREFIX_PRESENCE,
                    $prefix,
                    "presence key prefix '{$prefix}' must live inside the facade's dc:presence: namespace"
                );
            }

            // Guard 2b: the pre-migration key names escape dc:* and must throw.
            foreach ([
                'dc_presence_clients',
                'dc_active_clients',
                'dc_client_session:'.$this->tabA,
                'dc_session_clients:'.self::SESSION,
            ] as $retiredKey) {
                try {
                    \SharedState::get($retiredKey);
                    $this->fail("SharedState::get('{$retiredKey}') must throw — a key outside dc:* escapes the namespace guard");
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString($retiredKey, $e->getMessage());
                }
            }
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
            $this->authedSession();
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
            $this->assertSame(
                self::SESSION,
                \SharedState::get(self::CLIENT_SESSION_KEY_PREFIX.$this->tabA)
            );
            $this->assertSame([0 => $this->tabA], \SharedState::get(self::SESSION_CLIENTS_KEY_PREFIX.self::SESSION));
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
         * dc:flag:ws_new_handling reads ON (commit 9eabb50). The previous
         * version of this test simply omitted the variable and therefore
         * asserted dormancy while the handler ran.
         */
        public function testPresenceOpsAreDormantWhenFlagAIsExplicitlyOff(): void
        {
            $this->authedSession([FeatureFlags::VAR_NEW_HANDLING => 0]);

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0], $this->tabA);

            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sent, 'Flag A OFF: no reply');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sentToGroup, 'Flag A OFF: no broadcast');
            $this->assertNull($this->presenceRecord($this->tabA));
            foreach ($this->redis->allKeys() as $key) {
                $this->assertFalse(
                    str_starts_with($key, 'dc:presence:'),
                    "dormant Flag A must write no presence key at all, got '{$key}'"
                );
            }
            $this->assertDeadChannelTransportUnused('flag A off:');
        }
    }
}
