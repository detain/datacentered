<?php

use Workerman\Worker;

global $memcache, $redis;

/**
 * Queue endpoint for provisioning hosts (Workerman HTTP :55151).
 *
 * Step 3.3 glue (plan_queue_encrypt.md): every response-producing action is
 * routed through the shared envelope adapter \MyAdmin\Queue\QueueEndpointCrypto
 * (lives in the mystage autoload, required by start.php:8) so replay dedup and
 * response sealing happen EXACTLY ONCE per branch — never per echo. The
 * quadrant decision (legacy vs envelope), the update_key bootstrap seal and
 * the 403/500 bodies are owned by the adapter; this file supplies only the key
 * material, the master identity and the unchanged response bodies.
 *
 * Transport notes (Applications/Chat/start_web.php):
 *  - This file is include()'d per request — every reusable routine below MUST
 *    stay a closure in a local variable; a top-level function declaration
 *    would fatal on redeclare.
 *  - The transport populates $_GET/$_POST only (never $_REQUEST) and hardcodes
 *    "HTTP/1.1 200 OK" for string responses — http_response_code() below is
 *    contract parity with the mystage glue, not real status signalling.
 *  - Deploy-lag: while the mystage autoload root on this box lacks the adapter
 *    (or UpdateKey), every branch degrades to the exact pre-encryption
 *    plaintext flow.
 */

$queueCryptoClass = '\MyAdmin\Queue\QueueEndpointCrypto';
$cryptoReady = class_exists($queueCryptoClass);
// ServiceQueueHandler dispatch (mystage :130) has no class_exists guard of its
// own, so an absent UpdateKey handler must never reach the routing below.
$updateKeyReady = class_exists('\MyAdmin\vps\queue\ResponseHandlers\UpdateKey');

// Payload field for the Q1 envelope; this transport variant is $_POST-based.
$queuePayload = (string)($_POST['payload'] ?? '');

/**
 * Normalise {prefix}_queue_key from a masters row. Missing column (pre-ALTER
 * DB), NULL or blank string all mean "unkeyed" → NULL → legacy path. Anything
 * else is passed through untouched: the adapter fails loud (500) on material
 * that is not 64 lowercase hex.
 *
 * @param array|null $row masters row or null
 * @param string $prefix 'vps'|'qs'
 * @return string|null entry key for the adapter
 */
$masterKeyFromRow = static function (?array $row, string $prefix): ?string {
    if (null === $row || !array_key_exists($prefix . '_queue_key', $row)) {
        return null;
    }
    $key = trim((string)$row[$prefix . '_queue_key']);
    return '' === $key ? null : $key;
};

/**
 * Dual-module master lookup mirroring mystage public_html/queue.php:23-31:
 * try vps_masters, then qs_masters, keyed on {prefix}_ip = REMOTE_ADDR.
 * Unknown host or DB hiccup is non-fatal (reviewer-coder #2): null result →
 * key NULL → adapter legacy quadrant, behaviour identical to pre-3.3.
 *
 * @param string $ip REMOTE_ADDR
 * @return array{module:string,row:array,key:?string,identity:string}|null
 */
$lookupMasterByIp = static function (string $ip) use ($masterKeyFromRow): ?array {
    global $mysql_db;
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    foreach (['vps', 'qs'] as $prefix) {
        try {
            $row = $mysql_db->select('*')->from($prefix . '_masters')
                ->where($prefix . '_ip = :ip')->bindValues(['ip' => $ip])->row();
        } catch (\Throwable $e) {
            $row = false; // lookup failure = unkeyed legacy, never fatal
        }
        if (empty($row) || !is_array($row)) {
            continue;
        }
        return [
            'module' => $prefix,
            'row' => $row,
            'key' => $masterKeyFromRow($row, $prefix),
            // "{prefix}:{id}" — the empty identity + envelope 500 is adapter-
            // owned and unreachable from here (a row always carries {prefix}_id).
            'identity' => $prefix . ':' . ($row[$prefix . '_id'] ?? ''),
        ];
    }
    return null;
};

/**
 * Replay-dedup seam for this transport. The adapter's default store talks to
 * App::tf()->redis, which the Workerman web worker never populates — it would
 * fail-open silently. Inject this endpoint's own phpredis client instead.
 * $markerKey arrives fully built ('queue:dedup:'.sha256(identity|action|ts)),
 * so the namespace is byte-identical with the mystage endpoints sharing the
 * store. No client / any error → NULL = fail-open (defence-in-depth only; the
 * dormant USE_REDIS=false twin is not mirrored — see capture note below).
 */
