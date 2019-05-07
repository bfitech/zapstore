<?php


require_once __DIR__ . '/Common.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\RedisConn as ZapRedis;
use BFITech\ZapStore\RedisError as ZapRedisErr;


/**
 * Generic tests.
 *
 * This assumes all supported database drivers are installed
 * and test connection is set. Do not subclass this in redis-specific
 * packages.
 */
class RedisConnGenericTest extends TestCase {

	public static $logger;

	public static function setUpBeforeClass() {
		$logfile = testdir() .
			'/zapstore-redis.log';
		self::$logger = new Logger(Logger::DEBUG, $logfile);
	}

	public function test_constructor() {
		$args = prepare_config_redis('redis');
		# fail by wrong password
		unset($args['redispassword']);
		try {
			new ZapRedis($args, self::$logger);
		} catch (ZapRedisErr $e) {
			$this->assertEquals($e->code,
				ZapRedisErr::CONNECTION_ERROR);
		}
	}

	private function invoke_exception($type) {
		$args = prepare_config_redis($type);

		# fail by invalid database
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
