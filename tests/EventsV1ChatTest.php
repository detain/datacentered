<?php

/**
 * Test seam for the v1 `channel.*` / `chat.*` channels & messaging surface
 * added in WS-revamp Phase 2 step 2.7 (docs/PROTOCOL_V1.md §2.10 + §4).
 * Covers Events::handleChannelList/Join/Leave/Create/Publish and
 * handleChatSend, plus the shared chatValidChannelId / chatChannelAllowed /
 * chatCacheAppend / chatBroadcastPresence / chatFinishPublish /
 * chatPublishMessage helpers, driven through the public Events::dispatchV1()
 * entry with Flag A ON and an authed session.
 *
 * SEAM NOTES:
 *  - dispatchTask capture: channel.publish/chat.send persist to the TaskWorker
 *    (Tasks/chat_message.php) via Events::dispatchTask(), which in production
 *    opens an AsyncTcpConnection (fatals without an event loop). We inject the
 *    strict-null-guarded Events::$taskDispatcher seam to CAPTURE the
 *    ($type,$args) and INJECT a fake task result, exercising the real hub-side
 *    handlers end-to-end. This also lets us assert the level:"log" DB-skip
 *    (ZERO dispatch) and the persist-then-fanout ordering.
 *  - SharedState Redis facade (GlobalData retired, migration A2): the chat hot
 *    cache is one LIST per channel, dc:chat:msgs:<channel>, written via
 *    rPushLtrim (keeps the NEWEST CHAT_HISTORY_MAX=100 entries; lRange reads
 *    OLDEST-first) plus the dc:chat:activity ZSET index scored by last-activity
 *    ts; the channel_meta registry is the dc:state:channel_meta HASH. Every
 *    seed below goes through the same SharedState wrappers production uses
 *    (Events::chatCacheAppend / handleChannelCreate), so the fixtures are
 *    byte-identical to the real write shape, and assertions read the raw
 *    InMemoryRedis keyspace.
 *  - Fake Gateway group state: getClientSessionsByGroup /
 *    getClientIdCountByGroup / leaveGroup live on the shared fake Gateway
 *    (tests/V1TestSupport.php) so presence + list members can be asserted.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake Gateway seam (and with it TestBootstrap's
    // InMemoryRedis double), then requires FeatureFlags + SharedState + Events.
    require_once __DIR__.'/V1TestSupport.php';

    /**
     * Tests for the v1 channel / chat handlers (WS-revamp Phase 2 step 2.7).
     * Scope is strictly the NEW step-2.7 code.
     */
    class EventsV1ChatTest extends TestCase
    {
        /** @var InMemoryRedis the SharedState double injected by setUp() */
        private $redis;

        /** @var array<int,array{type:string,args:array}> captured dispatchTask calls */
        private $dispatched = [];

        /**
         * TaskWorker-shaped result the fake dispatcher feeds the onResult
         * callback ({"return":"<chat_message json>"}). Null => capture only.
         * @var string|null
         */
        private $fakeTaskReturn = null;

        /** When true the fake dispatcher fires $onError instead of $onResult. */
        private $fakeTaskError = false;

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
            $this->fakeTaskReturn = null;
            $this->fakeTaskError = false;
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

        /**
         * Seed the per-channel hot cache in the exact production write shape:
         * Events::chatCacheAppend() rPushLtrims each §2.10 message onto
         * dc:chat:msgs:<channel> (NEWEST CHAT_HISTORY_MAX kept) and scores the
         * channel into the dc:chat:activity ZSET. Seeding appends $messages in
         * order (oldest first), then registers activity once — an identical
         * keyspace to N sequential production appends at the same second.
         *
         * @param array<int,array<string,mixed>> $messages
         */
        private function seedCachedHistory(string $channel, array $messages): void
        {
            foreach ($messages as $message) {
                \SharedState::rPushLtrim(\Events::CHAT_MSGS_KEY_PREFIX.$channel, $message, \Events::CHAT_HISTORY_MAX);
            }
            \SharedState::zAdd(\Events::CHAT_ACTIVITY_KEY, time(), $channel);
        }

        /** Seed one dc:state:channel_meta registry field (handleChannelCreate's shape). */
        private function seedChannelMeta(string $channel, array $meta): void
        {
            \SharedState::hSet(\Events::CHANNEL_META_REGISTRY_KEY, $channel, $meta);
        }

        /** @return array<int,array<string,mixed>> hot-cache tail, OLDEST-first (what channel.join serves) */
        private function cachedHistory(string $channel): array
        {
            return \SharedState::lRange(\Events::CHAT_MSGS_KEY_PREFIX.$channel, 0, -1);
        }

        /** Assert nothing landed in the chat hot cache or the channel_meta registry. */
        private function assertNoChatStateWrites(string $context): void
        {
            $touched = array_values(array_filter(
                $this->redis->allKeys(),
                static function (string $key): bool {
                    return strpos($key, \SharedState::PREFIX_CHAT) === 0
                        || strpos($key, \SharedState::PREFIX_STATE) === 0;
                }
            ));
            $this->assertSame([], $touched, $context.': the dc:chat:/dc:state: keyspace must be untouched');
        }

        private function installTaskCapture(): void
        {
            \Events::$taskDispatcher = function ($type, $args, $onResult, $onError) {
                $this->dispatched[] = ['type' => $type, 'args' => $args];
                if ($this->fakeTaskError) {
                    if ($onError) {
                        $onError();
                    }
                    return;
                }
                if ($this->fakeTaskReturn !== null && $onResult) {
                    $onResult($this->fakeTaskReturn);
                }
            };
        }

        /** Build the TaskWorker envelope chat_message() returns on success. */
        private function taskOk(int $msgId): string
        {
            return json_encode(['return' => json_encode(['ok' => true, 'msg_id' => $msgId])]);
        }

        /** Admin session (uid "admin-7"). */
        private function asAdmin(string $uid = 'admin-7'): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'admin', 'uid' => $uid, 'name' => 'Nadia Admin'];
        }

        /**
         * Admin session with an INT uid — what production actually holds.
         *
         * asAdmin() above types the uid as a string, which no real admin session
         * ever does: handleAuthHello sets $_SESSION['uid'] = $results[0]['account_id'],
         * and workerman/mysql configures PDO with ATTR_STRINGIFY_FETCHES=false +
         * ATTR_EMULATE_PREPARES=false, so that INT column arrives as a native PHP
         * int. Because every admin fixture here was a string, no test could see
         * that Tasks/chat_message.php's is_string($args['from']) rejected the real
         * shape — every admin-published message failed to persist in production
         * while the suite stayed green.
         */
        private function asAdminAccount(int $uid = 2773): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'admin', 'uid' => $uid, 'name' => 'Nadia Admin'];
        }

        /** vps host session bound to uid "vps<id>". */
        private function asVpsHost(int $id): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'host', 'module' => 'vps', 'uid' => 'vps'.$id, 'name' => 'host'.$id];
        }

        /** bot session bound to uid "bot<n>". */
        private function asBot(string $uid = 'bot5'): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'bot', 'module' => 'bot', 'uid' => $uid, 'name' => $uid];
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

        private function closed(): array
        {
            return \GatewayWorker\Lib\Gateway::$closed;
        }

        private function joined(): array
        {
            return \GatewayWorker\Lib\Gateway::$joined;
        }

        private function left(): array
        {
            return \GatewayWorker\Lib\Gateway::$left;
        }

        private function sentToGroup(): array
        {
            return \GatewayWorker\Lib\Gateway::$sentToGroup;
        }

        private function sentToUid(): array
        {
            return \GatewayWorker\Lib\Gateway::$sentToUid;
        }

        /** The client reply that answers the request envelope id ($re). */
        private function replyFor(string $re = 'req-1'): array
        {
            foreach ($this->sent() as $s) {
                $d = json_decode($s['message'], true);
                if (is_array($d) && ($d['re'] ?? null) === $re) {
                    return $d;
                }
            }
            $this->fail("no client reply found for re={$re}");
        }

        private function assertErrorReply(string $code, string $re = 'req-1'): array
        {
            $reply = $this->replyFor($re);
            $this->assertFalse($reply['ok'], "reply must be ok:false for {$code}");
            $this->assertSame($code, $reply['error']['code']);
            return $reply;
        }

        /** Decode the last channel.message/chat.message push sent to a group. */
        private function lastGroupPush(): array
        {
            $g = $this->sentToGroup();
            $this->assertNotEmpty($g, 'expected a group fan-out');
            return json_decode(end($g)['message'], true);
        }

        // ================================================================
        // 5. Dormancy — Flag A OFF: all 6 ops inert
        // ================================================================

        public function testAllChatOpsDormantWhenFlagAOff(): void
        {
            $this->flagAOff();
            $this->asAdmin();
            $this->installTaskCapture();

            $ops = [
                'channel.list' => [],
                'channel.join' => ['channel' => 'chat:noc'],
                'channel.leave' => ['channel' => 'chat:noc'],
                'channel.create' => ['name' => 'noc'],
                'channel.publish' => ['channel' => 'chat:noc', 'body' => 'hi'],
                'chat.send' => ['channel' => 'chat:noc', 'body' => 'hi']
            ];
            foreach ($ops as $op => $data) {
                $this->dispatch($op, $data);
            }

            $this->assertCount(0, $this->sent(), 'Flag A OFF: no reply for any channel/chat op');
            $this->assertCount(0, $this->dispatched, 'Flag A OFF: no task dispatch');
            $this->assertCount(0, $this->joined(), 'Flag A OFF: no group join');
            $this->assertCount(0, $this->left(), 'Flag A OFF: no group leave');
            $this->assertCount(0, $this->sentToGroup(), 'Flag A OFF: no fan-out');
            $this->assertCount(0, $this->sentToUid(), 'Flag A OFF: no DM delivery');
            $this->assertCount(0, $this->closed());
            // Neither the hot cache nor the channel registry was touched.
            $this->assertNoChatStateWrites('Flag A OFF');
        }

        // ================================================================
        // 1a. channel.list
        // ================================================================

        public function testChannelListReflectsCreatedAndCachedChannels(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            // A created channel (registry) + a channel that only exists via cache.
            $this->seedChannelMeta('chat:noc', ['type' => 'chat', 'topic' => 'ops room', 'created_by' => 'admin-7', 'created_at' => 1]);
            $this->seedCachedHistory('chat:random', [
                ['channel' => 'chat:random', 'from' => 'x', 'body' => 'y', 'level' => 'chat', 'ts' => 1, 'msg_id' => 1]
            ]);
            \GatewayWorker\Lib\Gateway::$groupCounts = ['chat:noc' => 3, 'chat:random' => 0];

            $this->dispatch('channel.list', []);

            $reply = $this->replyFor();
            $this->assertTrue($reply['ok']);
            $byId = [];
            foreach ($reply['data']['channels'] as $c) {
                $byId[$c['id']] = $c;
            }
            $this->assertArrayHasKey('chat:noc', $byId);
            $this->assertArrayHasKey('chat:random', $byId);
            $this->assertSame('chat', $byId['chat:noc']['type']);
            $this->assertSame('ops room', $byId['chat:noc']['topic']);
            $this->assertSame(3, $byId['chat:noc']['members'], 'members = live group connection count');
            $this->assertSame('', $byId['chat:random']['topic'], 'no registry meta => empty topic');
        }

        public function testChannelListFiltersByAclForHost(): void
        {
            $this->flagAOn();
            $this->asVpsHost(12);
            // Three channels with hot-cache traffic (activity index makes them enumerable).
            $this->seedCachedHistory('host:vps12', [
                ['channel' => 'host:vps12', 'from' => 'vps12', 'body' => 'log', 'level' => 'log', 'ts' => 1, 'msg_id' => 0]
            ]);
            $this->seedCachedHistory('host:vps99', [
                ['channel' => 'host:vps99', 'from' => 'vps99', 'body' => 'x', 'level' => 'log', 'ts' => 1, 'msg_id' => 0]
            ]);
            $this->seedCachedHistory('chat:noc', [
                ['channel' => 'chat:noc', 'from' => 'a', 'body' => 'x', 'level' => 'chat', 'ts' => 1, 'msg_id' => 1]
            ]);

            $this->dispatch('channel.list', []);

            $ids = array_column($this->replyFor()['data']['channels'], 'id');
            $this->assertContains('host:vps12', $ids, 'host sees its own channel');
            $this->assertNotContains('host:vps99', $ids, "host must not see another host's channel");
            $this->assertNotContains('chat:noc', $ids, 'host must not see chat channels');
        }

        // ================================================================
        // 1b. channel.join — history + subscriber registration
        // ================================================================

        public function testChannelJoinReturnsCachedHistoryAndSubscribes(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $history = [];
            for ($i = 1; $i <= 3; $i++) {
                $history[] = ['channel' => 'chat:noc', 'from' => 'u', 'from_name' => 'U', 'body' => "m{$i}", 'level' => 'chat', 'ts' => $i, 'msg_id' => $i];
            }
            $this->seedCachedHistory('chat:noc', $history);

            $this->dispatch('channel.join', ['channel' => 'chat:noc'], 9);

            $reply = $this->replyFor();
            $this->assertTrue($reply['ok']);
            $this->assertSame($history, $reply['data']['history'], 'join returns the cached last-N messages');
            // Subscriber registration against the channel==group name.
            $this->assertContains(['client_id' => 9, 'group' => 'chat:noc'], $this->joined());
            // A presence broadcast follows the join.
            $push = $this->lastGroupPush();
            $this->assertSame('channel.presence', $push['op']);
            $this->assertSame('chat:noc', $push['data']['channel']);
        }

        public function testChannelJoinEmptyHistoryWhenChannelUnseen(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->dispatch('channel.join', ['channel' => 'chat:brand-new']);
            $this->assertSame([], $this->replyFor()['data']['history']);
        }

        public function testChannelJoinInvalidIdBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->dispatch('channel.join', ['channel' => 'no-colon-here']);
            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->joined());
        }

        // ================================================================
        // 1c. channel.leave — unsubscribe
        // ================================================================

        public function testChannelLeaveUnsubscribesAndRepliesEmptyObject(): void
        {
            $this->flagAOn();
            $this->asAdmin();

            $this->dispatch('channel.leave', ['channel' => 'chat:noc'], 4);

            $reply = $this->replyFor();
            $this->assertTrue($reply['ok']);
            $this->assertContains(['client_id' => 4, 'group' => 'chat:noc'], $this->left(), 'leaveGroup called');
            // data must serialize as {} (empty object), never [].
            $raw = null;
            foreach ($this->sent() as $s) {
                $d = json_decode($s['message'], true);
                if (($d['re'] ?? null) === 'req-1') {
                    $raw = $s['message'];
                }
            }
            $this->assertStringContainsString('"data":{}', $raw);
        }

        // ================================================================
        // 1d. channel.create — admin-gated, returns full id
        // ================================================================

        public function testChannelCreateAdminReturnsFullId(): void
        {
            $this->flagAOn();
            $this->asAdmin();

            $this->dispatch('channel.create', ['name' => 'noc', 'topic' => 'ops']);

            $reply = $this->replyFor();
            $this->assertTrue($reply['ok']);
            $this->assertSame('chat:noc', $reply['data']['channel'], 'reply carries full type:name id');
            $meta = \SharedState::hGet(\Events::CHANNEL_META_REGISTRY_KEY, 'chat:noc');
            $this->assertSame('chat', $meta['type']);
            $this->assertSame('ops', $meta['topic']);
            $this->assertSame('admin-7', $meta['created_by']);
        }

        public function testChannelCreateNonAdminForbidden(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->dispatch('channel.create', ['name' => 'noc']);
            $this->assertErrorReply('forbidden');
            $this->assertArrayNotHasKey(\Events::CHANNEL_META_REGISTRY_KEY, $this->redis->data, 'a forbidden create must not even mint the registry HASH');

            // A bot is likewise forbidden.
            \GatewayWorker\Lib\Gateway::reset();
            $this->asBot();
            $this->dispatch('channel.create', ['name' => 'noc'], 1, 'req-2');
            $this->assertErrorReply('forbidden', 'req-2');
        }

        public function testChannelCreateBadNameBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->dispatch('channel.create', ['name' => 'bad name!']);
            $this->assertErrorReply('bad_request');
        }

        // ================================================================
        // 4. channel_meta duplicate-create handling
        // ================================================================

        public function testChannelCreateDuplicateRejectedBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();

            $this->dispatch('channel.create', ['name' => 'noc'], 1, 'req-1');
            $this->assertTrue($this->replyFor('req-1')['ok'], 'first create succeeds');

            \GatewayWorker\Lib\Gateway::reset();
            $this->dispatch('channel.create', ['name' => 'noc', 'topic' => 'other'], 1, 'req-2');

            $reply = $this->assertErrorReply('bad_request', 'req-2');
            $this->assertStringContainsString('already exists', $reply['error']['message']);
            // The original registry entry is UNCHANGED (HSETNX atomic-reject, not overwrite).
            $this->assertSame('', \SharedState::hGet(\Events::CHANNEL_META_REGISTRY_KEY, 'chat:noc')['topic'], 'second create did not overwrite topic');
        }

        // ================================================================
        // 1e. channel.publish — persist + cache + fan-out + identity
        // ================================================================

        public function testChannelPublishPersistsCachesAndFansOut(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(4242);

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => "hello\nworld", 'level' => 'chat']);

            // (a) Dispatch to chat_message with exact §2.10 arg shape.
            $this->assertCount(1, $this->dispatched);
            $call = $this->dispatched[0];
            $this->assertSame('chat_message', $call['type']);
            $this->assertSame(['channel', 'from', 'body', 'level', 'ts'], array_keys($call['args']));
            $this->assertSame('chat:noc', $call['args']['channel']);
            $this->assertSame('admin-7', $call['args']['from'], 'from is the session uid');
            $this->assertSame("hello\nworld", $call['args']['body'], 'body stored RAW (no nl2br/escaping)');
            $this->assertSame('chat', $call['args']['level']);
            $this->assertIsInt($call['args']['ts']);

            // (b) hot-cache append with resolved msg_id.
            $cached = $this->cachedHistory('chat:noc');
            $this->assertCount(1, $cached);
            $this->assertSame(4242, $cached[0]['msg_id']);
            $this->assertSame('admin-7', $cached[0]['from']);
            $this->assertSame('Nadia Admin', $cached[0]['from_name']);

            // (c) fan-out channel.message envelope to the channel group.
            $g = $this->sentToGroup();
            $this->assertCount(1, $g);
            $this->assertSame('chat:noc', $g[0]['group']);
            $push = json_decode($g[0]['message'], true);
            $this->assertSame('channel.message', $push['op']);
            $this->assertSame('chat:noc', $push['data']['channel']);
            $this->assertSame('admin-7', $push['data']['from']);
            $this->assertSame('Nadia Admin', $push['data']['from_name']);
            $this->assertSame("hello\nworld", $push['data']['body']);
            $this->assertSame(4242, $push['data']['msg_id']);

            // Publisher ack carries msg_id.
            $reply = $this->replyFor();
            $this->assertTrue($reply['ok']);
            $this->assertSame(4242, $reply['data']['msg_id']);
        }

        public function testChannelPublishIdentityCannotBeSpoofed(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-real');
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(1);

            // Hostile client-supplied identity fields must be ignored.
            $this->dispatch('channel.publish', [
                'channel' => 'chat:noc',
                'body' => 'x',
                'from' => 'vps999',
                'from_name' => 'Somebody Else',
                'uid' => 'vps999',
                'host_id' => 999
            ]);

            $call = $this->dispatched[0]['args'];
            $this->assertSame('admin-real', $call['from'], 'stored from = session uid, not client from');
            $push = $this->lastGroupPush()['data'];
            $this->assertSame('admin-real', $push['from'], 'broadcast from = session uid');
            $this->assertSame('Nadia Admin', $push['from_name'], 'broadcast from_name = session name');
        }

        public function testChannelPublishEmptyBodyBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();
            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => '']);
            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testChannelPublishInvalidLevelBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();
            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => 'x', 'level' => 'bogus']);
            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // 2. log-level DB-write SKIP
        // ================================================================

        public function testChannelPublishLogLevelSkipsDbWriteButStillFansOut(): void
        {
            $this->flagAOn();
            $this->asVpsHost(12); // host may publish to its own host: channel
            $this->installTaskCapture();

            $this->dispatch('channel.publish', ['channel' => 'host:vps12', 'body' => 'daemon log line', 'level' => 'log']);

            // Core assertion: level:"log" dispatches NO persistence task.
            $this->assertCount(0, $this->dispatched, 'level:"log" must skip the chat_message DB dispatch entirely');
            // Still cached + fanned out with msg_id 0.
            $cached = $this->cachedHistory('host:vps12');
            $this->assertCount(1, $cached);
            $this->assertSame(0, $cached[0]['msg_id'], 'skipped DB write => msg_id 0');
            $push = $this->lastGroupPush();
            $this->assertSame('channel.message', $push['op']);
            $this->assertSame('log', $push['data']['level']);
            $this->assertSame(0, $push['data']['msg_id']);
            // Publisher still acked with msg_id 0.
            $this->assertSame(0, $this->replyFor()['data']['msg_id']);
        }

        /**
         * REGRESSION: the publisher used to receive its own message TWICE, so every
         * message you sent appeared twice in your own chat log.
         *
         * chatFinishPublish() fanned out with Gateway::sendToGroup() — which includes the
         * sender, since it is in the channel group — and THEN unconditionally
         * sendToClient()'d the same payload to the publisher "to cover the race where
         * channel.join is still in-flight". Both delivered, so the sender got two copies.
         *
         * It hid for a long time because the sender was never really in the group:
         * channel.join is sent from dc:auth-success, which did not fire until the client
         * learned to correlate its auth reply by id, so the direct send was genuinely the
         * only copy. Fixing auth exposed the double-send.
         *
         * The race is real, so the direct send stays as the publisher's SINGLE delivery
         * and the publisher is excluded from the group blast instead.
         */
        public function testPublisherReceivesItsOwnMessageExactlyOnce(): void
        {
            $this->flagAOn();
            $this->asVpsHost(12);
            $this->installTaskCapture();

            // level:"log" fans out synchronously (no TaskWorker round trip needed).
            $this->dispatch('channel.publish', ['channel' => 'host:vps12', 'body' => 'once please', 'level' => 'log']);

            $pushes = array_values(array_filter(
                \GatewayWorker\Lib\Gateway::$sent,
                static function (array $s): bool {
                    $decoded = json_decode($s['message'], true);
                    return is_array($decoded) && ($decoded['op'] ?? null) === 'channel.message';
                }
            ));
            $this->assertCount(
                1,
                $pushes,
                'the publisher must receive its own message exactly once, not once per fan-out path'
            );

            // And it must be excluded from the group blast, or the direct send duplicates.
            $this->assertCount(1, \GatewayWorker\Lib\Gateway::$sentToGroup);
            $this->assertSame(
                1,   // dispatch()'s default client id
                \GatewayWorker\Lib\Gateway::$sentToGroup[0]['exclude'],
                'the publisher must be excluded from sendToGroup'
            );

            // The ack is a separate frame and must still be there (re+ok, no op).
            $reply = $this->replyFor();
            $this->assertTrue($reply['ok']);
            $this->assertArrayNotHasKey('op', $reply);
        }

        /**
         * REGRESSION: an admin's int uid must reach the TaskWorker as a STRING.
         *
         * Production symptom (seen in billingd.log):
         *   chat_message persist failed for channel chat:noc:
         *   chat_message requires channel, from and body
         * chat_message() rejected `from` because it was an int, so NOTHING an
         * admin said was ever persisted. Fan-out still worked, which is why this
         * looked like a cosmetic log line rather than total persistence loss.
         */
        public function testAdminIntUidIsStringifiedForThePersistTask(): void
        {
            $this->flagAOn();
            $this->asAdminAccount(2773);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(41);

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => 'anybody home']);

            $this->assertCount(1, $this->dispatched, 'chat-level publish must dispatch the persist task');
            $this->assertSame('chat_message', $this->dispatched[0]['type']);
            $from = $this->dispatched[0]['args']['from'];
            $this->assertIsString($from, 'chat_message() rejects a non-string `from`; the hub must cast it');
            $this->assertSame('2773', $from);
        }

        /**
         * The same cast keeps the wire honest: §2.10 declares `from` as a str
         * ("sender uid — vps<id>, account id, or 'system'") and chat_messages.`from`
         * is VARCHAR(64). Uncast, admins published a JSON *number* into a field the
         * frozen protocol says is a string.
         */
        public function testAdminIntUidIsStringifiedOnTheFannedOutEvent(): void
        {
            $this->flagAOn();
            $this->asAdminAccount(2773);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(42);

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => 'anybody home']);

            $push = null;
            foreach (\GatewayWorker\Lib\Gateway::$sent as $s) {
                $decoded = json_decode($s['message'], true);
                if (is_array($decoded) && ($decoded['op'] ?? null) === 'channel.message') {
                    $push = $decoded;
                    break;
                }
            }
            $this->assertNotNull($push, 'the message must still fan out');
            $this->assertIsString($push['data']['from'], '§2.10: `from` is a str, not a number');
            $this->assertSame('2773', $push['data']['from']);
        }

        /**
         * chat.send derives the dm channel id from $from, so the cast must land
         * before the SORT_STRING pair sort — and the recipient list handed to
         * Gateway::sendToUid() must carry the string form too.
         */
        public function testAdminIntUidIsStringifiedForDirectMessages(): void
        {
            $this->flagAOn();
            $this->asAdminAccount(2773);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(43);

            $this->dispatch('chat.send', ['to' => 'vps12', 'body' => 'ping']);

            $this->assertCount(1, $this->dispatched);
            $this->assertSame('dm:2773:vps12', $this->dispatched[0]['args']['channel']);
            $this->assertIsString($this->dispatched[0]['args']['from']);
            $this->assertSame('2773', $this->dispatched[0]['args']['from']);
            $this->assertContains(
                '2773',
                array_map('strval', array_column(\GatewayWorker\Lib\Gateway::$sentToUid, 'uid')),
                'the sender must still be a dm recipient'
            );
        }

        /**
         * A DM reaches the sender through sendToUid (which covers every tab it has open),
         * so the direct send must be SKIPPED there or the sender doubles again.
         */
        public function testDmSenderIsNotAlsoSentDirectly(): void
        {
            $this->flagAOn();
            $this->asVpsHost(12);
            $this->installTaskCapture();

            $senderUid = $_SESSION['uid'];
            \GatewayWorker\Lib\Gateway::$sent = [];

            // chatFinishPublish() is private; reach it directly rather than adding a
            // test-only shim to production code. Driving chat.send end to end would need
            // the TaskWorker round trip, which is not what this test is about.
            $finish = new ReflectionMethod(\Events::class, 'chatFinishPublish');
            $finish->setAccessible(true);
            $finish->invoke(
                null,
                1,   // same client id dispatch() uses
                'req-dm',
                [
                    'channel' => 'chat:dm', 'from' => $senderUid, 'from_name' => 'U',
                    'body' => 'hi', 'level' => 'chat', 'ts' => 1, 'msg_id' => 1,
                ],
                [$senderUid, 'other-uid'],
                'chat.message'
            );

            $pushes = array_values(array_filter(
                \GatewayWorker\Lib\Gateway::$sent,
                static function (array $s): bool {
                    $decoded = json_decode($s['message'], true);
                    return is_array($decoded) && ($decoded['op'] ?? null) === 'chat.message';
                }
            ));
            $this->assertCount(
                0,
                $pushes,
                'sendToUid already reached the sender; a direct send would be a second copy'
            );
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sentToGroup, 'a DM does not blast the group');
        }

        public function testChannelPublishInfoLevelStillPersists(): void
        {
            // Only "log" skips; other non-chat levels still persist.
            $this->flagAOn();
            $this->asVpsHost(12);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(7);

            $this->dispatch('channel.publish', ['channel' => 'host:vps12', 'body' => 'x', 'level' => 'info']);

            $this->assertCount(1, $this->dispatched, 'level:"info" still persists');
            $this->assertSame('info', $this->dispatched[0]['args']['level']);
        }

        public function testChannelPublishPersistFailureStillFansOutWithMsgIdZero(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();
            $this->fakeTaskError = true; // dispatch/connection failure path

            $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => 'x']);

            $this->assertCount(1, $this->dispatched, 'a dispatch was attempted');
            // Availability over durability: message still fans out with msg_id 0.
            $push = $this->lastGroupPush();
            $this->assertSame('channel.message', $push['op']);
            $this->assertSame(0, $push['data']['msg_id']);
            $this->assertSame(0, $this->replyFor()['data']['msg_id']);
        }

        // ================================================================
        // 1f. chat.send — channel form
        // ================================================================

        public function testChatSendChannelFormBehavesLikePublishAndEmitsChannelMessage(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(11);

            $this->dispatch('chat.send', ['channel' => 'chat:noc', 'body' => 'via wrapper']);

            // Persists via chat_message, fans out to the group as channel.message.
            $this->assertCount(1, $this->dispatched);
            $this->assertSame('chat_message', $this->dispatched[0]['type']);
            $this->assertSame('chat:noc', $this->dispatched[0]['args']['channel']);
            $g = $this->sentToGroup();
            $this->assertCount(1, $g);
            $this->assertSame('chat:noc', $g[0]['group']);
            $this->assertSame('channel.message', json_decode($g[0]['message'], true)['op'], 'channel form emits channel.message');
            $this->assertCount(0, $this->sentToUid(), 'channel form is not a DM (no sendToUid)');
        }

        // ================================================================
        // 1g. chat.send — DM form (sorted channel + participant-only delivery)
        // ================================================================

        public function testChatSendDmPersistsSortedChannelAndDeliversToParticipantsOnly(): void
        {
            $this->flagAOn();
            $this->asAdmin('zeb'); // uid "zeb" sorts AFTER "amy"
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(88);

            $this->dispatch('chat.send', ['to' => 'amy', 'body' => 'hi amy']);

            // Sorted dm channel regardless of who sent it.
            $this->assertSame('dm:amy:zeb', $this->dispatched[0]['args']['channel']);
            $this->assertSame('zeb', $this->dispatched[0]['args']['from']);

            // Delivered ONLY to the two participants, never broadcast.
            $this->assertCount(0, $this->sentToGroup(), 'DM must not broadcast to a group');
            $recips = array_column($this->sentToUid(), 'uid');
            sort($recips);
            $this->assertSame(['amy', 'zeb'], $recips, 'DM delivered to exactly sender+recipient');
            // The push op is chat.message for DMs.
            $push = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('chat.message', $push['op']);
            $this->assertSame('dm:amy:zeb', $push['data']['channel']);
            $this->assertSame(88, $push['data']['msg_id']);
        }

        public function testChatSendDmChannelSortingIsSymmetric(): void
        {
            // Send amy->zeb, then zeb->amy: both must resolve to the SAME thread id.
            $this->flagAOn();
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(1);

            $this->asAdmin('amy');
            $this->dispatch('chat.send', ['to' => 'zeb', 'body' => 'a'], 1, 'req-1');
            $chanA = $this->dispatched[0]['args']['channel'];

            $this->dispatched = [];
            $this->asAdmin('zeb');
            $this->dispatch('chat.send', ['to' => 'amy', 'body' => 'b'], 1, 'req-2');
            $chanB = $this->dispatched[0]['args']['channel'];

            $this->assertSame('dm:amy:zeb', $chanA);
            $this->assertSame($chanA, $chanB, 'dm channel id is order-independent (symmetric/idempotent)');
        }

        public function testChatSendDmIdentityCannotBeSpoofed(): void
        {
            $this->flagAOn();
            $this->asAdmin('real-sender');
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(5);

            $this->dispatch('chat.send', [
                'to' => 'bob',
                'body' => 'x',
                'from' => 'evil',
                'from_name' => 'Evil',
                'uid' => 'evil'
            ]);

            // dm channel + from are built from the SESSION uid, not spoofed fields.
            $this->assertSame('dm:bob:real-sender', $this->dispatched[0]['args']['channel']);
            $this->assertSame('real-sender', $this->dispatched[0]['args']['from']);
            $push = json_decode($this->sentToUid()[0]['message'], true)['data'];
            $this->assertSame('real-sender', $push['from']);
            $recips = array_column($this->sentToUid(), 'uid');
            sort($recips);
            $this->assertSame(['bob', 'real-sender'], $recips, 'spoofed uid never becomes a recipient');
        }

        public function testChatSendDmEmptyToBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();
            $this->dispatch('chat.send', ['to' => '   ', 'body' => 'x']);
            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // 3. Hot-cache 100-message cap (rPushLtrim bounding correctness)
        // ================================================================

        public function testHotCacheCapsAtHistoryMaxAndEvictsOldest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();

            // 150 log-level publishes to one channel (log => synchronous, no task
            // callback dependency; each appends via rPushLtrim, which bounds the
            // LIST to the newest CHAT_HISTORY_MAX entries in the same pipeline).
            for ($i = 1; $i <= 150; $i++) {
                \GatewayWorker\Lib\Gateway::reset();
                $this->dispatch('channel.publish', ['channel' => 'chat:noc', 'body' => 'm'.$i, 'level' => 'log'], 1, 'req-'.$i);
            }

            $cache = $this->cachedHistory('chat:noc');
            $this->assertCount(\Events::CHAT_HISTORY_MAX, $cache, 'cache capped at CHAT_HISTORY_MAX (100)');
            $this->assertSame(100, \Events::CHAT_HISTORY_MAX);
            // Newest 100 remain, oldest evicted first, order preserved.
            $this->assertSame('m51', $cache[0]['body'], 'oldest surviving entry is #51 (1..50 evicted)');
            $this->assertSame('m150', $cache[99]['body'], 'newest entry is last');

            // channel.join history reflects exactly the capped, ordered set.
            \GatewayWorker\Lib\Gateway::reset();
            $this->dispatch('channel.join', ['channel' => 'chat:noc'], 1, 'join-1');
            $history = $this->replyFor('join-1')['data']['history'];
            $this->assertCount(100, $history);
            $this->assertSame('m51', $history[0]['body']);
            $this->assertSame('m150', $history[99]['body']);
        }

        // ================================================================
        // 7. ACL restrictions
        // ================================================================

        public function testHostCannotPublishToForeignHostChannel(): void
        {
            $this->flagAOn();
            $this->asVpsHost(12);
            $this->installTaskCapture();
            $this->dispatch('channel.publish', ['channel' => 'host:vps99', 'body' => 'x']);
            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->dispatched);
            $this->assertCount(0, $this->sentToGroup());
        }

        public function testHostCanPublishToOwnHostChannel(): void
        {
            $this->flagAOn();
            $this->asVpsHost(12);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(3);
            $this->dispatch('channel.publish', ['channel' => 'host:vps12', 'body' => 'x', 'level' => 'chat']);
            $this->assertTrue($this->replyFor()['ok']);
            $this->assertCount(1, $this->dispatched);
        }

        public function testHostCanPublishToOwnJobChannelButNotForeign(): void
        {
            $this->flagAOn();
            $this->asVpsHost(12);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(9);

            // job channel carrying the host uid as a segment => allowed.
            $this->dispatch('channel.publish', ['channel' => 'job:provision:vps12', 'body' => 'ok', 'level' => 'log'], 1, 'req-a');
            $this->assertTrue($this->replyFor('req-a')['ok'], 'own job channel allowed');

            \GatewayWorker\Lib\Gateway::reset();
            // job channel without the host uid => denied.
            $this->dispatch('channel.publish', ['channel' => 'job:provision:vps99', 'body' => 'no'], 1, 'req-b');
            $this->assertErrorReply('forbidden', 'req-b');
        }

        public function testBotCannotJoinNonChatChannelButCanJoinChat(): void
        {
            $this->flagAOn();
            $this->asBot('bot5');

            // bot on host:* => forbidden.
            $this->dispatch('channel.join', ['channel' => 'host:vps1'], 1, 'req-a');
            $this->assertErrorReply('forbidden', 'req-a');
            $this->assertCount(0, $this->joined());

            // bot on chat:* => allowed.
            \GatewayWorker\Lib\Gateway::reset();
            $this->dispatch('channel.join', ['channel' => 'chat:noc'], 1, 'req-b');
            $this->assertTrue($this->replyFor('req-b')['ok']);
            $this->assertContains(['client_id' => 1, 'group' => 'chat:noc'], $this->joined());
        }

        public function testBotCannotPublishNonChatChannel(): void
        {
            $this->flagAOn();
            $this->asBot('bot5');
            $this->installTaskCapture();
            $this->dispatch('channel.publish', ['channel' => 'job:x:y', 'body' => 'x']);
            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->dispatched);
        }

        public function testDmThirdPartyEvenAdminCannotJoin(): void
        {
            $this->flagAOn();
            // A DM thread between amy and bob; a THIRD party (admin) tries to join.
            $this->asAdmin('carol'); // admin, but NOT a participant
            $this->dispatch('channel.join', ['channel' => 'dm:amy:bob'], 1, 'req-a');
            $this->assertErrorReply('forbidden', 'req-a');
            $this->assertCount(0, $this->joined(), 'non-participant admin not subscribed to the DM');

            // A participant CAN join their own DM.
            \GatewayWorker\Lib\Gateway::reset();
            $this->asAdmin('amy');
            $this->dispatch('channel.join', ['channel' => 'dm:amy:bob'], 1, 'req-b');
            $this->assertTrue($this->replyFor('req-b')['ok']);
            $this->assertContains(['client_id' => 1, 'group' => 'dm:amy:bob'], $this->joined());
        }

        public function testDmChannelHiddenFromNonParticipantInList(): void
        {
            $this->flagAOn();
            $this->asAdmin('carol');
            $this->seedCachedHistory('dm:amy:bob', [
                ['channel' => 'dm:amy:bob', 'from' => 'amy', 'body' => 'secret', 'level' => 'chat', 'ts' => 1, 'msg_id' => 1]
            ]);
            $this->dispatch('channel.list', []);
            $ids = array_column($this->replyFor()['data']['channels'], 'id');
            $this->assertNotContains('dm:amy:bob', $ids, "a non-participant admin must not see others' DM in channel.list");
        }

        // ================================================================
        // Presence membership dedup (documented approximation)
        // ================================================================

        public function testPresenceMembersDedupedByUid(): void
        {
            $this->flagAOn();
            $this->asAdmin('amy');
            // Two connections for the same uid + one other uid.
            \GatewayWorker\Lib\Gateway::$groupSessions['chat:noc'] = [
                ['uid' => 'amy', 'name' => 'Amy', 'ima' => 'admin'],
                ['uid' => 'amy', 'name' => 'Amy', 'ima' => 'admin'],
                ['uid' => 'vps1', 'name' => 'host1', 'ima' => 'host']
            ];

            $this->dispatch('channel.join', ['channel' => 'chat:noc']);

            $push = $this->lastGroupPush();
            $this->assertSame('channel.presence', $push['op']);
            $members = $push['data']['members'];
            $uids = array_column($members, 'id');
            sort($uids);
            $this->assertSame(['amy', 'vps1'], $uids, 'members deduped by uid');
            foreach ($members as $m) {
                $this->assertTrue($m['online']);
            }
        }
    }

}
