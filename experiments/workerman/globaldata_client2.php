<?php
require_once __DIR__.'/../../../../vendor/workerman/globaldata/src/Client.php';

$global = new \GlobalData\Client('127.0.0.1:2207');

var_export(isset($global->abc));
$global->abc = array(1,2,3);
var_export($global->abc);
unset($global->abc);
var_export($global->abc);
$global->abc = array(1,2,3);
var_export($global->abc);
var_export($global->cas('abc', array(1,2,3), array(5,6,7)));
var_export($global->abc);

// Connect to the Global Data server
$global = new \GlobalData\Client('127.0.0.1', 2207);
// Trigger $global->__isset('somedata') Query whether the server stores the value of key as somedata
isset($global->somedata);
// trigger $global->__set('somedata', array(1,2,3)), inform the server to store the value of somedata as array (1,2,3)
$global->somedata = array(1,2,3);
// Trigger $global->__get('somedata'), query the value corresponding to somedata from server
var_export($global->somedata);
// trigger $global->__unset('somedata'), notify the server to delete somedata and the corresponding value
unset($global->somedata);