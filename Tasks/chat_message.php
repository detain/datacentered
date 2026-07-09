<?php

use Workerman\Worker;

/**
 * chat_message — TaskWorker writer for v1 chat persistence
 * (docs/PROTOCOL_V1.md §2.10 + §4, OQ5 decision; plan step 2.7).
 *
 * Receives, from Events::handleChannelPublish()/handleChatSend()
 * (dispatched via Events::dispatchTask('chat_message', ...)):
 *
 *   {
 *     channel: str,   // 'type:name' id, e.g. 'chat:noc', 'host:vps12', 'dm:a:b'
 *     from:    str,   // sender uid FROM THE AUTHED SESSION — never client-supplied
 *     body:    str,   // RAW message text (NOT pre-escaped HTML — §2.10 diff note)
 *     level:   str,   // 'chat' | 'log' | 'info' | 'warn' | 'error' (validated hub-side)
 *     ts:      int    // unix seconds
 *   }
 *
 * Inserts one row into chat_messages (migrations/2026_07_phase2_chat_messages.sql)
 * and returns the AUTO_INCREMENT id so the hub can stamp §2.10's required
 * `msg_id` onto the fanned-out channel.message/chat.message event and the
 * bounded hot-cache entry. Runs in the TaskWorker so the BusinessWorker event
 * loop never blocks on a DB write (same "Events.php stays thin, TaskWorker
 * does DB work" pattern as queue_action/boardctl_task).
 *
 * DB access uses the shared $worker_db global (Workerman MySQL connection,
 * auto-reconnects on 2006/2013) like bandwidth.php/map_queue_task.php.
 * The insert()->query() call returns PDO::lastInsertId() on success
 * (\Workerman\MySQL\Connection::query()'s insert branch).
 *
 * Only reachable via the Flag-A-gated v1 path — with WS_NEW_HANDLING off
 * nothing ever dispatches this task, so deploying it is a runtime no-op.
 *
 * @param array $args see shape above
 * @return string JSON: {"ok":true,"msg_id":<int>} or {"ok":false,"error":"<message>"}
 *                (wrapped by the TaskWorker into {"return":<this string>})
 */
function chat_message($args)
{
    global $worker_db;
    $channel = isset($args['channel']) && is_string($args['channel']) ? $args['channel'] : '';
    $from = isset($args['from']) && is_string($args['from']) ? $args['from'] : '';
    $body = isset($args['body']) && is_string($args['body']) ? $args['body'] : '';
    $level = isset($args['level']) && is_string($args['level']) && $args['level'] !== '' ? $args['level'] : 'chat';
    $ts = isset($args['ts']) && is_numeric($args['ts']) ? intval($args['ts']) : time();
    if ($channel === '' || $from === '' || $body === '') {
        return json_encode(['ok' => false, 'error' => 'chat_message requires channel, from and body']);
    }
    try {
        $msg_id = $worker_db->insert('chat_messages')->cols([
            'channel' => $channel,
            'from' => $from,
            'body' => $body,
            'level' => $level,
            'ts' => $ts
        ])->query();
        if (!is_numeric($msg_id) || intval($msg_id) <= 0) {
            return json_encode(['ok' => false, 'error' => 'chat_messages insert returned no id']);
        }
        return json_encode(['ok' => true, 'msg_id' => intval($msg_id)]);
    } catch (\Throwable $e) {
        Worker::safeEcho('chat_message insert exception '.$e->getCode().': '.$e->getMessage()."\n");
        return json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
