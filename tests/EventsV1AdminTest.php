<?php

/**
 * Test seam for the v1 `admin.*` introspection handlers added in WS-revamp
 * Phase 2 step 2.8 (docs/PROTOCOL_V1.md §2.9): Events::handleAdminHosts /
 * handleAdminTimers / handleAdminRunning, driven through the public
 * Events::dispatchV1() entry with Flag A ON and an appropriate $_SESSION role.
 *
 * Same Gateway-stub technique as EventsV1CmdTest / EventsV1ChatTest: the shared
 * tests/V1TestSupport.php declares a lightweight fake \GatewayWorker\Lib\Gateway
 * *before* Events.php loads, capturing every reply/close and answering
 * getAllClientSessions() from an in-memory session map. The REAL handlers run
 * end to end (admin role gates, registry reshaping, empty-object encoding,
 * read-only guarantees) — nothing is reimplemented here.
 *
 * The shared $global->hosts / $global->timers / $global->running registries are
 * backed by the same in-memory GlobalData client the other v1 tests use
 * (extends \GlobalData\Client so the FeatureFlags `instanceof` gate passes).
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake Gateway seam, then requires FeatureFlags + Events.
    require_once __DIR__.'/V1TestSupport.php';

    /**
     * In-memory GlobalData client with a working CAS, reused for Flag A toggling
     * AND for backing the admin.* read registries ($global->hosts/timers/running).
     */
    if (!class_exists('AdminFakeGlobalDataClient')) {
        class AdminFakeGlobalDataClient extends \GlobalData\Client
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
     * Tests for the v1 admin.* handlers (WS-revamp Phase 2 step 2.8). Scope is
     * strictly the NEW step-2.8 code.
     */
    class EventsV1AdminTest extends TestCase
    {
        /** @var AdminFakeGlobalDataClient */
        private $global;

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
            unset($GLOBALS['global']);

            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        // ------------------------------------------------------------------
        // Fixtures / helpers
        // ------------------------------------------------------------------

        /** Inject the in-memory GlobalData client and flip Flag A ON. */
        private function flagAOn(): AdminFakeGlobalDataClient
        {
            $client = new AdminFakeGlobalDataClient();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 1;
            $GLOBALS['global'] = $client;
            $this->global = $client;
            return $client;
        }

        /** Inject the in-memory GlobalData client WITHOUT flipping Flag A (dormant). */
        private function flagAOff(): AdminFakeGlobalDataClient
        {
            $client = new AdminFakeGlobalDataClient();
            $GLOBALS['global'] = $client;
            $this->global = $client;
            return $client;
        }

        /** Simulate an authenticated admin session with the given uid. */
        private function asAdmin($uid = 'admin-42'): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'admin', 'uid' => $uid];
        }

        /** Simulate an authenticated host session bound to the given host uid. */
        private function asHost(string $uid): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'host', 'uid' => $uid];
        }

        /** Set the fake all-connection session map returned by getAllClientSessions(). */
        private function seedSessions(array $sessions): void
        {
            \GatewayWorker\Lib\Gateway::$allSessions = $sessions;
        }

        /** Drive an admin.* op through the public dispatchV1 entry. */
        private function dispatch(string $op, array $data = [], int $client = 1, string $id = 'req-1'): void
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

        /** Decode the single client reply; assert exactly one was sent. */
        private function singleReply(): array
        {
            $sent = $this->sent();
            $this->assertCount(1, $sent, 'expected exactly one client reply on the wire');
            $decoded = json_decode($sent[0]['message'], true);
            $this->assertIsArray($decoded);
            return $decoded;
        }

        /** Assert the single client reply is ok:false with $code. */
        private function assertErrorReply(string $code): array
        {
            $reply = $this->singleReply();
            $this->assertFalse($reply['ok'], "reply must be ok:false for {$code}");
            $this->assertSame($code, $reply['error']['code']);
            return $reply;
        }

        /** Assert the single reply is a well-formed ok:true v1 reply echoing the req id. */
        private function assertOkReply(string $reqId = 'req-1'): array
        {
            $reply = $this->singleReply();
            $this->assertTrue($reply['ok'], 'reply must be ok:true');
            $this->assertSame(1, $reply['v']);
            $this->assertSame($reqId, $reply['re']);
            return $reply;
        }

        // ================================================================
        // 1. admin.hosts
        // ================================================================

        public function testAdminHostsHappyPathFrozenShape(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-7');

            // A vps host session missing type/ip (falls back to $global->hosts),
            // a bot session (real ima "bot", stays in hosts), an admin session
            // (goes to admins), and a session with NO uid (skipped entirely).
            $this->seedSessions([
                101 => ['uid' => 'vps1234', 'ima' => 'host', 'name' => 'edge-a', 'module' => 'vps', 'online' => '2026-07-06 10:00:00'],
                102 => ['uid' => 'bot9', 'ima' => 'bot', 'name' => 'the-bot', 'type' => 5, 'ip' => '10.0.0.9', 'online' => '2026-07-06 10:01:00'],
                103 => ['uid' => 'admin-7', 'ima' => 'admin', 'name' => 'Root', 'img' => 'root.png', 'online' => '2026-07-06 10:02:00'],
                104 => ['ima' => 'host', 'name' => 'no-uid-session'] // no uid -> skipped
            ]);
            // Registry fallback for vps1234's missing type/ip.
            $this->global->store['hosts'] = [
                1234 => ['vps_name' => 'reg-name', 'vps_type' => 11, 'vps_ip' => '192.168.1.34']
            ];

            $this->dispatch('admin.hosts');

            $reply = $this->assertOkReply();
            $data = $reply['data'];
            $this->assertArrayHasKey('hosts', $data);
            $this->assertArrayHasKey('admins', $data);

            // hosts: exactly the vps + bot sessions (admin & uid-less excluded).
            $this->assertCount(2, $data['hosts']);
            $byId = [];
            foreach ($data['hosts'] as $h) {
                $byId[$h['id']] = $h;
            }
            $this->assertArrayHasKey('vps1234', $byId);
            $this->assertArrayHasKey('bot9', $byId);

            // Frozen host field set: id/host_id/name/ima/type/ip/online/module.
            $vps = $byId['vps1234'];
            $this->assertSame(
                ['id', 'host_id', 'name', 'ima', 'type', 'ip', 'online', 'module'],
                array_keys($vps),
                'host entry keys must match the frozen §2.9 order/shape'
            );
            $this->assertSame('vps1234', $vps['id']);
            $this->assertSame(1234, $vps['host_id'], 'host_id parsed from the hub-bound uid, not client data');
            // Session had a name; type/ip fell back to the registry row.
            $this->assertSame('edge-a', $vps['name']);
            $this->assertSame('host', $vps['ima']);
            $this->assertSame(11, $vps['type'], 'type falls back to registry vps_type');
            $this->assertSame('192.168.1.34', $vps['ip'], 'ip falls back to registry vps_ip');
            $this->assertSame('2026-07-06 10:00:00', $vps['online']);
            $this->assertSame('vps', $vps['module']);

            // Bot appears in hosts with its real ima (session type/ip present).
            $bot = $byId['bot9'];
            $this->assertSame('bot', $bot['ima']);
            $this->assertSame(9, $bot['host_id']);
            $this->assertSame(5, $bot['type']);
            $this->assertSame('10.0.0.9', $bot['ip']);

            // admins: exactly the one admin session, frozen field set.
            $this->assertCount(1, $data['admins']);
            $admin = $data['admins'][0];
            $this->assertSame(['id', 'name', 'ima', 'img', 'online'], array_keys($admin));
            $this->assertSame('admin-7', $admin['id']);
            $this->assertSame('Root', $admin['name']);
            $this->assertSame('admin', $admin['ima']);
            $this->assertSame('root.png', $admin['img']);
            $this->assertSame('2026-07-06 10:02:00', $admin['online']);

            $this->assertSame([], $this->closed());
        }

        public function testAdminHostsEmptyWhenNoSessions(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->seedSessions([]);

            $this->dispatch('admin.hosts');

            $reply = $this->assertOkReply();
            $this->assertSame([], $reply['data']['hosts']);
            $this->assertSame([], $reply['data']['admins']);
        }

        public function testAdminHostsNonAdminHostForbidden(): void
        {
            $this->flagAOn();
            $this->asHost('vps5');
            $this->seedSessions([1 => ['uid' => 'vps1234', 'ima' => 'host']]);

            $this->dispatch('admin.hosts');

            $this->assertErrorReply('forbidden');
        }

        public function testAdminHostsMissingImaForbidden(): void
        {
            // Authed but no ima at all (defensive: role check reads $_SESSION['ima']).
            $this->flagAOn();
            $_SESSION = ['v1_authed' => true, 'uid' => 'x'];

            $this->dispatch('admin.hosts');

            $this->assertErrorReply('forbidden');
        }

        public function testAdminHostsUnauthedRepliesAuthRequiredAndCloses(): void
        {
            $this->flagAOn();
            $_SESSION = []; // NOT v1-authed

            $this->dispatch('admin.hosts', [], 33);

            $reply = $this->singleReply();
            $this->assertFalse($reply['ok']);
            $this->assertSame('auth_required', $reply['error']['code']);
            $this->assertContains(33, $this->closed(), 'unauthed admin op must close the connection');
        }

        public function testAdminHostsDormantWhenFlagAOff(): void
        {
            $this->flagAOff();
            $this->asAdmin();
            $this->seedSessions([1 => ['uid' => 'vps1234', 'ima' => 'host']]);

            $this->dispatch('admin.hosts');

            $this->assertCount(0, $this->sent(), 'Flag A OFF: admin.hosts produces no reply');
            $this->assertSame([], $this->closed(), 'Flag A OFF: no side effects');
        }

        public function testAdminHostsIgnoresClientSuppliedData(): void
        {
            // The op takes data:{} per spec; confirm client-supplied fields cannot
            // influence authorization or payload content.
            $this->flagAOn();
            $this->asAdmin('admin-real');
            $this->seedSessions([
                1 => ['uid' => 'vps1', 'ima' => 'host', 'name' => 'real-name', 'type' => 3, 'ip' => '1.1.1.1', 'online' => 'real-online']
            ]);

            // A malicious client tries to spoof identity/role and inject payload.
            $this->dispatch('admin.hosts', [
                'ima' => 'admin',            // must NOT grant authorization
                'hosts' => [['id' => 'evil']], // must NOT appear in the reply
                'host_id' => 999,
                'name' => 'spoofed',
                'type' => 'spoofed',
                'ip' => 'spoofed',
                'admins' => [['id' => 'ghost']]
            ]);

            $reply = $this->assertOkReply();
            // Payload is derived purely from sessions/registry, not from data.
            $this->assertCount(1, $reply['data']['hosts']);
            $h = $reply['data']['hosts'][0];
            $this->assertSame('vps1', $h['id']);
            $this->assertSame('real-name', $h['name'], 'client-supplied name ignored');
            $this->assertSame(3, $h['type'], 'client-supplied type ignored');
            $this->assertSame('1.1.1.1', $h['ip'], 'client-supplied ip ignored');
            $this->assertSame([], $reply['data']['admins'], 'client-supplied admins ignored');
        }

        // ================================================================
        // 2. admin.timers
        // ================================================================

        public function testAdminTimersHappyPath(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            // Registry as onWorkerStart writes it: name => {interval, timer_id}.
            // Plus one entry carrying an optional last_run, and one legacy scalar.
            $this->global->store['timers'] = [
                'processing_queue_timer' => ['interval' => 30, 'timer_id' => 11],
                'map_queue_timer' => ['interval' => 60, 'timer_id' => 22, 'last_run' => 1719000000],
                'legacy_scalar_timer' => 7 // bare Timer::add() id -> normalized
            ];

            $this->dispatch('admin.timers');

            $reply = $this->assertOkReply();
            $timers = $reply['data']['timers'];
            $this->assertIsArray($timers);
            $this->assertArrayHasKey('processing_queue_timer', $timers);

            $this->assertSame(
                ['interval' => 30, 'timer_id' => 11],
                $timers['processing_queue_timer'],
                'no last_run emitted when absent'
            );
            $this->assertSame(
                ['interval' => 60, 'timer_id' => 22, 'last_run' => 1719000000],
                $timers['map_queue_timer'],
                'last_run emitted only when the registry entry carries it'
            );
            $this->assertSame(
                ['interval' => 0, 'timer_id' => 7],
                $timers['legacy_scalar_timer'],
                'bare scalar id normalized to {interval:0, timer_id}'
            );
        }

        public function testAdminTimersEmptyIsObjectNotArray(): void
        {
            // Implementer claim: absent/empty registry replies {} (empty stdClass),
            // NOT []. Verify against the raw wire bytes (json_decode(assoc) would
            // hide the distinction, so inspect the encoded string directly).
            $this->flagAOn();
            $this->asAdmin();
            // No $global->timers seeded at all (registry absent).

            $this->dispatch('admin.timers');

            $reply = $this->assertOkReply();
            $this->assertSame([], $reply['data']['timers'], 'assoc-decode of {} is an empty array');

            // The load-bearing check: on the wire it must be an OBJECT, not [].
            $raw = $this->sent()[0]['message'];
            $this->assertStringContainsString('"timers":{}', $raw, 'empty timers must encode as {} object, not []');
            $this->assertStringNotContainsString('"timers":[]', $raw);
        }

        public function testAdminTimersNonArrayRegistryTreatedEmpty(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->global->store['timers'] = 'not-an-array';

            $this->dispatch('admin.timers');

            $reply = $this->assertOkReply();
            $this->assertSame([], $reply['data']['timers']);
            $this->assertStringContainsString('"timers":{}', $this->sent()[0]['message']);
        }

        public function testAdminTimersNonAdminForbidden(): void
        {
            $this->flagAOn();
            $this->asHost('vps5');
            $this->global->store['timers'] = ['x' => ['interval' => 1, 'timer_id' => 1]];

            $this->dispatch('admin.timers');

            $this->assertErrorReply('forbidden');
        }

        public function testAdminTimersDormantWhenFlagAOff(): void
        {
            $this->flagAOff();
            $this->asAdmin();
            $this->global->store['timers'] = ['x' => ['interval' => 1, 'timer_id' => 1]];

            $this->dispatch('admin.timers');

            $this->assertCount(0, $this->sent(), 'Flag A OFF: admin.timers produces no reply');
        }

        // ================================================================
        // 3. admin.running
        // ================================================================

        public function testAdminRunningHappyPathMixedEntries(): void
        {
            $this->flagAOn();
            $this->asAdmin();

            // v1-style entry: uuid run_id, `started` present.
            $v1Entry = [
                'run_id' => 'run-uuid-1', 'id' => 'run-uuid-1', 'host' => 'vps1234',
                'command' => 'uptime', 'interact' => false, 'update_after' => false,
                'for' => 'admin-7', 'rows' => 24, 'cols' => 80, 'started' => 1719700123, 'v' => 1
            ];
            // legacy-style entry: md5 key, NO run_id, NO started, has legacy `type`.
            $legacyKey = md5('service httpd restart');
            $legacyEntry = [
                'type' => 'run', 'command' => 'service httpd restart', 'id' => $legacyKey,
                'interact' => true, 'update_after' => true, 'host' => 'vps5',
                'rows' => 40, 'cols' => 100, 'for' => 'admin-legacy'
                // no 'run_id', no 'started'
            ];
            $seeded = ['run-uuid-1' => $v1Entry, $legacyKey => $legacyEntry];
            $this->global->store['running'] = $seeded;

            $this->dispatch('admin.running');

            $reply = $this->assertOkReply();
            $running = $reply['data']['running'];
            $this->assertIsArray($running);
            $this->assertCount(2, $running);

            $byRunId = [];
            foreach ($running as $r) {
                // Frozen §2.9 record shape — `type` must be dropped.
                $this->assertSame(
                    ['run_id', 'host', 'command', 'interact', 'update_after', 'for', 'rows', 'cols', 'started'],
                    array_keys($r),
                    'running entry keys must match the frozen §2.9 record (no legacy type)'
                );
                $this->assertArrayNotHasKey('type', $r, 'legacy `type` must be dropped from output');
                $byRunId[$r['run_id']] = $r;
            }

            // v1 entry preserved verbatim, started present.
            $v1 = $byRunId['run-uuid-1'];
            $this->assertSame('vps1234', $v1['host']);
            $this->assertSame('uptime', $v1['command']);
            $this->assertFalse($v1['interact']);
            $this->assertFalse($v1['update_after']);
            $this->assertSame('admin-7', $v1['for']);
            $this->assertSame(24, $v1['rows']);
            $this->assertSame(80, $v1['cols']);
            $this->assertSame(1719700123, $v1['started']);

            // legacy entry: run_id falls back to legacy `id`, started -> 0.
            $legacy = $byRunId[$legacyKey];
            $this->assertSame($legacyKey, $legacy['run_id'], 'legacy run_id falls back to `id`/registry key');
            $this->assertSame('vps5', $legacy['host']);
            $this->assertSame('service httpd restart', $legacy['command']);
            $this->assertTrue($legacy['interact']);
            $this->assertTrue($legacy['update_after']);
            $this->assertSame('admin-legacy', $legacy['for']);
            $this->assertSame(40, $legacy['rows']);
            $this->assertSame(100, $legacy['cols']);
            $this->assertSame(0, $legacy['started'], 'legacy entry lacking started reports started:0');

            // Read-only: registry byte-identical before/after.
            $this->assertSame($seeded, $this->global->store['running'], 'admin.running must not mutate the registry');
        }

        public function testAdminRunningLegacyRunIdFromRegistryKeyWhenNoIdField(): void
        {
            // Legacy entry with neither run_id nor a string id -> falls back to key.
            $this->flagAOn();
            $this->asAdmin();
            $this->global->store['running'] = [
                'md5key-xyz' => ['command' => 'ls', 'host' => 'vps1'] // no run_id, no id
            ];

            $this->dispatch('admin.running');

            $reply = $this->assertOkReply();
            $this->assertCount(1, $reply['data']['running']);
            $this->assertSame('md5key-xyz', $reply['data']['running'][0]['run_id']);
            $this->assertSame(0, $reply['data']['running'][0]['started']);
        }

        public function testAdminRunningEmptyRegistry(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->global->store['running'] = [];

            $this->dispatch('admin.running');

            $reply = $this->assertOkReply();
            $this->assertSame([], $reply['data']['running'], 'nothing in flight -> empty array');
        }

        public function testAdminRunningSkipsNonArrayEntries(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->global->store['running'] = [
                'ok' => ['run_id' => 'ok', 'host' => 'vps1', 'command' => 'x', 'started' => 5],
                'junk' => 'not-an-array'
            ];

            $this->dispatch('admin.running');

            $reply = $this->assertOkReply();
            $this->assertCount(1, $reply['data']['running']);
            $this->assertSame('ok', $reply['data']['running'][0]['run_id']);
        }

        public function testAdminRunningNonAdminForbidden(): void
        {
            $this->flagAOn();
            $this->asHost('vps5');
            $this->global->store['running'] = ['r' => ['run_id' => 'r', 'host' => 'vps1', 'command' => 'x']];

            $this->dispatch('admin.running');

            $this->assertErrorReply('forbidden');
        }

        public function testAdminRunningDormantWhenFlagAOff(): void
        {
            $this->flagAOff();
            $this->asAdmin();
            $this->global->store['running'] = ['r' => ['run_id' => 'r', 'host' => 'vps1', 'command' => 'x']];

            $this->dispatch('admin.running');

            $this->assertCount(0, $this->sent(), 'Flag A OFF: admin.running produces no reply');
        }

        public function testAdminRunningIgnoresClientSuppliedData(): void
        {
            // Confirm the op does not read `data` at all: injecting a `running`
            // array (or role) via data cannot change the reply.
            $this->flagAOn();
            $this->asAdmin();
            $this->global->store['running'] = [
                'real' => ['run_id' => 'real', 'host' => 'vps1', 'command' => 'true', 'started' => 9]
            ];

            $this->dispatch('admin.running', [
                'ima' => 'host', // must not downgrade authorization
                'running' => [['run_id' => 'ghost', 'host' => 'evil']]
            ]);

            $reply = $this->assertOkReply();
            $this->assertCount(1, $reply['data']['running']);
            $this->assertSame('real', $reply['data']['running'][0]['run_id'], 'reply derived from registry, not client data');
        }
    }
}
