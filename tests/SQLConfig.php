<?php


function prepare_config($engine=null, $config_file=null) {
	if (!$config_file)
		$config_file = __DIR__ . '/zapstore-sql.json';
	if (file_exists($config_file)) {
		$args = json_decode(
			file_get_contents($config_file), true);
		if ($engine)
			return $args[$engine];
		return $args;
	}
	# connection parameters stub
	$params = [
		'POSTGRES_HOST' => 'localhost',
		'POSTGRES_PORT' => 5432,
		'POSTGRES_USER' => 'postgres',
		'POSTGRES_PASS' => '',
		'POSTGRES_DB' => 'zapstore_test_db',

		'MYSQL_HOST' => '127.0.0.1',
		'MYSQL_PORT' => '3306',
		'MYSQL_USER' => 'root',
		'MYSQL_PASS' => '',
		'MYSQL_DB' => 'zapstore_test_db',
	];
	foreach ($params as $key => $val) {
		$var = getenv($key);
		if ($var)
			$params[$key] = $var;
	}
	extract($params);

	$args = [
		'sqlite3' => [
			'dbtype' => 'sqlite3',
			'dbname' => dirname($config_file) . '/zapstore.sq3',
		],
		'pgsql' => [
			'dbtype' => 'pgsql',
			'dbhost' => $POSTGRES_HOST,
			'dbport' => $POSTGRES_PORT,
			'dbuser' => $POSTGRES_USER,
			'dbpass' => $POSTGRES_PASS,
			'dbname' => $POSTGRES_DB,
		],
		'mysql' => [
			'dbtype' => 'mysql',
			'dbhost' => $MYSQL_HOST,
			'dbport' => $MYSQL_PORT,
			'dbuser' => $MYSQL_USER,
			'dbpass' => $MYSQL_PASS,
			'dbname' => $MYSQL_DB,
		],
	];

	file_put_contents($config_file,
		json_encode($args, JSON_PRETTY_PRINT));
	if ($engine)
		return $args[$engine];
	return $args;
}
