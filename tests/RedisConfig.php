<?php


function prepare_config_redis($engine=null) {
	$config_file = getcwd() .
		'/zapstore-redis-test.config.json';
	if (file_exists($config_file)) {
		$args = json_decode(
			file_get_contents($config_file), true);
		if ($engine)
			return $args[$engine];
		return $args;
	}
	# connection parameter stub
	$args = [
		'redis' => [
			'redistype' => 'redis',
			'redishost' => '127.0.0.1',
			'redisport' => '6379',
			'redispassword' => 'xoxo',
			'redisdatabase' => 10,
		],
		'predis' => [
			'redistype' => 'predis',
			'redishost' => '127.0.0.1',
			'redisport' => '6379',
			'redispassword' => 'xoxo',
			'redisdatabase' => 10,
		],
	];
	if ($engine) {
		foreach ($args as $key => $_) {
			if ($key != $engine)
				unset($args[$key]);
		}
	}
	file_put_contents($config_file,
		json_encode($args, JSON_PRETTY_PRINT));
	if ($engine)
		return $args[$engine];
	return $args;
}

