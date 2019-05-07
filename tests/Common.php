<?php


function testdir() {
	$dir = __DIR__ . '/testdata';
	if (!is_dir($dir))
		mkdir($dir, 0755);
	return $dir;
}

function prepare_config_sql($engine=null, $config_file=null) {
	if (!$config_file)
		$config_file = testdir() . '/zapstore-sql.json';
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
		'POSTGRES_PASS' => 'XxXxXxXxX',
		'POSTGRES_DB' => 'zapstore_test_db',

		'MYSQL_HOST' => '127.0.0.1',
		'MYSQL_PORT' => '3306',
		'MYSQL_USER' => 'root',
		'MYSQL_PASS' => 'XxXxXxXxX',
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

function prepare_config_redis($engine=null, $config_file=null) {
	if (!$config_file)
		$config_file = testdir() . '/zapstore-redis.json';
	if (file_exists($config_file)) {
		$args = json_decode(
			file_get_contents($config_file), true);
		if ($engine)
			return $args[$engine];
		return $args;
	}
	# connection parameter stub
	$params = [
		'redishost' => 'localhost',
		'redisport' => 6379,
		'redispassword' => 'xoxo',
		'redisdatabase' => 10,
	];
	foreach ($params as $key => $val) {
		$ukey = strtoupper($key);
		$var = getenv($ukey);
		if ($var)
			$params[$key] = $var;
	}
	extract($params);

	$args = [
		'redis' => $params,
		'predis' => $params,
	];
	$args['redis']['redistype'] = 'redis';
	$args['predis']['redistype'] = 'predis';

	file_put_contents($config_file,
		json_encode($args, JSON_PRETTY_PRINT));
	if ($engine)
		return $args[$engine];
	return $args;
}
