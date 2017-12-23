$router = Aerys\router()
	->get('/', function (Aerys\Request $req, Aerys\Response $res) {
		$csrf = bin2hex(random_bytes(32));
		$res->setCookie("csrf", $csrf);
		$res->end('<form action="form" method="POST" action="?csrf=$csrf"><input type="submit" value="1" name="typ" /> or <input type="submit" value="2" name="typ" /></form>');
	})
	->post('/form', function (Aerys\Request $req, Aerys\Response $res) {
		$body = yield Aerys\parseBody($req);
		if ($body->getString("typ") == "2")
			$res->end('2 is the absolutely right choice.');
	}, function (Aerys\Request $req, Aerys\Response $res) {
		$res->setStatus(303);
		$res->setHeader("Location", "/form");
		$res->end(); # try removing this line to see why it is necessary
	})
	->get('/form', function (Aerys\Request $req, Aerys\Response $res) {
		# if this route would not exist, we'd get a 405 Method Not Allowed
		$res->end('1 is a bad choice! Try again <a href="/">here</a>');
	});

(new Aerys\Host)
	->use(function(Aerys\Request $req, Aerys\Response $res) {
		if ($req->getMethod() == "POST" && $req->getCookie("csrf") != $req->getParam("csrf")) {
			$res->setStatus(400);
			$res->end("<h1>Bad Request</h1><p>Invalid csrf token!</p>");
		}
	})
	->use($router)
	->use(Aerys\root('/path/to/docroot'))
	->use(function (Aerys\Request $req, Aerys\Response $res) {
		# if no response was started in the router (or no match found), we can have a custom 404 page here
		$res->setStatus(404);
		$res->end("<h1>Not found!</h1><p>There is no content at {$res->getUri()}</p>");
	});
