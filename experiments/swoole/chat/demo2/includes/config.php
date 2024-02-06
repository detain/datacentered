<?php
/**
 * config
 *
 *
 */
 
$config['server'] = [
    
    'main' => [
        'host' => '0.0.0.0', 'port' => 1950
    ],

    'login' => [
        'host' => '0.0.0.0', 'port' => 1952
    ],
];
    

$config['database'] = [
    
    'default' => [
        'type' => 'mysqli',
        'host'=> '127.0.0.1',
        'username' => 'root',
        'password' => '',
        'pconnect' => 0,
        'port' => 3306,
        'dbname' => 'test',
        'charset' => 'utf8',
        'tableprex' => '',
    ],
];

//return $config;