$dedupTtl = $cryptoReady ? (int)constant($queueCryptoClass . '::DEDUP_TTL') : 600;
$replayDedupChecker = static function (string $markerKey) use ($dedupTtl): ?bool {
    global $redis;
    if (!defined('USE_REDIS') || USE_REDIS !== true || !is_object($redis) || !method_exists($redis, 'set')) {
        return null;
    }
    try {
        // phpredis NX+EX idiom copied from Wanguard.php:62 / Scrub.php:280:
        // true=acquired, false=already present, throw=down (caught → fail-open).
        return (bool)$redis->set($markerKey, 1, ['NX', 'EX' => $dedupTtl]);
    } catch (\Throwable $e) {
        return null;
    }
};

/**
 * Append one item to the queuein:<IP> telemetry stream. Shared verbatim logic
 * between the still-plaintext else-capture and the thin-routed bandwidth/
 * cpu_usage/server_info re-emits, so the consumer
 * Tasks/memcached_queue_task.php (which reads ['get','post','ip'] and knows
 * nothing about crypto) drains exactly one shape.
 */
$emitQueueinItem = static function (array $item): void {
    global $memcache, $redis;
    try {
        if (USE_REDIS === true) {
            $redis->rPush('queuein:' . $_SERVER['REMOTE_ADDR'], json_encode($item));
        } else {
            /*
             * REVIEW-FIX (carried from the original capture site): the key was
             * 'queuein'.<ip> — NO colon — while the only consumer,
             * Tasks/memcached_queue_task.php, reads 'queuein:'.<ip>. The
             * Memcached fallback therefore drained nothing and never has; only
             * the Redis path above (which does use the colon) ever worked.
             * Harmless while USE_REDIS is on, but the migration makes "Redis
             * unavailable" a routine, fail-safe state, so a fallback that
             * silently discards every queued bandwidth/CPU record is worth
             * having actually work. (The commented-out CAS variant that used to
             * sit here was abandoned code — never functional — and is dropped.)
             */
            $queuein = 'queuein:' . $_SERVER['REMOTE_ADDR'];
            $queue = $memcache->get($queuein);
            if (!is_array($queue)) {
                $queue = [];
            }
            $queue[] = $item;
            $memcache->set($queuein, $queue);
        }
    } catch (\Exception $e) {
        Worker::safeEcho('Caught Exception #'.$e->getCode().':'.$e->getMessage().' on '.__LINE__.'@'.__FILE__);
    }
};

/**
 * Run one response-producing action through the adapter and emit the result.
 *
 * $body(string $innerAction, array|false $queueData): string — the ORIGINAL
 * branch logic, buffered to a full-response string (ob_start/ob_get_clean) so
 * the adapter seals the complete body exactly once at branch end. $queueData
 * is false on every legacy (plaintext) call and the opened envelope array
 * (with '_encrypted') on envelope calls.
 *
 * The producer shim merges opened inner fields into $_GET/$_POST and
 * reconstructs $_REQUEST = $_GET + $_POST around dispatch — this transport
 * never fills $_REQUEST, yet legacy handler fallbacks (UpdateKey.php, the
 * queue_action.php idiom) read it. All three superglobals are restored in the
 * finally block. The OUTER $_POST['action'] stays the routing key: routing
 * completed before handle() runs, and the merge keeps inner action == outer
 * (adapter-enforced), so dispatch semantics are preserved.
 *
 * Producer exceptions are intentionally NOT caught here (adapter contract):
 * the inline \Exception catches inside the branch bodies stay, so parity with
 * the pre-3.3 flow holds; an uncaught \Error propagates to exec_php_file.
 */
