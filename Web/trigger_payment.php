<?php

/**
 * POST /trigger_payment.php — payment-queue nudge endpoint (plan step 2.9).
 *
 * Purpose: lets an authenticated caller (mystage's queue_process_payment in
 * plan step 4.3, replacing the legacy WS `paymentprocess` message) nudge the
 * existing payment-queue processing to run NOW instead of waiting for the
 * next 30s `processing_queue_timer` tick. This file contains NO payment
 * business logic and never touches `queue_log`/billing tables itself.
 *
 * Nudge mechanism: calls Events::processing_queue_timer() — the same method
 * the registered 30s timer (Events.php onWorkerStart) and the legacy
 * msgPaymentprocess() handler invoke. That method is cross-process safe by
 * design: it takes the SharedState (Redis) lock `processing_queue` before
 * reading the queue and dispatches each row to the dedicated payment
 * TaskWorker pool (Events::PAYMENT_TASK_ADDRESS, Text://127.0.0.1:2209) via
 * Events::dispatchTask('processing_queue_task', $row, ...). If the timer is
 * already mid-run the lock acquire simply loses and this nudge is a harmless
 * no-op.
 *
 * Routing: start_web.php's WebServer maps URL paths to files under Web/ by
 * filename (Web/queue.php -> /queue.php), so the plan's logical
 * "POST /trigger/payment" lands here as /trigger_payment.php (per the plan
 * E3 file-touch map: "new Web/trigger_*.php").
 *
 * Auth: shared-secret token supplied as a POST field named `token`, compared
 * with hash_equals() against the WS_TRIGGER_TOKEN constant defined in
 * /home/my/include/config/config.settings.php (already loaded by
 * start_web.php's onWorkerStart). Consistent with docs/AUTH_DESIGN.md's
 * constant-time token-compare rule (§4, item 3). If WS_TRIGGER_TOKEN is not
 * defined (or empty) the endpoint refuses every request — it is never open.
 * Reading the token only from $_POST is a deliberate hardening convention
 * that makes this a POST-only endpoint: requests that don't present the
 * token as a POST field (e.g. a conventional GET) always fail auth.
 *
 * Dormancy (plan B8 ship-dormant rule): gated behind Flag A via
 * FeatureFlags::useNewHandling(). With Flag A OFF a valid authenticated
 * request gets {"status":"error","error":"disabled"} and nothing is nudged.
 * Flag A is ON by default over reachable Redis (an absent flag reads as 1 —
 * see FeatureFlags.php:25-41); it is OFF only when an operator explicitly
 * sets it to 0 or Redis is unreachable (the fail-safe state), so this
 * endpoint is live unless an operator deliberately takes it dormant.
 *
 * Response: JSON body (the WebServer sends included-file output as a plain
 * 200 response, so success/failure is conveyed in the body's `status`
 * field, matching house style where endpoints communicate via body only).
 *   success: {"status":"ok","nudged":"processing_queue_timer","ts":<unix>}
 *   failure: {"status":"error","error":"<unauthorized|disabled|unavailable>"}
 *
 * Tests: tests/TriggerPaymentEndpointTest.php exercises this file as a black
 * box via include() under controlled superglobals/constants; the genuinely-
 * undefined-WS_TRIGGER_TOKEN branch is covered by the subprocess fixture
 * tests/fixtures/trigger_payment_undefined_token.php. Operator provisioning of
 * WS_TRIGGER_TOKEN is documented in docs/AUTH_DESIGN.md §10; the step write-up
 * is docs/PROTOCOL_V1.md §6 (Phase 2, step 2.9).
 */

use Workerman\Worker;

$trigger_payment_respond = function ($payload) {
    echo json_encode($payload);
};

try {
    // --- IP allowlist: local-only -------------------------------------------
    $addr = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($addr, ['127.0.0.1', '::1'], true)) {
        $trigger_payment_respond(['status' => 'error', 'error' => 'unauthorized']);
        return;
    }
    // --- auth: shared-secret token, constant-time compare -------------------
    $configuredToken = defined('WS_TRIGGER_TOKEN') ? (string) constant('WS_TRIGGER_TOKEN') : '';
    $presentedToken = isset($_POST['token']) ? (string) $_POST['token'] : '';
    if ($configuredToken === '' || $presentedToken === '' || !hash_equals($configuredToken, $presentedToken)) {
        $trigger_payment_respond(['status' => 'error', 'error' => 'unauthorized']);
        return;
    }
    // --- Flag A gate (B8): unset default is ON since 9eabb50; live unless explicitly OFF or Redis unreachable (fail-safe) --
    if (!class_exists('FeatureFlags', false)) {
        require_once __DIR__.'/../Applications/Chat/FeatureFlags.php';
    }
    if (!FeatureFlags::useNewHandling()) {
        $trigger_payment_respond(['status' => 'error', 'error' => 'disabled']);
        return;
    }
    // --- nudge: run the existing payment-queue timer callback now -----------
    if (!class_exists('Events', false)) {
        require_once __DIR__.'/../Applications/Chat/Events.php';
    }
    // GlobalData→Redis migration (A1): the timer's `processing_queue` lock is a
    // SharedState lock resolved through the $GLOBALS['redis'] the WebServer
    // already initializes in its onWorkerStart — no GlobalData client is needed
    // here anymore. USE_REDIS is therefore operationally REQUIRED for this
    // endpoint to ever win the lock (with Redis off, SharedState fails safe and
    // the nudge is a no-op until the next BusinessWorker tick takes it).
    Worker::safeEcho('trigger_payment: nudging processing_queue_timer for '.$_SERVER['REMOTE_ADDR']."\n");
    Events::processing_queue_timer();
    $trigger_payment_respond(['status' => 'ok', 'nudged' => 'processing_queue_timer', 'ts' => time()]);
} catch (\Throwable $e) {
    Worker::safeEcho('trigger_payment: Caught Exception #'.$e->getCode().':'.$e->getMessage().' on '.__LINE__.'@'.__FILE__."\n");
    $trigger_payment_respond(['status' => 'error', 'error' => 'unavailable']);
}
