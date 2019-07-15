<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\Redis;


/**
 * ext-redis-specific.
 */
class RedisTest extends Common {

	public function test_redis() {
		$testdir = self::tdir(__FILE__);
		$logfile = $testdir . '/zapstore-redis.log';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = self::open_config('redis');
		$red = new Redis($args, $logger);
		$this->eq()(
			$red->get_connection_params()['redistype'], 'redis');
	}

}
