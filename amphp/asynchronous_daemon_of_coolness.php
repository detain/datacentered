<?php
require_once __DIR__.'/../../include/functions.inc.php';
require_once __DIR__.'/amp_soap.php';
require_once __DIR__.'/amp_factory.php';

use Amp\Artax\Client;
use Detain\MyAdmin\Factory;
use Detain\MyAdmin\SoapClient;

$promises = [];
$factory = new Factory();
$options = ['soap_version' => SOAP_1_2];
$db = get_module_db('vps');
$db->query('select vps_id,vps_name,vps_ip,vps_root from vps_masters left join vps_master_details using (vps_id) where vps_type=11', __LINE__, __FILE__);
while ($db->next_record(MYSQL_ASSOC)) {
	$wsdl = "https://{$db->Record['vps_ip']}/HyperVService/HyperVService.asmx?WSDL";
	$client = $factory->create(new Client(), $wsdl, $options);
	$promises[$db->Record['vps_name']] = $client->callAsync('GetVMList', [['hyperVAdmin' => 'Administrator', 'adminPassword' => $db->Record['vps_root']]]);//-->wait();
}
$responses = Amp\wait(Amp\all($promises));
var_export($responses);
foreach ($responses as $key => $response)
	printf("%s | HTTP/%s %d %s\n", $key, $response->getProtocol(), $response->getStatus(), $response->getReason() );		$this->assertNotEmpty($response);

