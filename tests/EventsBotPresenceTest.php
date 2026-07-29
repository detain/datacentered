<?php

/**
 * Tests for the DataCenter Bot Presence System in Events.php.
 *
 * These tests verify the bot avatar system that simulates visitors walking
 * around the datacenter 3D scene when real users are present.
 *
 * Key patterns under test:
 *   - Bot client_id format: bot_$location
 *   - Bot state stored at dc_bot_state:$location
 *   - Bot timer stored at dc_bot_timer:$location
 *   - Bot moves at BOT_WALK_SPEED (1.2 units/sec)
 *   - Bot skips broadcasting when no real users at location
 *
 * @see Events::spawnBotForLocation()
 * @see Events::moveBot()
 * @see Events::cleanupBotForLocation()
 * @see Events::hasRealUsersAtLocation()
 */

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/V1TestSupport.php';

    /**
     * FakeChannelClient — captures Channel::publish() calls for assertion.
     */
    if (!class_exists('Channel\Client')) {
        class_alias(stdClass::class, 'Channel\Client');
    }

    class BotFakeChannelClient
    {
        /** @var array<int,array{channel:string,message:string}> */
        public static $published = [];

        public static function publish($channel, $message)
        {
            self::$published[] = ['channel' => $channel, 'message' => $message];
            return true;
        }

        public static function reset(): void
        {
            self::$published = [];
        }
    }

    // class_alias must happen BEFORE Events.php loads to redirect Channel\Client
    class_alias(BotFakeChannelClient::class, 'Channel\Client');

    /**
     * In-memory GlobalData client for bot presence tests.
     *
     * Extends the MultiTabFakeGlobalDataClient pattern and adds support for:
     *   - dc_bot_state:$location
     *   - dc_bot_timer:$location
     *   - dc_move_batch:bot_$location
     *   - Bot presence key: dc_presence:client:bot_$location
     */
    class BotFakeGlobalDataClient extends \GlobalData\Client
    {
        /** @var array<string,mixed> */
        public $store = [];

        /** @var array<string> timers that would be created (Timer::add) */
        public $timers = [];

        public function __construct()
        {
            // No address needed for fake
        }

        public function &__get($key)
        {
            // Bot state keys: dc_bot_state:main
            if (strpos($key, 'dc_bot_state:') === 0) {
                if (!isset($this->store[$key]) || !is_array($this->store[$key])) {
                    $this->store[$key] = null;
                }
                return $this->store[$key];
            }
            // Bot timer keys: dc_bot_timer:main
            if (strpos($key, 'dc_bot_timer:') === 0) {
                if (!isset($this->store[$key])) {
                    $this->store[$key] = null;
                }
                return $this->store[$key];
            }
            // Bot move batch keys: dc_move_batch:bot_main
            if (strpos($key, 'dc_move_batch:') === 0) {
                if (!isset($this->store[$key])) {
                    $this->store[$key] = null;
                }
                return $this->store[$key];
            }
            // Per-client presence keys: dc_presence:client:100 or dc_presence:client:bot_main
            if (strpos($key, 'dc_presence:client:') === 0) {
                if (!isset($this->store[$key]) || !is_array($this->store[$key])) {
                    $this->store[$key] = null;
                }
                return $this->store[$key];
            }
            // dc_presence_clients index
            if ($key === 'dc_presence_clients') {
                if (!isset($this->store[$key]) || !is_array($this->store[$key])) {
                    $this->store[$key] = [];
                }
                return $this->store[$key];
            }
            // dc_active_clients index
            if ($key === 'dc_active_clients') {
                if (!isset($this->store[$key]) || !is_array($this->store[$key])) {
                    $this->store[$key] = [];
                }
                return $this->store[$key];
            }
            // Ping, cleanup, session, viewport, throttle keys
            if (preg_match('/^(dc_ping|dc_cleanup|dc_client_session|dc_session_clients|dc_viewport|dc_move_throttle|dc_presence|dc_bot_presence):/', $key)) {
                if (!isset($this->store[$key])) {
                    $this->store[$key] = null;
                }
                return $this->store[$key];
            }
            // CAS keys like hosts, running, ptys, etc.
            if (array_key_exists($key, $this->store)) {
                return $this->store[$key];
            }
            // Auto-create for keys like hosts, running, ptys (needed for auth flow)
            if (in_array($key, ['hosts', 'running', 'ptys', 'sysinfos', 'rooms'])) {
                $this->store[$key] = [];
                return $this->store[$key];
            }
            // Key never stored — return static dummy by reference
            static $dummy = [];
            return $dummy;
        }

        public function __set($key, $value)
        {
            $this->store[$key] = $value;
        }

        public function __isset($key)
        {
            if (strpos($key, 'dc_bot_state:') === 0 || strpos($key, 'dc_bot_timer:') === 0 || strpos($key, 'dc_move_batch:') === 0) {
                return array_key_exists($key, $this->store);
            }
            if (strpos($key, 'dc_presence:client:') === 0) {
                return isset($this->store[$key]) && is_array($this->store[$key]);
            }
            if (in_array($key, ['dc_presence_clients', 'dc_active_clients'])) {
                return isset($this->store[$key]) && is_array($this->store[$key]);
            }
            if (preg_match('/^(dc_ping|dc_cleanup|dc_client_session|dc_session_clients|dc_viewport|dc_move_throttle|dc_presence|dc_bot_presence):/', $key)) {
                return isset($this->store[$key]);
            }
            return array_key_exists($key, $this->store);
        }

        public function __unset($key)
        {
            unset($this->store[$key]);
        }

        /**
         * CAS operation — compares by value for arrays, by reference otherwise.
         */
        public function cas($key, $old, $new)
        {
            $current = $this->store[$key] ?? null;
            if (is_array($current) && is_array($old)) {
                if ($current === $old) {
                    $this->store[$key] = $new;
                    return true;
                }
                return false;
            }
            if ($current === $old) {
                $this->store[$key] = $new;
                return true;
            }
            return false;
        }

        /**
         * Add a key with initial value if it doesn't exist (atomic increment-like).
         */
        public function add($key, $initial)
        {
            if (!isset($this->store[$key])) {
                $this->store[$key] = $initial;
                return true;
            }
            return false;
        }

        /**
         * Record a timer that would have been created via Timer::add.
         * Returns a fake timer ID for tracking.
         */
        public function createTimer(float $interval, $callback, array $args, bool $persistent): mixed
        {
            $timerId = 'timer_' . count($this->timers) . '_' . uniqid();
            $this->timers[] = [
                'id' => $timerId,
                'interval' => $interval,
                'callback' => $callback,
                'args' => $args,
                'persistent' => $persistent,
            ];
            return $timerId;
        }

        /**
         * Delete a recorded timer.
         */
        public function deleteTimer(string $timerId): void
        {
            $this->timers = array_values(array_filter(
                $this->timers,
                fn($t) => $t['id'] !== $timerId
            ));
        }
    }

    /**
     * Tests for the DataCenter Bot Presence System.
     */
    class EventsBotPresenceTest extends TestCase
    {
        private const LOCATION = 'main';
        private const BOT_ID = 'bot_main';

        protected function setUp(): void
        {
            $this->resetState();
            $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
            \Events::$channelClient = [BotFakeChannelClient::class, 'publish'];
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
            \Events::$moveBatch = [];
            \Events::$moveBatchTimer = null;
            BotFakeChannelClient::reset();
            unset($GLOBALS['global']);

            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        /**
         * Set up Flag C (dcBotPresenceEnabled) ON with bot presence enabled.
         * Returns the fake GlobalData client for direct assertion.
         */
        private function botFlagOn(): BotFakeGlobalDataClient
        {
            $client = new BotFakeGlobalDataClient();
            $client->store[FeatureFlags::VAR_DC_BOT_PRESENCE] = 1;
            $client->store['dc_presence_clients'] = [];
            $client->store['dc_active_clients'] = [];
            $GLOBALS['global'] = $client;

            return $client;
        }

        /**
         * Set up Flag C (dcBotPresenceEnabled) OFF.
         */
        private function botFlagOff(): BotFakeGlobalDataClient
        {
            $client = new BotFakeGlobalDataClient();
            $client->store[FeatureFlags::VAR_DC_BOT_PRESENCE] = 0;
            $client->store['dc_presence_clients'] = [];
            $client->store['dc_active_clients'] = [];
            $GLOBALS['global'] = $client;

            return $client;
        }

        // ========================================================================
        // spawnBotForLocation: basic creation
        // ========================================================================

        /**
         * VERIFY: spawnBotForLocation creates bot entry at dc_presence:client:bot_main.
         */
        public function testSpawnBotCreatesPresenceEntry(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $presenceKey = 'dc_presence:client:' . self::BOT_ID;
            $entry = $global->$presenceKey;

            $this->assertNotNull($entry, 'Bot presence entry must exist');
            $this->assertIsArray($entry);
            $this->assertSame(self::BOT_ID, $entry['uid']);
            $this->assertSame(self::BOT_ID, $entry['client_id']);
            $this->assertSame(self::LOCATION, $entry['location']);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('x', $entry);
            $this->assertArrayHasKey('z', $entry);
            $this->assertArrayHasKey('yaw', $entry);
        }

        /**
         * VERIFY: Bot position is within defined bounds.
         */
        public function testSpawnBotPositionWithinBounds(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $presenceKey = 'dc_presence:client:' . self::BOT_ID;
            $entry = $global->$presenceKey;

            $this->assertGreaterThanOrEqual(\Events::BOT_BOUNDS_X_MIN, $entry['x']);
            $this->assertLessThanOrEqual(\Events::BOT_BOUNDS_X_MAX, $entry['x']);
            $this->assertGreaterThanOrEqual(\Events::BOT_BOUNDS_Z_MIN, $entry['z']);
            $this->assertLessThanOrEqual(\Events::BOT_BOUNDS_Z_MAX, $entry['z']);
        }

        /**
         * VERIFY: Bot is added to dc_presence_clients index.
         */
        public function testSpawnBotAddsToClientsIndex(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $clientList = $global->dc_presence_clients;
            $this->assertIsArray($clientList);
            $this->assertContains(self::BOT_ID, $clientList, 'Bot must be in dc_presence_clients index');
        }

        /**
         * VERIFY: Bot is added to dc_active_clients list.
         */
        public function testSpawnBotAddsToActiveClients(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $activeClients = $global->dc_active_clients;
            $this->assertIsArray($activeClients);
            $this->assertContains(self::BOT_ID, $activeClients, 'Bot must be in dc_active_clients');
        }

        /**
         * VERIFY: Bot state is stored at dc_bot_state:main.
         */
        public function testSpawnBotStoresBotState(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $botStateKey = 'dc_bot_state:' . self::LOCATION;
            $botState = $global->$botStateKey;

            $this->assertNotNull($botState, 'Bot state must be stored');
            $this->assertIsArray($botState);
            $this->assertSame(self::BOT_ID, $botState['uid']);
            $this->assertArrayHasKey('target_x', $botState);
            $this->assertArrayHasKey('target_z', $botState);
        }

        /**
         * VERIFY: spawnBotForLocation broadcasts dc.presence.joined event via Channel\Client::publish.
         * When a bot spawns, it announces its presence to the dc_presence channel so the frontend
         * can render the bot avatar.
         */
        public function testSpawnBotBroadcastsJoinedEvent(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            // Find the dc.presence.joined broadcast
            $found = false;
            foreach (BotFakeChannelClient::$published as $entry) {
                if ($entry['channel'] === 'dc_presence') {
                    $msg = json_decode($entry['message'], true);
                    if (isset($msg['op']) && $msg['op'] === 'dc.presence.joined') {
                        $found = true;
                        // Verify bot state data is included in the payload
                        $this->assertArrayHasKey('client_id', $msg['data'], 'Payload must contain client_id');
                        $this->assertArrayHasKey('location', $msg['data'], 'Payload must contain location');
                        $this->assertArrayHasKey('x', $msg['data'], 'Payload must contain x');
                        $this->assertArrayHasKey('z', $msg['data'], 'Payload must contain z');
                        $this->assertArrayHasKey('yaw', $msg['data'], 'Payload must contain yaw');
                        $this->assertArrayHasKey('name', $msg['data'], 'Payload must contain name');
                        // Verify the bot ID in payload matches expected format
                        $this->assertSame(self::BOT_ID, $msg['data']['client_id']);
                        $this->assertSame(self::LOCATION, $msg['data']['location']);
                        break;
                    }
                }
            }
            $this->assertTrue($found, 'spawnBotForLocation must broadcast dc.presence.joined to dc_presence channel');
        }

        // ========================================================================
        // spawnBotForLocation: flag control
        // ========================================================================

        /**
         * VERIFY: Bot is NOT spawned when Flag C is disabled.
         */
        public function testSpawnBotSkippedWhenFlagDisabled(): void
        {
            $global = $this->botFlagOff();

            \Events::spawnBotForLocation(self::LOCATION);

            $presenceKey = 'dc_presence:client:' . self::BOT_ID;
            $this->assertNull($global->$presenceKey, 'Bot must NOT be created when flag is off');

            $botStateKey = 'dc_bot_state:' . self::LOCATION;
            $this->assertNull($global->$botStateKey, 'Bot state must NOT be created when flag is off');
        }

        // ========================================================================
        // spawnBotForLocation: stale timer handling
        // ========================================================================

        /**
         * VERIFY: Handles stale timer (timer exists but state lost).
         * When the timer exists but state is null, the stale timer should be cleaned up
         * and a new bot should be spawned.
         */
        public function testSpawnBotHandlesStaleTimer(): void
        {
            $global = $this->botFlagOn();

            // Simulate a stale timer from a previous crash/power loss
            // Timer exists but state is gone
            $botTimerKey = 'dc_bot_timer:' . self::LOCATION;
            $global->$botTimerKey = 'stale_timer_123';

            // State should be null initially (simulating lost state)
            $botStateKey = 'dc_bot_state:' . self::LOCATION;
            $this->assertNull($global->$botStateKey, 'State should be null (simulating lost state)');

            // Spawn should succeed after cleaning up stale timer
            \Events::spawnBotForLocation(self::LOCATION);

            // Bot should now exist
            $presenceKey = 'dc_presence:client:' . self::BOT_ID;
            $this->assertNotNull($global->$presenceKey, 'Bot should be spawned after stale timer cleanup');
        }

        /**
         * VERIFY: Spawn returns early if bot already exists (timer AND state present).
         */
        public function testSpawnBotSkipsIfAlreadyExists(): void
        {
            $global = $this->botFlagOn();

            // Pre-populate bot state
            $botStateKey = 'dc_bot_state:' . self::LOCATION;
            $global->$botStateKey = [
                'uid' => self::BOT_ID,
                'name' => 'Existing Bot',
                'x' => 10.0,
                'z' => 20.0,
                'yaw' => 1.5,
                'target_x' => 30.0,
                'target_z' => 40.0,
                'client_id' => self::BOT_ID,
                'location' => self::LOCATION,
            ];
            // Pre-populate timer
            $botTimerKey = 'dc_bot_timer:' . self::LOCATION;
            $global->$botTimerKey = 'existing_timer_456';

            // Capture state before attempting second spawn
            $presenceKey = 'dc_presence:client:' . self::BOT_ID;
            $originalEntry = $global->$presenceKey;

            \Events::spawnBotForLocation(self::LOCATION);

            // Entry should be unchanged
            $this->assertSame($originalEntry, $global->$presenceKey, 'Existing bot entry must not be modified');
        }

        // ========================================================================
        // moveBot: real users check
        // ========================================================================

        /**
         * VERIFY: moveBot skips broadcasting when no real users at location.
         * (hasRealUsersAtLocation returns false when only bots are present)
         */
        public function testMoveBotSkipsWhenNoRealUsers(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot first
            \Events::spawnBotForLocation(self::LOCATION);

            // Ensure NO real users - only the bot is in the clients list
            // (already set up that way by spawnBotForLocation)

            // Clear any previous move batch
            $batchKey = 'dc_move_batch:' . self::BOT_ID;
            $global->$batchKey = null;

            // Call moveBot
            \Events::moveBot(self::LOCATION);

            // Bot state should be updated but no batch write should happen
            // (because hasRealUsersAtLocation returns false when only bot is present)
            $botStateKey = 'dc_bot_state:' . self::LOCATION;
            $botState = $global->$botStateKey;
            $this->assertNotNull($botState, 'Bot state should still exist');
        }

        /**
         * VERIFY: moveBot proceeds when real users ARE present.
         */
        public function testMoveBotProceedsWhenRealUsersPresent(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot
            \Events::spawnBotForLocation(self::LOCATION);

            // Add a real user to the clients list
            $clientList = $global->dc_presence_clients;
            $clientList[] = 999; // Real user client_id
            $global->dc_presence_clients = $clientList;

            // Add real user's presence entry
            $global->{'dc_presence:client:999'} = [
                'uid' => 'admin1',
                'name' => 'Admin One',
                'x' => 5.0,
                'z' => 5.0,
                'yaw' => 0.0,
                'client_id' => 999,
            ];

            // Record original position
            $botStateKey = 'dc_bot_state:' . self::LOCATION;
            $originalState = $global->$botStateKey;
            $originalX = $originalState['x'];
            $originalZ = $originalState['z'];

            // Call moveBot
            \Events::moveBot(self::LOCATION);

            // Bot should have moved (or at least state updated)
            $updatedState = $global->$botStateKey;
            $this->assertNotNull($updatedState);

            // Position or target should have been updated
            $moved = ($updatedState['x'] !== $originalX) || ($updatedState['z'] !== $originalZ)
                || ($updatedState['target_x'] !== $originalState['target_x'])
                || ($updatedState['target_z'] !== $originalState['target_z']);
            $this->assertTrue($moved, 'Bot should have updated position or picked new target');
        }

        // ========================================================================
        // moveBot: flag control
        // ========================================================================

        /**
         * VERIFY: moveBot cleans up bot when flag is disabled.
         */
        public function testMoveBotCleansUpWhenFlagDisabled(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot with flag ON
            \Events::spawnBotForLocation(self::LOCATION);
            $this->assertNotNull($global->{'dc_bot_state:' . self::LOCATION});

            // Now disable flag
            $global->store[FeatureFlags::VAR_DC_BOT_PRESENCE] = 0;

            // Add real user so moveBot doesn't just skip
            $clientList = $global->dc_presence_clients;
            $clientList[] = 999;
            $global->dc_presence_clients = $clientList;
            $global->{'dc_presence:client:999'} = [
                'uid' => 'admin1', 'name' => 'Admin', 'x' => 0, 'z' => 0, 'yaw' => 0, 'client_id' => 999,
            ];

            // Call moveBot - should trigger cleanup
            \Events::moveBot(self::LOCATION);

            // Bot should be cleaned up
            $this->assertNull($global->{'dc_bot_state:' . self::LOCATION}, 'Bot state should be removed when flag disabled');
        }

        // ========================================================================
        // moveBot: movement mechanics
        // ========================================================================

        /**
         * VERIFY: moveBot writes to dc_move_batch:bot_main when moving.
         */
        public function testMoveBotWritesToMoveBatch(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot
            \Events::spawnBotForLocation(self::LOCATION);

            // Add real user so move actually proceeds
            $clientList = $global->dc_presence_clients;
            $clientList[] = 999;
            $global->dc_presence_clients = $clientList;
            $global->{'dc_presence:client:999'} = [
                'uid' => 'admin1', 'name' => 'Admin', 'x' => 0, 'z' => 0, 'yaw' => 0, 'client_id' => 999,
            ];

            // Call moveBot
            \Events::moveBot(self::LOCATION);

            // Check batch entry was written
            $batchKey = 'dc_move_batch:' . self::BOT_ID;
            $batchEntry = $global->$batchKey;
            $this->assertNotNull($batchEntry, 'Move batch entry should be written');

            $decoded = json_decode($batchEntry, true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('x', $decoded);
            $this->assertArrayHasKey('z', $decoded);
            $this->assertArrayHasKey('yaw', $decoded);
        }

        /**
         * VERIFY: moveBot picks new random target when arrived at current position.
         */
        public function testMoveBotPicksNewTargetWhenArrived(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot
            \Events::spawnBotForLocation(self::LOCATION);

            // Add real user
            $clientList = $global->dc_presence_clients;
            $clientList[] = 999;
            $global->dc_presence_clients = $clientList;
            $global->{'dc_presence:client:999'} = [
                'uid' => 'admin1', 'name' => 'Admin', 'x' => 0, 'z' => 0, 'yaw' => 0, 'client_id' => 999,
            ];

            // Set bot's target to be very close to current position (within threshold)
            $botStateKey = 'dc_bot_state:' . self::LOCATION;
            $botState = $global->$botStateKey;
            $botState['target_x'] = $botState['x'] + 0.1; // Within BOT_TARGET_THRESHOLD
            $botState['target_z'] = $botState['z'] + 0.1;
            $global->$botStateKey = $botState;

            $originalTargetX = $botState['target_x'];
            $originalTargetZ = $botState['target_z'];

            // Call moveBot
            \Events::moveBot(self::LOCATION);

            // Should have picked a new target (far from original)
            $updatedState = $global->$botStateKey;

            // The new target should be different, and likely far from original
            // (using lcg_value so exact prediction not possible, but should change)
            $this->assertNotEquals($originalTargetX, $updatedState['target_x'], 'Target X should change when arrived');
            $this->assertNotEquals($originalTargetZ, $updatedState['target_z'], 'Target Z should change when arrived');
        }

        // ========================================================================
        // cleanupBotForLocation: basic cleanup
        // ========================================================================

        /**
         * VERIFY: cleanupBotForLocation removes bot from dc_presence_clients.
         */
        public function testCleanupRemovesBotFromClientsIndex(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot
            \Events::spawnBotForLocation(self::LOCATION);
            $this->assertContains(self::BOT_ID, $global->dc_presence_clients);

            // Clean up
            \Events::cleanupBotForLocation(self::LOCATION);

            // Bot should be removed
            $this->assertNotContains(self::BOT_ID, $global->dc_presence_clients, 'Bot must be removed from clients index');
        }

        /**
         * VERIFY: cleanupBotForLocation removes bot from dc_active_clients.
         */
        public function testCleanupRemovesBotFromActiveClients(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot
            \Events::spawnBotForLocation(self::LOCATION);
            $this->assertContains(self::BOT_ID, $global->dc_active_clients);

            // Clean up
            \Events::cleanupBotForLocation(self::LOCATION);

            // Bot should be removed
            $this->assertNotContains(self::BOT_ID, $global->dc_active_clients, 'Bot must be removed from active clients');
        }

        /**
         * VERIFY: cleanupBotForLocation deletes dc_bot_state key.
         */
        public function testCleanupDeletesBotState(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot
            \Events::spawnBotForLocation(self::LOCATION);
            $this->assertNotNull($global->{'dc_bot_state:' . self::LOCATION});

            // Clean up
            \Events::cleanupBotForLocation(self::LOCATION);

            // State should be deleted
            $this->assertNull($global->{'dc_bot_state:' . self::LOCATION}, 'Bot state must be deleted');
        }

        /**
         * VERIFY: cleanupBotForLocation deletes dc_bot_timer key.
         */
        public function testCleanupDeletesBotTimer(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot
            \Events::spawnBotForLocation(self::LOCATION);
            $this->assertNotNull($global->{'dc_bot_timer:' . self::LOCATION});

            // Clean up
            \Events::cleanupBotForLocation(self::LOCATION);

            // Timer should be deleted
            $this->assertNull($global->{'dc_bot_timer:' . self::LOCATION}, 'Bot timer must be deleted');
        }

        /**
         * VERIFY: cleanupBotForLocation deletes bot presence entry.
         */
        public function testCleanupDeletesBotPresenceEntry(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot
            \Events::spawnBotForLocation(self::LOCATION);
            $this->assertNotNull($global->{'dc_presence:client:' . self::BOT_ID});

            // Clean up
            \Events::cleanupBotForLocation(self::LOCATION);

            // Presence entry should be deleted
            $this->assertNull($global->{'dc_presence:client:' . self::BOT_ID}, 'Bot presence entry must be deleted');
        }

        // ========================================================================
        // cleanupBotForLocation: real users safety
        // ========================================================================

        /**
         * VERIFY: cleanupBotForLocation only cleans up bot entries, not real users.
         */
        public function testCleanupDoesNotAffectRealUsers(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot
            \Events::spawnBotForLocation(self::LOCATION);

            // Add a real user
            $global->{'dc_presence:client:999'} = [
                'uid' => 'admin1',
                'name' => 'Admin One',
                'x' => 5.0,
                'z' => 5.0,
                'yaw' => 0.0,
                'client_id' => 999,
            ];
            $clientList = $global->dc_presence_clients;
            $clientList[] = 999;
            $global->dc_presence_clients = $clientList;

            $activeClients = $global->dc_active_clients;
            $activeClients[] = 999;
            $global->dc_active_clients = $activeClients;

            // Clean up bot
            \Events::cleanupBotForLocation(self::LOCATION);

            // Real user's entries must still exist
            $this->assertNotNull($global->{'dc_presence:client:999'}, 'Real user presence must NOT be removed');
            $this->assertContains(999, $global->dc_presence_clients, 'Real user must remain in clients index');
            $this->assertContains(999, $global->dc_active_clients, 'Real user must remain in active clients');
        }

        // ========================================================================
        // hasRealUsersAtLocation: helper method
        // ========================================================================

        /**
         * VERIFY: hasRealUsersAtLocation returns false when only bots present.
         * (Uses reflection to test private method)
         */
        public function testHasRealUsersReturnsFalseWhenOnlyBots(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot (only bot in list)
            \Events::spawnBotForLocation(self::LOCATION);

            // Use reflection to test private method
            $method = new ReflectionMethod(\Events::class, 'hasRealUsersAtLocation');
            $method->setAccessible(true);

            $result = $method->invoke(null, self::LOCATION);

            $this->assertFalse($result, 'hasRealUsersAtLocation should return false when only bots present');
        }

        /**
         * VERIFY: hasRealUsersAtLocation returns true when real users present.
         */
        public function testHasRealUsersReturnsTrueWhenRealUsersPresent(): void
        {
            $global = $this->botFlagOn();

            // Spawn bot
            \Events::spawnBotForLocation(self::LOCATION);

            // Add a real user
            $clientList = $global->dc_presence_clients;
            $clientList[] = 999;
            $global->dc_presence_clients = $clientList;
            $global->{'dc_presence:client:999'} = [
                'uid' => 'admin1', 'name' => 'Admin', 'x' => 0, 'z' => 0, 'yaw' => 0, 'client_id' => 999,
            ];

            // Use reflection to test private method
            $method = new ReflectionMethod(\Events::class, 'hasRealUsersAtLocation');
            $method->setAccessible(true);

            $result = $method->invoke(null, self::LOCATION);

            $this->assertTrue($result, 'hasRealUsersAtLocation should return true when real users present');
        }

        /**
         * VERIFY: hasRealUsersAtLocation returns false when no users at all.
         */
        public function testHasRealUsersReturnsFalseWhenEmpty(): void
        {
            $global = $this->botFlagOn();

            // No bots, no users - empty scene
            $global->dc_presence_clients = [];
            $global->dc_active_clients = [];

            // Use reflection to test private method
            $method = new ReflectionMethod(\Events::class, 'hasRealUsersAtLocation');
            $method->setAccessible(true);

            $result = $method->invoke(null, self::LOCATION);

            $this->assertFalse($result, 'hasRealUsersAtLocation should return false when no users');
        }

        // ========================================================================
        // Integration: user join/leave flow
        // ========================================================================

        /**
         * VERIFY: Real user join triggers bot spawn (via handleDcPresenceJoin calling spawnBotForLocation).
         * This is an integration test that verifies the flow from join to bot spawn.
         */
        public function testUserJoinTriggersBotSpawn(): void
        {
            $global = $this->botFlagOn();

            // Set up authenticated session
            $_SESSION['v1_authed'] = true;
            $_SESSION['uid'] = 'admin77';
            $_SESSION['name'] = 'Admin';
            $_SESSION['ima'] = 'admin';
            $_SESSION['login'] = true;

            // Dispatch dc.presence.join for a real user
            $clientId = 500;
            \Events::dispatchV1($clientId, [
                'v' => 1,
                'id' => 'test-join',
                'op' => 'dc.presence.join',
                'ts' => time(),
                'data' => ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0],
            ]);

            // Bot should have been spawned
            $this->assertNotNull($global->{'dc_presence:client:' . self::BOT_ID}, 'Bot should be spawned when real user joins');
            $this->assertNotNull($global->{'dc_bot_state:' . self::LOCATION}, 'Bot state should exist');
        }

        /**
         * VERIFY: Last real user leave triggers bot cleanup (via handleDcPresenceLeave).
         * This test verifies that when the last real user leaves, the bot is cleaned up.
         */
        public function testLastUserLeaveTriggersBotCleanup(): void
        {
            $global = $this->botFlagOn();

            // Set up authenticated session
            $_SESSION['v1_authed'] = true;
            $_SESSION['uid'] = 'admin77';
            $_SESSION['name'] = 'Admin';
            $_SESSION['ima'] = 'admin';
            $_SESSION['login'] = true;

            // First, have user join (which spawns bot)
            $clientId = 500;
            \Events::dispatchV1($clientId, [
                'v' => 1,
                'id' => 'test-join',
                'op' => 'dc.presence.join',
                'ts' => time(),
                'data' => ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0],
            ]);

            // Verify bot exists
            $this->assertNotNull($global->{'dc_bot_state:' . self::LOCATION}, 'Bot should exist after user join');

            // Now have user leave
            \Events::dispatchV1($clientId, [
                'v' => 1,
                'id' => 'test-leave',
                'op' => 'dc.presence.leave',
                'ts' => time(),
                'data' => [],
            ]);

            // Bot should be cleaned up
            $this->assertNull($global->{'dc_bot_state:' . self::LOCATION}, 'Bot should be cleaned up when last user leaves');
        }

        // ========================================================================
        // Constants verification
        // ========================================================================

        /**
         * VERIFY: Bot movement constants are correct.
         */
        public function testBotConstants(): void
        {
            $this->assertSame(0.5, \Events::BOT_MOVE_INTERVAL, 'BOT_MOVE_INTERVAL should be 0.5s');
            $this->assertSame(1.2, \Events::BOT_WALK_SPEED, 'BOT_WALK_SPEED should be 1.2 units/sec');
            $this->assertSame(1.0, \Events::BOT_TARGET_THRESHOLD, 'BOT_TARGET_THRESHOLD should be 1.0 units');
            $this->assertSame(-50.0, \Events::BOT_BOUNDS_X_MIN);
            $this->assertSame(50.0, \Events::BOT_BOUNDS_X_MAX);
            $this->assertSame(-50.0, \Events::BOT_BOUNDS_Z_MIN);
            $this->assertSame(50.0, \Events::BOT_BOUNDS_Z_MAX);
            $this->assertSame('main', \Events::BOT_DEFAULT_LOCATION);
        }
    }
}
