<?php

/**
 * Tests for the DataCenter bot-presence system: a simulated visitor avatar that
 * walks the 3D scene while real users are there.
 *
 * WHAT CHANGED HERE AND WHY (migration A2: the retired shared-state store → SharedState Redis)
 *
 *  This whole file was near-completely rewritten for the lock model. The bot
 *  used to be owned by a raw "<host>:<pid>" marker plus a heartbeat ts in the
 *  retired shared-state store, and liveness was probed with is_dir('/proc/<pid>')
 *  — which was WRONG across
 *  the three datacentered instances that shared one store (a foreign pid that
 *  merely happened not to exist locally read as "owner gone" and a SECOND bot
 *  spawned over another host's). That whole mechanism is gone.
 *
 *  Ownership is now ONE SharedState Redis lock per location:
 *  dc:lock:bot_owner:<location> at BOT_OWNER_LOCK_TTL (10s). Acquiring it IS
 *  taking ownership; every moveBot() tick renews it (that renew IS the old
 *  heartbeat, now with a real, ENFORCED expiry), and a crashed owner's lock
 *  lapses so the next join takes over — no reaper, no /proc, no md5 CAS. The
 *  process-local Workerman timer id (THE BOT #4) stays process-local exactly as
 *  before: a Workerman id means nothing in another process, so a worker that
 *  loses the lock retires only ITS OWN timer.
 *
 *  Test-port consequences:
 *   - Rival/dead-owner fixtures no longer seed the retired store. A live rival is
 *     a RAW lock value ($redis->set('dc:lock:bot_owner:main', token,
 *     ['nx','ex'=>10]))
 *     — never SharedState::set(), whose JSON encoding would corrupt the raw
 *     token the Lua compare scripts string-match. A lapsed owner is a real
 *     acquire() fast-forwarded past the TTL.
 *   - Presence indexes are Redis ZSETs written through the facade (zAdd), so
 *     the retired store's empty-array seed (and its NULL-vs-[] cas livelock) has
 *     no analog: the first zAdd creates the index.
 *   - The old \Events::$moveBatch static was REMOVED in migration wave 5.1 (it had
 *     been dead on the presence path since A2), so it is absent from this file's
 *     reset list, as are the retired fake shared-store clients / FeatureFlags
 *     private-client resets; batch state is now the dc:presence:move_batch:<bot>
 *     key. Only the still-existing process-local $botTimers and $botLockTokens
 *     are reset by reflection.
 *
 *  The seam discipline from BUG-A3 is retained: Events::$channelClient stays
 *  NULL (production configuration) so broadcasts are asserted on
 *  Gateway::sendToGroup('dc_presence', …), and the dead \Channel\Client
 *  transport is a recording tripwire every broadcast test checks.
 *
 * @see Events::spawnBotForLocation()
 * @see Events::moveBot()
 * @see Events::cleanupBotForLocation()
 * @see Events::hasRealUsersAtLocation()
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the offline seams (Channel tripwire, recording Timer, fake
    // Gateway, InMemoryRedis) then loads FeatureFlags + Events through the
    // shared V1 support, so the whole bot cluster runs against the SharedState
    // facade with no socket to anywhere.
    require_once __DIR__.'/V1TestSupport.php';

    class EventsBotPresenceTest extends TestCase
    {
        use DcTransportAssertions;

        private const LOCATION = 'main';
        private const BOT_ID = 'bot_main';

        /** @var InMemoryRedis the SharedState double every state/lock assertion reads */
        private $redis;

        /** @var string a real user's 20-char hex connection id */
        private string $userId;

        protected function setUp(): void
        {
            $this->userId = dc_client_id(2001);
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
            TestTimer::install();
            $_SESSION = [];
            \Events::$db = null;
            \Events::$channelClient = null;   // production configuration
            \Events::$moveBatchTimer = null;

            // FeatureFlagsTest-style injection: a leaked $GLOBALS['redis'] from
            // another suite would win over the facade's own client, so start
            // from "no shared connection" and hand SharedState a fresh double.
            unset($GLOBALS['redis']);
            SharedState::reset();
            $this->redis = new InMemoryRedis();
            SharedState::setClient($this->redis);

            // Bot timer ids AND lock tokens are process-local statics that
            // survive between tests — a stale token would let a later moveBot()
            // "renew" against a keyspace that no longer holds it.
            $this->clearBotStatics();
        }

        // ====================================================================
        // SharedState key builders + seeding helpers (the lock model)
        // ====================================================================

        private function stateKey(): string
        {
            return \Events::BOT_STATE_KEY_PREFIX . self::LOCATION;
        }

        private function presenceKey(string $clientId): string
        {
            return \Events::DC_PRESENCE_KEY_PREFIX . $clientId;
        }

        private function roomBoundsKey(): string
        {
            return \Events::DC_ROOM_BOUNDS_KEY_PREFIX . self::LOCATION;
        }

        private function moveBatchKey(string $clientId = self::BOT_ID): string
        {
            return 'dc:presence:move_batch:' . $clientId;
        }

        /** The lock NAME SharedState::lock() takes (it applies dc:lock: itself). */
        private function ownerLockName(): string
        {
            return 'bot_owner:' . self::LOCATION;
        }

        /** The full RAW Redis key the owner lock lives at. */
        private function ownerLockKey(): string
        {
            return SharedState::PREFIX_LOCK . $this->ownerLockName();
        }

        /** Flag A ON (so dispatchV1 routes the integration joins) + Flag C ON. */
        private function botFlagOn(): void
        {
            SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 1);
            SharedState::set(FeatureFlags::VAR_DC_BOT_PRESENCE, 1);
        }

        /** Flag C explicitly OFF (Flag A stays ON — the gate is bot presence, not v1). */
        private function botFlagOff(): void
        {
            SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 1);
            SharedState::set(FeatureFlags::VAR_DC_BOT_PRESENCE, 0);
        }

        private function clearBotStatics(): void
        {
            foreach (['botTimers', 'botLockTokens'] as $name) {
                $prop = new ReflectionProperty(\Events::class, $name);
                $prop->setAccessible(true);
                $prop->setValue(null, []);
            }
        }

        /**
         * Forget ONLY our ownership token while KEEPING the move timer — the exact
         * precondition of Events::spawnBotForLocation()'s duplicate-timer retire
          * branch (~:5146 `isset($botTimers[$location]) && !isset($botLockTokens[$location])`).
         * Production reaches this when our lock lapses and a rival takes over
         * between ticks; we force it via reflection because both statics are
         * process-local and no shared write can empty just one of their slots.
         */
        private function dropOwnershipTokenOnly(): void
        {
            $prop = new ReflectionProperty(\Events::class, 'botLockTokens');
            $prop->setAccessible(true);
            $tokens = $prop->getValue();
            unset($tokens[self::LOCATION]);
            $prop->setValue(null, $tokens);
        }

        /** @return array<string,int> */
        private function getBotTimers(): array
        {
            $prop = new ReflectionProperty(\Events::class, 'botTimers');
            $prop->setAccessible(true);
            return $prop->getValue();
        }

        /** @return array<string,string> */
        private function getBotLockTokens(): array
        {
            $prop = new ReflectionProperty(\Events::class, 'botLockTokens');
            $prop->setAccessible(true);
            return $prop->getValue();
        }

        /** The token this process holds for the location (null when not owner). */
        private function ownerToken(): ?string
        {
            return $this->getBotLockTokens()[self::LOCATION] ?? null;
        }

        /** Raw keyspace view of a lock value (phpredis: a MISS is false, not null). */
        private function rawLockValue(): mixed
        {
            return $this->redis->get($this->ownerLockKey());
        }

        /** A frozen bot-state written by a now-dead owner (name-parameterised ghost). */
        private function seedGhostState(string $name, float $x = 1.0, float $z = 2.0): void
        {
            SharedState::set($this->stateKey(), [
                'uid' => self::BOT_ID, 'name' => $name, 'x' => $x, 'z' => $z, 'yaw' => 0.0,
                'target_x' => $x, 'target_z' => $z, 'ts' => time(),
                'client_id' => self::BOT_ID, 'location' => self::LOCATION,
            ], \Events::BOT_STATE_TTL);
        }

        /**
         * Another instance currently owns the bot: a RAW lock value carrying a
         * foreign token, never through SharedState::set (which would JSON-wrap
         * it and break the Lua token compare). This is the "marker naming a live
         * foreign owner" case of the old suite.
         */
        private function seedRivalOwnerLockAlive(string $token = 'otherhost:999:deadbeef'): void
        {
            $seeded = $this->redis->set($this->ownerLockKey(), $token, ['nx', 'ex' => \Events::BOT_OWNER_LOCK_TTL]);
            $this->assertTrue($seeded, 'precondition: the rival lock must actually be seeded');
        }

        /**
         * Reproduce a crashed owner: it acquired the lock (real TTL) and then
         * stopped renewing, so the TTL lapsed and the key self-freed — the exact
         * mechanism the retired pid/heartbeat probe emulated by hand. The 30s
         * state TTL outlives the 10s lock, so a ghost bot-state lingers, which is
         * precisely the takeover opportunity.
         */
        private function expireOwnerLock(): void
        {
            $ghost = SharedState::lock($this->ownerLockName(), \Events::BOT_OWNER_LOCK_TTL);
            $this->assertNotNull($ghost, 'precondition: the ghost owner acquires the lock');
            $this->redis->fastForward(\Events::BOT_OWNER_LOCK_TTL + 1);
            $this->assertFalse($this->rawLockValue(), 'precondition: the owner lock lapsed on TTL');
        }

        /** A real (non-bot) user's presence record + both index memberships, via the facade. */
        private function addRealUser(string $clientId, float $x = 0.0, float $z = 0.0): void
        {
            SharedState::set($this->presenceKey($clientId), [
                'uid' => 4242, 'name' => 'admin', 'x' => $x, 'z' => $z, 'yaw' => 0.0,
                'ts' => time(), 'client_id' => $clientId,
            ], \Events::PRESENCE_STALE_TTL);
            // Mirrors Events::presenceIndexAdd(): the member is written as a JSON
            // string through the facade so reads decode it back to the bare id.
            SharedState::zAdd(\Events::DC_PRESENCE_INDEX_KEY, time(), $clientId);
            SharedState::zAdd(\Events::DC_ACTIVE_INDEX_KEY, time(), $clientId);
        }

        /**
         * Establish real ownership the way production does — spawn through the
         * facade (which acquires the lock, records the token, arms the timer) —
         * then impose a deterministic bot-state so a single moveBot() tick is
         * reproducible. Returns the armed timer's id.
         */
        private function takeOwnershipAndSeedState(array $state): int
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            SharedState::set($this->stateKey(), $state, \Events::BOT_STATE_TTL);

            return $this->botMoveTimers()[0]['id'];
        }

        /** A bot state at the origin walking toward ($tx,$ty). */
        private function walkState(float $tx, float $tz, string $name = 'Walker', float $yaw = 0.0): array
        {
            return [
                'uid' => self::BOT_ID, 'name' => $name,
                'x' => 0.0, 'z' => 0.0, 'yaw' => $yaw,
                'target_x' => $tx, 'target_z' => $tz,
                'ts' => time(), 'client_id' => self::BOT_ID, 'location' => self::LOCATION,
            ];
        }

        private function seedRoomBounds(float $min, float $max): void
        {
            SharedState::set($this->roomBoundsKey(), [
                'minX' => -$min, 'maxX' => $max, 'minZ' => -$min, 'maxZ' => $max,
            ], \Events::PRESENCE_SESSION_TTL);
        }

        /** The recorded repeating bot move timer(s). */
        private function botMoveTimers(): array
        {
            return array_values(array_filter(
                TestTimer::added(),
                static fn(array $t) => abs($t['interval'] - \Events::BOT_MOVE_INTERVAL) < 1e-9
                    && $t['args'] === [self::LOCATION]
            ));
        }

        // ====================================================================
        // spawnBotForLocation
        // ====================================================================

        public function testSpawnBotCreatesStatePresenceEntryAndIndexes(): void
        {
            $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $entry = SharedState::get($this->presenceKey(self::BOT_ID));
            $this->assertIsArray($entry, 'the bot gets a presence entry like any client');
            $this->assertSame(self::BOT_ID, $entry['uid']);
            $this->assertSame(self::BOT_ID, $entry['client_id']);
            $this->assertSame(self::LOCATION, $entry['location']);
            $this->assertIsString($entry['name']);
            $this->assertIsFloat($entry['x']);
            $this->assertIsFloat($entry['z']);
            $this->assertIsFloat($entry['yaw']);

            $state = SharedState::get($this->stateKey());
            $this->assertIsArray($state);
            $this->assertArrayHasKey('target_x', $state);
            $this->assertArrayHasKey('target_z', $state);
            $this->assertSame($entry['x'], $state['x'], 'presence entry and bot state start identical');

            $this->assertContains(self::BOT_ID, SharedState::zRange(\Events::DC_PRESENCE_INDEX_KEY, 0, -1));
            $this->assertContains(self::BOT_ID, SharedState::zRange(\Events::DC_ACTIVE_INDEX_KEY, 0, -1));
            $this->assertDeadChannelTransportUnused('spawn:');
        }

        /**
         * A bot client_id is the non-hex sentinel 'bot_<location>'. Everything
         * that separates bots from sockets keys off that prefix
         * (`strpos($cid, 'bot_') === 0`), so it must never be hex-shaped.
         */
        public function testBotClientIdIsThePrefixedSentinelNotAHexId(): void
        {
            $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $members = SharedState::zRange(\Events::DC_PRESENCE_INDEX_KEY, 0, -1);
            $this->assertSame(self::BOT_ID, $members[0]);
            $this->assertStringStartsWith('bot_', $members[0]);
            $this->assertDoesNotMatchRegularExpression(
                '/^[0-9a-f]{20}$/',
                $members[0],
                'a bot id must be distinguishable from a real 20-char hex client_id'
            );
        }

        /**
         * THE BOT #4 (lock form): the process-local (raw) Workerman timer id is
         * NEVER shared — Timer::del() of another process's id kills an unrelated
         * timer in this one. What names the owner ACROSS instances is now the
         * host:pid:hex token at dc:lock:bot_owner:<location>, not the timer id.
         */
        public function testSpawnBotArmsRepeatingMoveTimerAndOwnsItViaLockToken(): void
        {
            $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $timers = $this->botMoveTimers();
            $this->assertCount(1, $timers, 'exactly one bot move timer');
            $this->assertTrue($timers[0]['persistent'], 'the bot walks on a REPEATING timer');
            $this->assertSame(\Events::BOT_MOVE_INTERVAL, $timers[0]['interval']);
            $this->assertSame(['Events', 'moveBot'], $timers[0]['func']);

            $token = $this->ownerToken();
            $this->assertNotNull($token, 'spawn records the ownership token process-locally');
            $this->assertStringStartsWith(gethostname() . ':' . getmypid() . ':', $token,
                'the token identifies THIS host+pid among the three datacentered instances');
            $this->assertSame($token, $this->rawLockValue(), 'the raw lock value IS the ownership token (never JSON-encoded)');

            // The raw timer id must stay process-local and must NOT be in Redis.
            $this->assertNotSame((string) $timers[0]['id'], $this->rawLockValue());
            $this->assertArrayHasKey(self::LOCATION, $this->getBotTimers());
            $this->assertSame($timers[0]['id'], $this->getBotTimers()[self::LOCATION]);
            $this->assertNotContains(
                (string) $timers[0]['id'],
                $this->redis->allKeys(),
                'a process-local Workerman timer id must never leak into shared state'
            );
        }

        /** The joined announcement goes out on the Gateway group, as an event. */
        public function testSpawnBotAnnouncesJoinedOverGatewayGroup(): void
        {
            $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $events = $this->presenceGroupEvents('dc.presence.joined');
            $this->assertCount(1, $events, 'the bot must announce itself so frontends create the avatar');
            $this->assertIsV1Event($events[0], 'dc.presence.joined');

            $data = $events[0]['data'];
            $this->assertSame(self::BOT_ID, $data['clientId'], 'camelCase clientId, as the frontend expects');
            $this->assertArrayNotHasKey('client_id', $data);
            $this->assertSame(self::LOCATION, $data['location']);
            $this->assertArrayHasKey('name', $data);
            $this->assertArrayHasKey('x', $data);
            $this->assertArrayHasKey('z', $data);
            $this->assertArrayHasKey('yaw', $data);
            $this->assertDeadChannelTransportUnused('spawn announce:');
        }

        public function testSpawnBotSkippedWhenFlagCDisabled(): void
        {
            $this->botFlagOff();

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertNull(SharedState::get($this->presenceKey(self::BOT_ID)));
            $this->assertNull(SharedState::get($this->stateKey()));
            $this->assertFalse($this->rawLockValue(), 'Flag C off acquires no ownership lock');
            $this->assertSame([], $this->botMoveTimers(), 'no timer may be armed with Flag C off');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sentToGroup);
        }

        /** A live bot WE own (unexpired lock) is not respawned. */
        public function testSpawnBotIsIdempotentWhileWeHoldTheOwnerLock(): void
        {
            $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);
            $first = SharedState::get($this->stateKey());
            $tokenBefore = $this->ownerToken();
            $timerCountAfterFirst = count($this->botMoveTimers());

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertSame($first, SharedState::get($this->stateKey()), 'state must not be rewritten');
            $this->assertSame($tokenBefore, $this->ownerToken(), 're-spawn while we own must keep the same lock token');
            $this->assertCount($timerCountAfterFirst, $this->botMoveTimers(), 'no second move timer');
        }

        /**
         * A crashed owner leaves its bot-state behind (the 30s state TTL outlives
         * the 10s lock) but its lock has LAPSED, so the bot would sit frozen
         * forever. The next spawn acquires the free lock and takes over.
         */
        public function testSpawnBotTakesOverWhenTheOwnerLockHasLapsed(): void
        {
            $this->botFlagOn();
            $this->seedGhostState('Ghost');
            $this->expireOwnerLock();

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertNotNull($this->ownerToken(), 'ownership transfers to the respawning worker');
            $this->assertSame($this->ownerToken(), $this->rawLockValue(), 'the free lock is ours now');
            $this->assertCount(1, $this->botMoveTimers(), 'a fresh move timer is armed here');
            // REVIEW-FIX (decision D): the takeover now ADOPTS the surviving state
            // instead of replacing it. The bot's clientId is always
            // 'bot_<location>', so a "replace" renamed AND teleported the very same
            // avatar on every handoff; frontends saw one continuous bot suddenly
            // become someone else somewhere else. What a takeover must guarantee is
            // that the bot is owned and MOVING again, which the assertions above
            // cover; identity continuity is the point of the ghost state outliving
            // the lock.
            $adoptedState = SharedState::get($this->stateKey());
            $this->assertSame('Ghost', $adoptedState['name'], 'the frozen bot is adopted, keeping its identity');
            $this->assertGreaterThanOrEqual(
                time() - 5,
                (int) $adoptedState['ts'],
                'ts is re-stamped on adoption so the adopted bot is not itself seen as stale'
            );
        }

        /** A cold, unowned scene (no lock, no state) spawns a fresh bot. */
        public function testSpawnBotSpawnsWhenNeitherStateNorLockExists(): void
        {
            $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertIsArray(SharedState::get($this->stateKey()));
            $this->assertSame($this->ownerToken(), $this->rawLockValue());
            $this->assertCount(1, $this->botMoveTimers());
        }

        // ====================================================================
        // Cross-instance ownership (three datacentered instances, one Redis)
        // ====================================================================

        /**
         * A lock held (TTL not lapsed) by ANOTHER instance whose bot is still
         * being walked must be left completely alone. Under the retired pid model
         * this was the exact cross-host hazard; under the lock it is trivially
         * correct — SET NX fails while the key is live, so no second bot spawns.
         */
        public function testForeignOwnerWithLiveLockIsNotTakenOver(): void
        {
            $this->botFlagOn();
            $this->seedRivalOwnerLockAlive();
            $this->seedGhostState('RemoteWalker', 5.0, 6.0);

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertSame(
                'otherhost:999:deadbeef',
                $this->rawLockValue(),
                'must not steal ownership of a bot another instance is walking'
            );
            $this->assertSame(
                'RemoteWalker',
                SharedState::get($this->stateKey())['name'],
                'the remote bot state must be left intact'
            );
            $this->assertSame([], $this->botMoveTimers(), 'no competing move timer here');
            $this->assertSame([], $this->getBotTimers(), 'this process holds no bot timer');
            $this->assertSame([], $this->getBotLockTokens(), 'this process holds no ownership token');
        }

        /**
         * SPAWN-side duplicate-timer retire branch (Events.php ~:5146-5153).
         *
         * Companion to testMoveBotRetiresItsOwnTimerWhenTheOwnerLockIsLost, which
         * reaches the same "retire OUR timer" outcome through the moveBot renew
         * path. Here a re-entrant spawn (a join hitting an already-walked bot)
         * finds a live rival lock and must retire this process's now-orphaned move
         * timer rather than respawn a bot it no longer owns.
         *
         * Production, verbatim (Events.php):
         *   5145  $token = SharedState::lock(self::botOwnerLockName($location), self::BOT_OWNER_LOCK_TTL);
         *   5146  if ($token === null) {
         *   5147      if (isset(self::$botTimers[$location]) && !isset(self::$botLockTokens[$location])) {
         *   5151          Timer::del(self::$botTimers[$location]);
         *   5152          unset(self::$botTimers[$location]);
         *   5153          Worker::safeEcho("[dc_bot] retiring duplicate bot timer ...");
         *   5154      }
         *   5155      return;
         *   5156  }
         *   5157  self::$botLockTokens[$location] = $token;   // NEVER reached: token is null
         *
         * The branch RETURNS at 5155, so it does not fall through to store a token
         * at 5157 and never re-locks: a null token cannot be kept, and the rival's
         * raw hold is left untouched.
         */
        public function testSpawnRetiresOurOrphanedTimerWhenARivalHoldsTheLiveLock(): void
        {
            // Spawn normally: this process acquires the lock (token) and arms the timer.
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $ourTimerId = $this->botMoveTimers()[0]['id'];
            $this->assertArrayHasKey(self::LOCATION, $this->getBotTimers(), 'precondition: we hold a move timer');
            $this->assertArrayHasKey(self::LOCATION, $this->getBotLockTokens(), 'precondition: we initially own the bot');

            // Our lock lapses on TTL, then a rival takes over with a RAW live hold.
            $this->redis->fastForward(\Events::BOT_OWNER_LOCK_TTL + 1);
            $this->seedRivalOwnerLockAlive();
            $this->assertSame(
                'otherhost:999:deadbeef',
                $this->rawLockValue(),
                'precondition: the rival now holds the lock'
            );

            // Force the branch precondition: timer retained, ownership slot emptied.
            $this->dropOwnershipTokenOnly();
            $this->assertArrayHasKey(self::LOCATION, $this->getBotTimers(), 'setup: the move timer is retained');
            $this->assertArrayNotHasKey(self::LOCATION, $this->getBotLockTokens(), 'setup: the token slot is emptied');

            // Re-spawn: the rival's live lock makes lock() return null, so the
            // branch retires OUR orphaned timer and returns without taking over.
            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertContains($ourTimerId, TestTimer::deleted(), 'we retire OUR orphaned timer');
            $this->assertArrayNotHasKey(self::LOCATION, $this->getBotTimers(), 'the retired timer slot is cleared');
            $this->assertSame([], $this->botMoveTimers(), 'no competing move timer remains here');
            $this->assertArrayNotHasKey(
                self::LOCATION,
                $this->getBotLockTokens(),
                'the null token is never stored — we do NOT re-acquire ownership'
            );
            $this->assertNull($this->ownerToken(), 'this process holds no ownership token after the retire branch');
            $this->assertSame(
                'otherhost:999:deadbeef',
                $this->rawLockValue(),
                'the rival keeps the lock — a retiring spawner must not steal or delete a foreign hold'
            );
        }

        /**
         * ...but when that instance's lock lapses (it crashed / stalled past the
         * TTL) the bot IS orphaned and we DO take over — the lock's own enforced
         * expiry is the liveness signal now, replacing the bot ts heartbeat the
         * removed staleness window used to measure. The ghost state outlives the
         * lock (state TTL > lock TTL), which is exactly why a takeover is possible
         * and necessary.
         */
        public function testForeignOwnerWithLapsedLockIsTakenOver(): void
        {
            $this->botFlagOn();
            $this->seedRivalOwnerLockAlive();
            $this->seedGhostState('Abandoned', 5.0, 6.0);

            $this->redis->fastForward(\Events::BOT_OWNER_LOCK_TTL + 1); // the rival stops renewing
            $this->assertFalse($this->rawLockValue(), 'precondition: the foreign lock has lapsed');

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertNotNull($this->ownerToken(), 'a lapsed lock means the owning instance is gone');
            $this->assertSame($this->ownerToken(), $this->rawLockValue(), 'we acquire the freed lock');
            // REVIEW-FIX (decision D): adopted, not replaced — see
            // testSpawnBotTakesOverWhenTheOwnerLockHasLapsed for the reasoning.
            $adoptedState = SharedState::get($this->stateKey());
            $this->assertSame('Abandoned', $adoptedState['name'], 'the orphaned bot keeps its identity across the takeover');
            $this->assertSame(5.0, (float) $adoptedState['x'], 'and its last known position');
            $this->assertSame(6.0, (float) $adoptedState['z']);
            $this->assertCount(1, $this->botMoveTimers());
        }

        /**
         * The owning process must recognise its OWN lock. Every moveBot() tick
         * renews it; a successful renew means the duplicate-timer guard must keep
         * driving, not retire the very timer that walks the bot.
         */
        public function testOwningProcessRenewsItsOwnLockAndKeepsItsTimer(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $before = $this->botMoveTimers();
            $this->assertCount(1, $before);
            $token = $this->ownerToken();
            $this->assertStringStartsWith(gethostname() . ':' . getmypid() . ':', $token);

            \Events::moveBot(self::LOCATION);

            $this->assertSame(
                [self::LOCATION => $before[0]['id']],
                $this->getBotTimers(),
                'the owner must NOT retire its own timer'
            );
            $this->assertSame($token, $this->ownerToken(), 'the ownership token survives a successful renew');
            $this->assertNotNull(SharedState::get($this->stateKey()));
        }

        // ====================================================================
        // Spawn position (contract BOT-BOUNDS)
        // ====================================================================

        /**
         * With no reported bounds the bot spawns inside the FALLBACK +/-50 box,
         * inset so it never clips the walls.
         */
        public function testSpawnPositionStaysInsideTheInsetFallbackBounds(): void
        {
            $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);
            $state = SharedState::get($this->stateKey());

            $inset = \Events::BOT_BOUNDS_INSET;
            $this->assertGreaterThanOrEqual(\Events::BOT_BOUNDS_X_MIN + $inset, $state['x']);
            $this->assertLessThanOrEqual(\Events::BOT_BOUNDS_X_MAX - $inset, $state['x']);
            $this->assertGreaterThanOrEqual(\Events::BOT_BOUNDS_Z_MIN + $inset, $state['z']);
            $this->assertLessThanOrEqual(\Events::BOT_BOUNDS_Z_MAX - $inset, $state['z']);
            // The facade JSON round-trips integral floats to ints, so the inset
            // box is compared NUMERICALLY (assertEquals), not type-strict.
            $this->assertEquals(
                [
                    'minX' => \Events::BOT_BOUNDS_X_MIN + $inset,
                    'maxX' => \Events::BOT_BOUNDS_X_MAX - $inset,
                    'minZ' => \Events::BOT_BOUNDS_Z_MIN + $inset,
                    'maxZ' => \Events::BOT_BOUNDS_Z_MAX - $inset,
                ],
                $state['bounds'],
                'the recorded bounds are the INSET walkable box'
            );
        }

        /**
         * THE BOT #1: with browser-reported bounds the bot spawns inside the REAL
         * room, which dc.js builds nowhere near the world origin (racks start at
         * offsetX/offsetZ = -100), so the fallback box would put it out of sight.
         */
        public function testSpawnUsesBrowserReportedRoomBounds(): void
        {
            $this->botFlagOn();
            $reported = ['minX' => -200.0, 'maxX' => -100.0, 'minZ' => -200.0, 'maxZ' => -100.0];

            \Events::spawnBotForLocation(self::LOCATION, null, $reported);
            $state = SharedState::get($this->stateKey());

            $this->assertEquals($reported, SharedState::get($this->roomBoundsKey()), 'round-tripped numerically (facade JSON turns integral floats into ints)');
            $this->assertGreaterThanOrEqual(-198.0, $state['x'], 'inside the reported room, not the +/-50 box');
            $this->assertLessThanOrEqual(-102.0, $state['x']);
            $this->assertGreaterThanOrEqual(-198.0, $state['z']);
            $this->assertLessThanOrEqual(-102.0, $state['z']);
        }

        /**
         * THE BOT #1/#3: the bot spawns within BOT_SPAWN_RADIUS of the joining
         * player, so it is actually visible instead of wandering empty space.
         */
        public function testSpawnIsNearTheJoiningPlayer(): void
        {
            $this->botFlagOn();
            $reported = ['minX' => -300.0, 'maxX' => 300.0, 'minZ' => -300.0, 'maxZ' => 300.0];
            $near = ['x' => 150.0, 'z' => -150.0];

            \Events::spawnBotForLocation(self::LOCATION, $near, $reported);
            $state = SharedState::get($this->stateKey());

            $distance = sqrt(
                ($state['x'] - $near['x']) ** 2 + ($state['z'] - $near['z']) ** 2
            );
            $this->assertLessThanOrEqual(
                \Events::BOT_SPAWN_RADIUS + 1e-9,
                $distance,
                'the bot must spawn within BOT_SPAWN_RADIUS of the joining player'
            );
        }

        /**
         * With no $near, the anchor falls back to a random REAL player's last
         * known position (THE BOT #3) rather than a uniform point in the box.
         */
        public function testSpawnFallsBackToARealPlayerPositionWhenNoNearGiven(): void
        {
            $this->botFlagOn();
            SharedState::set($this->roomBoundsKey(), [
                'minX' => -400.0, 'maxX' => 400.0, 'minZ' => -400.0, 'maxZ' => 400.0,
            ], \Events::PRESENCE_SESSION_TTL);
            $this->addRealUser($this->userId, 300.0, 300.0);

            \Events::spawnBotForLocation(self::LOCATION);
            $state = SharedState::get($this->stateKey());

            $distance = sqrt(($state['x'] - 300.0) ** 2 + ($state['z'] - 300.0) ** 2);
            $this->assertLessThanOrEqual(
                \Events::BOT_SPAWN_RADIUS + 1e-9,
                $distance,
                'the only real player\'s position must be used as the spawn anchor'
            );
        }

        // ====================================================================
        // moveBot — the step-clamp invariant
        // ====================================================================

        /**
         * THE CLAMP INVARIANT.
         *
         * One tick moves BOT_WALK_SPEED * BOT_MOVE_INTERVAL = 11.7 * 0.5 = 5.85
         * units, which is far MORE than BOT_TARGET_THRESHOLD (1.0). An unclamped
         * step therefore overshoots the target, the next tick overshoots back,
         * and the bot oscillates around the target forever without ever getting
         * within the threshold to pick a new one. moveBot() must clamp the step
         * to the remaining distance:
         *     min(BOT_WALK_SPEED * BOT_MOVE_INTERVAL, $distance)
         *
         * Here the target is 2.0 units away — less than one tick — so after the
         * tick the bot must be exactly ON the target, never past it.
         */
        public function testMoveBotClampsTheStepToTheRemainingDistance(): void
        {
            $tick = \Events::BOT_WALK_SPEED * \Events::BOT_MOVE_INTERVAL;
            $this->assertGreaterThan(
                \Events::BOT_TARGET_THRESHOLD,
                $tick,
                'precondition: one tick is longer than the arrival threshold, so the clamp is load-bearing'
            );

            $this->seedRoomBounds(100.0, 100.0);
            $this->takeOwnershipAndSeedState($this->walkState(2.0, 0.0));
            $this->addRealUser($this->userId, 0.0, 0.0);

            \Events::moveBot(self::LOCATION);
            $state = SharedState::get($this->stateKey());

            $this->assertEqualsWithDelta(
                2.0,
                $state['x'],
                1e-9,
                'the step must be clamped to the remaining 2.0 units — an unclamped 5.85 step '
                .'would land at x=5.85 and overshoot the target'
            );
            $this->assertEqualsWithDelta(0.0, $state['z'], 1e-9);
            $this->assertLessThanOrEqual(
                2.0,
                sqrt($state['x'] ** 2 + $state['z'] ** 2),
                'the bot must never travel further than the distance to its target'
            );
            // Having arrived, the next tick is free to pick a new target.
            $arrival = sqrt(($state['target_x'] - $state['x']) ** 2 + ($state['target_z'] - $state['z']) ** 2);
            $this->assertLessThan(
                \Events::BOT_TARGET_THRESHOLD,
                $arrival,
                'after a clamped step the bot is within the arrival threshold — which is the whole '
                .'point: without the clamp it can never get there'
            );
        }

        /** A far target produces exactly one full tick of travel. */
        public function testMoveBotWalksOneFullTickTowardADistantTarget(): void
        {
            $tick = \Events::BOT_WALK_SPEED * \Events::BOT_MOVE_INTERVAL;
            $this->seedRoomBounds(500.0, 500.0);
            $this->takeOwnershipAndSeedState($this->walkState(400.0, 0.0));
            $this->addRealUser($this->userId, 0.0, 0.0);

            \Events::moveBot(self::LOCATION);
            $state = SharedState::get($this->stateKey());

            $this->assertEqualsWithDelta($tick, $state['x'], 1e-9, 'one tick = speed * interval = 5.85 units');
            $this->assertEqualsWithDelta(400.0, $state['target_x'], 1e-9, 'a distant target is kept, not re-rolled');
        }

        /**
         * Repeated ticks must CONVERGE. Without the clamp this loop oscillates
         * around the target forever and the assertion below never holds.
         */
        public function testRepeatedTicksConvergeOnTheTargetInsteadOfOscillating(): void
        {
            $this->seedRoomBounds(100.0, 100.0);
            $this->takeOwnershipAndSeedState($this->walkState(20.0, 0.0));
            // No real players: randomRealClientPosition() returns null, so a new
            // target would be uniform in bounds — but we assert convergence on the
            // ORIGINAL target, which is only reachable if the step is clamped.
            $arrived = false;
            for ($tick = 0; $tick < 4; $tick++) {
                \Events::moveBot(self::LOCATION);
                $state = SharedState::get($this->stateKey());
                if (abs($state['x'] - 20.0) < \Events::BOT_TARGET_THRESHOLD && abs($state['z']) < 1e-9) {
                    $arrived = true;
                    break;
                }
                $this->assertLessThanOrEqual(
                    20.0,
                    $state['x'],
                    'the bot must never step PAST the target (overshoot = oscillation forever)'
                );
            }
            $this->assertTrue(
                $arrived,
                '20 units at 5.85/tick must be covered in 4 ticks; failing this means the step '
                .'overshoots and the bot never "arrives" to pick a new target'
            );
        }

        /** On arrival a new target is chosen, inside the room bounds. */
        public function testMoveBotPicksANewInBoundsTargetOnArrival(): void
        {
            $this->seedRoomBounds(60.0, 60.0);
            $this->takeOwnershipAndSeedState($this->walkState(0.1, 0.1));
            $this->addRealUser($this->userId, 10.0, 10.0);

            \Events::moveBot(self::LOCATION);
            $state = SharedState::get($this->stateKey());

            $this->assertNotEquals(0.1, $state['target_x'], 'arrival must trigger a new target');
            $inset = \Events::BOT_BOUNDS_INSET;
            $this->assertGreaterThanOrEqual(-60.0 + $inset, $state['target_x']);
            $this->assertLessThanOrEqual(60.0 - $inset, $state['target_x']);
            $this->assertGreaterThanOrEqual(-60.0 + $inset, $state['target_z']);
            $this->assertLessThanOrEqual(60.0 - $inset, $state['target_z']);

            // THE BOT #3: the new target is near the only real player.
            $distance = sqrt(($state['target_x'] - 10.0) ** 2 + ($state['target_z'] - 10.0) ** 2);
            $this->assertLessThanOrEqual(
                \Events::BOT_WANDER_RADIUS + 1e-9,
                $distance,
                'targets are picked within BOT_WANDER_RADIUS of a real player'
            );
        }

        /** Movement is queued for the shared batch flush (BUG-B7), not broadcast directly. */
        public function testMoveBotQueuesTheBatchAndArmsTheSharedFlush(): void
        {
            $this->seedRoomBounds(100.0, 100.0);
            $this->takeOwnershipAndSeedState($this->walkState(50.0, 0.0));
            // Clear the spawn announcement so the "no direct broadcast" assertion
            // below is really about moveBot(), not about the earlier spawn().
            \GatewayWorker\Lib\Gateway::reset();

            \Events::moveBot(self::LOCATION);

            $queued = SharedState::get($this->moveBatchKey());
            $this->assertIsArray($queued, 'the bot move must be queued at dc:presence:move_batch:bot_<location>');
            $this->assertArrayHasKey('x', $queued);
            $this->assertArrayHasKey('yaw', $queued);
            $this->assertSame(
                [],
                \GatewayWorker\Lib\Gateway::$sentToGroup,
                'moveBot must not broadcast directly — it arms the SHARED 50ms flush'
            );

            $flush = TestTimer::withInterval(0.05);
            $this->assertCount(1, $flush, 'moveBot arms exactly one shared flush timer');

            TestTimer::run($flush[0]['id']);

            $events = $this->presenceGroupEvents('dc.presence.batch_updated');
            $this->assertCount(1, $events, 'the flush emits the bot move as a batch event');
            $this->assertArrayHasKey(self::BOT_ID, $events[0]['data']);
            $this->assertDeadChannelTransportUnused('bot move flush:');
        }

        /**
         * The bot yaw faces the direction of travel: yaw = atan2(-dirX, -dirZ),
         * i.e. 0 = facing +Z.
         */
        public function testMoveBotFacesItsDirectionOfTravel(): void
        {
            $this->seedRoomBounds(100.0, 100.0);
            $this->takeOwnershipAndSeedState($this->walkState(-50.0, 50.0, 'Walker', 123.0)); // north-west diagonal

            \Events::moveBot(self::LOCATION);
            $state = SharedState::get($this->stateKey());

            // dirX = -1/sqrt(2), dirZ = +1/sqrt(2) => atan2(0.7071, -0.7071) = 3*PI/4.
            $this->assertEqualsWithDelta(3 * M_PI / 4, $state['yaw'], 1e-9);
            // Sanity: the heading really points at the movement delta.
            $this->assertEqualsWithDelta(
                atan2(-$state['x'], -$state['z']),
                $state['yaw'],
                1e-9,
                'yaw must be derived from the actual displacement'
            );
        }

        /** Positions are clamped into the room even if state drifted outside it. */
        public function testMoveBotClampsPositionIntoTheRoomBounds(): void
        {
            $this->seedRoomBounds(10.0, 10.0);
            $this->takeOwnershipAndSeedState(
                array_merge($this->walkState(1000.0, 0.0), ['x' => 7.0]) // target far outside, start near the wall
            );

            \Events::moveBot(self::LOCATION);
            $state = SharedState::get($this->stateKey());

            $inset = min(\Events::BOT_BOUNDS_INSET, 20.0 / 4, 20.0 / 4);
            // x=7 heading to 1000 is a full 5.85 step (12.85) that MUST clamp back
            // to the inset wall (10 - inset = 8) — proving the clamp fires, not just
            // that an interior step happens to stay inside.
            $this->assertGreaterThan(8.0, 7.0 + \Events::BOT_WALK_SPEED * \Events::BOT_MOVE_INTERVAL,
                'precondition: the unclamped step really would breach the wall, so the clamp is load-bearing');
            $this->assertEqualsWithDelta(10.0 - $inset, $state['x'], 1e-9, 'must never clip through a wall');
            $this->assertGreaterThanOrEqual(-10.0 + $inset, $state['x']);
        }

        // ====================================================================
        // moveBot — ownership + flag control
        // ====================================================================

        public function testMoveBotCleansUpWhenFlagCGoesOff(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $this->assertIsArray(SharedState::get($this->stateKey()));
            $timerId = $this->botMoveTimers()[0]['id'];

            $this->botFlagOff();
            \Events::moveBot(self::LOCATION);

            $this->assertNull(SharedState::get($this->stateKey()), 'state removed');
            $this->assertNull(SharedState::get($this->presenceKey(self::BOT_ID)), 'avatar removed');
            $this->assertContains($timerId, TestTimer::deleted(), 'the move timer must be deleted');
            $this->assertFalse($this->rawLockValue(), 'cleanup releases the ownership lock this process held');
        }

        public function testMoveBotCleansUpWhenBotStateVanishes(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $timerId = $this->botMoveTimers()[0]['id'];
            SharedState::del($this->stateKey());

            \Events::moveBot(self::LOCATION);

            $this->assertContains($timerId, TestTimer::deleted());
            $this->assertNotContains(self::BOT_ID, SharedState::zRange(\Events::DC_PRESENCE_INDEX_KEY, 0, -1));
        }

        /**
         * THE BOT #4 (lock form): if our ownership lock lapsed and ANOTHER
         * instance has taken the bot over, this process retires ITS OWN timer and
         * touches NOTHING of the new owner's — a renew against a foreign token
         * fails, and a Workerman id may only be deleted by the process that made
         * it. This is the whole point of the token-checked lock over the retired
         * unconditional shared-store release.
         */
        public function testMoveBotRetiresItsOwnTimerWhenTheOwnerLockIsLost(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $ourTimerId = $this->botMoveTimers()[0]['id'];
            $stateBefore = SharedState::get($this->stateKey());
            $ourToken = $this->ownerToken();

            // Our tick stalls long enough for the lock to lapse, then a rival takes it.
            $this->redis->fastForward(\Events::BOT_OWNER_LOCK_TTL + 1);
            $this->seedRivalOwnerLockAlive();
            $this->assertNotSame($ourToken, $this->rawLockValue(), 'precondition: the rival now holds the lock');

            \Events::moveBot(self::LOCATION);

            $this->assertContains($ourTimerId, TestTimer::deleted(), 'we retire OUR timer');
            $this->assertArrayNotHasKey(self::LOCATION, $this->getBotTimers(), 'the retired timer slot is cleared');
            $this->assertArrayNotHasKey(self::LOCATION, $this->getBotLockTokens(), 'the stale ownership token is dropped');
            $this->assertSame(
                $stateBefore,
                SharedState::get($this->stateKey()),
                'the shared state must not be touched by the retiring process'
            );
            $this->assertSame(
                'otherhost:999:deadbeef',
                $this->rawLockValue(),
                'the new owner keeps the lock — a stale renew must not resurrect or delete it'
            );
        }

        // ====================================================================
        // cleanupBotForLocation
        // ====================================================================

        public function testCleanupRemovesEverythingAboutTheBot(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $timerId = $this->botMoveTimers()[0]['id'];
            SharedState::set($this->moveBatchKey(), ['x' => 1], \Events::PRESENCE_MOVE_TTL);

            \Events::cleanupBotForLocation(self::LOCATION);

            $this->assertNull(SharedState::get($this->stateKey()));
            $this->assertFalse($this->rawLockValue(), 'the owner releases the lock');
            $this->assertNull(SharedState::get($this->presenceKey(self::BOT_ID)));
            $this->assertNull(SharedState::get($this->moveBatchKey()));
            $this->assertNotContains(self::BOT_ID, SharedState::zRange(\Events::DC_PRESENCE_INDEX_KEY, 0, -1));
            $this->assertNotContains(self::BOT_ID, SharedState::zRange(\Events::DC_ACTIVE_INDEX_KEY, 0, -1));
            $this->assertContains($timerId, TestTimer::deleted());
        }

        /**
         * Despawn must announce dc.presence.left. spawn announces joined, so
         * without the matching left the cleaned-up bot lingers as a ghost avatar
         * until the page reloads — visible now that presence broadcasts actually
         * reach clients (BUG-A3).
         */
        public function testCleanupAnnouncesLeftOverGatewayGroup(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            \GatewayWorker\Lib\Gateway::reset();

            \Events::cleanupBotForLocation(self::LOCATION);

            $events = $this->presenceGroupEvents('dc.presence.left');
            $this->assertCount(1, $events, 'a despawned bot must be announced or it ghosts');
            $this->assertIsV1Event($events[0], 'dc.presence.left');
            $this->assertSame(self::BOT_ID, $events[0]['data']['uid']);
            $this->assertSame(self::BOT_ID, $events[0]['data']['clientId']);
            $this->assertDeadChannelTransportUnused('cleanup:');
        }

        public function testCleanupLeavesRealUsersAlone(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $this->addRealUser($this->userId, 5.0, 5.0);

            \Events::cleanupBotForLocation(self::LOCATION);

            $this->assertIsArray(SharedState::get($this->presenceKey($this->userId)));
            $this->assertContains($this->userId, SharedState::zRange(\Events::DC_PRESENCE_INDEX_KEY, 0, -1));
            $this->assertContains($this->userId, SharedState::zRange(\Events::DC_ACTIVE_INDEX_KEY, 0, -1));
        }

        /**
         * A process that does NOT own the lock may clear the shared bot-state (so
         * the real owner's next tick self-reaps its own timer) but must NEVER
         * unlock another instance's hold and must NEVER Timer::del() an id it did
         * not create — a Workerman timer id is only valid in its creating process.
         *
         * This is the lock model's answer to the retired "leave the marker for the
         * owner to reap" branch: the "reap" is now simply the lock lapsing on TTL
         * after the state is cleared, and a non-owner is structurally incapable of
         * touching either the foreign lock or a foreign timer.
         */
        public function testCleanupFromANonOwningProcessNeverTouchesTheOwnerLock(): void
        {
            $this->botFlagOn();
            $this->seedRivalOwnerLockAlive();          // another instance owns it
            $this->seedGhostState('Walker');
            SharedState::set($this->presenceKey(self::BOT_ID), ['uid' => self::BOT_ID, 'client_id' => self::BOT_ID], \Events::BOT_STATE_TTL);
            SharedState::zAdd(\Events::DC_PRESENCE_INDEX_KEY, time(), self::BOT_ID);
            SharedState::zAdd(\Events::DC_ACTIVE_INDEX_KEY, time(), self::BOT_ID);
            // This process holds no timer and no token — it is not the owner.
            $this->clearBotStatics();

            \Events::cleanupBotForLocation(self::LOCATION);

            $this->assertSame(
                [],
                TestTimer::deleted(),
                'a Workerman timer id is only valid in its creating process — never Timer::del() a foreign id'
            );
            $this->assertSame(
                'otherhost:999:deadbeef',
                $this->rawLockValue(),
                'a non-owner must never release a foreign ownership lock'
            );
            $this->assertNull(
                SharedState::get($this->stateKey()),
                'clearing the state is what makes the real owner stop next tick and self-reap'
            );
            $this->assertNotContains(self::BOT_ID, SharedState::zRange(\Events::DC_PRESENCE_INDEX_KEY, 0, -1));
        }

        // ====================================================================
        // hasRealUsersAtLocation
        // ====================================================================

        private function hasRealUsers(): bool
        {
            $method = new ReflectionMethod(\Events::class, 'hasRealUsersAtLocation');
            $method->setAccessible(true);
            return $method->invoke(null, self::LOCATION);
        }

        public function testHasRealUsersIsFalseWithOnlyBots(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertFalse($this->hasRealUsers());
        }

        public function testHasRealUsersIsTrueWithAHexClientIdPresent(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $this->addRealUser($this->userId);

            $this->assertTrue($this->hasRealUsers(), 'a 20-char hex client_id is a real user, not a bot');
        }

        public function testHasRealUsersIsFalseOnAnEmptyScene(): void
        {
            $this->botFlagOn();

            $this->assertFalse($this->hasRealUsers());
        }

        /**
         * The bot/real split is a 'bot_' PREFIX test, so an id that merely
         * CONTAINS "bot" is still a real user. Hex ids can never start with
         * 'bot_' (b, o and t are not all hex), which is why the sentinel is safe.
         */
        public function testOnlyThePrefixMakesAClientIdABot(): void
        {
            $this->botFlagOn();
            $lookalike = 'abbot_main';
            SharedState::set($this->presenceKey($lookalike), ['uid' => 1, 'client_id' => $lookalike], \Events::PRESENCE_STALE_TTL);
            SharedState::zAdd(\Events::DC_PRESENCE_INDEX_KEY, time(), $lookalike);

            $this->assertTrue($this->hasRealUsers(), 'only a leading "bot_" marks a bot');
        }

        // ====================================================================
        // Integration: join spawns, last leave despawns
        // ====================================================================

        public function testRealUserJoinSpawnsTheBotNearThem(): void
        {
            $this->botFlagOn();
            $_SESSION = [
                'v1_authed' => true, 'uid' => 4242, 'name' => 'admin',
                'ima' => 'admin', 'login' => true,
            ];

            \Events::dispatchV1($this->userId, [
                'v' => 1, 'id' => 'join-1', 'op' => 'dc.presence.join', 'ts' => time(),
                'data' => [
                    'x' => 40.0, 'z' => -40.0, 'yaw' => 0.0,
                    'bounds' => ['minX' => -200.0, 'maxX' => 200.0, 'minZ' => -200.0, 'maxZ' => 200.0],
                ],
            ]);

            $state = SharedState::get($this->stateKey());
            $this->assertIsArray($state, 'a real user joining must spawn the bot');
            $distance = sqrt(($state['x'] - 40.0) ** 2 + ($state['z'] + 40.0) ** 2);
            $this->assertLessThanOrEqual(
                \Events::BOT_SPAWN_RADIUS + 1e-9,
                $distance,
                'the bot spawns near the joining player, not at the world origin'
            );

            // Both the user's join and the bot's join reach clients over the group.
            $joined = $this->presenceGroupEvents('dc.presence.joined');
            $this->assertCount(2, $joined, 'the user and the bot are both announced');
            $ids = array_map(static fn(array $e) => $e['data']['clientId'], $joined);
            $this->assertContains($this->userId, $ids);
            $this->assertContains(self::BOT_ID, $ids);
            $this->assertDeadChannelTransportUnused('join+spawn:');
        }

        public function testLastRealUserLeavingDespawnsTheBot(): void
        {
            $this->botFlagOn();
            $_SESSION = [
                'v1_authed' => true, 'uid' => 4242, 'name' => 'admin',
                'ima' => 'admin', 'login' => true,
            ];

            \Events::dispatchV1($this->userId, [
                'v' => 1, 'id' => 'join-1', 'op' => 'dc.presence.join', 'ts' => time(),
                'data' => ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0],
            ]);
            $this->assertIsArray(SharedState::get($this->stateKey()));
            $timerId = $this->botMoveTimers()[0]['id'];

            \Events::dispatchV1($this->userId, [
                'v' => 1, 'id' => 'leave-1', 'op' => 'dc.presence.leave', 'ts' => time(), 'data' => [],
            ]);

            $this->assertNull(SharedState::get($this->stateKey()), 'the bot leaves with the last human');
            $this->assertNull(SharedState::get($this->presenceKey(self::BOT_ID)));
            $this->assertContains($timerId, TestTimer::deleted());

            $left = $this->presenceGroupEvents('dc.presence.left');
            $ids = array_map(static fn(array $e) => $e['data']['clientId'], $left);
            $this->assertContains($this->userId, $ids, 'the user is announced as gone');
            $this->assertContains(self::BOT_ID, $ids, 'the bot avatar is announced as gone (no ghost)');
        }

        /** A second human leaving while one remains must NOT despawn the bot. */
        public function testBotSurvivesWhileAnotherRealUserRemains(): void
        {
            $this->botFlagOn();
            $other = dc_client_id(2002);
            $_SESSION = [
                'v1_authed' => true, 'uid' => 4242, 'name' => 'admin',
                'ima' => 'admin', 'login' => true,
            ];

            \Events::dispatchV1($this->userId, [
                'v' => 1, 'id' => 'join-1', 'op' => 'dc.presence.join', 'ts' => time(),
                'data' => ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0],
            ]);
            \Events::dispatchV1($other, [
                'v' => 1, 'id' => 'join-2', 'op' => 'dc.presence.join', 'ts' => time(),
                'data' => ['x' => 1.0, 'z' => 1.0, 'yaw' => 0.0],
            ]);

            \Events::dispatchV1($other, [
                'v' => 1, 'id' => 'leave-2', 'op' => 'dc.presence.leave', 'ts' => time(), 'data' => [],
            ]);

            $this->assertIsArray(
                SharedState::get($this->stateKey()),
                'the bot must stay while a human is still in the scene'
            );
        }

        // ====================================================================
        // Constants
        // ====================================================================

        public function testBotConstants(): void
        {
            $this->assertSame(0.5, \Events::BOT_MOVE_INTERVAL, 'BOT_MOVE_INTERVAL should be 0.5s');
            // 11.7 u/s, NOT 1.2. The scene's unit is UNITS_PER_INCH = 15/70 (1 unit ~= 0.12 m),
            // so 1.2 u/s was ~0.14 m/s — a crawl, which is why the bot looked frozen/absent.
            // A 1.4 m/s human walk is ~11.7 u/s; the client player walks at WALK = 14 u/s
            // (public_html/js/dc-multi.js). This assertion previously froze the bug in place.
            $this->assertSame(11.7, \Events::BOT_WALK_SPEED, 'BOT_WALK_SPEED should be ~1.4 m/s in scene units');
            $this->assertSame(1.0, \Events::BOT_TARGET_THRESHOLD, 'BOT_TARGET_THRESHOLD should be 1.0 units');
            // One tick moves BOT_WALK_SPEED * BOT_MOVE_INTERVAL = 5.85 units, which EXCEEDS
            // BOT_TARGET_THRESHOLD (1.0). moveBot() must therefore clamp the step to the
            // remaining distance or the bot oscillates around its target and never arrives
            // (proved behaviourally in testMoveBotClampsTheStepToTheRemainingDistance).
            $this->assertGreaterThan(
                \Events::BOT_TARGET_THRESHOLD,
                \Events::BOT_WALK_SPEED * \Events::BOT_MOVE_INTERVAL,
                'per-tick step exceeds the arrival threshold, so the step MUST be clamped in moveBot()'
            );
            // Retained as the FALLBACK bounds only — dcRoomBounds() now prefers the
            // browser-reported dc:presence:room_bounds:<location> when one has been recorded.
            $this->assertSame(-50.0, \Events::BOT_BOUNDS_X_MIN);
            $this->assertSame(50.0, \Events::BOT_BOUNDS_X_MAX);
            $this->assertSame(-50.0, \Events::BOT_BOUNDS_Z_MIN);
            $this->assertSame(50.0, \Events::BOT_BOUNDS_Z_MAX);
            $this->assertSame('main', \Events::BOT_DEFAULT_LOCATION);
            $this->assertSame(2.0, \Events::BOT_BOUNDS_INSET);
            $this->assertSame(25.0, \Events::BOT_SPAWN_RADIUS);
            $this->assertSame(30.0, \Events::BOT_WANDER_RADIUS);

            // Migration A2 key + TTL contract. The owner lock is the whole
            // ownership mechanism now; the state TTL deliberately EXCEEDS the lock
            // TTL so a crashed owner's ghost state outlives its lock — the window a
            // takeover relies on (proved in testSpawnBotTakesOverWhenTheOwnerLockHasLapsed).
            // REVIEW-FIX (decision D): raised 10 -> 30. The GlobalData-era owner
            // check used /proc/<pid> liveness, so a live-but-stalled same-host owner
            // was never robbed; the pure-TTL rewrite lost that, and these workers do
            // synchronous MySQL/SOAP work where a >10s stall is reachable. A steal
            // caused by a stall respawns the bot with a new name+position, so the
            // window is widened rather than left at ~20 ticks.
            $this->assertSame(30, \Events::BOT_OWNER_LOCK_TTL, 'bot owner lock TTL is the enforced heartbeat that replaced the retired staleness constant');
            $this->assertGreaterThan(
                \Events::BOT_OWNER_LOCK_TTL,
                \Events::BOT_STATE_TTL,
                'state TTL must outlive the owner lock so a dead owner leaves a takeable ghost, not an instant vanish'
            );
            $this->assertSame('dc:presence:room_bounds:', \Events::DC_ROOM_BOUNDS_KEY_PREFIX);
            $this->assertSame('dc:presence:bot_state:', \Events::BOT_STATE_KEY_PREFIX);
            $this->assertSame('dc:presence:client:', \Events::DC_PRESENCE_KEY_PREFIX);
            $this->assertSame('dc:presence:index', \Events::DC_PRESENCE_INDEX_KEY);
            $this->assertSame('dc:presence:active', \Events::DC_ACTIVE_INDEX_KEY);
            // The owner lock name resolves under the facade's dc:lock: namespace.
            $this->assertSame(
                'dc:lock:bot_owner:' . self::LOCATION,
                SharedState::PREFIX_LOCK . 'bot_owner:' . self::LOCATION
            );
        }
    }
}
