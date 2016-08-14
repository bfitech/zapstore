<?php


/* Load configuration. */

// Check essential constants.
foreach (['ZAPCORE', 'ZAPAPP', 'ZAPDATA', 'ZAPLIB'] as $dir) {
	if (!defined($dir) || !is_dir(constant($dir)))
		die("ERROR: $dir not set or invalid.");
}
// Reset global _S.
if (isset($_S))
	unset($_S);


/* Load core, in order. */

foreach(['filter', 'header', 'db', 'request'] as $fn) {
	require(ZAPCORE . "/${fn}.php");
}

// We have abort() at this point. Register at shutdown
// in case of uncaught route.
register_shutdown_function('shutdown');


/* Load app. */

// Set authentication cookie name.
$auth_cookie = substr(md5(__FILE__), 3, 17);
if (defined('ZAPAUTH_COOKIE'))
	$auth_cookie = ZAPAUTH_COOKIE . $auth_cookie;
$_S['cookie_adm'] = $auth_cookie;


// Load common functions.
require(ZAPCORE . '/common.php');

// Load template functions. Monkey-patching default
// handlers happen here.
if (file_exists(ZAPAPP . '/template.php'))
	require(ZAPAPP . '/template.php');


/* Begin executions. */

// Load app route. Execute almost everything.
if (!file_exists(ZAPAPP . '/route.php'))
	die("ERROR: " . ZAPAPP . "/route.php not found.");
require(ZAPAPP . '/route.php');

// Done. Uncaught route will abort(404).
die();

