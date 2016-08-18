<?php


if (DEBUG) {
	@ini_set('display_errors', 1);
	@ini_set('display_startup_errors', 1);
	@error_reporting(E_ALL);
}


/* composer */
require_once(ZAPLIB . '/vendor/autoload.php');

/* locales */

// This at least prevents escapeshellarg() from stripping 
// non-ASCII chars.
// http://php.net/manual/en/function.escapeshellarg.php#99213
setlocale(LC_CTYPE, "en_US.UTF-8");


/**
 * Check integrity of a key-value pair arrays.
 *
 * @params array $data A dict to verify.
 * @params array $keys A list of keys the dict must have.
 * @returns bool|dict False for failing regular test, verified
 *     dict for successful test.
 */
function _check_data($data, $keys) {
	$keys = (array)$keys;
	foreach ($keys as $key) {
		if (!isset($data[$key]) || trim($data[$key]) == '') {
			return false;
		}
		$data[$key] = trim($data[$key]);
	}
	return $data;
}


/**
 * Connection for site database.
 */
function dbsite() {
	global $_S;
	if (isset($_S['dbsite']))
		return $_S['dbsite'];
	$path = (defined(DBSITE) && DBSITE)
		? DBSITE : ZAPDATA . '/site.sq3';
	$qc = new db([
		'dbtype' => 'sqlite3', 
		'dbname' => $path,
	]);
	$qc->open();
	return $S_['dbsite'] = $qc;
}


/**
 * Check authentication for administration.
 */
function get_auth_adm() {
	global $_S;

	if (isset($_S['udata_adm']))
		return $_S['udata_adm'];

	$cname = $_S['cookie_adm'];
	if (!isset($_COOKIE[$cname]))
		return $_S['udata_adm'] = [];
	$token = $_COOKIE[$cname];

	// TODO: Cache with redis.
	$qc = dbsite();
	$sess = $qc->query(
		"SELECT * FROM v_usess " .
		"WHERE token=? AND expire>datetime('now') " .
		"LIMIT 1",
		[$token]);
	if (!$sess)
		return $_S['udata_adm'] = [];

	return $_S['udata_adm'] = $sess;
}

/* Generic URL formatter */

function _urlfmt($pfx, $loc) {
	$pfx = rtrim($pfx, '/');
	$loc = ltrim($loc, '/');

	// find components
	preg_match('!^([^\?]+)(\?[^#]+)?(#.+)?$!sU', $loc, $res);
	# path
	$path = $res[1];
	# query string
	if (isset($res[2]) || strpos($loc, '?') === 0) {
		$qs = substr($res[2], 1, strlen($res[2]));
		parse_str($qs, $qsa);
	} else {
		$qsa = [];
	}
	# hash
	$hash = isset($res[3]) ? $res[3] : '';

	// no-cache switch when applies
	if (DEBUG || get_auth_adm()) {
		// for js and css only
		if (preg_match('!\.(js|css)$!i', $path))
			$qsa['nc'] = time();
	}

	// assemble
	$url = $pfx . '/' . $path;
	if ($qsa)
		$url.= '?' . http_build_query($qsa);
	if ($hash)
		$url.= $hash;
	return $url;

}

/**
 * Format a URL and return it.
 */
function _ur($path='') {
	if (!$path)
		return HOME;
	return _urlfmt(HOME, $path);
}

/**
 * Format a URL and echo it.
 */
function _ue($path='') {
	echo _ur($path);
}


/* URL formatter for views. */

/**
 * Generate view URL and return it.
 */
function _vr($handler, $path='') {
	$path = ltrim($path, '/');
	if (!$path)
		return HOME . 'v/';
	return _urlfmt(HOME . "v/${handler}/", $path);
}
/**
 * Generate view URL and echo it.
 */
function _ve($handler, $path='') {
	echo _vr($handler, $path);
}

/* URL formatter for 'true' static files, i.e. files that doesn't
 * need to pass interpreter, just plain webserver.
 */
function _sr($path='') {
	$url = rtrim(PFX_STATIC, '/');
	$url.= '/' . $path;
	return $url;
}

function _se($path='') {
	echo _sr($path);
}

/* filesystem cache */

function fs_cache_prepare() {
	if (!defined('PAGE_CACHE_INTERVAL'))
		define('PAGE_CACHE_INTERVAL', null);
	if (!in_array(PAGE_CACHE_INTERVAL, ['minute', 'hour', 'day']))
		return false;

	$stmp_fmt = 'Ymd';
	if (PAGE_CACHE_INTERVAL == 'hour')
		$stmp_fmt .= 'H';
	if (PAGE_CACHE_INTERVAL == 'minute')
		$stmp_fmt .= 'Hi';

	$cache_dir = ZAPDATA . '/page-cache';
	if (!is_dir($cache_dir)) {
		@mkdir($cache_dir);
		@chmod($cache_dir, 0775);
	}
	if (!is_dir($cache_dir))
		return false;
	if (!is_writable($cache_dir))
		return false;
	$stmp = date($stmp_fmt);
	return [
		'dir'  => $cache_dir,
		'stmp' => $stmp,
	];
}
function fs_cache_read($cache_hash, $type='html') {
	if (get_auth_adm())
		// don't read cache when editing
		return false;
	if (false === $cache_env = fs_cache_prepare())
		return false;
	$cache_fln = sprintf('%s/%s-%s.%s',
		$cache_env['dir'], $cache_hash, $cache_env['stmp'], $type);
	if (!file_exists($cache_fln))
		return false;
	_header($cache_fln, 3600);
}

