<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\RedisConn as RedisConn;
use BFITech\ZapStore\RedisError;


/**
 * Generic tests.
 *
 * This doesn't loop over all supported drivers.
 */
class RedisConnGenericTest extends Common {

	public static $logger;

	public static function setUpBeforeClass() {
		$logfile = self::tdir(__FILE__) . '/zapstore-redis.log';
		self::$logger = new Logger(Logger::DEBUG, $logfile);
	}

	public function test_constructor() {
		$this->expectException(RedisError::class);

		$args = self::open_config('redis');
		$args['redistype'] = 'redis';

		# success
		$red = new RedisConn($args, self::$logger);
		self::eq()($red->get_driver(), 'redis');

		# fail by wrong password
		unset($args['redispassword']);
		new RedisConn($args, self::$logger);
	}

	private function invoke_exception($type) {
		$args = self::open_config($type);
		$args['redistype'] = $type;
		extract(self::vars());

		# fail by invalid database
		$args['redisdatabase'] = -1000;
		try {
			new RedisConn($args, self::$logger);
		} catch(RedisError $err) {
			$eq($err->code, RedisError::CONNECTION_ERROR);
		}

		# invalid password
		$args['redisdatabase'] = 10;
		$args['redispassword'] = 'xoxox';
		try {
			new RedisConn($args, self::$logger);
		} catch(RedisError $err) {
			$eq($err->code, RedisError::CONNECTION_ERROR);
		}

		# valid, with timeout
		$args['redispassword'] = 'xoxo';
		$args['redistimeout'] = 0.1;
		$redis = new RedisConn($args, self::$logger);
		$redis->close();
		$eq($redis->get_connection(), null);

		# invalid host, default port
		$args['redishost'] = '0.0.0.1';
		unset($args['redisport']);
		$fail = false;
		try {
			new RedisConn($args, self::$logger);
		} catch(RedisError $err) {
			$fail = true;
			$eq($err->code, RedisError::CONNECTION_ERROR);
		}
		$tr($fail);
	}

	public function test_exception() {
		foreach (['redis', 'predis'] as $type) {
			$this->invoke_exception($type);
		}
	}

	public function test_connection_parameters() {
		$args = ['redishost' => 'localhost'];
		try {
			$sql = new RedisConn($args, self::$logger);
		} catch(RedisError $err) {
			$this->eq()($err->code, RedisError::CONNECTION_ARGS_ERROR);
		}

		$args['redishost'] = '127.0.0.1';
		$args['redistype'] = 'sqlite';
		try {
			$sql = new RedisConn($args, self::$logger);
		} catch(RedisError $err) {
			self::eq()($err->code, RedisError::REDISTYPE_ERROR);
		}
	}

}
