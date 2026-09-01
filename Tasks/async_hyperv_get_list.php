<?php
use MyAdmin\App;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

require_once __DIR__.'/../Applications/Chat/SharedState.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
    ini_set('default_socket_timeout', 1200);
}

/**
 * Writes an InfluxDB v2 line-protocol point recording the outcome of a HyperV SOAP call.
 *
 * Emits a `task_stats,module=Hyper-V` measurement (duration/success/code/msg) via the shared
 * `$influx_v2_database` global — the same InfluxDB v2 client used by `Tasks/bandwidth.php`. This
 * replaces the removed `workerman/statistics` dependency and its `StatisticClient::report()` calls
 * (which formerly emitted to a UDP `STATISTICS_SERVER` stream that nothing in this repo consumed).
 * The `$msg` field is escaped for line protocol (backslash, double-quote, newline, carriage return).
 * No-ops when InfluxDB v2 is disabled (`INFLUX_V2 !== true`) or the client global is unset.
 *
 * @param string    $interface the api call being reported on, e.g. 'GetVMList'
 * @param float     $startTime microtime(true) captured just before the call (constructor included)
 * @param bool      $success   whether the call succeeded
 * @param int       $code      error code (0 on success)
 * @param string    $msg       error message ('' on success)
 * @param int       $host      the vps_masters vps_id the call was made against
 */
function async_hyperv_report_metric($interface, $startTime, $success, $code, $msg, $host)
{
    global $influx_v2_database;
    if (INFLUX_V2 !== true || !isset($influx_v2_database)) {
        return;
    }
    $duration = microtime(true) - $startTime;
    $msg = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string)$msg);
    try {
        $influx_v2_database->write('task_stats,module=Hyper-V,interface='.$interface.',host='.(int)$host.' duration='.round($duration, 6).',success='.($success ? 1 : 0).',code='.(int)$code.',msg="'.$msg.'"');
    } catch (\Exception $e) {
        Worker::safeEcho('InfluxDB got Exception '.$e->getMessage().' while writing Hyper-V task metric'.PHP_EOL);
    }
}

/**
 * Processes one host's GetVMList cycle. The caller (async_hyperv_get_list) owns the
 * 'vps_host_<id>' lock and passes its token down; this function is the sole releaser,
 * via the single token-checked unlock in the `finally` below — the same discipline as
 * async_hyperv_queue_runner.php.
 *
 * @param array     $service_master the vps_masters row (+details) for one host
 * @param string    $token          lock() token for renew()/unlock() on this host
 * @return void
 */
