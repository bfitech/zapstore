<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\RedisConn;
use BFITech\ZapStore\RedisError;
use BFITech\ZapStore\Predis;
use BFITech\ZapStore\Redis;
use Predis\Response\Status as ResponseStatus;


/**
 * Driver-specific test.
 *
 * This loops over all supported drivers.
 */
class RedisConnTest extends Common {
	public static $types = ['redis', 'predis'];
	public static $conns = [];

	public static function setUpBeforeClass() {
		$cfile =  self::tdir(__FILE__) . "/zapstore-redis.json";
		$cnf = self::open_config(null, $cfile);

		$logfile = self::tdir(__FILE__) . '/zapstore-redis.log';
		if (file_exists($logfile))
			@unlink($logfile);
		$logger = new Logger(Logger::DEBUG, $logfile);

		foreach (self::$types as $type) {
			try {
				$params = $cnf[$type];
				$params['redistype'] = $type;
				self::$conns[$type] = new RedisConn($params, $logger);
			} catch(RedisError $e) {
				printf(
					"ERROR: Cannot connect to '%s' test database.\n\n" .
					"- Check extensions for interpreter: %s.\n" .
					"- Fix test configuration '%s': %s\n" .
					"- Inspect test log: %s.\n\n",
					$type, PHP_BINARY, $cfile,
					file_get_contents($cfile), $logfile);
				exit(1);
			}
		}
	}

	public function tearDown() {
		$this->loop(function($conn, $redistype){
			$conn->get_connection()->flushdb();
		});
	}

	private function loop($fn) {
		foreach (self::$types as $type) {
			$conn = self::$conns[$type];
			$fn($conn, $type);
			self::eq()(
				$conn->get_connection_params()['redistype'], $type);
		}
	}

	public function test_connection() {
		$this->loop(function($conn, $type){
			$tr = self::tr();
			$rconn = $conn->get_connection();
			if ($type == 'redis')
				$tr($rconn instanceof \Redis);
			if ($type == 'predis')
				$tr($rconn instanceof \Predis\Client);
		});
	}

	public function test_set() {
		$this->loop(function($conn, $type){
			$eq = self::eq();
			if ($type == 'redis')
				$eq($conn->set('myvalue', 'hello'), true);
			if ($type == 'predis') {
				$response = $conn->set('myvalue', 'hello');
				$eq($response::get('OK'), ResponseStatus::get('OK'));
			}
		});
	}

	public function test_hset() {
		$this->loop(function($conn, $_){
			$ret = $conn->hset('h', 'mykey', 'hello');
			if($ret === false)
				$ret = 2;
			$this->eq()(in_array($ret, [0,1]), true);
		});
	}

	public function test_del() {
		$this->loop(function($conn, $_){
			$conn->set('key1', 'val1');
			$conn->set('key2', 'val2');
			$conn->set('key3', 'val3');
			$conn->set('key4', 'val4');
			$ret = $conn->del(['key1', 'key2']);
			# returns number of deleted keys
			$this->eq()($ret, 2);
		});
	}

	public function test_expire() {
		$this->loop(function($conn, $_){
			$eq = self::eq();

			$conn->set('key1', 'val1');
			# set expire in the past
			$conn->expire('key1', 1);
			sleep(2);
			$ret = $conn->get('key1');
			$eq($ret, false);

			$conn->set('key2', 'val2');
			# set expireat in the past
			$time = $conn->time() - 3;
			$conn->expireat('key2', $time);
			$ret = $conn->get('key2');
			$eq($ret, false);
		});
	}

	public function test_get() {
		$this->loop(function($conn, $_){
			$conn->set('key1', 'val1');
			$ret = $conn->get('key1');
			$this->eq()($ret, 'val1');
			$conn->del('key1');
		});
	}

	public function test_hget() {
		$this->loop(function($conn, $_){
			$conn->del('h');
			$conn->hset('h', 'key1', 'val1');
			$conn->hset('h', 'key2', 'val2');
			$ret = $conn->hget('h', 'key1');
			$this->eq()($ret, 'val1');
			$conn->del('h');
		});
	}

	public function test_ttl() {
		$this->loop(function($conn, $_){
			$conn->set('key1', 'val1');
			$time = $conn->time(true);
			$expire = intval($time) + 10;
			$conn->expireat('key1', $expire);
			$ttl = $conn->ttl('key1');
			$this->eq()(in_array($ttl, [9, 10]), true);
			$conn->del('key1');
		});
	}

}