function fs_cache_write($cache_hash, $content, $type='html') {
	// keep writing cache when editing
	if (false === $cache_env = fs_cache_prepare())
		return;
	$cache_base = sprintf('%s/%s', $cache_env['dir'], $cache_hash);
	$ls = glob($cache_base . '*.' . $type);
	foreach ($ls as $l)
		@unlink($l);
	$cache_fln = sprintf('%s-%s.%s',
		$cache_base, $cache_env['stmp'], $type);
	@file_put_contents($cache_fln, $content);
	@chmod($cache_fln, 0666);
}

function fs_cache_wipe() {
	// wipe all files in page cache directory
	if (!fs_cache_prepare())
		return;
	foreach (glob(ZAPDATA . '/page-cache/*') as $fn) {
		if (is_file($fn))
			@unlink($fn);
	}
}

/* minifier */

function minify($key, $src) {
	if (!defined('HTTP_FILTER_URL'))
		return $src;
	$ctx = stream_context_create([
		'http' => [
			'method'  => 'POST',
			'header'  => 'Content-type: application/x-www-form-urlencoded',
			'content' => http_build_query([$key => $src]),
			'timeout' => 3,
		],
	]);
	$result = @file_get_contents(HTTP_FILTER_URL, false, $ctx);
	if (!$result)
		return $src;
	if ($key == 'html')
		return preg_replace('!</body></html>$!', '', $result);
	if (in_array($key, ['css', 'js'])) {
		if (defined('COPYRIGHT') && COPYRIGHT) {
			$result = sprintf("/* %s - %s */\n%s", COPYRIGHT, date('Y'), $result);
		}
		return $result;
	}
	return $src;
}

/* monkey-path static file */

function __static_file__($rpath, $disposition=false) {

	if (!file_exists($rpath))
		return abort(404);

	// no cache switch
	if (isset($_GET['nc']))
		return _header($rpath, 3600);

	if (preg_match('!\.css$!i', $rpath)) {
		// CSS
		$type = 'css';
		@header('Content-Type: text/css');
	} elseif (preg_match('!\.js$!i', $rpath)) {
		// JS
		$type = 'js';
		@header('Content-Type: application/javascript');
	} elseif (preg_match('!\.html?$!i', $rpath)) {
		// HTML
		$type = 'html';
		@header('Content-Type: text/html; charset=utf-8');
	} else {
		// others, no filter, no cache
		_header($rpath, 3600, 1, 200, $disposition);
	}

	$hash = md5($rpath);
	$content = file_get_contents($rpath);
	$content = minify($type, $content);
	if (fs_cache_read($hash, $type))
		return;
	fs_cache_write($hash, $content, $type);
	die($content);
}
$_S['request']->static_file_custom = '__static_file__';

/**
 * Request handler.
 *
 * @params string $case Which handler to use. This must correspond to a script
 *     ZAPPAPP/handler/$case.php. The script must have a function '_'.$case
 *     for 'autoloading'.
 * @params array $args Arguments gathered by route().
 */
function handle($case, $args) {

	// find corresponding script for each route or abort
	$scr = ZAPAPP . '/handler/' . $case . '.php';
	if (!file_exists($scr))
		return abort(404);
	require_once($scr);
	$fn = '_' . $case;
	if (!function_exists($fn))
		return abort(404);
	$fn($args);
	die();
}

/**
 * Load view. 
 *
 * This require()s PHP template and just die. The template is just regular PHP
 * scripts mostly containing HTML, e.g. semi-static files for single page
 * applications.
 *
 * @params string $handler Handler type as determined in route.php which
 *     coincides with dirname of the view.
 * @params string $path Basename of the view. Defaults to index.html.php.
 * @params bool $echo Will print if set to true, otherwise this return
 *     the buffer as a string.
 */
function view($handler, $path='index.html', $echo=true) {

	$rpath = ZAPAPP . '/view/' . $handler . '/' . $path;
	if (!file_exists($rpath))
		$rpath.= '.php';

	// Echo the view and exit.
	if ($echo) {
		if (!file_exists($rpath))
			return abort(404);
		_header(0, 3600, 0, 200);
		@header('Content-Type: text/html; charset=utf-8');
		ob_start();
		require($rpath);
		die(minify('html', ob_get_clean()));
	}

	// Return buffer.
	if (!file_exists($rpath))
		return '';
	ob_start();
	require($rpath);
	// Do not minify just yet as the return usually will be processed later
	// after whole document is assembled.
	return ob_get_clean();
}

