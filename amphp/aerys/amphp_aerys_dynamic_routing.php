<?php
$composer = require_once __DIR__.'/../../vendor/autoload.php';
$router = Aerys\router()
	->get('/foo/?', function (Aerys\Request $req, Aerys\Response $res) {
		# This just works for trailing slashes
		$res->end('You got here by either requesting /foo or redirected here from /foo/ to /foo.');
	})
	->get('/user/{name}/{id:[0-9]+}', function (Aerys\Request $req, Aerys\Response $res, array $route) {
		# matched by e.g. /user/rdlowrey/42
		# but not by /user/bwoebi/foo (note the regex requiring digits)
		$res->end("The user with name {$route['name']} and id {$route['id']} has been requested!");
	});

(new Aerys\Host)->use($router);
