<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger as Logger;
use BFITech\ZapStore\RedisConn as ZapRedis;
use BFITech\ZapStore\RedisError as ZapRedisErr;
use BFITech\ZapStore\Predis;
use BFITech\ZapStore\Redis;
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
class RedisConnTest extends TestCase {
	public static $args = [];
	public static $redis = [];
	public static $config_file = null;
	public static $logger = null;

	public static $engine = null;

	public static function prepare_config() {
		self::$config_file = getcwd() .
			'/zapstore-redis-test.config.json';
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
			} catch(ZapRedisErr $e) {
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

	public function tearDown() {
		$this->loopredis(function($redis, $redistype){
			$redis->get_connection()->flushDb();
		});
	}

	private function loopredis($fn) {
		foreach (array_keys(self::$args) as $redistype) {
			$fn(self::$redis[$redistype], $redistype);
			$this->assertEquals(
				self::$redis[$redistype]->get_connection_params(),
				self::$args[$redistype]);

			$args = self::$redis[$redistype]
				->get_connection_params();
			$this->assertEquals($args['redistype'], $redistype);
		}
	}

	public function test_connection() {
		$this->loopredis(function($redis, $redistype){
			$conn = $redis->get_connection();
			if ($redistype == 'redis')
				$this->assertEquals($conn instanceof \Redis, true);
			if ($redistype == 'predis')
				$this->assertEquals($conn instanceof \Predis\Client,
					true);
		});
	}

	public function test_set() {
		$this->loopredis(function($redis, $redistype){
			if ($redistype == 'redis')
				$this->assertEquals(
					$redis->set('myvalue', 'hello'), true);
			if ($redistype == 'predis') {
				$response = $redis->set('myvalue', 'hello');
				$this->assertEquals($response::get('OK'),
					ResponseStatus::get('OK'));
			}
		});
	}

	public function test_hset() {
		$this->loopredis(function($redis, $redistype){
			$ret = $redis->hset('h', 'mykey', 'hello');
			if($ret === false)
				$ret = 2;
			$this->assertEquals(in_array($ret, [0,1]), true);
		});
	}

	public function test_del() {
		$this->loopredis(function($redis, $redistype){
			$redis->set('key1', 'val1');
			$redis->set('key2', 'val2');
			$redis->set('key3', 'val3');
			$redis->set('key4', 'val4');
			$ret = $redis->del(['key1', 'key2']);
			# returns number of deleted keys
			$this->assertEquals($ret, 2);
		});
	}

	public function test_expire() {
		$this->loopredis(function($redis, $redistype){
			$redis->set('key1', 'val1');
			# set expire in the past
			$redis->expire('key1', 1);
			sleep(2);
			$ret = $redis->get('key1');
			$this->assertEquals($ret, false);

			$redis->set('key2', 'val2');
			# set expireat in the past
			$time = $redis->time() - 3;
			$redis->expireat('key2', $time);
			$ret = $redis->get('key2');
			$this->assertEquals($ret, false);
		});
	}

	public function test_get() {
		$this->loopredis(function($redis, $redistype){
			$redis->set('key1', 'val1');
			$ret = $redis->get('key1');
			$this->assertEquals($ret, 'val1');
			$redis->del('key1');
		});
	}

	public function test_hget() {
		$this->loopredis(function($redis, $redistype){
			$redis->del('h');
			$redis->hset('h', 'key1', 'val1');
			$redis->hset('h', 'key2', 'val2');
			$ret = $redis->hget('h', 'key1');
			$this->assertEquals($ret, 'val1');
			$redis->del('h');
		});
	}

	public function test_ttl() {
		$this->loopredis(function($redis, $redistype){
			$redis->set('key1', 'val1');
			$time = $redis->time(true);
			$expire = intval($time) + 10;
			$redis->expireat('key1', $expire);
			$ttl = $redis->ttl('key1');
			$this->assertEquals(in_array($ttl, [9, 10]), true);
			$redis->del('key1');
		});
	}

	public function test_predis() {
		self::$engine = 'predis';
		$logger = new Logger(
			Logger::ERROR, getcwd() . '/zapstore-redis-test.log');
		$config = json_decode(
			file_get_contents(
				getcwd() . '/zapstore-redis-test.config.json'),
			true);
		$sql = new Predis($config['predis'], $logger);
		$this->assertEquals(
			$sql->get_connection_params()['redistype'], 'predis');
	}

	public function test_redis() {
		self::$engine = 'redis';
		$logger = new Logger(
			Logger::ERROR, getcwd() . '/zapstore-redis-test.log');
		$config = json_decode(
			file_get_contents(
				getcwd() . '/zapstore-redis-test.config.json'),
			true);
		$sql = new Redis($config['redis'], $logger);
		$this->assertEquals(
			$sql->get_connection_params()['redistype'], 'redis');
	}
}

