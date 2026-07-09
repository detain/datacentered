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
 * It loads the same bootstrap the suite uses (composer autoload + V1TestSupport
 * for the fake Gateway seam and Worker::$outputStream redirect), then includes
 * the real endpoint. WS_TRIGGER_TOKEN is deliberately NOT defined here.
 */

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../V1TestSupport.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_POST = ['token' => '']; // present an empty token; undefined constant must still refuse
$GLOBALS['global'] = null;

// Sanity guard: if some future bootstrap ever defines WS_TRIGGER_TOKEN, this
// harness would no longer test the undefined branch — fail loudly instead of
// silently passing.
if (defined('WS_TRIGGER_TOKEN')) {
    echo json_encode(['status' => 'error', 'error' => 'harness_invalid_token_defined']);
    return;
}

ob_start();
include __DIR__.'/../../Web/trigger_payment.php';
echo ob_get_clean();
