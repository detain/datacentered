<?php

/**
 * Direct unit test of the Tasks/queue_action.php superglobal save/restore
 * WRAPPER logic (WS-revamp Phase 2 step 2.5, test #5) in isolation from the
 * mystage runtime.
 *
 * WHY A SHIM AND NOT queue_action() DIRECTLY:
 *   The real Tasks/queue_action.php require's /home/my/include/functions.inc.php
 *   and calls the mystage-only vps_queue_handler()/qs_queue_handler() — neither
 *   is loadable in this datacentered PHPUnit harness (they bootstrap inside the
 *   TaskWorker). So this test re-implements ONLY the save/inject/restore CONTRACT
 *   the task promises (queue_action.php docblock CAVEAT 1: $_REQUEST/$_POST saved
 *   before injection, restored in `finally`, INCLUDING when the handler throws)
 *   and proves that contract byte-for-byte with a fake handler. It asserts:
 *     (a) during the call, $_REQUEST/$_POST are exactly the injected args (the
 *         handler sees no stale carryover from a prior dispatch);
 *     (b) after the call, $_REQUEST/$_POST are byte-identical to before —
 *         even when the handler throws (restore in finally).
 *   This is THE additive-safety property that keeps a long-lived Workerman
 *   TaskWorker from leaking request state between dispatches or into the legacy
 *   tasks sharing the 2208 pool.
 *
 *   The wrapper below mirrors Tasks/queue_action.php lines 86-88 (save),
 *   99-100 (inject), 115-116 (restore) verbatim; keep them in lockstep if the
 *   task's shim changes.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    class QueueActionSuperglobalShimTest extends TestCase
    {
        /**
         * The exact save/inject/restore wrapper Tasks/queue_action.php uses,
         * with the mystage handler call replaced by an injected callable.
         */
        private function runShim(array $wsArgs, callable $handler)
        {
            $savedRequest = $_REQUEST;
            $savedPost = $_POST;
            try {
                $_REQUEST = $wsArgs;
                $_POST = $wsArgs;
                return $handler();
            } finally {
                $_REQUEST = $savedRequest;
                $_POST = $savedPost;
            }
        }

        public function testSuperglobalsInjectedDuringCallAndRestoredAfter(): void
        {
            $_REQUEST = ['pre' => 'existing-request', 'shared' => 'A'];
            $_POST = ['pre_post' => 'existing-post'];
            $before_request = $_REQUEST;
            $before_post = $_POST;

            $wsArgs = ['id' => 55, 'action_field' => 'x'];
            $seenRequest = null;
            $seenPost = null;
            $result = $this->runShim($wsArgs, function () use (&$seenRequest, &$seenPost) {
                // (a) handler sees ONLY the injected args, no stale carryover.
                $seenRequest = $_REQUEST;
                $seenPost = $_POST;
                return 'handler-output';
            });

            $this->assertSame($wsArgs, $seenRequest, '$_REQUEST injected with the ws args during the call');
            $this->assertSame($wsArgs, $seenPost, '$_POST injected with the ws args during the call');
            $this->assertSame('handler-output', $result);
            // (b) restored byte-identically afterwards.
            $this->assertSame($before_request, $_REQUEST, '$_REQUEST restored after dispatch');
            $this->assertSame($before_post, $_POST, '$_POST restored after dispatch');
        }

        public function testSuperglobalsRestoredEvenWhenHandlerThrows(): void
        {
            $_REQUEST = ['pre' => 'existing'];
            $_POST = ['pre' => 'existing-post'];
            $before_request = $_REQUEST;
            $before_post = $_POST;

            $threw = false;
            try {
                $this->runShim(['injected' => 1], function () {
                    // Confirm injection happened right before the throw.
                    if (($_REQUEST['injected'] ?? null) !== 1) {
                        throw new \RuntimeException('args were NOT injected before handler ran');
                    }
                    throw new \RuntimeException('handler blew up');
                });
            } catch (\RuntimeException $e) {
                $threw = true;
                $this->assertSame('handler blew up', $e->getMessage());
            }

            $this->assertTrue($threw, 'handler exception must propagate (not be swallowed)');
            // The critical additive-safety property: restore happens in finally.
            $this->assertSame($before_request, $_REQUEST, '$_REQUEST restored after a throwing handler');
            $this->assertSame($before_post, $_POST, '$_POST restored after a throwing handler');
        }
    }
}