$serveAdapted = static function (
    string $outerAction,
    ?string $masterKey,
    string $identity,
    callable $body
) use ($queueCryptoClass, $cryptoReady, $queuePayload, $replayDedupChecker): void {
    $producer = static function ($action, $queueData) use ($body) {
        $savedGet = $_GET;
        $savedPost = $_POST;
        $hadRequest = array_key_exists('REQUEST', $GLOBALS);
        $savedRequest = $hadRequest ? $_REQUEST : null;
        try {
            if (is_array($queueData)) {
                $innerFields = $queueData;
                unset($innerFields['_encrypted']);
                $_GET = array_merge($_GET, $innerFields);
                $_POST = array_merge($_POST, $innerFields);
            }
            $_REQUEST = array_merge($_GET, $_POST);
            return $body((string)$action, $queueData);
        } finally {
            $_GET = $savedGet;
            $_POST = $savedPost;
            if ($hadRequest) {
                $_REQUEST = $savedRequest;
            } else {
                unset($_REQUEST);
            }
        }
    };
    if (!$cryptoReady) {
        // Deploy-lag: no adapter on this box's autoload root yet — the exact
        // pre-3.3 plaintext flow, unsealed.
        echo $producer($outerAction, false);
        return;
    }
    $result = $queueCryptoClass::handle(
        $masterKey,
        $outerAction,
        $queuePayload,
        $producer,
        false, // enforcement parked (user policy): plaintext from keyed masters stays accepted
        $identity,
        $_POST, // plainFields map for the update_key bootstrap seal — this variant is $_POST-based
        $replayDedupChecker
    );
    // No-op under the Workerman string-send transport (status is hardcoded
    // 200); kept for contract parity with the mystage glue (step 3.1).
    // REVIEW-FIX (LOW): this worker runs a CLI loop where headers are
    // permanently "sent", so the unconditional call spammed a per-request
    // Cannot-modify-header-information warning on STDERR; the body alone
    // conveys auth-failed/error. Guard, do not delete (parity kept).
    if (!headers_sent()) {
        http_response_code($result['status']);
    }
    echo $result['body'];
};

