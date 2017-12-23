<?php
require_once __DIR__.'/../../../vendor/autoload.php';
$z = json_decode(trim(file_get_contents('z')), true);
$used = [];
foreach ($z as $jdata) {
	$parsed_url = parse_url($jdata['url']);
	unset($parsed_url['fragment']);
	$jdata['url'] = unparse_url($parsed_url);
	echo ' * [' . htmlspecialchars($jdata['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . "]($jdata['url'])\n";
}
