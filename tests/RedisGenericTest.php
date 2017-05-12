<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger as Logger;
use BFITech\ZapStore\Redis as ZapRedis;
use BFITech\ZapStore\RedisError as ZapRedisErr;

/**
 * Generic tests.
 *
 * This assumes all supported database drivers are installed.
 * Do not subclass this in redis-specific packages.
 */
class RedisGenericTest extends TestCase {

	public static $logger;

	public static function setUpBeforeClass() {
		$logfile = getcwd() . '/zapstore-redis-test.log';
		self::$logger = new Logger(Logger::DEBUG, $logfile);
	}

	public function test_exception() {
		$args = [
			'redistype' => 'predis', 
			'redishost' => '10.0.0.1'
		];

		try {
			$redis = new ZapRedis($args, self::$logger);
			$redis->close();
		} catch(SQLError $e) {
			$this->assertEquals($e->code,
				ZapRedisErr::CONNECTION_ERROR);
		}
	}

	public function test_connection_parameters() {
		$args = ['redistype' => 'predis'];
		try {
			$sql = new ZapRedis($args, self::$logger);
		} catch(ZapRedisErr $e) {
			$this->assertEquals($e->code,
				ZapRedisErr::CONNECTION_ARGS_ERROR);
		}

		$args['redishost'] = '127.0.0.1';
		$args['redistype'] = 'sqlite';
		try {
			$sql = new ZapRedis($args, self::$logger);
		} catch(ZapRedisErr $e) {
			$this->assertEquals($e->code,
				ZapRedisErr::REDISTYPE_ERROR);
		}
	}
}