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
 *  - SharedState Redis facade (GlobalData retired, migration A2): the
 *    channel_meta registry the command counts is the dc:state:channel_meta
 *    HASH, seeded directly through the SharedState::hSet shape production's
 *    handleChannelCreate writes (the old CAS-capable GlobalData fake is gone).
 *  - Fake Gateway: getAllClientSessions lives on the shared fake Gateway
 *    (tests/V1TestSupport.php).
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake Gateway seam (and with it TestBootstrap's
    // InMemoryRedis double), then requires FeatureFlags + SharedState + Events.
    require_once __DIR__ . '/V1TestSupport.php';

    /**
     * Tests for the v1 /status command (WS-revamp Phase 2 step 2.7).
     */
    class EventsV1StatusCommandTest extends TestCase
    {
        /** @var InMemoryRedis the SharedState double injected by setUp() */
        private $redis;

        /** @var array<int,array{type:string,args:array}> captured dispatchTask calls */
        private $dispatched = [];

        protected function setUp(): void
        {
            $this->resetState();

            // SharedState contract (identical to tests/SharedStateTest.php):
            // $GLOBALS['redis'] must not leak in from another suite — the facade
            // prefers it over any injected client — so every test starts from
            // the "no shared connection" baseline, then gets a fresh double.
            unset($GLOBALS['redis']);
            $this->redis = new \InMemoryRedis();
            \SharedState::setClient($this->redis);
        }

        protected function tearDown(): void
        {
            $this->resetState();
            \SharedState::setClient(null);
            unset($GLOBALS['redis']);
            \SharedState::reset();
        }

        private function resetState(): void
        {
            \GatewayWorker\Lib\Gateway::reset();
            $_SESSION = [];
            \Events::$db = null;
            \Events::$taskDispatcher = null;
            $this->dispatched = [];
        }

        // ------------------------------------------------------------------
        // Fixtures / helpers
        // ------------------------------------------------------------------

        /** Flag A ON — the same write FeatureFlags::setNewHandling(null, true) makes. */
        private function flagAOn(): void
        {
            \SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 1);
        }

        /** Flag A explicitly OFF (dormant) — unset would mean ON with a live client. */
        private function flagAOff(): void
        {
            \SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 0);
        }

        /** Seed one dc:state:channel_meta registry field (handleChannelCreate's shape). */
        private function seedChannelMeta(string $channel, array $meta): void
        {
            \SharedState::hSet(\Events::CHANNEL_META_REGISTRY_KEY, $channel, $meta);
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

            // Nothing under dc:chat: may exist: neither the per-channel LIST
            // (dc:chat:msgs:chat:noc) nor the activity ZSET (dc:chat:activity).
            $this->assertArrayNotHasKey(\Events::CHAT_MSGS_KEY_PREFIX.'chat:noc', $this->redis->data, '/status must NOT write the per-channel hot-cache LIST');
            $this->assertArrayNotHasKey(\Events::CHAT_ACTIVITY_KEY, $this->redis->data, '/status must NOT touch the dc:chat:activity index either');
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
                        // Production contract: handleStatusCommand() hardcodes
                        // level 'chat' for the response (docblock: "so the name
                        // prefix renders") — the 'info' this test once expected
                        // predates that decision.
                        $this->assertSame('chat', $d['data']['level']);
                        $this->assertArrayHasKey('body', $d['data']);
                        // `ts` is a v1Envelope TOP-LEVEL field; the /status push
                        // deliberately omits the message-obj ts inside `data`
                        // (unlike a real chatPublishMessage payload).
                        $this->assertArrayHasKey('ts', $d);
                        $this->assertArrayNotHasKey('ts', $d['data'], 'the /status push omits the message-obj ts inside data');
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
         * NOTE: PHPUnit 12 removed support for the @dataProvider ANNOTATION —
         * the provider was silently ignored and this test errored with
         * "Too few arguments". It must be the #[DataProvider] attribute.
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('notStatusMessagesProvider')]
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
                '/status二三' => ['/status二三'],
                '/statusquery' => ['/statusquery'],
                '/STATUS' => ['/STATUS'],
                '/status/' => ['/status/'],
                'hello /status world' => ['hello /status world'],
                '/ status' => ['/ status'],
                'status' => ['status'],
            ];
        }

        /**
         * The command match is whitespace-TOLERANT: handleChannelPublish() does
         * `$body = trim($body)` before comparing with '/status', so surrounding
         * whitespace and a trailing newline still invoke the command.
         *
         * These three inputs used to sit in notStatusMessagesProvider() asserting
         * the opposite. They never ran: the provider was wired with the
         * @dataProvider ANNOTATION, which PHPUnit 12 ignores, so the test errored
         * with "Too few arguments" instead of executing a single case — the drift
         * from the trim() being added was completely invisible.
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('whitespacePaddedStatusProvider')]
        public function testStatusCommandMatchIsWhitespaceTolerant(string $body): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => $body]);

            $this->assertCount(
                0,
                $this->dispatched,
                json_encode($body).' is the /status command, so it must NOT be published as chat'
            );
            $this->assertCount(
                0,
                $this->sentToGroup(),
                '/status replies to the caller only and is never fanned out to the channel'
            );
            $this->assertNotEmpty($this->sent(), '/status must reply to the requesting client');
        }

        public static function whitespacePaddedStatusProvider(): array
        {
            return [
                'trailing newline' => ['/status'."\n"],
                'trailing space' => ['/status '],
                'leading space' => [' /status'],
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
            // The dc:state:channel_meta HASH is never seeded: hGetAll on an
            // absent key is [] in Redis, so the registry reads as "no channels".

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
            // "Empty registry" in Redis: seed a channel, then delete its only
            // field — HDEL of the last field drops the HASH key entirely (the
            // double mirrors the server), which hGetAll reads back as [].
            $this->seedChannelMeta('chat:gone', ['type' => 'chat']);
            \SharedState::hDel(\Events::CHANNEL_META_REGISTRY_KEY, 'chat:gone');

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
            $this->seedChannelMeta('chat:noc', ['type' => 'chat']);
            $this->seedChannelMeta('chat:alerts', ['type' => 'chat']);
            $this->seedChannelMeta('host:vps5', ['type' => 'host']);

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
            // With a live client, unset Flag A would read ON — set it explicitly OFF.
            $this->flagAOff();
            $this->asAdmin();
            $this->installTaskCapture();

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '/status']);

            $this->assertCount(0, $this->sent(), 'Flag A OFF: /status must not send any message');
            $this->assertCount(0, $this->dispatched, 'Flag A OFF: /status must not dispatch any task');
            $this->assertCount(0, $this->sentToGroup(), 'Flag A OFF: /status must not fan out');
        }
    }
}
