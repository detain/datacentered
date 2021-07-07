<?php

use Workerman\Worker;

//Worker::safeEcho('running zonemta insert query for '.$_GET['table'].PHP_EOL);
//return;

/**
* @var {\Workerman\MySQL\Connection}
*/
global $mysql_db;
global $_GET, $_POST;
//error_log('Logger called for table '.$_GET['table']);
if (!isset($_GET['table'])) {
	Worker::safeEcho("logger url missing Table Data\n");
	return;
}
/* Generated with:
for t in logentry messagestore senderdelivered; do
  echo "'$t' => ['$(mysqldump -d zonemta mail_$t 2>/dev/null|grep "^  \`"|cut -d\` -f2|tr "\n" " "|sed -e s#" *$"#""#g -e s#" "#"','"#g)'],";
done */
$tableFields = [
	'logentry' => ['_id','id','seq','action','category','zone','from','returnPath','to','mx','host','ip','response','size','timer','start','reason','result','score','tests'],
	'messagestore' => ['_id','id','interface','from','to','origin','originhost','transhost','transtype','user','time','messageId','date','sendingZone','bodySize','sourceMd5','doc'],
	'senderdelivered' => ['_id','id','seq','domain','sendingZone','recipient','locked','lockTime','assigned','queued','created','_lock','interface','from','to','origin','originhost','transhost','transtype','user','time','messageId','date','bodySize','sourceMd5','logger','mxPort','connectionKey','localAddress','localHostname','localPort','mxHostname','sentBodyHash','sentBodySize','md5Match','poolDisabled','fbl','doc'],
];
$table = $_GET['table'];
$unsetFields = ['tls', 'parsedEnvelope', 'disabledAddresses', 'envelope', 'dnsOptions', 'dkim'];
$foldFields = ['from', 'to'];
$post = json_decode($_POST['data'], true);
$out = [];
$doc = [];
$fieldCharLimits = ['to' => 300, 'from' => 300];
//if ($table == 'senderdelivered')
//	return;
if ($table == 'senderdelivered') {
	if (isset($post['spam'])) {
		$post['spam']['score'] = $post['spam']['default']['score'];
		unset($post['spam']['default']);
	}
	if (isset($post['headers'])) {
		$lines = [];
		foreach ($post['headers']['lines'] as $line) {
			$lines[] = $line['line'];
		}
		//$post['headers']['lines'] = $lines;
		//unset($post['headers']['libmime']);
		$post['headers'] = implode(PHP_EOL, $lines);
	}
}
if ($table == 'messagestore') {
	foreach ($fieldCharLimits as $field => $limit) {
		if (isset($post[$field])) {
			if (is_array($post[$field])) {
				foreach ($post[$field] as $fieldKey => $fieldValue) {
					if (!is_array($fieldValue) && mb_strlen($fieldValue) > $limit) {
						$post[$field][$fieldKey] = mb_substr($fieldValue, -$limit);
					}
				}
			} elseif (mb_strlen($post[$field]) > $limit) {
				$post[$field] = mb_substr($post[$field], -$limit);
			}
		}
	}
}
foreach ($post as $field => $data) {
	if (!in_array($field, $unsetFields) && ($table != 'messagestore' || $field != 'dkim')) {
		if (in_array($field, $foldFields)) {
			if (is_array($data)) {
				$data = implode(',', $data);
			}
			$out[$field] = $data;
		} else {
			if (!in_array($field, $tableFields[$table]) || is_array($data)) {
				$doc[$field] = $post[$field];
			} else {
				$out[$field] = $data;
			}
		}
	}
}
if (isset($out['time'])) {
	$out['time'] = floor((int)$out['time'] / 1000);
}
if (count($doc) > 0) {
	if ($table != 'senderdelivered') {
		$out['doc'] = json_encode($doc);
	}
}
$insertId = $mysql_db
	->insert('mail_'.$table)
	->cols($out)
	->query();
if (count($doc) > 0) {
	if ($table == 'senderdelivered') {
		$extra = [
			'senderdelivered_id' => $insertId,
			'doc' => json_encode($doc)
		];
		$insertExtraId = $mysql_db
			->insert('mail_'.$table.'_extra')
			->cols($extra)
			->query();
	}
}

