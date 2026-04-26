<?php
use MyAdmin\App;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

require_once '/home/my/vendor/workerman/statistics/Applications/Statistics/Clients/StatisticClient.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
    ini_set('default_socket_timeout', 1200);
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
        \StatisticClient::report('Hyper-V', 'GetVMList', false, $e->getCode(), $e->getMessage(), STATISTICS_SERVER);
        $global->$var = 0;
        return;
    }
    \StatisticClient::tick('Hyper-V', 'GetVMList');
    $global->$requestVar = 'get_vm_list';
    try {
        $result = $client->GetVMList(['hyperVAdmin' => 'Administrator', 'adminPassword' => $service_master['vps_root']]);
        if (isset($result->GetVMListResult->Success)) {
            $result = $result->GetVMListResult;
        }
        if (isset($result->Success) && ($result->Success == 'true' || $result->Success == 1)) {
            if (isset($result->VMList) && isset($result->VMList->VirtualMachineSummary)) {
                \StatisticClient::report('Hyper-V', 'GetVMList', true, 0, '', STATISTICS_SERVER);
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
            \StatisticClient::report('Hyper-V', 'GetVMList', false, 100, 'Missing expected output fields', STATISTICS_SERVER);
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
        \StatisticClient::report('Hyper-V', 'GetVMList', false, $e->getCode(), $e->getMessage(), STATISTICS_SERVER);
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
}
