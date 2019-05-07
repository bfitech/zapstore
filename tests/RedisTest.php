<?php


require_once __DIR__ . '/Common.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\Redis;


class RedisTest extends TestCase {

	public function test_redis() {
		$testdir = testdir();
		$logfile = $testdir . '/zapstore-redis.log';
		$cnffile = $testdir . '/zapstore-redis.json';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = prepare_config_redis('redis', $cnffile);
		$sql = new Redis($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['redistype'], 'redis');
	}

}
