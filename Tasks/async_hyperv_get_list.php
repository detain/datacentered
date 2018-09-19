<?php
use Workerman\Connection\AsyncTcpConnection;

require_once __DIR__.'/../../../vendor/workerman/statistics/Applications/Statistics/Clients/StatisticClient.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
	ini_set('default_socket_timeout', 1200);
}

function async_hyperv_get_list_server(\Clue\React\Buzz\Browser &$browser, $service_master)
{
	$url = "https://{$service_master['vps_ip']}/HyperVService/HyperVService.asmx?WSDL";
	//echo "Creating Client for {$service_master['vps_name']} @ {$url}\n";
	$browser->get($url)->then(
		function (\Psr\Http\Message\ResponseInterface $response) use ($browser, $service_master) {
			// WSDL file is ready, create client
			try {
				$client = new \Clue\React\Soap\Client($browser, (string)$response->getBody(), ['soap_version' => SOAP_1_2]);
			} catch (\SoapFault $e) {
				echo 'Error: ' . $e->getMessage() . PHP_EOL;
				return;
			}
			$api = new \Clue\React\Soap\Proxy($client);
			//echo "Running GetVMList for {$service_master['vps_name']}\n";
			\StatisticClient::tick('Hyper-V', 'GetVMList');
			$api->GetVMList(['hyperVAdmin' => 'Administrator', 'adminPassword' => $service_master['vps_root']])->then(
				function ($result) use (&$factory, &$client, $service_master) {
					/**
					* @var \GlobalData\Client
					*/
					global $global;
					$var = 'vps_host_'.$service_master['vps_id'];
					if (isset($result->GetVMListResult->Success)) {
						$result = $result->GetVMListResult;
					}
					if (isset($result->Success) && $result->Success == 'true' && isset($result->VMList) && isset($result->VMList->VirtualMachineSummary)) {
						\StatisticClient::report('Hyper-V', 'GetVMList', true, 0, '', 'udp://199.231.187.29:55656');
						if (isset($result->VMList->VirtualMachineSummary->VmId)) {
							$result->VMList->VirtualMachineSummary = [0 => $result->VMList->VirtualMachineSummary];
						}
						//echo $service_master['vps_name'].' Successfull Get VM List'.PHP_EOL;
						function_requirements('vps_queue_handler');
						vps_queue_handler($service_master, 'server_list', $result);
						//echo $service_master['vps_name'] . ' Got VM List'.PHP_EOL;
					} else {
						\StatisticClient::report('Hyper-V', 'GetVMList', false, 100, 'Missing expected output fields', 'udp://199.231.187.29:55656');
						//echo $service_master['vps_name'].' ERROR: Command Completed but missing expected fields! Output: '.json_encode($result).PHP_EOL;
						if (isset($result->Success) && $result->Success == 'false' && $global->$var < 3) {
							$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');                                                // Asynchronous link with the remote task service
							$task_connection->send(json_encode(['type' => 'hyperv_cleanupresources', 'args' => ['service_master' => $service_master, 'queue' => ['server_list']]]));    // send data
							$task_connection->onMessage = function ($task_connection, $task_result) use ($task_connection) {                    // get the result asynchronously
								$task_connection->close();                                                                                    // remember to turn off the asynchronous link after getting the result
							};
							$task_connection->connect();                                                                                    // execute async link
						}
					}
					$global->$var = 0;
				},
				function (Exception $e) use ($service_master) {
					echo $service_master['vps_name'].' GetVMList ERROR: ' . $e->getMessage() . PHP_EOL;
					\StatisticClient::report('Hyper-V', 'GetVMList', false, $e->getCode(), $e->getMessage(), 'udp://199.231.187.29:55656');
					/**
					* @var \GlobalData\Client
					*/
					global $global;
					$var = 'vps_host_'.$service_master['vps_id'];
					$global->$var = 0;
				}
			);
		},
		function (\Exception $e) {
			\StatisticClient::report('Hyper-V', 'GetVMList', false, $e->getCode(), $e->getMessage(), 'udp://199.231.187.29:55656');
			echo 'Error: an error occured while trying to download the WSDL'.PHP_EOL;
			return;
		}
	);
}

function async_hyperv_get_list($args)
{
	require_once __DIR__.'/../../../include/functions.inc.php';
	$loop = \React\EventLoop\Factory::create();
	$connector = new \React\Socket\Connector($loop, [
		//'dns' => '127.0.0.1',
		//'tcp' => [
			//'bindto' => '192.168.10.1:0'
		//],
		'tls' => [
			'verify_peer' => false,
			'verify_peer_name' => false
		]
	]);
	$browser = new \Clue\React\Buzz\Browser($loop, $connector);
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	$db = $GLOBALS['tf']->db;
	$db->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_type=".get_service_define('HYPERV'));
	$rows = [];
	$sids = [];
	while ($db->next_record(MYSQL_ASSOC)) {
		$rows[$db->Record['vps_id']] = $db->Record;
		$sids[] = $db->Record['vps_id'];
	}
	foreach ($rows as $service_id => $service_master) {
		$var = 'vps_host_'.$service_id;
		if (!isset($global->$var)) {
			$global->$var = 0;
		}
		if ($global->cas($var, 0, 1)) {
			async_hyperv_get_list_server($browser, $service_master);
		}
	}
	$loop->run();
}
