<?php
/**
 * GlobalData is used to share variables between processes.
 * Using PHP __set __get __isset __unsetmagic method to trigger communication with GlobalData server,
 * the actual variable is stored in GlobalData server. For example, when setting a non-existent
 * property to a client class, a __setmagic method is triggered . The client class __setsends a
 * request to the GlobalData server in the method and saves it in a variable. When accessing a
 * non-existent variable in the __getclient class, the method of the class is triggered. The client
 * initiates a request to the GlobalData server to read this value, thereby completing the process
 * of variable sharing between processes.
 *
 */
use Workerman\Worker;
require_once __DIR__.'/../../../../vendor/workerman/globaldata/src/Server.php';

$worker = new GlobalData\Server('127.0.0.1', 2207);

/*
require_once __DIR__ . '/../src/Client.php';
// Connect to the Global Data server
$global = new GlobalData\Client('127.0.0.1', 2207);
// Trigger $ global -> __ isset ('somedata') Query whether the server stores the value of key as somedata
isset($global->somedata);
// trigger $ global -> __set ('somedata', array (1,2,3)), inform the server to store the value of somedata as array (1,2,3)
$global->somedata = array(1,2,3);
// Trigger $ global -> __get ('somedata'), query the value corresponding to somedata from server
var_export($global->somedata);
// trigger $ global -> __ unset ('somedata'), notify the server to delete somedata and the corresponding value
unset($global->somedata);
*/