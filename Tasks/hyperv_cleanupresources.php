<?php

function hyperv_cleanupresources($args)
{
    require_once '/home/my/include/functions.inc.php';
    if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
        ini_set('default_socket_timeout', 1200);
    }
    $service_master = $args['service_master'];
    // opened right before use; one that will not open is alerted and nothing is sent to the host (MyAdmin plan_2way §5.11)
    require_once __DIR__.'/../Applications/Chat/HyperVHostSecret.php';
    $adminPassword = \HyperVHostSecret::password($service_master, 'hyperv_cleanupresources');
    if ($adminPassword === false) {
        return false;
    }
    $parameters = [
        'hyperVAdmin' => 'Administrator',
        'adminPassword' => $adminPassword
    ];
    try {
        $soap = new SoapClient("https://{$service_master['vps_ip']}/HyperVService/HyperVService.asmx?WSDL", \Detain\MyAdminHyperv\Plugin::getSoapClientParams());
        $response = $soap->CleanUpResources($parameters);
    } catch (Exception $e) {
        echo 'Caught exception: '.$e->getMessage().PHP_EOL;
        return false;
    }
    if (isset($args['queue']) && count($args['queue']) > 0) {
        function_requirements('vps_queue_handler');
        foreach ($args['queue'] as $queue) {
            vps_queue_handler($service_master, $queue);
        }
    }
}
