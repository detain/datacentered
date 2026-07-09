<?php

use MyAdmin\App;
use Workerman\Worker;

/**
 * queue_action — TaskWorker executor for the v1 `queue.*` parity bridge
 * (docs/PROTOCOL_V1.md §2.4; plan step 2.5, B4 dual-transport parity).
 *
 * Receives, from Events::handleQueueAction()/handleQueuePull()/handleQueueProvision()
 * (dispatched via Events::dispatchTask('queue_action', ...)):
 *
 *   {
 *     module:  "vps" | "quickservers",   // validated hub-side against the authed session
 *     action:  str,                       // snake_case ServiceQueueHandler action (get_queue, lock, ...)
 *     args:    obj,                       // the fields the ResponseHandler reads from $_REQUEST (§2.4 table)
 *     host_id: int,                       // FROM THE AUTHED SESSION uid — never client-supplied
 *     uid:     str                        // authed session uid ("vps<id>"/"qs<id>"), logging only
 *   }
 *
 * It re-resolves the master row itself from the authenticated host_id (the same
 * `<prefix>_masters LEFT JOIN <prefix>_master_details` row mystage
 * public_html/queue.php and Web/queue.php build — theirs keyed by REMOTE_ADDR,
 * ours by the token-authenticated host_id) and invokes the IDENTICAL reusable
 * callable both HTTP paths use: vps_queue_handler()/qs_queue_handler()
 * (→ MyAdmin\Services\ServiceQueueHandler::render() → ResponseHandlers/*).
 * NO queue logic is copied or reimplemented here — response payloads stay
 * byte-identical to the HTTP transport for the same action+args (⛔ invariant:
 * the legacy queue paths are untouched; this only ADDS a WS entrypoint into
 * the unchanged callable).
 *
 * Execution context mirrors mystage public_html/queue.php exactly
 * (sessionid / account_id 160308 / appnocache ima / App::tf()->ima all
 * 'services'), then restores the task-pool convention state (account_id
 * 160307, empty accounts data) in `finally`, like boardctl_task /
 * processing_queue_task.
 *
 * $_REQUEST/$_POST SHIM (CAVEAT 1): several ResponseHandlers read
 * $_REQUEST directly (e.g. Lock.php's `$_REQUEST['id']`; the §2.4 per-action
 * args table was frozen FROM those reads). The HTTP transport populates them
 * from the request; over WS we inject `args` into $_REQUEST/$_POST for the
 * duration of the handler call. Because the TaskWorker is a LONG-LIVED
 * Workerman process where superglobals persist across dispatches, the
 * previous values are saved before injection and restored in `finally` so no
 * state ever leaks between dispatches (or into the legacy tasks sharing this
 * pool).
 *
 * @param array $args see shape above
 * @return string JSON: {"ok":true,"result":"<raw render() output, unmodified>"}
 *                   or {"ok":false,"error":"<message>"} (wrapped by the
 *                   TaskWorker into {"return":<this string>})
 */
function queue_action($args)
{
    require_once '/home/my/include/functions.inc.php';
    $module = isset($args['module']) && $args['module'] === 'quickservers' ? 'quickservers' : 'vps';
    $prefix = $module === 'quickservers' ? 'qs' : 'vps';
    $action = isset($args['action']) && is_string($args['action']) ? trim($args['action']) : '';
    // snake_case exactly as HTTP (§2.4); also keeps ServiceQueueHandler's
    // camelCase class lookup from being fed anything class-name-hostile.
    if ($action === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $action)) {
        return json_encode(['ok' => false, 'error' => 'invalid or missing action']);
    }
    $host_id = isset($args['host_id']) ? intval($args['host_id']) : 0;
    if ($host_id <= 0) {
        return json_encode(['ok' => false, 'error' => 'invalid or missing host_id']);
    }
    $wsArgs = isset($args['args']) && is_array($args['args']) ? $args['args'] : [];
    try {
        App::db()->haltOnError = 'report';
        // Resolve the master row from the AUTHENTICATED host_id (never a
        // client-supplied identity) — the same row Web/queue.php and mystage
        // queue.php select (they key on the source IP; the WS bridge keys on
        // the token-authed vps_id/qs_id the hub passed from the session).
        $db = App::db();
        $db->query("select * from {$prefix}_masters left join {$prefix}_master_details using ({$prefix}_id) where {$prefix}_id=".$host_id, __LINE__, __FILE__);
        if ($db->num_rows() == 0) {
            return json_encode(['ok' => false, 'error' => "no {$module} master row for host_id {$host_id}"]);
        }
        $db->next_record(MYSQL_ASSOC);
        $serverInfo = $db->Record;
    } catch (\Throwable $e) {
        Worker::safeEcho('queue_action master-row lookup exception '.$e->getCode().': '.$e->getMessage()."\n");
        return json_encode(['ok' => false, 'error' => 'master row lookup failed: '.$e->getMessage()]);
    }
    // Save the persistent superglobals BEFORE any mutation; restored in finally.
    $savedRequest = $_REQUEST;
    $savedPost = $_POST;
    try {
        // Same execution context mystage public_html/queue.php establishes
        // before calling the queue handler (mirrored line for line).
        App::session()->sessionid = md5(time() . _randomstring(20));
        App::session()->account_id = 160308;
        App::session()->appnocache('ima', 'services');
        App::tf()->ima = 'services';
        // Inject the WS args as the ONLY request fields visible to the
        // handler — same field names the ResponseHandlers read from
        // $_REQUEST today (§2.4 per-action args table), no stale carryover.
        $_REQUEST = $wsArgs;
        $_POST = $wsArgs;
        function_requirements($prefix.'_queue_handler');
        // The IDENTICAL callable HTTP uses; $queueData=false exactly like
        // mystage queue.php ("echo <prefix>_queue_handler($db->Record, $_REQUEST['action'])").
        $output = call_user_func($prefix.'_queue_handler', $serverInfo, $action);
        // Raw render() output UNMODIFIED (bash script text / JSON string /
        // empty). null/false render returns echo as '' over HTTP, so '' here
        // keeps the WS reply byte-identical to the HTTP body.
        return json_encode(['ok' => true, 'result' => is_string($output) ? $output : '']);
    } catch (\Throwable $e) {
        Worker::safeEcho('queue_action exception '.$e->getCode().': '.$e->getMessage()."\n");
        return json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } finally {
        // Restore superglobals so nothing leaks across dispatches in this
        // long-lived process (CAVEAT 1), then the task-pool session convention.
        $_REQUEST = $savedRequest;
        $_POST = $savedPost;
        try {
            App::session()->account_id = 160307;
            App::accounts()->data = [];
        } catch (\Throwable $e) {
            // context restore must never mask the real result/error
        }
    }
}
