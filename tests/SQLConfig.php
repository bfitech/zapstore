<?php


function prepare_config($engine=null) {
	$config_file = getcwd() . '/zapstore-test.config.json';
	if (file_exists($config_file)) {
		$args = json_decode(
			file_get_contents($config_file), true);
		if ($engine)
			return $args[$engine];
		return $args;
	}
	# connection parameter stub
	$args = [
		'sqlite3' => [
			'dbtype' => 'sqlite3',
			'dbname' => getcwd() . '/zapstore-test.sq3',
		],
		'pgsql' => [
			'dbtype' => 'pgsql',
			'dbname' => 'zapstore_test_db',
			'dbhost' => 'localhost',
			'dbuser' => 'postgres',
			'dbpass' => '',
		],
		'mysql' => [
			'dbtype' => 'mysql',
			'dbname' => 'zapstore_test_db',
			'dbhost' => '127.0.0.1',
			'dbuser' => 'root',
			'dbpass' => '',
		],
	];
	file_put_contents($config_file,
		json_encode($args, JSON_PRETTY_PRINT));
	if ($engine)
		return $args[$engine];
	return $args;
}

