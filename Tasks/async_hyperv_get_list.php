<?php
use MyAdmin\App;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

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

function async_hyperv_get_list_server($service_master)
{
    /**
    * @var \GlobalData\Client
    */
    global $global;
    $var = 'vps_host_'.$service_master['vps_id'];
    $requestVar = $var.'_request';
    $global->$requestVar = 'get_list__soap_call';
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
        $global->$var = 0;
        return;
    }
    $statStart = microtime(true);
    $global->$requestVar = 'get_vm_list';
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
            $global->$requestVar = 'server_list';
            function_requirements('vps_queue_handler');
            vps_queue_handler($service_master, 'server_list', $result);
        } else {
            async_hyperv_report_metric('GetVMList', $statStart, false, 100, 'Missing expected output fields', $service_master['vps_id']);
            echo $service_master['vps_name'].' ERROR: Command Completed but missing expected fields! Output: '.json_encode($result).PHP_EOL;
            $global->$requestVar = 'cleanup_resources';
            if (isset($result->Success) && $result->Success == 'false' && $global->$var < 3) {
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
    $global->$var = 0;
}

function async_hyperv_get_list($args)
{
    require_once '/home/my/include/functions.inc.php';
    /**
    * @var \GlobalData\Client
    */
    global $global;
    $db = App::db();
    $db->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_type=".get_service_define('HYPERV'));
    $rows = [];
    $sids = [];
    while ($db->next_record(MYSQL_ASSOC)) {
        $rows[$db->Record['vps_id']] = $db->Record;
        $sids[] = $db->Record['vps_id'];
    }
    foreach ($rows as $service_id => $service_master) {
        $var = 'vps_host_'.$service_id;
        $requestVar = $var.'_request';
        if (!isset($global->$var)) {
            $global->$var = 0;
        }
        if ($global->cas($var, 0, time())) {
            $global->requestVar = 'none';
            async_hyperv_get_list_server($service_master);
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
