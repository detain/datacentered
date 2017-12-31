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