if (isset($_POST['action']) && $_POST['action'] == 'map') {
    $mapLookup = $lookupMasterByIp($_SERVER['REMOTE_ADDR']);
    $serveAdapted('map', $mapLookup['key'] ?? null, $mapLookup['identity'] ?? '', static function () {
        global $memcache, $redis;
        ob_start();
        try {
            if (USE_REDIS === true) {
                $map = json_decode($redis->get('maps:'.$_SERVER['REMOTE_ADDR']), true);
            } else {
                $map = $memcache->get('maps'.$_SERVER['REMOTE_ADDR']);
            }
            if (is_array($map)) {
                if (array_key_exists('slice', $map)) {
                    echo "echo ".escapeshellarg($map['slice'])." > /root/cpaneldirect/vps.slicemap;".PHP_EOL;
                }
                if (array_key_exists('ip', $map)) {
                    echo 'oldm="$(md5sum /root/cpaneldirect/vps.ipmap)";'.PHP_EOL;
                    echo "echo ".escapeshellarg($map['ip'])." > /root/cpaneldirect/vps.ipmap;".PHP_EOL;
                    echo 'newm="$(md5sum /root/cpaneldirect/vps.ipmap)";'.PHP_EOL;
                    echo 'if [ "$(which virsh)" != "" ] && [ "$newm" != "$oldm" ]; then bash /root/cpaneldirect/run_buildebtables.sh; fi;'.PHP_EOL;
                }
                if (array_key_exists('vnc', $map)) {
                    echo "echo ".escapeshellarg($map['vnc'])." > /root/cpaneldirect/vps.vncmap;".PHP_EOL;
                    echo 'if [ "$(which virsh)" != "" ]; then
	    for vps in $(virsh list | grep -v -e "State$" -e "------$" -e "^$" | awk \'{ print $2 }\'); do
		    # Validate VPS name is safe alphanumeric + dash/underscore before shell use
		    if ! echo "$vps" | grep -qE \'^[a-zA-Z0-9_-]+$\'; then
			    continue;
		    fi;
		    ip="$(grep "$vps:" /root/cpaneldirect/vps.vncmap | cut -d: -f2)";
		    if [ "$ip" = "" ]; then
			    ip="66.45.228.100";
		    fi;
		    if [ ! -e /etc/xinetd.d/$vps ]; then
			    /root/cpaneldirect/provirted.phar vnc setup $vps $ip;
		    fi;
	    done;
    fi;
    ';
                }
                if (array_key_exists('main', $map)) {
                    echo "echo ".escapeshellarg($map['main'])." > /root/cpaneldirect/vps.mainips;".PHP_EOL;
                }
            }
        } catch (\Exception $e) {
            Worker::safeEcho('Caught Exception #'.$e->getCode().':'.$e->getMessage().' on '.__LINE__.'@'.__FILE__);
        } finally {
            $out = (string)ob_get_clean();
        }
        return $out;
    });
} elseif (isset($_POST['action']) && $_POST['action'] == 'get_queue') {
    global $mysql_db;
    $ip = $_SERVER['REMOTE_ADDR'];
    $vpsMaster = false;
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $vpsMaster = $mysql_db->select('*')->from('vps_masters')->leftJoin('vps_master_details', 'vps_masters.vps_id=vps_master_details.vps_id')->where('vps_ip = :ip')->bindValues(['ip' => $_SERVER['REMOTE_ADDR']])->row();
    }
    $serveAdapted('get_queue', $masterKeyFromRow(false === $vpsMaster ? null : $vpsMaster, 'vps'), false === $vpsMaster ? '' : 'vps:' . $vpsMaster['vps_id'], function ($action, $queueData) use ($vpsMaster, $ip) {
        global $mysql_db;
        // $queueData (the opened envelope field-map) is deliberately unused
        // here: this branch answers from the per-row queue_log record, exactly
        // as before — an authenticated empty envelope is just "pull my queue".
        ob_start();
        try {
            if (false === $vpsMaster) {
                echo "Bad IP {$ip}";
            } elseif (false !== $results = $mysql_db->select('*')->from('queue_log')->leftJoin('vps', 'vps_id=history_type')->where('history_section="vpsqueue" and vps_server=:id')->bindValues(['id' => $vpsMaster['vps_id']])->query()) {
                function_requirements('vps_queue_handler');
                foreach ($results as $result) {
                    echo vps_queue_handler($vpsMaster, 'get_queue', $result);
                }
            }
        } finally {
            $out = (string)ob_get_clean();
        }
        return $out;
    });
} elseif (isset($_POST['action']) && $_POST['action'] == 'get_new_vps') {
    global $mysql_db;
    $ip = $_SERVER['REMOTE_ADDR'];
    $vpsMaster = false;
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $vpsMaster = $mysql_db->select('*')->from('vps_masters')->leftJoin('vps_master_details', 'vps_masters.vps_id=vps_master_details.vps_id')->where('vps_ip = :ip')->bindValues(['ip' => $_SERVER['REMOTE_ADDR']])->row();
    }
    $serveAdapted('get_new_vps', $masterKeyFromRow(false === $vpsMaster ? null : $vpsMaster, 'vps'), false === $vpsMaster ? '' : 'vps:' . $vpsMaster['vps_id'], function ($action, $queueData) use ($vpsMaster, $ip) {
        // $queueData: false on the legacy path (handler falls back to the
        // shimmed superglobals), opened field-map on envelope pulls.
        ob_start();
        try {
            if (false === $vpsMaster) {
                echo "Bad IP {$ip}";
            } else {
                function_requirements('vps_queue_handler');
                echo vps_queue_handler($vpsMaster, $action, $queueData);
            }
        } finally {
            $out = (string)ob_get_clean();
        }
        return $out;
    });
} elseif (isset($_POST['action']) && $_POST['action'] == 'get_qs_queue') {
    global $mysql_db;
    $ip = $_SERVER['REMOTE_ADDR'];
    $qsMaster = false;
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $qsMaster = $mysql_db->select('*')->from('qs_masters')->leftJoin('qs_master_details', 'qs_masters.qs_id=qs_master_details.qs_id')->where('qs_ip = :ip')->bindValues(['ip' => $_SERVER['REMOTE_ADDR']])->row();
    }
    $serveAdapted('get_qs_queue', $masterKeyFromRow(false === $qsMaster ? null : $qsMaster, 'qs'), false === $qsMaster ? '' : 'qs:' . $qsMaster['qs_id'], function ($action, $queueData) use ($qsMaster, $ip) {
        global $mysql_db;
        // Handler action argument stays the LITERAL 'get_queue': there is no
        // GetQsQueue response class — the envelope's inner 'get_qs_queue'
        // authenticates the request, dispatch remains what it always was.
        ob_start();
        try {
            if (false === $qsMaster) {
                echo "Bad IP {$ip}";
            } elseif (false !== $results = $mysql_db->select('*')->from('queue_log')->leftJoin('quickservers', 'qs_id=history_type')->where('history_section="quickserversqueue" and qs_server=:id')->bindValues(['id' => $qsMaster['qs_id']])->query()) {
                function_requirements('qs_queue_handler');
                foreach ($results as $result) {
                    echo qs_queue_handler($qsMaster, 'get_queue', $result);
                }
            }
        } finally {
            $out = (string)ob_get_clean();
        }
        return $out;
    });
} elseif (isset($_POST['action']) && $_POST['action'] == 'get_new_qs') {
    global $mysql_db;
    $ip = $_SERVER['REMOTE_ADDR'];
    $qsMaster = false;
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $qsMaster = $mysql_db->select('*')->from('qs_masters')->leftJoin('qs_master_details', 'qs_masters.qs_id=qs_master_details.qs_id')->where('qs_ip = :ip')->bindValues(['ip' => $_SERVER['REMOTE_ADDR']])->row();
    }
    $serveAdapted('get_new_qs', $masterKeyFromRow(false === $qsMaster ? null : $qsMaster, 'qs'), false === $qsMaster ? '' : 'qs:' . $qsMaster['qs_id'], function ($action, $queueData) use ($qsMaster, $ip) {
        ob_start();
        try {
            if (false === $qsMaster) {
                echo "Bad IP {$ip}";
            } else {
                function_requirements('qs_queue_handler');
                echo qs_queue_handler($qsMaster, $action, $queueData);
            }
        } finally {
            $out = (string)ob_get_clean();
        }
        return $out;
    });
} elseif (isset($_POST['action']) && $_POST['action'] == 'queue') {
    $queueLookup = $lookupMasterByIp($_SERVER['REMOTE_ADDR']);
    $serveAdapted('queue', $queueLookup['key'] ?? null, $queueLookup['identity'] ?? '', static function () {
        global $memcache, $redis;
        // Destructive drain (lPop / CAS-clear): running INSIDE the adapter
        // producer is what makes the dedup-before-producer contract real.
        ob_start();
        try {
            if (USE_REDIS === true) {
                $maxItems = 100;
                $count = 0;
                while ($count < $maxItems && false !== ($queue = $redis->lPop('queue:' . $_SERVER['REMOTE_ADDR']))) {
                    echo $queue . PHP_EOL;
                    $count++;
                }
                if ($count >= $maxItems) {
                    Worker::safeEcho("queue: hit max items limit ($maxItems), remaining items stay in queue\n");
                }
            } else {
                $queueArray = $memcache->get('queue');
                if (is_array($queueArray)) {
                    /*if (array_key_exists($_SERVER['REMOTE_ADDR'], $queueArray['new']) && count($queueArray['new'][$_SERVER['REMOTE_ADDR']]) > 0) {
                        echo implode(PHP_EOL, $queueArray['new'][$_SERVER['REMOTE_ADDR']]).PHP_EOL;
                    }*/
                    if (array_key_exists($_SERVER['REMOTE_ADDR'], $queueArray['queue']) && count($queueArray['queue'][$_SERVER['REMOTE_ADDR']]) > 0) {
                        echo implode(PHP_EOL, $queueArray['queue'][$_SERVER['REMOTE_ADDR']]).PHP_EOL;
                        $loopCount = 0;
                        do {
                            $response = $memcache->get('queue', function ($memcache, $key, &$value) {
                                $value = [];
                                return true;
                            }, \Memcached::GET_EXTENDED);
                            $queue = $response['value'];
                            $cas = $response['cas'];
                            $queue['queue'][$_SERVER['REMOTE_ADDR']] = [];
                            $loopCount++;
                            if ($loopCount > 100) {
                                Worker::safeEcho('Max Loops Reached Trying to Get queue CAS set '.PHP_EOL);
                                break;
                            }
                        } while (!$memcache->cas($response['cas'], 'queue', $queue));
                    }
                }
            }
        } catch (\Exception $e) {
            Worker::safeEcho('Caught Exception #'.$e->getCode().':'.$e->getMessage().' on '.__LINE__.'@'.__FILE__);
        } finally {
            $out = (string)ob_get_clean();
        }
        return $out;
    });
} elseif (isset($_POST['action']) && $_POST['action'] == 'update_key' && $cryptoReady && $updateKeyReady) {
    // Thin case: key bootstrap/rotation is driven entirely by the shared
    // adapter + mystage ResponseHandlers\UpdateKey. Module resolves via the
    // dual vps→qs masters loop (mirrors mystage public_html/queue.php:23-31);
    // the adapter's sealKey matrix handles the bootstrap response (sealed with
    // the just-stored $_POST['queue_key']) — this branch only routes.
    $keyLookup = $lookupMasterByIp($_SERVER['REMOTE_ADDR']);
    if (null === $keyLookup) {
        // Unknown host: no module context exists to drive UpdateKey against.
        // Pre-3.3 flow for a novel action was the telemetry capture; keep it.
        $emitQueueinItem(['get' => $_GET, 'post' => $_POST, 'ip' => $_SERVER['REMOTE_ADDR']]);
    } else {
        $serveAdapted('update_key', $keyLookup['key'], $keyLookup['identity'], static function ($action, $queueData) use ($keyLookup) {
            $module = $keyLookup['module'];
            function_requirements($module . '_queue_handler');
            // ServiceQueueHandler($module, $masterRow, $action, $queueData)->render()
            // → ResponseHandlers\UpdateKey; $_REQUEST inside it falls back to the
            // shimmed superglobals on the bootstrap (legacy) leg.
            return (string)call_user_func($module . '_queue_handler', $keyLookup['row'], $action, $queueData);
        });
    }
} elseif (isset($_POST['action']) && $cryptoReady && ($_POST['action'] == 'bandwidth' || $_POST['action'] == 'cpu_usage' || $_POST['action'] == 'server_info')) {
    // Thin-route for the PRIMARY telemetry ingress (audit docs/queue-encryption-
    // replay-audit.md, user decision at :78): open → dedup → re-emit the opened
    // PLAINTEXT into queuein:<IP> in the exact consumer shape, so the
    // memcached_queue_task.php daemon keeps zero crypto knowledge AND replays
    // stop double-writing timestamp-less Influx points. Without a key (or
    // without a payload) the adapter routes to legacy: today's verbatim
    // plaintext re-emit, ACK 'ok' instead of an empty body (cron ignores it).
    $telemetryLookup = $lookupMasterByIp($_SERVER['REMOTE_ADDR']);
    $serveAdapted((string)$_POST['action'], $telemetryLookup['key'] ?? null, $telemetryLookup['identity'] ?? '', static function ($action, $queueData) use ($emitQueueinItem) {
        if (is_array($queueData)) {
            $innerFields = $queueData;
            unset($innerFields['_encrypted']);
            // REVIEW-FIX (field fidelity, reviewer option (a)): the frozen host
            // CLI shim (`queue-crypto.php` enc mode) seals ONLY the minimal
            // {action, v, ts} map — the consumer fields the daemon reads
            // (Tasks/memcached_queue_task.php :253 module, :432-433
            // bandwidth/servers) ride on the OUTER plaintext POST, so once
            // enforcement-era hosts seal telemetry, re-emitting the opened
            // inner map alone would starve them. Merge OUTER fields BENEATH the
            // opened inner fields: inner wins (authenticated provenance).
            // Excluded from the outer base: 'payload' (the envelope itself, not
            // a consumer field) and '_encrypted' (adapter-internal marker; the
            // shim already stripped it from the inner side, stripping the outer
            // key too denies a spoofed plaintext _encrypted from reaching the
            // queue). v/ts passthrough stays as-is (consumer ignores extras).
            // Inside the producer shim $_POST is already outer ∪ inner (inner
            // won), so re-merging $innerFields on top is idempotent and the
            // expression stays correct even if the shim's merge ever moves.
            // Envelope requests are pure POSTs (no query params) — 'get' stays
            // empty; 'post' becomes the outer map topped by the decrypted
            // field-map.
            $postFields = $_POST;
            unset($postFields['payload'], $postFields['_encrypted']);
            $emitQueueinItem(['get' => [], 'post' => array_merge($postFields, $innerFields), 'ip' => $_SERVER['REMOTE_ADDR']]);
        } else {
            $emitQueueinItem(['get' => $_GET, 'post' => $_POST, 'ip' => $_SERVER['REMOTE_ADDR']]);
        }
        return 'ok';
    });
} else {
    // TODO(plan_queue_encrypt 7.x enforcement): remaining telemetry captures
    // stay PLAINTEXT indefinitely per user policy. An envelope arriving for an
    // action not routed above is captured here as Q1 ciphertext and simply
    // fails to parse downstream — acceptable per the 2.2 logging policy
    // (reviewer m4): no key material, no crash, next cron re-measures.
    $item = ['get' => $_GET, 'post' => $_POST, 'ip' => $_SERVER['REMOTE_ADDR']];
    $output = '';
    $emitQueueinItem($item);
}
//\Workerman\Protocols\Http::end($output);
