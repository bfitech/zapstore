<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\Redis;


/**
 * ext-redis-specific.
 */
class RedisTest extends Common {

	public function test_redis() {
		$logfile = self::tdir(__FILE__) . '/zapstore-redis.log';
		$logger = new Logger(Logger::ERROR, $logfile);
		$params = self::open_config('redis');
		$red = new Redis($params, $logger);
		self::eq()(
			$red->get_connection_params()['redistype'], 'redis');
	}

}
