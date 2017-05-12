<?php

use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger as Logger;
use BFITech\ZapStore\Redis as ZapRedis;
use BFITech\ZapStore\RedisError as ZapRedisErr;

use Predis\Response\Status as ResponseStatus;

/**
 * Database-specific test.
 *
 * All tests are written in single class, but only one can be
 * activated at a time by setting static::$engine to the
 * driver of choice. Change static::$engine in redis-
 * specific packages, otherwise tests for all drivers will be
 * run. Such change must also be reflected on composer 'require'
 * directive.
 */
class RedisTest extends TestCase {
	public static $args = [];
	public static $redis = [];
	public static $config_file = null;
	public static $logger = null;

	public static $engine = null;

	public static function prepare_config() {
		self::$config_file = getcwd() . '/zapstore-redis-test.config.json';
		if (file_exists(self::$config_file)) {
			$args = @json_decode(
				file_get_contents(self::$config_file), true);
			if ($args) {
				self::$args = $args;
				return;
			}
		}
		# connection parameter stub
		$args = [
			'redis' => [
				'redistype' => 'redis',
				'redishost' => '127.0.0.1',
				'redisport' => '6379',
			],
			'predis' => [
				'redistype' => 'predis',
				'redishost' => '127.0.0.1',
				'redisport' => '6379',
			],
		];
		if (static::$engine) {
			foreach ($args as $key => $_) {
				if ($key != static::$engine)
					unset($args[$key]);
			}
		}
		file_put_contents(self::$config_file,
			json_encode($args, JSON_PRETTY_PRINT));
		self::$args = $args;
	}

	public static function setUpBeforeClass() {
		self::prepare_config();

		$logfile = getcwd() . '/zapstore-redis-test.log';
		if (file_exists($logfile))
			@unlink($logfile);
		self::$logger = new Logger(Logger::DEBUG, $logfile);

		foreach (self::$args as $key => $val) {
			try {
				self::$redis[$key] = new ZapRedis($val, self::$logger);
			} catch(ZapRedisError $e) {
				printf(
					"ERROR: Cannot connect to '%s' test database.\n\n" .
					"- Check extensions for interpreter: %s.\n" .
					"- Fix test configuration: %s.\n" .
					"- Inspect test log: %s.\n\n",
				$key, PHP_BINARY, self::$config_file, $logfile);
				exit(1);
			}
		}
	}

	public static function tearDownAfterClass() {}

	private function loopredis($fn) {
		foreach (self::$args as $redistype => $_) {
			$fn(self::$redis[$redistype], $redistype);
		}
	}

	public function test_set() {
		$this->loopredis(function($redis, $redistype){
			if ($redistype == 'redis')
				$this->assertEquals($redis->set('myvalue', 'hello'), true);
			if ($redistype == 'predis') {
				$response = $redis->set('myvalue', 'hello');
				$this->assertEquals($response::get('OK'), ResponseStatus::get('OK'));
			}
		});
	}

	public function test_hset() {
		$this->loopredis(function($redis, $redistype){
			$ret = $redis->hset('h', 'mykey', 'hello');
			if(!$ret)
				$ret = 2;
			$this->assertEquals(in_array($ret, [0,1]), true);
		});
	}
}