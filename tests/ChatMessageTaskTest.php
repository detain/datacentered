<?php

/**
 * Direct unit test of Tasks/chat_message.php — the TaskWorker writer behind v1
 * chat persistence (docs/PROTOCOL_V1.md §2.10 + §4).
 *
 * Unlike Tasks/queue_action.php, this task is self-contained: it pulls in no
 * mystage runtime, touches only the $worker_db global and Workerman\Worker, so
 * the real function is loaded and called directly with a fake fluent DB.
 *
 * WHY THIS FILE EXISTS: the task's argument validation was the sole reason
 * every admin-published chat message failed to persist in production —
 *   chat_message persist failed for channel chat:noc:
 *   chat_message requires channel, from and body
 * — because `from` arrived as a native PHP int (accounts.account_id via PDO with
 * ATTR_STRINGIFY_FETCHES=false) and the guard was a bare is_string(). The task
 * had no test of its own, and the hub-side fixtures all typed admin uids as
 * strings, so nothing in the suite modelled the real shape.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__.'/TestBootstrap.php';
    require_once __DIR__.'/../Tasks/chat_message.php';

    /** Fluent stand-in for \Workerman\MySQL\Connection's insert()->cols()->query(). */
    if (!class_exists('FakeChatMessageDb')) {
        class FakeChatMessageDb
        {
            /** @var array<int,array{table:string,cols:array}> every insert attempted */
            public $inserts = [];

            /** @var mixed what query() returns (the AUTO_INCREMENT id on success) */
            public $returnId = 1;

            /** @var \Throwable|null when set, query() throws it (e.g. missing table) */
            public $throwOnQuery = null;

            /** @var string */
            private $table = '';

            /** @var array */
            private $cols = [];

            public function insert($table)
            {
                $this->table = $table;
                $this->cols = [];
                return $this;
            }

            public function cols(array $cols)
            {
                $this->cols = $cols;
                return $this;
            }

            public function query()
            {
                $this->inserts[] = ['table' => $this->table, 'cols' => $this->cols];
                if ($this->throwOnQuery !== null) {
                    throw $this->throwOnQuery;
                }
                return $this->returnId;
            }
        }
    }

    class ChatMessageTaskTest extends TestCase
    {
        /** @var FakeChatMessageDb */
        private $db;

        protected function setUp(): void
        {
            $this->db = new FakeChatMessageDb();
            $GLOBALS['worker_db'] = $this->db;
            if (!is_resource(\Workerman\Worker::$outputStream ?? null)) {
                \Workerman\Worker::$outputStream = fopen('/dev/null', 'w');
            }
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['worker_db']);
        }

        /** @return array decoded task result */
        private function runTask(array $args): array
        {
            return json_decode(chat_message($args), true);
        }

        private function validArgs(array $overrides = []): array
        {
            return $overrides + [
                'channel' => 'chat:noc',
                'from' => 'vps12',
                'body' => 'hello',
                'level' => 'chat',
                'ts' => 1719700000,
            ];
        }

        public function testStringFromPersistsAndReturnsTheInsertId(): void
        {
            $this->db->returnId = 77;
            $result = $this->runTask($this->validArgs());

            $this->assertTrue($result['ok']);
            $this->assertSame(77, $result['msg_id']);
            $this->assertCount(1, $this->db->inserts);
            $this->assertSame('chat_messages', $this->db->inserts[0]['table']);
            $this->assertSame('vps12', $this->db->inserts[0]['cols']['from']);
        }

        /**
         * REGRESSION (production): admin/client uids are accounts.account_id, an
         * INT column, and workerman/mysql sets PDO::ATTR_STRINGIFY_FETCHES=false
         * + ATTR_EMULATE_PREPARES=false — so $_SESSION['uid'] is a native int, and
         * json_encode/json_decode across the TaskWorker hop preserves that int.
         * The old is_string() guard rejected it outright, so no admin message was
         * ever written to chat_messages.
         */
        public function testIntFromIsAcceptedAndStoredAsAString(): void
        {
            $this->db->returnId = 78;
            $result = $this->runTask($this->validArgs(['from' => 2773]));

            $this->assertTrue(
                $result['ok'],
                'an int uid must persist: it is what every admin session actually holds'
            );
            $this->assertCount(1, $this->db->inserts);
            $this->assertSame(
                '2773',
                $this->db->inserts[0]['cols']['from'],
                'chat_messages.`from` is VARCHAR(64) — store the string form'
            );
        }

        public function testMissingChannelIsRejectedWithoutTouchingTheDb(): void
        {
            $result = $this->runTask($this->validArgs(['channel' => '']));

            $this->assertFalse($result['ok']);
            $this->assertSame('chat_message requires channel, from and body', $result['error']);
            $this->assertSame([], $this->db->inserts);
        }

        public function testMissingBodyIsRejectedWithoutTouchingTheDb(): void
        {
            $result = $this->runTask($this->validArgs(['body' => '']));

            $this->assertFalse($result['ok']);
            $this->assertSame([], $this->db->inserts);
        }

        /**
         * Non-scalar `from` must STILL be rejected — widening the guard to accept
         * ints must not turn it into "accept anything and let (string) explode".
         */
        public function testArrayFromIsStillRejected(): void
        {
            $result = $this->runTask($this->validArgs(['from' => ['not', 'a', 'uid']]));

            $this->assertFalse($result['ok']);
            $this->assertSame('chat_message requires channel, from and body', $result['error']);
            $this->assertSame([], $this->db->inserts);
        }

        public function testLevelDefaultsToChatAndTsDefaultsToNow(): void
        {
            $args = $this->validArgs();
            unset($args['level'], $args['ts']);
            $before = time();
            $this->runTask($args);

            $cols = $this->db->inserts[0]['cols'];
            $this->assertSame('chat', $cols['level']);
            $this->assertGreaterThanOrEqual($before, $cols['ts']);
        }

        /**
         * The DDL in migrations/2026_07_phase2_chat_messages.sql has to be applied
         * by hand (there is no migration runner). Until it is, every insert throws
         * "Table 'my.chat_messages' doesn't exist" — this pins that the task
         * reports that as a persist failure rather than taking the BusinessWorker
         * down with an uncaught throwable.
         */
        public function testMissingTableIsReportedAsAPersistFailureNotAThrow(): void
        {
            $this->db->throwOnQuery = new \RuntimeException("Table 'my.chat_messages' doesn't exist", 1146);
            $result = $this->runTask($this->validArgs());

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString("chat_messages' doesn't exist", $result['error']);
        }

        public function testNonNumericInsertIdIsReportedAsAFailure(): void
        {
            $this->db->returnId = false;
            $result = $this->runTask($this->validArgs());

            $this->assertFalse($result['ok']);
            $this->assertSame('chat_messages insert returned no id', $result['error']);
        }
    }
}
