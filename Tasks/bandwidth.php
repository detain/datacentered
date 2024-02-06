<?php

use Workerman\Worker;

function bandwidth($args)
{
    global $worker_db, $influx_v2_database;
    $points = [];
    $veids = [];
    foreach ($args['content'] as $ip => $data) {
        if (!isset($data['vps'])) {
            Worker::safeEcho("[bandwidth Task] Missing VPS for ip {$ip}\n");
        }
        //Worker::SafeEcho("[bandwidth Task] Loaded VPS {$data['vps']} with Server {$args['uid']}\n");
        if (!isset($veids[$data['vps']])) {
            $veids[$data['vps']] = $worker_db->select('*')
                ->from('vps')
                ->where('vps_server=:vps_server and (vps_hostname=:hostname or vps_vzid=:vzid)')
                ->bindValues([
                    'vps_server'=>$args['uid'],
                    'hostname'=>$data['vps'],
                    'vzid'=>$data['vps']
                ])
                ->row();
            //Worker::SafeEcho("[bandwidth Task] Loaded VPS {$data['vps']} " : ".json_encode($veids[$data['vps']]).PHP_EOL);
        }
        $row = $veids[$data['vps']];
        if ($row !== false) {
            if (INFLUX_V2 === true) {
                /*$point = \InfluxDB2\Point::measurement('bandwidth')
                    ->addTag('vps', (int)$row['vps_id'])
                    ->addTag('host', (int)$args['uid'])
                    ->addTag('ip', $ip)
                    ->addField('in', (int)$data['in'])
                    ->addField('out', (int)$data['out'])
                    ->time(time());
                $influx_v2_database->write($point);*/
                $influx_v2_database->write('bandwidth,vps='.(int)$row['vps_id'].',host='.(int)$args['uid'].',ip='.$ip.' in='.(int)$data['in'].',out='.(int)$data['out']);
            }
        }
    }
    //Worker::safeEcho("[bandwidth Task] built up points ".json_encode($points).PHP_EOL);
    if (INFLUX_V2 === true) {
        $influx_v2_database->close();
    }
    return true;
}
