<?php


if (!defined('DEBUG'))
	define('DEBUG', false);

/**
 * die function wrapper
 */
function perish($str='') {
	if ($str != '')
		die((string)$str);
	die("R.I.P");
}

/**
 * json print
 *
 * Do not use $cache for XHR unless you know what you're doing.
 * Clearing the cache on client side will be such a pain.
 *
 * This can be previously declared e.g. for testing.
 */
if (!function_exists('pj')) {
function pj($errno=0, $data=[], $cache=0) {
	$js = json_encode(compact('errno', 'data'));
	@header("Content-Length: ".strlen($js));
	if (DEBUG)
		@header('Content-Type: text/html');
	else
		@header('Content-Type: application/json');
	if ($cache) {
		$age = (isset($_POST) && !empty($_POST)) ? 0 : 3600 * 24;
		_header(0, $age, 0, 200);
	} else
		_header(0, 0, 0, 200);
	die($js);
}
}

/**
 * header string
 */
function _header_string($code) {
	$header_str = [
		100 => 'Continue',
		101 => 'Switching Protocols',
		102 => 'Processing',
		200 => 'OK',
		201 => 'Created',
		202 => 'Accepted',
		203 => 'Non-Authoritative Information',
		204 => 'No Content',
		205 => 'Reset Content',
		206 => 'Partial Content',
		207 => 'Multi-Status',
		226 => 'IM Used',
		300 => 'Multiple Choices',
		301 => 'Moved Permanently',
		302 => 'Found',
		303 => 'See Other',
		304 => 'Not Modified',
		305 => 'Use Proxy',
		306 => 'Reserved',
		307 => 'Temporary Redirect',
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',
		408 => 'Request Timeout',
		409 => 'Conflict',
		410 => 'Gone',
		411 => 'Length Required',
		412 => 'Precondition Failed',
		413 => 'Request Entity Too Large',
		414 => 'Request-URI Too Long',
		415 => 'Unsupported Media Type',
		416 => 'Requested Range Not Satisfiable',
		417 => 'Expectation Failed',
		422 => 'Unprocessable Entity',
		423 => 'Locked',
		424 => 'Failed Dependency',
		426 => 'Upgrade Required',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Timeout',
		505 => 'HTTP Version Not Supported',
		506 => 'Variant Also Negotiates',
		507 => 'Insufficient Storage',
		510 => 'Not Extended'
	];
	if (!isset($header_str[$code]))
		$code = 404;
	return [
		'code' => $code,
		'msg'  => $header_str[$code]
	];
}

/**
 * send response headers or read a file
 *
 * @param string|false filename to read or false
 * @param int|false cache age or no cache at all
 * @param bool $echo if true and the file exists, print it and die
 * @param int $code HTTP code
 */
function _header($fname=0, $cache=0, $echo=1, $code=200, $disposition=false) {

	if ($fname && (!file_exists($fname) || is_dir($fname)))
		$code = 404;
		
	extract(_header_string($code));

	if (!defined('NOW'))
		define('NOW', time());
	
	@header("HTTP/1.0 $code $msg");
	if ($cache) {
		$cache = intval($cache);
		$expire = NOW + $cache;
		@header("Expires: ".gmdate("D, d M Y H:i:s",$expire)." GMT");
		@header("Cache-Control: must-revalidate");
	}
	else {
		@header("Expires: Mon, 27 Jul 1996 07:00:00 GMT");
		@header("Cache-Control: no-store, no-cache, must-revalidate");
		@header("Cache-Control: post-check=0, pre-check=0", false);
		@header("Pragma: no-cache");
	}
	@header("Last-Modified: ".gmdate("D, d M Y H:i:s")." GMT");
	@header("X-Powered-By: Bazinga!");

	if (!$echo)
		return;

	if ($code != 200)
		# echoing error page doesn't make sense; error pages must
		# always be generated and not cached
		die;
	
	$mime = _magic($fname);
	
	header("Content-Type: {$mime}");

	if ($disposition) {
		header(sprintf(
			'Content-Disposition: attachment; filename="%s"',
			basename($fname)));
	}

	$str = file_get_contents($fname);
	@header('Content-Length: '.strlen($str));

	echo $str;
	die;
}

/**
 * find a mime type using `file`; lazy, I know :D
 *
 * @param string $fname the file name
 * @return string the mime type or application/octet-stream
 */
function _magic($fname) {
	$pi = pathinfo($fname);
	if (isset($pi['extension'])) {
		// because these things are magically ambiguous, we'll
		// resort to extension
		$pe = strtolower($pi['extension']);
		if ($pe == 'css')
			return 'text/css';
		if ($pe == 'js')
			return 'application/x-javascript';
		if ($pe == 'json')
			return 'application/x-json';
		if ($pe == 'htm' || $pe == 'html')
			return 'text/html; charset=utf-8';
	}
	// default
	$mime = 'application/octet-stream';
	// using mime_content_type
	$check_mime = @mime_content_type($fname);
	if ($check_mime)
		$mime = $check_mime;
	return $mime;
}

