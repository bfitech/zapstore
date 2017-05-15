<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger as Logger;
use BFITech\ZapStore\RedisConn as ZapRedis;
use BFITech\ZapStore\RedisError as ZapRedisErr;


/**
 * Generic tests.
 *
 * This assumes all supported database drivers are installed.
 * Do not subclass this in redis-specific packages.
 */
class RedisConnGenericTest extends TestCase {

	public static $logger;

	public static function setUpBeforeClass() {
		$logfile = getcwd() . '/zapstore-redis-test.log';
		self::$logger = new Logger(Logger::DEBUG, $logfile);
	}

	public function test_constructor() {
		$args = [
			'redistype' => 'redis',
			'redishost' => '127.0.0.1',
			'redisport' => '6379'
		];
		try {
			$redis = new ZapRedis($args, self::$logger);
			$redis->close();
			$this->assertEquals($redis->get_connection(), null);
		} catch(ZapRedisErr $e) {
			$this->assertEquals($e->code,
				ZapRedisErr::CONNECTION_ERROR);
		}
	}

	public function test_exception() {
		$args = [
			'redisscheme' => 'tcp',
			'redistype' => 'predis',
			'redispass' => 'root',
			'redisdb' => 'redisdb',
			'redistimeout' => 5,
			'redishost' => '10.0.0.1'
		];

		try {
			$redis = new ZapRedis($args, self::$logger);
			$redis->close();
		} catch(ZapRedisErr $e) {
			$this->assertEquals($e->code,
				ZapRedisErr::CONNECTION_ERROR);
		}

		$args = [
			'redistype' => 'redis',
			'redishost' => '127.0.0.1'
		];
		try {
			$redis = new ZapRedis($args, self::$logger);
			$redis->close();
		} catch(ZapRedisErr $e) {
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

