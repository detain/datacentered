<?php
use Clue\React\Buzz\Browser;
use Clue\React\Soap\Factory;
use Clue\React\Soap\Proxy;
use Clue\React\Soap\Client;
require_once __DIR__.'/include/functions.inc.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1)
	ini_set('default_socket_timeout', 1200);

$loop = React\EventLoop\Factory::create();
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
$browser = new Browser($loop, $connector);
$factory = new Factory($loop, $browser);

$db = $GLOBALS['tf']->db;
$db->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_type=".get_service_define('HYPERV'));
$rows = [];
$sids = [];
while ($db->next_record(MYSQL_ASSOC)) {
	$db->Record['newvps'] = [];
	$db->Record['queue'] = [];
	$rows[$db->Record['vps_id']] = $db->Record;
	$sids[] = $db->Record['vps_id'];
}
//	vps_queue_handler($server_info, 'getnewvps');
$db->query("select * from vps where vps_status='pending-setup' and vps_server in (".implode(',', $sids).")", __LINE__, __FILE__);
while ($db->next_record(MYSQL_ASSOC))
	$rows[$db->Record['vps_server']]['newvps'][] = $db->Record;
//	vps_queue_handler($server_info, 'getqueue');
$db->query("select vps.*, hl1.* from vps, queue_log as hl1 left join queue_log as hl2 on hl2.history_type=hl1.history_id and hl2.history_section='vpsqueuedone' where hl1.history_section='vpsqueue' and hl1.history_type=vps_id and hl2.history_id is null and vps_server in (".implode(',', $sids).")", __LINE__, __FILE__);
while ($db->next_record(MYSQL_ASSOC))
	$rows[$db->Record['vps_server']]['queue'][] = $db->Record;
foreach ($rows as $service_id => $service_master) {
	$url = "https://{$service_master['vps_ip']}/HyperVService/HyperVService.asmx?WSDL";
	//echo "Creating Client for {$service_master['vps_name']} @ {$url}\n";
	$factory->createClient($url)->then(function (Client $client) use ($service_master) {
		$api = new Proxy($client);
		$api->GetVMList(['hyperVAdmin' => 'Administrator', 'adminPassword' => $service_master['vps_root']])->then(
			function ($result) use ($service_master) {
				if (isset($result->GetVMListResult->VMList) && isset($result->GetVMListResult->VMList->VirtualMachineSummary)) {
					if (isset($result->GetVMListResult->VMList->VirtualMachineSummary->VmId))
						$result->GetVMListResult->VMList->VirtualMachineSummary = [0 => $result->GetVMListResult->VMList->VirtualMachineSummary];
					vps_queue_handler($service_master, 'serverlist', $result);
				} else {
					echo $service_master['vps_name'].' ERROR: Command Completed but missing expected fields!'.PHP_EOL;
				}
			},
			function (Exception $e) use ($service_master) {
				echo $service_master['vps_name'].' ERROR: ' . $e->getMessage() . PHP_EOL;
			}
		);
	});
}
$loop->run();
