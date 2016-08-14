<?php


/**
 * request functions
 *
 * Needs OOP so monkey-patching is possible. :/
 */


global $_S;

class Request {

	public $request_path = null;
	public $request_comp = [];
	public $request_routes = [];
	public $request_handled = false;

	public function __construct() {
		$this->_request_parse();
	}

	/**
	 * generic method 'overloading' caller
	 *
	 * Without this, methods added to instance can't be called.
	 */
	public function __call($method, $args) {
		if (isset($this->$method)) {
			$fn = $this->$method;
			return call_user_func_array($fn, $args);
		}
	}

	/**
	 * request parser
	 */
	private function _request_parse() {

		if ($this->request_path)
			return;

		if (!defined('HOME')) {
			$home = dirname($_SERVER['SCRIPT_NAME']);
			if ($home != '/')
				$home = rtrim($home, '/'). '/';
			define('HOME', $home);
		}

		if (!defined('HOST')) {
			$host = isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS'])
				? 'https://' : 'http://';
			$host.= $_SERVER['HTTP_HOST'];
			if (!in_array($_SERVER['SERVER_PORT'], [80, 443]))
				$host.= ':' . $_SERVER['SERVER_PORT'];
			$host.= HOME;
			define('HOST', $host);
		}

		// initialize from request uri
		$req = $_SERVER['REQUEST_URI'];
		// remove query string
		$req = preg_replace("!\?.+$!", '', $req);
		// remove script name
		$hom = "/^" . str_replace("/", "\\/", quotemeta(HOME)) . "/";
		$req = preg_replace($hom, '', $req);
		// trim slashes
		$req = trim($req, "/");

		$this->request_path = $req;
		$this->request_comp = explode('/', $req);

	}

	private function _route_parse($valid=true, $params=[], $frac, $reqs) {

		// fraction and request run out
		if (!$frac && !$reqs)
			return [$valid, $params, $frac, $reqs];
		if (!isset($frac[0]) && !isset($reqs[0]))
			return [$valid, $params, $frac, $reqs];

		// either fraction or request runs out
		if (!isset($frac[0]) && isset($reqs[0]))
			return [false];
		if (isset($frac[0]) && !isset($reqs[0]))
			return [false];

		$key = $frac[0];
		if ($key[0] == '<' && $key[strlen($key)-1] == '>') {
			// parse <key> to $params
			$key = substr($key, 1, strlen($key) - 2);
			$params[$key] = $reqs[0];
		} else {
			// fraction and request don't match
			if ($key != $reqs[0])
				return [false];
		}

		// shift
		array_shift($frac);
		array_shift($reqs);

		// recurse
		return $this->_route_parse(true, $params, $frac, $reqs);
	}

	public function route($path, $callback, $method='GET') {

		if ($this->request_handled)
			// if request is already handled, stop processing
			return;

		// begin selector

		/* route */

		# path is empty
		if (!$path || $path[0] != '/')
			return;
		# ignore trailing slash
		$path = rtrim($path, '/');

		# init variables
		$arg = [];
		$arg['params'] = [];

		# parse URL
		$frac = explode('/', substr($path, 1, strlen($path)));
		$reqs = explode('/', $this->request_path);
		if ($frac != $reqs) {
			if (!$frac[0])
				return;
			$route = $this->_route_parse(true, [], $frac, $reqs);
			# path doesn't match
			if ($route[0] === false)
				return;
			$arg['params'] = $route[1];
		}

		# process route only once
		if (in_array($path, $this->request_routes))
			return;
		$this->request_routes[] = $path;

		// begin validator

		/* method */

		$request_method = $_SERVER['REQUEST_METHOD']
			? $_SERVER['REQUEST_METHOD'] : 'GET';
		# always allow HEAD
		if (is_array($method))
			$method[] = 'HEAD';
		else
			$method = ['HEAD', $method];
		$method = array_unique($method);
		if (!in_array($request_method, $method))
			return $this->abort(501);

		/* callback */

		if (is_string($callback)) {
			if (!function_exists($callback))
				return $this->abort(501);
		} elseif (!is_object($callback)) {
			return $this->abort(501);
		}

		/* http vars */

		$arg['get'] = $_GET;
		$arg['post'] = $_POST;
		$arg['files'] = [];
		$arg['put'] = null;
		$arg['delete'] = null;
		$arg['cookie'] = $_COOKIE;

		/* method */

		if (in_array($request_method, ['HEAD', 'GET'])) {
			# HEAD, GET
			$this->request_handled = 1;
			return $callback($arg);
		}
		if ($request_method == 'POST') {
			# POST, FILES
			if (isset($_FILES) && !empty($_FILES))
				$arg['files'] = $_FILES;
		} elseif ($request_method == 'PUT') {
			# PUT
			$arg['put'] = file_get_contents("php://input");
		} elseif ($request_method == 'DELETE') {
			# DELETE
			$arg['delete'] = file_get_contents("php://input");
		} else {
			return $this->abort(501);
		}
		$arg['method'] = $request_method;

		// call callback

		$this->request_handled = 1;
		return $callback($arg);

	}

