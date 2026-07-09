<?php

/**
 * Shared test support for the protocol-v1 Events tests (WS-revamp Phase 2).
 *
 * Declares the fake \GatewayWorker\Lib\Gateway seam ONCE, before Events.php is
 * loaded, so the composer autoloader never pulls the real gateway transport
 * (which reaches into a running gateway process and cannot run under PHPUnit).
 * Every dispatchV1/auth reply, close, session write, uid bind and group join is
 * captured in the static arrays below for assertion.
 *
 * Both EventsV1RouterTest.php and EventsV1AuthHelloTest.php require this file
 * first (require_once — idempotent regardless of which test file PHPUnit loads
 * first), then require FeatureFlags.php + Events.php. Keeping the stub in one
 * place avoids a duplicate-class fatal when PHPUnit loads every test file into
 * the same process.
 */

namespace GatewayWorker\Lib {
    class Gateway
    {
        /** @var array<int,array{client_id:mixed,message:string}> */
        public static $sent = [];

        /** @var array<int,mixed> client_ids passed to closeClient() */
        public static $closed = [];

        /** @var array<int,array{client_id:mixed,session:mixed}> */
        public static $sessions = [];

        /** @var array<int,array{client_id:mixed,uid:mixed}> */
        public static $bound = [];

        /** @var array<int,array{client_id:mixed,group:mixed}> */
        public static $joined = [];

        /** @var array<int,array{uid:mixed,message:string}> messages sent via sendToUid() */
        public static $sentToUid = [];

        /** @var array<int,array{group:mixed,message:string}> messages sent via sendToGroup() */
        public static $sentToGroup = [];

        /** @var array<int,array{client_id:mixed,group:mixed}> client_id/group passed to leaveGroup() */
        public static $left = [];

        /** @var array<int|string,bool> uids the fake considers online (for isUidOnline) */
        public static $onlineUids = [];

        /**
         * Per-group fake membership state for presence/list assertions.
         * $groupSessions[$group] = array<int,array session> — the sessions
         * getClientSessionsByGroup() returns; $groupCounts[$group] = int the
         * connection count getClientIdCountByGroup() returns (defaults to the
         * session array size when not explicitly set).
         *
         * @var array<string,array<int,array>>
         */
        public static $groupSessions = [];

        /** @var array<string,int> */
        public static $groupCounts = [];

        /**
         * Fake all-connection session map for getAllClientSessions() — used by
         * v1 admin.hosts (and legacy msgClients). Keyed by gateway client id,
         * value is that connection's session array.
         *
         * @var array<int|string,array>
         */
        public static $allSessions = [];

        public static function sendToClient($client_id, $message, $raw = false)
        {
            self::$sent[] = ['client_id' => $client_id, 'message' => $message];
            return true;
        }

        public static function sendToUid($uid, $message)
        {
            self::$sentToUid[] = ['uid' => $uid, 'message' => $message];
            return true;
        }

        public static function sendToGroup($group, $message, $exclude_client_id = null, $raw = false)
        {
            self::$sentToGroup[] = ['group' => $group, 'message' => $message];
            return true;
        }

        public static function isUidOnline($uid)
        {
            return !empty(self::$onlineUids[$uid]);
        }

        public static function closeClient($client_id, $message = null)
        {
            self::$closed[] = $client_id;
            return true;
        }

        public static function setSession($client_id, $session)
        {
            self::$sessions[] = ['client_id' => $client_id, 'session' => $session];
            return true;
        }

        public static function bindUid($client_id, $uid)
        {
            self::$bound[] = ['client_id' => $client_id, 'uid' => $uid];
            return true;
        }

        public static function joinGroup($client_id, $group)
        {
            self::$joined[] = ['client_id' => $client_id, 'group' => $group];
            return true;
        }

        public static function leaveGroup($client_id, $group)
        {
            self::$left[] = ['client_id' => $client_id, 'group' => $group];
            return true;
        }

        /** @return array<int,array> sessions of the connections in $group */
        public static function getClientSessionsByGroup($group)
        {
            return self::$groupSessions[$group] ?? [];
        }

        /** @return int connection count for $group */
        public static function getClientIdCountByGroup($group)
        {
            if (isset(self::$groupCounts[$group])) {
                return self::$groupCounts[$group];
            }
            return isset(self::$groupSessions[$group]) ? count(self::$groupSessions[$group]) : 0;
        }

        /** @return array<int|string,array> every connection's session (client_id => session) */
        public static function getAllClientSessions($group_id = '')
        {
            return self::$allSessions;
        }

        /** Reset every capture array (call in test setUp/tearDown). */
        public static function reset()
        {
            self::$sent = [];
            self::$closed = [];
            self::$sessions = [];
            self::$bound = [];
            self::$joined = [];
            self::$left = [];
            self::$sentToUid = [];
            self::$sentToGroup = [];
            self::$onlineUids = [];
            self::$groupSessions = [];
            self::$groupCounts = [];
            self::$allSessions = [];
        }
    }
}

namespace {
    require_once __DIR__.'/../Applications/Chat/FeatureFlags.php';
    // Requiring Events.php now (fake Gateway already declared above) wires the
    // class against our capture stub instead of the real gateway transport.
    require_once __DIR__.'/../Applications/Chat/Events.php';

    // Events' auth success/ALERT paths call Worker::safeEcho(), which writes to
    // Worker::$outputStream. Outside a running Workerman process that stream is
    // null, so feof(null) throws a TypeError (and leaves a dangling error
    // handler). Point it at /dev/null so logging is a harmless no-op in tests.
    if (!is_resource(\Workerman\Worker::$outputStream ?? null)) {
        \Workerman\Worker::$outputStream = fopen('/dev/null', 'w');
    }
}
