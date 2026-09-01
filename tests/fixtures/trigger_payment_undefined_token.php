<?php

/**
 * Subprocess harness for TriggerPaymentEndpointTest::testFailsClosedWhenTokenConstantUndefined.
 *
 * PHP define() is permanent, so the truly-*undefined* WS_TRIGGER_TOKEN branch of
 * Web/trigger_payment.php cannot be reached inside the main PHPUnit process once
 * any test has defined the constant. This tiny script runs the endpoint in a
 * clean child where WS_TRIGGER_TOKEN is guaranteed never defined, presenting an
 * empty token, and echoes the endpoint's JSON body. The parent test asserts the
 * endpoint fails closed (unauthorized) — i.e. it is never open by default.
 *
 * The child also injects an ISOLATED empty SharedState Redis double (GlobalData→
 * Redis migration, A1 — the endpoint no longer touches GlobalData at all) and
 * reports back its final keyspace, so the parent can prove the fail-closed path
 * nudged nothing: no processing_queue lock acquired, no key written.
 *
 * It loads the same bootstrap the suite uses (composer autoload + V1TestSupport
 * for the fake Gateway seam, TestBootstrap's InMemoryRedis and the Worker
 *::$outputStream redirect), then includes the real endpoint. WS_TRIGGER_TOKEN is
 * deliberately NOT defined here. REMOTE_ADDR is loopback so the shipped
 * loopback-only IP allowlist is PASSED and the request is refused specifically
 * for the *undefined token*, not for its address.
 */

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../V1TestSupport.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // pass the loopback allowlist; fail on the token
$_POST = ['token' => '']; // present an empty token; undefined constant must still refuse

// Isolated, empty Redis keyspace for the child process (duck-typed SharedState
// double; never a real socket). SharedState::reset() first drops any facade memo
// so the double is the sole resolved client for this run.
SharedState::reset();
$childRedis = new InMemoryRedis();
SharedState::setClient($childRedis);

// Sanity guard: if some future bootstrap ever defines WS_TRIGGER_TOKEN, this
// harness would no longer test the undefined branch — fail loudly instead of
// silently passing.
if (defined('WS_TRIGGER_TOKEN')) {
    echo json_encode(['status' => 'error', 'error' => 'harness_invalid_token_defined']);
    return;
}

ob_start();
include __DIR__.'/../../Web/trigger_payment.php';
$body = ob_get_clean();

$decoded = json_decode($body, true);
if (!is_array($decoded)) {
    // Preserve the endpoint's raw output so the parent's json assertion still
    // surfaces it verbatim on a malformed-body regression.
    echo $body;
    return;
}

// Merge the side-effect diagnostics the parent asserts on.
$decoded['lock_present'] = SharedState::exists('dc:lock:processing_queue');
$decoded['all_keys'] = $childRedis->allKeys();
echo json_encode($decoded);
