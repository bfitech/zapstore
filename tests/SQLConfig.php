<?php

function prepare_config($engine=null, $config_file=null) {
	if (!$config_file)
		$config_file = getcwd() . '/zapstore-test.config.json';
	if (file_exists($config_file)) {
		$args = json_decode(
			file_get_contents($config_file), true);
		if ($engine)
			return $args[$engine];
		return $args;
	}
	# connection parameter stub
	$params = [
		'postgres_host' => 'localhost',
		'postgres_port' => 5432,
		'postgres_user' => 'postgres',
		'postgres_pass' => '',
		'postgres_db' => 'zapstore_test_db',

		'mysql_host' => '127.0.0.1',
		'mysql_port' => '',
		'mysql_user' => 'root',
		'mysql_pass' => '',
		'mysql_db' => 'zapstore_test_db',
	];
	foreach ($params as $key => $val) {
		$ukey = strtoupper($key);
		$var = getenv($ukey);
		if ($var)
			$params[$key] = $var;
	}
	extract($params);

	$args = [
		'sqlite3' => [
			'dbtype' => 'sqlite3',
			'dbname' => getcwd() . '/zapstore-test.sq3',
		],
		'pgsql' => [
			'dbtype' => 'pgsql',
			'dbhost' => $postgres_host,
			'dbport' => $postgres_port,
			'dbuser' => $postgres_user,
			'dbpass' => $postgres_pass,
			'dbname' => $postgres_db,
		],
		'mysql' => [
			'dbtype' => 'mysql',
			'dbhost' => $mysql_host,
			'dbport' => $mysql_port,
			'dbuser' => $mysql_user,
			'dbpass' => $mysql_pass,
			'dbname' => $mysql_db,
		],
	];

	file_put_contents($config_file,
		json_encode($args, JSON_PRETTY_PRINT));
	if ($engine)
		return $args[$engine];
	return $args;
}
