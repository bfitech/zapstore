<?php


function prepare_config_redis($engine=null, $config_file=null) {
	if (!$config_file)
		$config_file = __DIR__ . '/zapstore-redis.json';
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