function async_hyperv_get_list_server($service_master, $token)
{
    $lockName = 'vps_host_'.$service_master['vps_id'];
    // Debug sibling of the lock: pure observability (op currently running); each set() below
    // passes TTL 900 with the lock family — overwritten every cycle, the TTL only bounds orphans.
    $requestKey = 'dc:lock:vps_host_'.$service_master['vps_id'].':request';
    // The finally below is the ONLY unlock site. It runs on every exit path: normal
    // completion, the SoapFault early return, either renew-abort early return, and —
    // the gap this closes — an \Error/\TypeError escaping vps_queue_handler, which
    // catch(\Exception) cannot catch; without the finally that lock would sit held
    // for its full 900s TTL. The unlock is token-checked, so after a failed renew
    // (lock expired or taken) it is a safe no-op, and while still held it releases.
    try {
        SharedState::set($requestKey, 'get_list__soap_call', 900);
        $url = "https://{$service_master['vps_ip']}/HyperVService/HyperVService.asmx?WSDL";
        $streamContext = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $statStart = microtime(true);
        try {
            $client = new \SoapClient($url, [
                'soap_version' => SOAP_1_2,
                'stream_context' => $streamContext,
                'exceptions' => true,
                'trace' => false,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ]);
        } catch (\SoapFault $e) {
            echo $service_master['vps_name'].' Error: ' . $e->getMessage() . PHP_EOL;
            async_hyperv_report_metric('GetVMList', $statStart, false, $e->getCode(), $e->getMessage(), $service_master['vps_id']);
            // The former explicit SharedState::unlock here is gone: this return
            // still runs the outer finally, the single release point.
            return;
        }
        $statStart = microtime(true);
        SharedState::set($requestKey, 'get_vm_list', 900);
        // Renew before the blocking GetVMList round-trip so the drain lock outlives a
        // slow host instead of expiring mid-call (the GlobalData lock had no TTL at all).
        // 900s matches the acquire TTL: HyperV GetVMList can take 10+ minutes, the lock
        // must never expire before the call it guards. A false renew means ownership is
        // gone (expired or taken) — abort before starting the op; the token-checked
        // unlock at the end of this function no-ops on a lost lock, so nothing to clean.
        if (!SharedState::renew($lockName, $token, 900)) {
            Worker::safeEcho($service_master['vps_name'].' lost lock before GetVMList — skipping this host this cycle'.PHP_EOL);
            return;
        }
        try {
            $result = $client->GetVMList(['hyperVAdmin' => 'Administrator', 'adminPassword' => $service_master['vps_root']]);
            if (isset($result->GetVMListResult->Success)) {
                $result = $result->GetVMListResult;
            }
            if (isset($result->Success) && ($result->Success == 'true' || $result->Success == 1)) {
                if (isset($result->VMList) && isset($result->VMList->VirtualMachineSummary)) {
                    async_hyperv_report_metric('GetVMList', $statStart, true, 0, '', $service_master['vps_id']);
                    if (isset($result->VMList->VirtualMachineSummary->VmId)) {
                        $result->VMList->VirtualMachineSummary = [0 => $result->VMList->VirtualMachineSummary];
                    }
                } else {
                    $result->VMList->VirtualMachineSummary = [];
                }
                SharedState::set($requestKey, 'server_list', 900);
                // Same discipline as the GetVMList renew: never run the server_list
                // handler after losing the host lock — abort; the finally-unlock is
                // token-checked and would no-op on a lost lock anyway.
                if (!SharedState::renew($lockName, $token, 900)) {
                    Worker::safeEcho($service_master['vps_name'].' lost lock before server_list — skipping this host this cycle'.PHP_EOL);
                    return;
                }
                function_requirements('vps_queue_handler');
                vps_queue_handler($service_master, 'server_list', $result);
            } else {
                async_hyperv_report_metric('GetVMList', $statStart, false, 100, 'Missing expected output fields', $service_master['vps_id']);
                echo $service_master['vps_name'].' ERROR: Command Completed but missing expected fields! Output: '.json_encode($result).PHP_EOL;
                SharedState::set($requestKey, 'cleanup_resources', 900);
                // KNOWN dead/buggy branch (pre-existing, preserved verbatim — do NOT "fix"):
                // the old `$global->$var < 3` read the lock var, which cas($var, 0, time()) had
                // just set to a time() timestamp; a timestamp is never < 3, so this
                // hyperv_cleanupresources dispatch was UNREACHABLE. SET NX stores an opaque
                // token here, so there is no counter to compare — we keep the guard permanently
                // false to preserve that exact unreachable behaviour (retry-counter semantics
                // are out of scope for this migration).
                $legacyRetryCounterAlwaysFalse = false;
                if (isset($result->Success) && $result->Success == 'false' && $legacyRetryCounterAlwaysFalse) {
                    $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
                    $task_connection->send(json_encode(['type' => 'hyperv_cleanupresources', 'args' => ['service_master' => $service_master, 'queue' => ['server_list']]]));
                    $task_connection->onMessage = function ($connection, $task_result) use ($task_connection) {
                        $task_connection->close();
                    };
                    $task_connection->connect();
                }
            }
        } catch (\Exception $e) {
            echo $service_master['vps_name'].' GetVMList ERROR: ' . $e->getMessage() . PHP_EOL;
            async_hyperv_report_metric('GetVMList', $statStart, false, $e->getCode(), $e->getMessage(), $service_master['vps_id']);
        }
    } finally {
        SharedState::unlock($lockName, $token);
    }
}

function async_hyperv_get_list($args)
{
    require_once '/home/my/include/functions.inc.php';
    $db = App::db();
    $db->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_type=".get_service_define('HYPERV'));
    $rows = [];
    $sids = [];
    while ($db->next_record(MYSQL_ASSOC)) {
        $rows[$db->Record['vps_id']] = $db->Record;
        $sids[] = $db->Record['vps_id'];
    }
    foreach ($rows as $service_id => $service_master) {
        $lockName = 'vps_host_'.$service_id;
        // Debug sibling of the lock: pure observability (op currently running); set() passes
        // TTL 900 with the lock family — the TTL only bounds orphans from decommissioned hosts.
        $requestKey = 'dc:lock:vps_host_'.$service_id.':request';
        // SET NX + TTL 900 replaces cas($var, 0, time()); absence == free so the
        // isset-seed is gone. A null token means the host is already being polled
        // (or Redis is unavailable) — the same "skip this host" outcome as before.
        // The 900s TTL mirrors the old GlobalData 900s stale-reap window (never
        // shorter per ops rule): GetVMList below can run 10+ minutes on one host.
        $token = SharedState::lock($lockName, 900);
        if ($token !== null) {
            SharedState::set($requestKey, 'none', 900);
            // async_hyperv_get_list_server owns releasing this lock (unlock via token).
            async_hyperv_get_list_server($service_master, $token);
        }
    }
    // Flush any buffered InfluxDB metric writes once after all hosts have been processed.
    global $influx_v2_database;
    if (INFLUX_V2 === true && isset($influx_v2_database)) {
        try {
            $influx_v2_database->close();
        } catch (\Exception $e) {
            Worker::safeEcho('InfluxDB got Exception '.$e->getMessage().' while flushing writes'.PHP_EOL);
        }
    }
}
