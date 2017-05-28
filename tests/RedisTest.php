<?php


require_once __DIR__ . '/RedisConfig.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\Redis;


class RedisTest extends TestCase {

	public function test_redis() {
		$logger = new Logger(
			Logger::ERROR, getcwd() . '/zapstore-redis-test.log');
		$args = prepare_config_redis('redis');
		$sql = new Redis($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['redistype'], 'redis');
	}

}
