<?php

/**
 * filter functions
 */

/**
 * Dump a variable. For debugging purposes only.
 *
 * @param mixed $param
 *
 */
function deb($str) {
	echo "<pre>";
	ob_start('htmlspecialchars');
	print_r($str);
	ob_end_flush();
	echo "</pre>";
}

/**
 * Start that timer
 */
function tstart() {
	global $_S;
	if (isset($_S['timer_start']))
		return;
	$mtime = microtime();
	$mtime = explode(' ',$mtime);
	$_S['timer_start'] = $mtime[0] + $mtime[1];
}
/**
 * stop that timer
 *
 * @param bool $echo not false if the output should be printed
 * @return int the seconds elapsed so far in formatted float
 */
function tstop($echo=0) {
	global $_S;
	if (!isset($_S['timer_start']))
		return;
	$mtime = microtime();
	$mtime = explode (' ',$mtime);
	$mtime = $mtime[0] + $mtime[1];
	$rtime = $mtime - $_S['timer_start'];
	$total = sprintf("%1.3f",$rtime);
	if (!$echo)
		return $total;
	else
		echo $total;
}

/**
 * recursive slash stripper
 *
 * @param string|array $arg
 * @return string|array arguments with slashes stripped off
 */
function clnslash($arg) {
	return is_array($arg)
		? array_map('clnslash', $arg)
		: stripslashes($arg);
}

/**
 * recursive slash add
 *
 * @param string|array $arg
 * @return string|array arguments with slashes added
 */
function addslash($arg) {
	return is_array($arg)
		? array_map('addslash', $arg)
		: addslashes($arg);
}

/**
 * backslash-to-slash converter
 *
 * @param string $str
 * @return string the sloshes are no longer there
 */
function slosh($str) {
	return str_replace("\\", '/', $str);
}

/**
 * recursive desloshing glob
 *
 * @param array $paths
 * @return array paths with sloshes converted
 */
function globr($paths) {
	$ls = glob($paths);
	return is_array($ls) ? array_map('slosh',$ls) : $ls;
}

/**
 * recursive trimming
 *
 * @param string|array $arg
 * @param string $char character to trim, default to white space
 * @return string|array trimmed data
 */
function atrim($arg, $char='') {
	if ($char != '')
		return is_array($arg)
			? array_map('atrim', $arg, $char)
			: trim($arg, $char);
	return is_array($arg)
		? array_map('atrim', $arg)
		: trim($arg);
}

/**
 * html special characters escape
 *
 * It's htmlspecialchars() with single quotes always be converted.
 *
 * @param string $str
 * @return string escaped string safe enough to put in anchors
 */
function hs($str) {
	return htmlspecialchars($str, ENT_QUOTES);
}

/**
 * shorthand for strip_tags
 *
 * @param string $str
 * @return string
 */
function st($str) {
	return strip_tags($str);
}

/**
 * removal of all non-alphanumeric characters
 *
 * @param string $str
 * @return string alphanumeric string
 */
function alnum($str) {
	return preg_replace("![^a-z0-9]!i",'',$str);
}

/**
 * change all \s to single white space
 *
 * @param string $str
 * @return string single-lined string
 */
function flat($str) {
	$str = preg_replace("![\r\n\t]+!",' ',$str);
	$str = preg_replace("! +!",' ',$str);
	return $str;
}

/**
 * shorthand for trim() and flat()
 */
function trimflat($str) {
	$str = flat($str);
	$str = trim($str);
	return $str;
}

/**
 * verify isodate
 *
 * WARNING: This relies on PHP>=5.2.6. This also doesn't set time
 * zone to UTC.
 *
 * @param string $str Isodate input (Y-m-d\TH:i:sO).
 * @return bool|string False on failure, utc isodate on success.
 */
function _isodate($str) {
	try {
		$dt = new DateTime($str);
		return $dt->format(DATE_ISO8601);
	}
	catch(Exception $e) {
		return false;
	}
}

/**
 * SQL escape using Postgres
 *
 * @param string $str String to escape.
 * @return string Escaped string.
 */
function qs($str) {
	return pg_escape_string((string)$str);
}

/**
 * Filter any path to slug.
 *
 * @param string $path Path to escape.
 * @return string Escaped path.
 */
function slug($path) {
	$slug = str_replace(':', ' - ', $path);
	$slug = preg_replace("![^a-z0-9\-_\. ]!smU", '', $slug);
	$slug = preg_replace("! +!", ' ', trim($slug));
	return $slug;
}

