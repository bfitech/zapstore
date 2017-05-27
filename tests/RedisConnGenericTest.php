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
			new ZapRedis($args, self::$logger);
		} catch(ZapRedisErr $e) {
			$this->assertEquals($e->code,
				ZapRedisErr::CONNECTION_ERROR);
		}
	}

	private function invoke_exception($type) {
		$config_file = getcwd() .
			'/zapstore-redis-test.config.json';
		if (file_exists($config_file)) {
			$args = json_decode(file_get_contents(
				$config_file), true)[$type];
		} else {
			$args = [
				'redistype' => $type,
				'redishost' => '127.0.0.1',
				'redisport' => '6379',
			];
		}

		# invalid database
		$args['redisdatabase'] = -1000;
		try {
			new ZapRedis($args, self::$logger);
		} catch(ZapRedisErr $e) {
			$this->assertEquals($e->code,
				ZapRedisErr::CONNECTION_ERROR);
		}

		# invalid password
		$args['redisdatabase'] = 10;
		$args['redispassword'] = 'xoxox';
		try {
			new ZapRedis($args, self::$logger);
		} catch(ZapRedisErr $e) {
			$this->assertEquals($e->code,
				ZapRedisErr::CONNECTION_ERROR);
		}

		# valid
		$args['redispassword'] = 'xoxo';
		$redis = new ZapRedis($args, self::$logger);
		$redis->close();
		$this->assertEquals($redis->get_connection(), null);

	}

	public function test_exception() {
		foreach (['redis', 'predis'] as $type) {
			$this->invoke_exception($type);
		}
	}

	public function test_connection_parameters() {
		$args = ['redishost' => 'localhost'];
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

