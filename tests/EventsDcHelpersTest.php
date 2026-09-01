<?php

/**
 * Unit tests for the side-effect-free DC helpers lifted out of the presence /
 * bot code paths, plus the two invariants that the callers depend on.
 *
 *   sanitiseRoomBounds($raw)              validate untrusted browser bounds
 *   dcRoomBounds($location)               reported-or-fallback, inset
 *   dcPresenceIsStale($pong,$sent,$now,$t) keepalive watchdog decision
 *   randomPointNear($anchor,$bounds,$r)   spawn/wander target selection
 *   randomRealClientPosition()            "wander where the humans are"
 *   isInPeerViewport(...)                 per-recipient move filtering
 *
 * plus the bot-OWNERSHIP contract the spawn/move timers depend on:
 *
 *   SharedState lock 'bot_owner:<location>' (full key dc:lock:bot_owner:<loc>,
 *   TTL Events::BOT_OWNER_LOCK_TTL) — acquire IS ownership, renew IS liveness,
 *   expiry IS death. The retired botOwnerAlive() GlobalData owner-pid marker
 *   probe (reading /proc across three hosts sharing one store) was replaced by
 *   this real, enforced TTL lock in migration A2; the cases below pin the
 *   lock semantics that probe used to approximate.
 *
 * These are the cheapest place to pin the contracts the integration tests then
 * rely on, and the only place where the hostile inputs (non-numeric, INF/NAN,
 * inverted, degenerate, absurd) can be enumerated exhaustively.
 *
 * All helpers are private statics, so they are reached by reflection; that is
 * deliberate — testing them through a handler would make the property-style
 * assertions below (hundreds of random draws staying inside the box) impossible
 * to write.
 *
 * Storage goes exclusively through the SharedState Redis facade backed by the
 * InMemoryRedis double (GlobalData→Redis migration A2): no $GLOBALS['global']
 * anywhere in this file.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__.'/V1TestSupport.php';

    class EventsDcHelpersTest extends TestCase
    {
        use DcTransportAssertions;

        /** @var InMemoryRedis the double injected by setUp() for every test */
        private $redis;

        protected function setUp(): void
        {
            \GatewayWorker\Lib\Gateway::reset();
            \Channel\Client::reset();
            TestTimer::install();
            $_SESSION = [];

            // FeatureFlagsTest-style injection discipline: a leaked
            // $GLOBALS['redis'] or a leftover facade memo from another suite
            // must never decide behaviour here, so drop both, then inject a
            // FRESH double (fresh keyspace AND fresh controllable clock).
            unset($GLOBALS['redis']);
            \SharedState::reset();
            $this->redis = new InMemoryRedis();
            \SharedState::setClient($this->redis);
        }

        protected function tearDown(): void
        {
            \SharedState::reset();
            unset($GLOBALS['redis']);
        }

        /** Invoke a private static Events helper. */
        private static function call(string $method, ...$args)
        {
            $ref = new ReflectionMethod(\Events::class, $method);
            $ref->setAccessible(true);
            return $ref->invoke(null, ...$args);
        }

        /** The facade's raw GET on a lock key: token string, or false when absent. */
        private function rawLock(string $name)
        {
            return $this->redis->get(\SharedState::PREFIX_LOCK.$name);
        }

        /**
         * Seed a foreign lock HOLDER directly on the double.
         *
         * MUST be a raw SET, never SharedState::set: lock tokens are stored
         * un-encoded because the compare-token Lua scripts string-compare GET
         * against ARGV — the facade's JSON wrapping would break the compare.
         */
        private function seedForeignHolder(string $name, string $token): void
        {
            $this->assertSame(
                true,
                $this->redis->set(
                    \SharedState::PREFIX_LOCK.$name,
                    $token,
                    ['nx', 'ex' => \Events::BOT_OWNER_LOCK_TTL]
                )
            );
        }

        /** Seed browser-reported bounds exactly as spawnBotForLocation stores them. */
        private function seedReportedBounds(string $location, $bounds): void
        {
            $this->assertTrue(\SharedState::set(
                \Events::DC_ROOM_BOUNDS_KEY_PREFIX.$location,
                $bounds,
                \Events::PRESENCE_SESSION_TTL
            ), 'fixture: reported bounds stored at dc:presence:room_bounds:'.$location);
        }

        /**
         * Seed one presence member exactly as handleDcPresenceJoin does:
         * indexed in the dc:presence:index ZSET (score = last-seen ts) with a
         * per-client STRING record carrying the EX90 staleness TTL.
         *
         * @param mixed $record null seeds the index member ONLY (orphan index entry)
         */
        private function seedPresenceMember(string $clientId, $record): void
        {
            $this->assertTrue(\SharedState::zAdd(\Events::DC_PRESENCE_INDEX_KEY, time(), $clientId));
            if ($record !== null) {
                $this->assertTrue(\SharedState::set(
                    \Events::DC_PRESENCE_KEY_PREFIX.$clientId,
                    $record,
                    \Events::PRESENCE_STALE_TTL
                ), 'fixture: presence record for '.$clientId);
            }
        }

        // ====================================================================
        // sanitiseRoomBounds — untrusted browser input
        // ====================================================================

        public function testSanitiseRoomBoundsAcceptsAValidBoxAndCastsToFloat(): void
        {
            $this->assertSame(
                ['minX' => -120.0, 'maxX' => 40.5, 'minZ' => -130.0, 'maxZ' => 20.0],
                self::call('sanitiseRoomBounds', ['minX' => -120, 'maxX' => 40.5, 'minZ' => '-130', 'maxZ' => 20]),
                'numeric strings and ints are accepted and normalised to float'
            );
        }

        public function testSanitiseRoomBoundsIgnoresExtraKeys(): void
        {
            $this->assertSame(
                ['minX' => -10.0, 'maxX' => 10.0, 'minZ' => -10.0, 'maxZ' => 10.0],
                self::call('sanitiseRoomBounds', [
                    'minX' => -10, 'maxX' => 10, 'minZ' => -10, 'maxZ' => 10,
                    'minY' => 0, 'evil' => '<script>', 'nested' => ['a' => 1],
                ]),
                'only the four bound fields are read; the result is a clean 4-key array'
            );
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('rejectedBoundsProvider')]
        public function testSanitiseRoomBoundsRejects($raw, string $why): void
        {
            $this->assertNull(self::call('sanitiseRoomBounds', $raw), $why);
        }

        public static function rejectedBoundsProvider(): array
        {
            $ok = ['minX' => -50, 'maxX' => 50, 'minZ' => -50, 'maxZ' => 50];
            $with = static function (array $override) use ($ok) {
                return array_merge($ok, $override);
            };

            return [
                // --- not even the right shape ---
                'null' => [null, 'a missing bounds value is simply absent'],
                'string' => ['minX=1', 'a scalar is not a bounds object'],
                'int' => [42, 'a scalar is not a bounds object'],
                'empty array' => [[], 'no fields at all'],
                'list not map' => [[-50, 50, -50, 50], 'a positional list has none of the four keys'],

                // --- missing / non-numeric fields ---
                'missing minX' => [['maxX' => 50, 'minZ' => -50, 'maxZ' => 50], 'all four fields are required'],
                'missing maxZ' => [['minX' => -50, 'maxX' => 50, 'minZ' => -50], 'all four fields are required'],
                'null field' => [$with(['minX' => null]), 'null is not numeric'],
                'string field' => [$with(['minX' => 'left']), 'a non-numeric string is rejected'],
                'bool field' => [$with(['maxX' => true]), 'a bool is not numeric'],
                'array field' => [$with(['maxX' => [1]]), 'an array is not numeric'],
                'empty string field' => [$with(['minZ' => '']), 'an empty string is not numeric'],

                // --- non-finite ---
                'INF' => [$with(['maxX' => INF]), 'INF is not finite'],
                '-INF' => [$with(['minX' => -INF]), '-INF is not finite'],
                'NAN' => [$with(['minZ' => NAN]), 'NAN is not finite'],
                'overflow to INF' => [$with(['maxZ' => 1e400]), '1e400 parses to INF'],

                // --- inverted / degenerate ---
                'minX == maxX' => [$with(['minX' => 10, 'maxX' => 10]), 'a zero-width room has no walkable area'],
                'minX > maxX' => [$with(['minX' => 60, 'maxX' => -60]), 'inverted X bounds'],
                'minZ == maxZ' => [$with(['minZ' => 5, 'maxZ' => 5]), 'a zero-depth room has no walkable area'],
                'minZ > maxZ' => [$with(['minZ' => 60, 'maxZ' => -60]), 'inverted Z bounds'],

                // --- spans below BOT_BOUNDS_MIN_SPAN (4.0) ---
                'X span 3.9' => [$with(['minX' => 0, 'maxX' => 3.9]), 'a span under BOT_BOUNDS_MIN_SPAN is degenerate'],
                'Z span 0.001' => [$with(['minZ' => 0, 'maxZ' => 0.001]), 'a span under BOT_BOUNDS_MIN_SPAN is degenerate'],

                // --- spans above BOT_BOUNDS_MAX_SPAN (5000.0) ---
                'X span 5001' => [$with(['minX' => 0, 'maxX' => 5001]), 'a span over BOT_BOUNDS_MAX_SPAN is absurd'],
                'Z span 1e6' => [$with(['minZ' => 0, 'maxZ' => 1000000]), 'a span over BOT_BOUNDS_MAX_SPAN is absurd'],

                // --- coordinates beyond BOT_BOUNDS_MAX_COORD (100000.0) ---
                'coord 100001' => [
                    ['minX' => 99000, 'maxX' => 100001, 'minZ' => -50, 'maxZ' => 50],
                    '|coord| over BOT_BOUNDS_MAX_COORD is rejected even with a sane span',
                ],
                'coord -100001' => [
                    ['minX' => -100001, 'maxX' => -99000, 'minZ' => -50, 'maxZ' => 50],
                    'negative coords are bounded too',
                ],
                'teleport attack' => [
                    ['minX' => 1e300, 'maxX' => 1e300 + 10, 'minZ' => 1e300, 'maxZ' => 1e300 + 10],
                    'a hostile client must not be able to teleport the bot to (1e300, 1e300)',
                ],
            ];
        }

        /** The exact boundary values are ACCEPTED (the checks are strict, not off-by-one). */
        public function testSanitiseRoomBoundsAcceptsTheExactLimits(): void
        {
            $minSpan = \Events::BOT_BOUNDS_MIN_SPAN;
            $this->assertIsArray(
                self::call('sanitiseRoomBounds', ['minX' => 0, 'maxX' => $minSpan, 'minZ' => 0, 'maxZ' => $minSpan]),
                'a span of exactly BOT_BOUNDS_MIN_SPAN is allowed'
            );

            $maxSpan = \Events::BOT_BOUNDS_MAX_SPAN;
            $this->assertIsArray(
                self::call('sanitiseRoomBounds', ['minX' => 0, 'maxX' => $maxSpan, 'minZ' => 0, 'maxZ' => $maxSpan]),
                'a span of exactly BOT_BOUNDS_MAX_SPAN is allowed'
            );

            $maxCoord = \Events::BOT_BOUNDS_MAX_COORD;
            $this->assertIsArray(
                self::call('sanitiseRoomBounds', [
                    'minX' => $maxCoord - 100, 'maxX' => $maxCoord,
                    'minZ' => -$maxCoord, 'maxZ' => -$maxCoord + 100,
                ]),
                'a coordinate of exactly BOT_BOUNDS_MAX_COORD is allowed'
            );
        }

        // ====================================================================
        // dcRoomBounds — reported-or-fallback, inset
        //
        // Reported bounds live at dc:presence:room_bounds:<location> (SharedState
        // STRING, JSON payload, written by spawnBotForLocation with the
        // PRESENCE_SESSION_TTL 86400s carry-alive) — see Events::DC_ROOM_BOUNDS_KEY_PREFIX.
        // ====================================================================

        public function testDcRoomBoundsFallsBackToTheConstantsWhenNothingReported(): void
        {
            $inset = \Events::BOT_BOUNDS_INSET;

            $this->assertSame([
                'minX' => \Events::BOT_BOUNDS_X_MIN + $inset,
                'maxX' => \Events::BOT_BOUNDS_X_MAX - $inset,
                'minZ' => \Events::BOT_BOUNDS_Z_MIN + $inset,
                'maxZ' => \Events::BOT_BOUNDS_Z_MAX - $inset,
            ], self::call('dcRoomBounds', 'main'));
        }

        public function testDcRoomBoundsWorksWithNoRedisClientAtAll(): void
        {
            // The migration's fail-safe contract: with no client injected the
            // facade resolves null, get() returns null — the OLD code had to
            // survive a null $GLOBALS['global'] the same way, no fatal either way.
            \SharedState::reset();

            $bounds = self::call('dcRoomBounds', 'main');
            $this->assertIsArray($bounds, 'a clientless facade must not fatal — the fallback box is used');
            $this->assertSame(\Events::BOT_BOUNDS_X_MIN + \Events::BOT_BOUNDS_INSET, $bounds['minX']);

            \SharedState::setClient($this->redis);
        }

        public function testDcRoomBoundsPrefersTheReportedRoomAndInsetsIt(): void
        {
            $this->seedReportedBounds('main', [
                'minX' => -200.0, 'maxX' => -100.0, 'minZ' => -300.0, 'maxZ' => -200.0,
            ]);
            $inset = \Events::BOT_BOUNDS_INSET;

            $this->assertSame([
                'minX' => -200.0 + $inset,
                'maxX' => -100.0 - $inset,
                'minZ' => -300.0 + $inset,
                'maxZ' => -200.0 - $inset,
            ], self::call('dcRoomBounds', 'main'), 'dc.js builds the room around x/z = -100, not the origin');
        }

        /** A stored value that no longer validates is ignored in favour of the fallback. */
        public function testDcRoomBoundsIgnoresAnInvalidStoredValue(): void
        {
            $this->seedReportedBounds('main', ['minX' => 'x', 'maxX' => 1, 'minZ' => 0, 'maxZ' => 1]);

            $this->assertSame(
                \Events::BOT_BOUNDS_X_MIN + \Events::BOT_BOUNDS_INSET,
                self::call('dcRoomBounds', 'main')['minX']
            );
        }

        /**
         * The inset is capped at a quarter of each span, so a small room never
         * inverts into a negative-size box.
         */
        public function testDcRoomBoundsCapsTheInsetForSmallRooms(): void
        {
            $this->seedReportedBounds('tiny', [
                'minX' => 0.0, 'maxX' => 4.0, 'minZ' => 0.0, 'maxZ' => 4.0,
            ]);

            // inset = min(2.0, 4/4, 4/4) = 1.0
            $this->assertSame(
                ['minX' => 1.0, 'maxX' => 3.0, 'minZ' => 1.0, 'maxZ' => 3.0],
                self::call('dcRoomBounds', 'tiny')
            );
            $bounds = self::call('dcRoomBounds', 'tiny');
            $this->assertLessThan($bounds['maxX'], $bounds['minX'], 'the inset box must stay non-degenerate');
            $this->assertLessThan($bounds['maxZ'], $bounds['minZ']);
        }

        /** Bounds are per location. */
        public function testDcRoomBoundsAreScopedPerLocation(): void
        {
            $this->seedReportedBounds('main', [
                'minX' => -80.0, 'maxX' => 80.0, 'minZ' => -80.0, 'maxZ' => 80.0,
            ]);

            $this->assertSame(-78.0, self::call('dcRoomBounds', 'main')['minX']);
            $this->assertSame(
                \Events::BOT_BOUNDS_X_MIN + \Events::BOT_BOUNDS_INSET,
                self::call('dcRoomBounds', 'other')['minX'],
                'another location must not inherit main\'s reported bounds'
            );
        }

        // ====================================================================
        // dcPresenceIsStale — the keepalive watchdog decision (BUG-B3/B4)
        // ====================================================================

        #[\PHPUnit\Framework\Attributes\DataProvider('stalenessProvider')]
        public function testDcPresenceIsStale(int $pong, int $sent, int $now, int $threshold, bool $expected, string $why): void
        {
            $this->assertSame($expected, self::call('dcPresenceIsStale', $pong, $sent, $now, $threshold), $why);
        }

        public static function stalenessProvider(): array
        {
            $now = 1_000_000;
            $t = 90;

            return [
                // --- has ponged at some point: measure from the LAST PONG only ---
                'ponged just now' => [$now, $now, $now, $t, false, 'a fresh pong is never stale'],
                'ponged 89s ago' => [$now - 89, $now, $now, $t, false, 'inside the threshold'],
                'ponged exactly at the threshold' => [$now - 90, $now, $now, $t, false, 'the check is < (now - threshold), so exactly 90s old survives'],
                'ponged 91s ago' => [$now - 91, $now, $now, $t, true, 'past the threshold => drop'],
                'ponged long ago, just pinged' => [
                    $now - 500, $now, $now, $t, true,
                    'a recent PING must not rescue a client that stopped ponging — that was BUG-B4',
                ],
                'ponged recently, pinged long ago' => [
                    $now - 5, $now - 900, $now, $t, false,
                    'staleness is measured from the pong, never from the ping',
                ],

                // --- never ponged: only an OUTSTANDING ping can make it stale ---
                'never pinged, never ponged' => [
                    0, 0, $now, $t, false,
                    'a client we have never pinged can never be judged silent',
                ],
                'pinged 1s ago, no pong yet' => [
                    0, $now - 1, $now, $t, false,
                    'pinged-but-not-yet-ponged must NEVER be dropped',
                ],
                'pinged 89s ago, no pong yet' => [0, $now - 89, $now, $t, false, 'still inside the grace window'],
                'pinged exactly threshold ago, no pong' => [0, $now - 90, $now, $t, false, 'boundary survives'],
                'pinged 91s ago, no pong yet' => [0, $now - 91, $now, $t, true, 'an outstanding ping older than the threshold => drop'],
                'pinged 200s ago, no pong ever' => [
                    0, $now - 200, $now, $t, true,
                    'a client that never pongs at all must eventually be dropped, not be immune forever',
                ],

                // --- threshold is a parameter, not a constant ---
                'zero threshold, ponged now' => [$now, $now, $now, 0, false, 'now < now is false'],
                'tight threshold' => [$now - 2, $now, $now, 1, true, 'a small threshold drops sooner'],
            ];
        }

        /**
         * dcPresenceIsStale is PURE: no globals, no clock, no I/O. The health
         * sweep depends on that — it takes a snapshot BEFORE sending this round's
         * pings and judges from the snapshot, which only works if the decision
         * function cannot read live state.
         */
        public function testDcPresenceIsStaleIsPureAndTouchesNothing(): void
        {
            for ($i = 0; $i < 50; $i++) {
                self::call('dcPresenceIsStale', $i, $i + 1, $i + 2, 90);
            }

            // The fresh setUp double IS the sentinel: any SharedState write —
            // however innocent it looks — leaves a key behind.
            $this->assertSame([], $this->redis->allKeys(), 'no SharedState/Redis writes');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sent, 'no sends');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed, 'no closes');
            $this->assertSame([], TestTimer::added(), 'no timers');
            $this->assertDeadChannelTransportUnused('dcPresenceIsStale:');
        }

        // ====================================================================
        // randomPointNear
        // ====================================================================

        public function testRandomPointNearWithNoAnchorStaysUniformlyInsideBounds(): void
        {
            $bounds = ['minX' => -30.0, 'maxX' => 70.0, 'minZ' => -80.0, 'maxZ' => -10.0];

            for ($i = 0; $i < 400; $i++) {
                [$x, $z] = self::call('randomPointNear', null, $bounds, 25.0);
                $this->assertGreaterThanOrEqual($bounds['minX'], $x);
                $this->assertLessThanOrEqual($bounds['maxX'], $x);
                $this->assertGreaterThanOrEqual($bounds['minZ'], $z);
                $this->assertLessThanOrEqual($bounds['maxZ'], $z);
            }
        }

        public function testRandomPointNearAnchorStaysWithinRadiusAndBounds(): void
        {
            $bounds = ['minX' => -1000.0, 'maxX' => 1000.0, 'minZ' => -1000.0, 'maxZ' => 1000.0];
            $anchor = ['x' => 120.0, 'z' => -340.0];
            $radius = 25.0;

            for ($i = 0; $i < 400; $i++) {
                [$x, $z] = self::call('randomPointNear', $anchor, $bounds, $radius);
                $distance = sqrt(($x - $anchor['x']) ** 2 + ($z - $anchor['z']) ** 2);
                $this->assertLessThanOrEqual(
                    $radius + 1e-9,
                    $distance,
                    'with room to spare, every point must be inside the radius'
                );
            }
        }

        /**
         * An anchor near (or outside) a wall must still yield an in-bounds point:
         * the result is clamped, so the bot can never be placed through a wall.
         */
        public function testRandomPointNearClampsAnAnchorAgainstAWall(): void
        {
            $bounds = ['minX' => 0.0, 'maxX' => 10.0, 'minZ' => 0.0, 'maxZ' => 10.0];
            $anchor = ['x' => 500.0, 'z' => -500.0];   // far outside the room

            for ($i = 0; $i < 200; $i++) {
                [$x, $z] = self::call('randomPointNear', $anchor, $bounds, 30.0);
                $this->assertGreaterThanOrEqual($bounds['minX'], $x);
                $this->assertLessThanOrEqual($bounds['maxX'], $x);
                $this->assertGreaterThanOrEqual($bounds['minZ'], $z);
                $this->assertLessThanOrEqual($bounds['maxZ'], $z);
            }
        }

        /** A zero radius pins the point to the (clamped) anchor. */
        public function testRandomPointNearWithZeroRadiusReturnsTheAnchor(): void
        {
            $bounds = ['minX' => -50.0, 'maxX' => 50.0, 'minZ' => -50.0, 'maxZ' => 50.0];

            [$x, $z] = self::call('randomPointNear', ['x' => 7.0, 'z' => -3.0], $bounds, 0.0);

            $this->assertEqualsWithDelta(7.0, $x, 1e-9);
            $this->assertEqualsWithDelta(-3.0, $z, 1e-9);
        }

        /** Draws actually vary — the helper is not returning a constant. */
        public function testRandomPointNearActuallyVaries(): void
        {
            $bounds = ['minX' => -100.0, 'maxX' => 100.0, 'minZ' => -100.0, 'maxZ' => 100.0];
            $seen = [];
            for ($i = 0; $i < 50; $i++) {
                $seen[] = implode(',', self::call('randomPointNear', null, $bounds, 25.0));
            }
            $this->assertGreaterThan(40, count(array_unique($seen)), 'points must be randomly distributed');
        }

        // ====================================================================
        // randomRealClientPosition
        //
        // Membership comes from the dc:presence:index ZSET (members = client ids,
        // score = last-seen ts) and positions from the per-client
        // dc:presence:client:<id> STRING records (EX90 = PRESENCE_STALE_TTL) —
        // the GlobalData dc_presence_clients array map is gone.
        // ====================================================================

        public function testRandomRealClientPositionReturnsNullWithoutARedisClient(): void
        {
            \SharedState::reset();
            $this->assertNull(self::call('randomRealClientPosition'), 'clientless zRange fail-safes to [] — no fatal');
            \SharedState::setClient($this->redis);
        }

        public function testRandomRealClientPositionReturnsNullOnAnEmptyScene(): void
        {
            // Fresh double: the index ZSET does not exist yet. Redis has no
            // NULL-vs-empty absent-key trap here — zRange on a missing key is
            // simply [], so there is nothing to seed and nothing to livelock on.
            $this->assertNull(self::call('randomRealClientPosition'));
        }

        /** Bots are not real players, so a bot-only scene has no anchor. */
        public function testRandomRealClientPositionSkipsBots(): void
        {
            $this->seedPresenceMember('bot_main', ['uid' => 'bot_main', 'x' => 5.0, 'z' => 5.0]);

            $this->assertNull(
                self::call('randomRealClientPosition'),
                'anchoring the bot on itself would make it chase its own tail'
            );
        }

        public function testRandomRealClientPositionReturnsAHumanPosition(): void
        {
            $human = dc_client_id(7);
            $this->seedPresenceMember('bot_main', ['uid' => 'bot_main', 'x' => -999.0, 'z' => -999.0]);
            $this->seedPresenceMember($human, ['uid' => 42, 'x' => 12.5, 'z' => -7.25]);

            $this->assertSame(
                ['x' => 12.5, 'z' => -7.25],
                self::call('randomRealClientPosition'),
                'the human\'s position is used, and cast to float'
            );
        }

        /** Entries with missing or non-numeric coordinates are skipped, not fatal. */
        public function testRandomRealClientPositionSkipsMalformedRecords(): void
        {
            $good = dc_client_id(8);
            $this->seedPresenceMember(dc_client_id(1), 'not an array');                        // STRING payload
            $this->seedPresenceMember(dc_client_id(2), ['uid' => 1]);                          // no x/z
            $this->seedPresenceMember(dc_client_id(3), ['uid' => 1, 'x' => 'left', 'z' => 0]); // non-numeric
            $this->seedPresenceMember($good, ['uid' => 1, 'x' => '3', 'z' => '4']);

            $this->assertSame(['x' => 3.0, 'z' => 4.0], self::call('randomRealClientPosition'));
        }

        /** With several humans the choice is random but always one of theirs. */
        public function testRandomRealClientPositionPicksAmongAllHumans(): void
        {
            $a = dc_client_id(11);
            $b = dc_client_id(12);
            $this->seedPresenceMember($a, ['uid' => 1, 'x' => 1.0, 'z' => 1.0]);
            $this->seedPresenceMember($b, ['uid' => 2, 'x' => 2.0, 'z' => 2.0]);

            $seen = [];
            for ($i = 0; $i < 100; $i++) {
                $seen[json_encode(self::call('randomRealClientPosition'))] = true;
            }
            $seen = array_keys($seen);
            sort($seen);   // insertion order depends on the draw, so normalise

            $this->assertSame(
                ['{"x":1,"z":1}', '{"x":2,"z":2}'],
                $seen,
                'every draw must be one of the two humans, and both must be reachable'
            );
        }

        /**
         * A corrupt non-array INDEX could not fatal here even in the old
         * GlobalData model, and is now type-impossible: dc:presence:index is a
         * ZSET, its members are just ids. The realistic analogue — a sweep/leave
         * race where the index still names a member whose record key is already
         * gone — must skip gracefully, never fatal and never anchor the bot on
         * a phantom position.
         */
        public function testRandomRealClientPositionSkipsIndexedMemberWithAbsentRecord(): void
        {
            $ghost = dc_client_id(13);
            $good = dc_client_id(14);
            $this->seedPresenceMember($ghost, null);   // index member only, NO record key
            $this->seedPresenceMember($good, ['uid' => 1, 'x' => 6.5, 'z' => -2.25]);

            for ($i = 0; $i < 30; $i++) {
                $this->assertSame(
                    ['x' => 6.5, 'z' => -2.25],
                    self::call('randomRealClientPosition'),
                    'the orphaned index member must be skipped, leaving the good human'
                );
            }

            // Ghost-only scene degrades to "no anchor known", not a crash.
            \SharedState::del(\Events::DC_PRESENCE_KEY_PREFIX.$good);
            $this->assertNull(
                self::call('randomRealClientPosition'),
                'an index whose every member lost its record yields null'
            );
        }

        // ====================================================================
        // Bot ownership — SharedState lock 'bot_owner:<location>'
        //
        // botOwnerAlive() is RETIRED: the GlobalData-era scheme stamped an
        // owner-pid marker and probed /proc (across three hosts sharing one
        // store, so a foreign pid meant nothing) plus a heartbeat staleness
        // window. Migration A2 replaced that whole probe with one TTL lock —
        // dc:lock:bot_owner:<location> @ Events::BOT_OWNER_LOCK_TTL (10s ≈ 20
        // missed 0.5s moveBot ticks): acquiring IS taking ownership, renewing
        // IS the liveness heartbeat, expiry IS death (next join self-heals).
        // These are the lock-semantics equivalents of the old marker cases.
        // ====================================================================

        /** Old "our own pid marker" (and its digit-string twin) == we hold the lock. */
        public function testBotOwnerLockOwnerRenewsItsHoldWhileRivalsAreRefused(): void
        {
            $name = self::call('botOwnerLockName', 'main');
            $this->assertSame('bot_owner:main', $name, 'facade lock name; the dc:lock: prefix is applied by SharedState');

            $token = \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL);
            $this->assertNotNull($token, 'uncontended acquire of an unowned bot must succeed');
            $this->assertMatchesRegularExpression(
                '/^[^:]+:\d+:[0-9a-f]{16}$/',
                (string) $this->rawLock($name),
                'the key dc:lock:bot_owner:main holds the raw host:pid:hex token — stored un-encoded so the Lua scripts string-compare it'
            );
            $this->assertSame($token, $this->rawLock($name));

            $this->assertTrue(
                \SharedState::renew($name, $token, \Events::BOT_OWNER_LOCK_TTL),
                'the holder renewing with its own token is the heartbeat moveBot runs every tick'
            );
            $this->assertNull(
                \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL),
                'a second acquirer is refused while the owner is alive — the bot is already driven'
            );
        }

        public function testBotOwnerLockForeignTokenRenewIsRefused(): void
        {
            $name = self::call('botOwnerLockName', 'main');
            $token = \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL);
            $this->assertNotNull($token, 'precondition: this process owns the bot');

            $this->assertFalse(
                \SharedState::renew($name, 'rivalhost:999:deadbeef', \Events::BOT_OWNER_LOCK_TTL),
                'a worker that lost ownership (TTL lapsed, taken over) can never extend the new owner\'s hold'
            );
            $this->assertFalse(
                \SharedState::unlock($name, 'rivalhost:999:deadbeef'),
                '…and can never release it either'
            );
            $this->assertSame($token, $this->rawLock($name), 'a refused renew leaves the holder untouched');

            $this->assertTrue(\SharedState::renew($name, $token, \Events::BOT_OWNER_LOCK_TTL), 'the real owner still can');
        }

        /** Old "alive pid that is not ours" == another instance holds the lock: hands off. */
        public function testBotOwnerLockRivalHostHolderIsNotTakeableWhileAlive(): void
        {
            $name = self::call('botOwnerLockName', 'main');
            $this->seedForeignHolder($name, 'rivalhost:999:deadbeef');

            $this->assertNull(
                \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL),
                'a live owner on another datacentered instance keeps its bot'
            );

            $this->redis->fastForward(\Events::BOT_OWNER_LOCK_TTL + 1);
            $this->assertNotNull(
                \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL),
                'the rival\'s hold lasts exactly as long as its renewals — a crashed host self-heals with no reaper'
            );
        }

        /** Old "dead pid must be respawnable" == expiry takeover on the controllable clock. */
        public function testBotOwnerLockExpiryLetsTheNextJoinTakeOver(): void
        {
            $name = self::call('botOwnerLockName', 'main');
            $deadOwnerToken = \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL);
            $this->assertNotNull($deadOwnerToken);
            $this->assertNull(\SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL), 'precondition: held while alive');

            $this->redis->fastForward(\Events::BOT_OWNER_LOCK_TTL + 1);   // 11s > the 10s TTL
            $this->assertFalse($this->rawLock($name), 'the expired lock key is gone on the clock — no probe needed');
            $this->assertFalse(
                \SharedState::renew($name, $deadOwnerToken, \Events::BOT_OWNER_LOCK_TTL),
                'the dead owner cannot resurrect its hold'
            );

            $newToken = \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL);
            $this->assertNotNull($newToken, 'a dead owner self-heals: the next join takes ownership once the TTL lapses');
            $this->assertNotSame($deadOwnerToken, $newToken);

            $this->assertFalse(
                \SharedState::unlock($name, $deadOwnerToken),
                'a slow dying owner must never delete the new holder\'s lock (token check)'
            );
            $this->assertSame($newToken, $this->rawLock($name));
        }

        /** Old "fresh heartbeat keeps ownership" == renewing faster than the TTL never loses it. */
        public function testBotOwnerLockHeartbeatCadenceKeepsTheBotHome(): void
        {
            $name = self::call('botOwnerLockName', 'main');
            $token = \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL);
            $this->assertNotNull($token);

            // Emulate the owner beating every 4s — comfortably inside the 10s
            // TTL (production renews every BOT_MOVE_INTERVAL = 0.5s).
            for ($beat = 1; $beat <= 3; $beat++) {
                $this->redis->fastForward(4);
                $this->assertTrue(
                    \SharedState::renew($name, $token, \Events::BOT_OWNER_LOCK_TTL),
                    "heartbeat {$beat} renews the hold while the owner stays inside the TTL"
                );
                $this->assertNull(
                    \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL),
                    "no takeover during heartbeat {$beat} — the last renew re-armed the full 10s TTL"
                );
                $this->assertSame($token, $this->rawLock($name), 'the holder never changes while beating');
            }

            // Ownership is only ever as alive as the last beat.
            $this->redis->fastForward(\Events::BOT_OWNER_LOCK_TTL + 1);
            $this->assertNotNull(
                \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL),
                'silence for one full TTL reads exactly like a crash'
            );
        }

        /**
         * The old rule "a marker that is not a pid tells us nothing, so assume
         * alive" maps onto Redis string reality: whatever unparseable value a
         * legacy writer left at the lock key, it BLOCKS takeover while held and
         * lapses on the clock — never wedging the bot, never needing a probe.
         * Values are the strings such markers could physically hold (Redis
         * stores strings; null/array survive only as their serialised shapes).
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('legacyOwnerMarkerProvider')]
        public function testUnparseableOwnerMarkersBlockTakeoverUntilTheyExpire(string $marker, string $why): void
        {
            $name = self::call('botOwnerLockName', 'main');
            $this->seedForeignHolder($name, $marker);

            $this->assertNull(\SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL), $why.' — held is held; assume alive');

            $this->redis->fastForward(\Events::BOT_OWNER_LOCK_TTL + 1);
            $this->assertNotNull(
                \SharedState::lock($name, \Events::BOT_OWNER_LOCK_TTL),
                $why.' — but the TTL outlives no marker, so nothing is stuck forever'
            );
        }

        public static function legacyOwnerMarkerProvider(): array
        {
            return [
                'legacy timer label' => ['stale_timer_123', 'a labelled marker is not a token'],
                'empty string' => ['', 'Redis EXISTS has no NULL-vs-empty trap — "" is present'],
                'serialised null' => ['N;', 'the GlobalData wire shape of a null marker'],
                'float pid' => ['1234.5', 'a non-integer marker tells us nothing'],
                'serialised array' => ['a:1:{i:0;i:1234;}', 'a structured marker predates the lock'],
                'bool marker' => ['1', 'a bare flag string is not a host:pid:token'],
                'negative pid string' => ['-1', 'an impossible pid is just an opaque string'],
            ];
        }

        // ====================================================================
        // isInPeerViewport — per-recipient move filtering (BUG-B5)
        // ====================================================================

        /** Missing viewport data fails OPEN: broadcast rather than hide avatars. */
        public function testIsInPeerViewportFailsOpenOnIncompleteData(): void
        {
            $this->assertTrue(self::call('isInPeerViewport', 0.0, 0.0, []));
            $this->assertTrue(self::call('isInPeerViewport', 0.0, 0.0, ['x' => 0, 'z' => 0]));
            $this->assertTrue(
                self::call('isInPeerViewport', 0.0, 0.0, ['x' => 0, 'z' => 0, 'viewDist' => 50]),
                'without a look direction there is nothing to cull against'
            );
        }

        public function testIsInPeerViewportSeesAMoverStraightAhead(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0, 'viewDist' => 50.0];

            $this->assertTrue(self::call('isInPeerViewport', 0.0, 10.0, $peer));
        }

        public function testIsInPeerViewportCullsAMoverBehindThePeer(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0, 'viewDist' => 50.0];

            $this->assertFalse(self::call('isInPeerViewport', 0.0, -10.0, $peer), 'directly behind');
        }

        public function testIsInPeerViewportCullsBeyondTwiceTheViewDistance(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0, 'viewDist' => 50.0];

            $this->assertTrue(self::call('isInPeerViewport', 0.0, 99.0, $peer), 'inside 2 x viewDist');
            $this->assertFalse(self::call('isInPeerViewport', 0.0, 101.0, $peer), 'outside 2 x viewDist');
        }

        /** The cone is 60 degrees wide, i.e. +/-30 degrees off the look axis. */
        public function testIsInPeerViewportCullsOutsideTheSixtyDegreeCone(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0, 'viewDist' => 50.0];
            $dist = 10.0;

            // 25 degrees off-axis: inside the +/-30 half-angle.
            $a = deg2rad(25);
            $this->assertTrue(self::call('isInPeerViewport', sin($a) * $dist, cos($a) * $dist, $peer));

            // 40 degrees off-axis: outside.
            $b = deg2rad(40);
            $this->assertFalse(self::call('isInPeerViewport', sin($b) * $dist, cos($b) * $dist, $peer));
        }

        /** A mover at exactly the peer's position is always visible (dist == 0). */
        public function testIsInPeerViewportKeepsACoincidentMover(): void
        {
            $peer = ['x' => 5.0, 'z' => 5.0, 'dirX' => 1.0, 'dirZ' => 0.0, 'viewDist' => 50.0];

            $this->assertTrue(self::call('isInPeerViewport', 5.0, 5.0, $peer));
        }

        /**
         * A TILTED camera must still see what is in front of it.
         *
         * dc.js reports camera.getWorldDirection(), a unit vector in 3D, so its
         * horizontal part is only unit-length when the camera is perfectly level:
         * looking down 45 degrees leaves |(dirX,dirZ)| ~= 0.707. Without
         * normalising in the XZ plane the raw dot product can never reach
         * cos(30 degrees) = 0.866, so EVERY peer is culled — tilt the camera and
         * remote avatars freeze for up to DC_VIEWPORT_MAX_AGE seconds.
         */
        public function testIsInPeerViewportNormalisesATiltedLookDirection(): void
        {
            $halfRoot2 = sqrt(0.5);   // camera pitched 45 degrees down, facing +Z

            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => $halfRoot2, 'viewDist' => 50.0];
            $this->assertTrue(
                self::call('isInPeerViewport', 0.0, 10.0, $peer),
                'a mover straight ahead must be visible even when the camera is pitched'
            );

            // Even more extreme pitch: |(dirX,dirZ)| = 0.1.
            $peer['dirZ'] = 0.1;
            $this->assertTrue(self::call('isInPeerViewport', 0.0, 10.0, $peer));

            // Normalisation must not break the culling it exists to enable.
            $this->assertFalse(
                self::call('isInPeerViewport', 0.0, -10.0, $peer),
                'a mover behind the peer is still culled after normalisation'
            );
        }

        /**
         * Looking straight up or down leaves NO horizontal facing at all, so
         * there is nothing to cull against: fail open rather than blind the peer.
         */
        public function testIsInPeerViewportFailsOpenWithNoHorizontalFacing(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 0.0, 'viewDist' => 50.0];

            $this->assertTrue(self::call('isInPeerViewport', 0.0, 10.0, $peer));
            $this->assertTrue(self::call('isInPeerViewport', 0.0, -10.0, $peer));
        }

        /**
         * handleDcViewportUpdate() writes ALL of x/z/dirX/dirZ/viewDist with
         * (float) defaults, so the isset() fail-open can never trigger for a
         * STORED viewport — every degenerate value has to be caught inside the
         * maths or the peer goes blind.
         */
        public function testIsInPeerViewportFailsOpenOnNonFiniteGeometry(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0, 'viewDist' => 50.0];

            $this->assertTrue(self::call('isInPeerViewport', NAN, 0.0, $peer), 'NAN mover x');
            $this->assertTrue(self::call('isInPeerViewport', INF, 0.0, $peer), 'INF mover x');
            $this->assertTrue(self::call('isInPeerViewport', 0.0, NAN, $peer), 'NAN mover z');
            $this->assertTrue(
                self::call('isInPeerViewport', 0.0, 10.0, array_merge($peer, ['x' => NAN])),
                'NAN peer position'
            );
            $this->assertTrue(
                self::call('isInPeerViewport', 0.0, 10.0, array_merge($peer, ['dirX' => NAN, 'dirZ' => NAN])),
                'a NAN look direction has no usable length'
            );
        }

        /** A zero/garbage view radius falls back to the 50-unit default. */
        public function testIsInPeerViewportFallsBackToTheDefaultViewDistance(): void
        {
            $base = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0];

            foreach ([0.0, -10.0, NAN, INF] as $bad) {
                $peer = array_merge($base, ['viewDist' => $bad]);
                $this->assertTrue(
                    self::call('isInPeerViewport', 0.0, 99.0, $peer),
                    'inside the default 2 x 50 radius'
                );
                $this->assertFalse(
                    self::call('isInPeerViewport', 0.0, 101.0, $peer),
                    'outside the default 2 x 50 radius — a garbage radius must not disable culling'
                );
            }
        }
    }
}
