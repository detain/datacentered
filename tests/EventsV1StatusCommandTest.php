<?php
declare(strict_types=1);

/**
 * Test seam for the v1 `/status` command handler added to WS-revamp Phase 2
 * step 2.7 (docs/PROTOCOL_V1.md §2.10).
 *
 * Covers Events::handleStatusCommand via Events::handleChannelPublish when
 * body === '/status', driven through the public Events::dispatchV1() entry
 * with Flag A ON and an authed session.
 *
 * SEAM NOTES:
 *  - handleStatusCommand uses Gateway::sendToClient directly (no task dispatch,
 *    no cache write, no fan-out) — this is the key behavior under test.
 *  - CAS-capable GlobalData: an in-memory client backs $global->channel_meta.
 *  - Fake Gateway: getAllClientSessions lives on the shared fake Gateway
 *    (tests/V1TestSupport.php).
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake Gateway seam, then requires FeatureFlags + Events.
    require_once __DIR__ . '/V1TestSupport.php';

    /**
     * In-memory GlobalData client with add-if-absent + whole-map CAS.
     * (Same class as EventsV1ChatTest — declared once via V1TestSupport include.)
     */
    if (!class_exists('ChatFakeGlobalDataClient')) {
        class ChatFakeGlobalDataClient extends \GlobalData\Client
        {
            /** @var array<string,mixed> */
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

            /** Add-if-absent, like the real GlobalData add(). */
            public function add($key, $value)
            {
                if (!array_key_exists($key, $this->store)) {
                    $this->store[$key] = $value;
                }
                return true;
            }

            /** Whole-map compare-and-swap (strict value equality, like the real client). */
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
     * Tests for the v1 /status command (WS-revamp Phase 2 step 2.7).
     */
    class EventsV1StatusCommandTest extends TestCase
    {
        /** @var ChatFakeGlobalDataClient */
        private $global;

        /** @var array<int,array{type:string,args:array}> captured dispatchTask calls */
        private $dispatched = [];

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
            \GatewayWorker\Lib\Gateway::reset();
            $_SESSION = [];
            \Events::$db = null;
            \Events::$taskDispatcher = null;
            unset($GLOBALS['global']);
            $this->dispatched = [];

            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        // ------------------------------------------------------------------
        // Fixtures / helpers
        // ------------------------------------------------------------------

        /** Inject the in-memory GlobalData client and flip Flag A ON. */
        private function flagAOn(): ChatFakeGlobalDataClient
        {
            $client = new ChatFakeGlobalDataClient();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 1;
            $GLOBALS['global'] = $client;
            $this->global = $client;
            return $client;
        }

        private function installTaskCapture(): void
        {
            \Events::$taskDispatcher = function ($type, $args, $onResult, $onError) {
                $this->dispatched[] = ['type' => $type, 'args' => $args];
            };
        }

        /** Admin session (uid "admin-7"). */
        private function asAdmin(string $uid = 'admin-7'): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'admin', 'uid' => $uid, 'name' => 'Nadia Admin'];
        }

        private function dispatch(string $op, array $data, int $client = 1, string $id = 'req-1'): void
        {
            \Events::dispatchV1($client, [
                'v' => 1, 'id' => $id, 'op' => $op, 'ts' => 1719700000, 'data' => $data
            ]);
        }

        private function sent(): array
        {
            return \GatewayWorker\Lib\Gateway::$sent;
        }

        private function sentToGroup(): array
        {
            return \GatewayWorker\Lib\Gateway::$sentToGroup;
        }

        private function sentToUid(): array
        {
            return \GatewayWorker\Lib\Gateway::$sentToUid;
        }

        // ================================================================
        // Core /status command behavior
        // ================================================================

        /**
         * /status must NOT call dispatchTask (no DB write) — send-to-client only.
         */
        public function testStatusCommandDoesNotPersistToDb(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status']);

            $this->assertCount(0, $this->dispatched, '/status must NOT dispatch any task (no DB write)');
        }

        /**
         * /status must NOT write to the hot cache.
         */
        public function testStatusCommandDoesNotWriteToHotCache(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status']);

            $this->assertArrayNotHasKey('channels', $this->global->store, '/status must NOT write to hot cache');
            $this->assertArrayNotHasKey('channel_msgs:chat:noc', $this->global->store, '/status must NOT write per-channel cache');
        }

        /**
         * /status must NOT fan out to the channel group — send-to-client only.
         */
        public function testStatusCommandDoesNotFanOutToGroup(): void
        {
            $this->flagAOn();
            $this->asAdmin();

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status']);

            $this->assertCount(0, $this->sentToGroup(), '/status must NOT fan out to the channel group');
        }

        /**
         * /status must NOT send via sendToUid (not a DM).
         */
        public function testStatusCommandDoesNotSendToUid(): void
        {
            $this->flagAOn();
            $this->asAdmin();

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status']);

            $this->assertCount(0, $this->sentToUid(), '/status must NOT use sendToUid');
        }

        /**
         * /status returns a channel.message structure to the requesting client.
         */
        public function testStatusCommandReturnsChannelMessageStructure(): void
        {
            $this->flagAOn();
            $this->asAdmin();

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status'], 42, 'status-req');

            // The status response is pushed via sendToClient (no reply envelope — it's a push, not an ack).
            // Find the message sent to client 42.
            $found = false;
            foreach ($this->sent() as $s) {
                if ($s['client_id'] === 42) {
                    $d = json_decode($s['message'], true);
                    if (is_array($d) && ($d['op'] ?? null) === 'channel.message') {
                        $found = true;
                        // Verify structure
                        $this->assertSame(1, $d['v']);
                        $this->assertSame('channel.message', $d['op']);
                        $this->assertSame('chat:noc', $d['data']['channel']);
                        $this->assertSame('system', $d['data']['from']);
                        $this->assertSame('Status Bot', $d['data']['from_name']);
                        $this->assertSame('info', $d['data']['level']);
                        $this->assertArrayHasKey('body', $d['data']);
                        $this->assertArrayHasKey('ts', $d['data']);
                        $this->assertSame(0, $d['data']['msg_id'], 'msg_id must be 0 (no DB write)');
                        // body must contain the status text
                        $this->assertStringContainsString('Status:', $d['data']['body']);
                        $this->assertStringContainsString('Clients:', $d['data']['body']);
                        $this->assertStringContainsString('Channels:', $d['data']['body']);
                        break;
                    }
                }
            }
            $this->assertTrue($found, 'Expected a channel.message push to client 42');
        }

        /**
         * /status must NOT send a reply ack ({ok:true,...}) — it sends only the
         * channel.message push to the requesting client.
         */
        public function testStatusCommandDoesNotSendReplyAck(): void
        {
            $this->flagAOn();
            $this->asAdmin();

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status'], 1, 'status-req');

            // Assert no reply with ok:true was sent (reply ack format: {ok:true,data:{msg_id:#}})
            foreach ($this->sent() as $s) {
                $d = json_decode($s['message'], true);
                $this->assertFalse(
                    is_array($d) && ($d['ok'] ?? false) === true,
                    'Expected no {ok:true} reply ack to be sent'
                );
            }
        }

        // ================================================================
        // Non-/status messages flow through the normal path
        // ================================================================

        /**
         * A regular message must still call dispatchTask (persist) and fan out.
         */
        public function testNonStatusMessagesStillFlowNormally(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();
            \Events::$taskDispatcher = function ($type, $args, $onResult, $onError) {
                $this->dispatched[] = ['type' => $type, 'args' => $args];
                if ($onResult) {
                    $onResult(json_encode(['return' => json_encode(['ok' => true, 'msg_id' => 99])]));
                }
            };

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => 'hello world']);

            $this->assertCount(1, $this->dispatched, 'Regular message must dispatch chat_message task');
            $this->assertSame('chat_message', $this->dispatched[0]['type']);
            $this->assertCount(1, $this->sentToGroup(), 'Regular message must fan out to channel group');
        }

        /**
         * A message that looks like /status but is not exactly "/status" must still
         * flow through the normal path.
         *
         * @dataProvider notStatusMessagesProvider
         */
        public function testNotStatusMessagesFlowNormally(string $body): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();
            \Events::$taskDispatcher = function ($type, $args, $onResult, $onError) {
                $this->dispatched[] = ['type' => $type, 'args' => $args];
                if ($onResult) {
                    $onResult(json_encode(['return' => json_encode(['ok' => true, 'msg_id' => 1])]));
                }
            };

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => $body]);

            $this->assertCount(1, $this->dispatched, "'{$body}' must NOT be treated as /status");
            $this->assertCount(1, $this->sentToGroup(), "'{$body}' must fan out to channel group");
        }

        public static function notStatusMessagesProvider(): array
        {
            return [
                '/status' . "\n" => ['/status' . "\n"],
                '/status ' => ['/status '],
                ' /status' => [' /status'],
                '/status二三' => ['/status二三'],
                '/statusquery' => ['/statusquery'],
                '/STATUS' => ['/STATUS'],
                '/status/' => ['/status/'],
                'hello /status world' => ['hello /status world'],
            ];
        }

        // ================================================================
        // Edge cases
        // ================================================================

        /**
         * When getAllClientSessions returns false/null, client count must be 0.
         */
        public function testStatusCommandHandlesGetAllClientSessionsReturningFalse(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            // Ensure $allSessions is not an array (null/false behavior)
            \GatewayWorker\Lib\Gateway::$allSessions = null;

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status'], 1, 'status-req');

            $found = false;
            foreach ($this->sent() as $s) {
                $d = json_decode($s['message'], true);
                if (is_array($d) && ($d['op'] ?? null) === 'channel.message') {
                    $found = true;
                    $this->assertStringContainsString('Clients: 0', $d['data']['body']);
                    break;
                }
            }
            $this->assertTrue($found, 'Expected a channel.message push');
        }

        /**
         * When getAllClientSessions returns an empty array, client count must be 0.
         */
        public function testStatusCommandHandlesEmptySessions(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            \GatewayWorker\Lib\Gateway::$allSessions = [];

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status'], 1, 'status-req');

            $found = false;
            foreach ($this->sent() as $s) {
                $d = json_decode($s['message'], true);
                if (is_array($d) && ($d['op'] ?? null) === 'channel.message') {
                    $found = true;
                    $this->assertStringContainsString('Clients: 0', $d['data']['body']);
                    break;
                }
            }
            $this->assertTrue($found, 'Expected a channel.message push');
        }

        /**
         * When getAllClientSessions returns sessions, count must be accurate.
         */
        public function testStatusCommandCountsClientsCorrectly(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            \GatewayWorker\Lib\Gateway::$allSessions = [
                1 => ['uid' => 'admin-1', 'name' => 'Admin 1'],
                2 => ['uid' => 'admin-2', 'name' => 'Admin 2'],
                3 => ['uid' => 'host-5', 'name' => 'Host 5'],
            ];

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status'], 1, 'status-req');

            $found = false;
            foreach ($this->sent() as $s) {
                $d = json_decode($s['message'], true);
                if (is_array($d) && ($d['op'] ?? null) === 'channel.message') {
                    $found = true;
                    $this->assertStringContainsString('Clients: 3', $d['data']['body']);
                    break;
                }
            }
            $this->assertTrue($found, 'Expected a channel.message push');
        }

        /**
         * When channel_meta is null/undefined, channel count must be 0.
         */
        public function testStatusCommandHandlesNullChannelMeta(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            // channel_meta key is not set at all
            // ChatFakeGlobalDataClient returns null for unset keys

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status'], 1, 'status-req');

            $found = false;
            foreach ($this->sent() as $s) {
                $d = json_decode($s['message'], true);
                if (is_array($d) && ($d['op'] ?? null) === 'channel.message') {
                    $found = true;
                    $this->assertStringContainsString('Channels: 0', $d['data']['body']);
                    break;
                }
            }
            $this->assertTrue($found, 'Expected a channel.message push');
        }

        /**
         * When channel_meta is an empty array, channel count must be 0.
         */
        public function testStatusCommandHandlesEmptyChannelMeta(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->global->store['channel_meta'] = [];

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status'], 1, 'status-req');

            $found = false;
            foreach ($this->sent() as $s) {
                $d = json_decode($s['message'], true);
                if (is_array($d) && ($d['op'] ?? null) === 'channel.message') {
                    $found = true;
                    $this->assertStringContainsString('Channels: 0', $d['data']['body']);
                    break;
                }
            }
            $this->assertTrue($found, 'Expected a channel.message push');
        }

        /**
         * When channel_meta has channels, count must be accurate.
         */
        public function testStatusCommandCountsChannelsCorrectly(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->global->store['channel_meta'] = [
                'chat:noc' => ['type' => 'chat'],
                'chat:alerts' => ['type' => 'chat'],
                'host:vps5' => ['type' => 'host'],
            ];

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status'], 1, 'status-req');

            $found = false;
            foreach ($this->sent() as $s) {
                $d = json_decode($s['message'], true);
                if (is_array($d) && ($d['op'] ?? null) === 'channel.message') {
                    $found = true;
                    $this->assertStringContainsString('Channels: 3', $d['data']['body']);
                    break;
                }
            }
            $this->assertTrue($found, 'Expected a channel.message push');
        }

        /**
         * The status body must contain a timestamp.
         */
        public function testStatusCommandContainsTimestamp(): void
        {
            $this->flagAOn();
            $this->asAdmin();

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status'], 1, 'status-req');

            $found = false;
            foreach ($this->sent() as $s) {
                $d = json_decode($s['message'], true);
                if (is_array($d) && ($d['op'] ?? null) === 'channel.message') {
                    $found = true;
                    // Timestamp in format YYYY-MM-DD HH:MM:SS
                    $this->assertMatchesRegularExpression(
                        '/Status: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
                        $d['data']['body'],
                        'Status body must contain a timestamp'
                    );
                    break;
                }
            }
            $this->assertTrue($found, 'Expected a channel.message push');
        }

        // ================================================================
        // Dormancy — Flag A OFF: /status must be inert
        // ================================================================

        /**
         * When Flag A is OFF, /status must not send any message.
         */
        public function testStatusCommandDormantWhenFlagAOff(): void
        {
            $client = new ChatFakeGlobalDataClient();
            // Do NOT set Flag A — it's off by default
            $GLOBALS['global'] = $client;
            $this->global = $client;
            $this->asAdmin();
            $this->installTaskCapture();

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status']);

            $this->assertCount(0, $this->sent(), 'Flag A OFF: /status must not send any message');
            $this->assertCount(0, $this->dispatched, 'Flag A OFF: /status must not dispatch any task');
            $this->assertCount(0, $this->sentToGroup(), 'Flag A OFF: /status must not fan out');
        }
    }
}