	private function _abort_default($code) {
		extract(_header_string($code));
		_header(0, 0, 0, $code);
		$html = <<<EOD
<!doctype html>
<html>
	<head>
		<meta charset='utf-8'/>
		<title>%s %s</title>
		<style type="text/css">
			body {background-color: #eee; font-family: sans;}
			div  {background-color: #fff; border: 1px solid #ddd;
				  padding: 25px; max-width:800px;
				  margin:20vh auto 0 auto; text-align:center;}
		</style>
	</head>
	<body>
		<div>
			<h1>%s %s</h1>
			<p>The URL <tt>&#039;<a href='%s'>%s</a>&#039;</tt>
			   caused an error.</p>
		</div>
	</body>
</html>
EOD;
		$uri = $_SERVER['REQUEST_URI'];
		printf($html, $code, $msg, $code, $msg, $uri, $uri);

		die();
	}

	/**
	 * Abort method.
	 *
	 * This returns something instead of just die(), to allow
	 * ad-hoc $this->abort_custom() be processed, e.g. in unit tests.
	 *
	 * WARNING: Add die() to the end of applied abort_custom() in
	 * real application just to make sure script ends as expected.
	 */
	public function abort($code) {
		$this->request_handled = true;
		if (!isset($this->abort_custom)) {
			$this->_abort_default($code);
			die();
		}
		return $this->abort_custom($code);
	}

	public function redirect_default($destination) {
		extract(_header_string(301));
		_header(0, 0, 0, $code);
		@header("Location: $destination");
		$html = <<<EOD
<!doctype html>
<html>
	<head>
		<meta charset='utf-8'/>
		<title>%s %s</title>
		<style type="text/css">
			body {background-color: #eee; font-family: sans;}
			div  {background-color: #fff; border: 1px solid #ddd;
				  padding: 25px; max-width:800px;
				  margin:20vh auto 0 auto; text-align:center;}
		</style>
	</head>
	<body>
		<div>
			<h1>%s %s</h1>
			<p>See <tt>&#039;<a href='%s'>%s</a>&#039;</tt>.</p>
		</div>
	</body>
</html>
EOD;
		printf($html, $code, $msg, $code, $msg,
			$destination, $destination);
		die();
	}

	/**
	 * Redirect method.
	 *
	 * This returns something instead of just die(), to allow
	 * ad-hoc $this->redirect_custom() be processed, e.g. in 
	 * unit tests.
	 *
	 * For normal web usage, just leave $this->redirect_custom()
	 * unset so that the default is used.
	 */
	public function redirect($destination) {
		$this->request_handled = true;
		if (!isset($this->redirect_custom))
			$this->redirect_default($destination);
		return $this->redirect_custom($destination);
	}

	/* static file */

	public function static_file_default($path, $disposition=false) {
		if (file_exists($path))
			_header($path, 3600, 1, 200, $disposition);
		$this->abort(404);
	}

	public function static_file($path, $disposition=false) {
		if (!isset($this->static_file_custom))
			return $this->static_file_default($path, $disposition);
		return $this->static_file_custom($path, $disposition);
	}

	/* shutdown function */

	public function shutdown() {
		global $_S;
		if ($this->request_handled)
			return;
		$this->abort(404);
	}

}


// store request globally 

if (!isset($_S['request']))
	$_S['request'] = new Request();

// procedural aliases

function get_request_path() {
	global $_S;
	return $_S['request']->request_path;
}

function get_request_comp($index=null) {
	global $_S;
	$comp = $_S['request']->request_comp;
	if ($index === null)
		return $comp;
	if (isset($comp[$index]))
		return $comp[$index];
	return null;
}

function route($path, $callback, $method='GET') {
	global $_S;
	$_S['request']->route($path, $callback, $method);
}

function static_file($path, $disposition=false) {
	global $_S;
	$_S['request']->static_file($path, $disposition);
}

function redirect($destination) {
	global $_S;
	return $_S['request']->redirect($destination);
}

function abort($code) {
	global $_S;
	return $_S['request']->abort($code);
}

function shutdown() {
	global $_S;
	$_S['request']->shutdown();
}

