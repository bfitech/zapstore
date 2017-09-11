<?php


require_once __DIR__ . '/RedisConfig.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCoreDev\CoreDev;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\Redis;


class RedisTest extends TestCase {

	public function test_redis() {
		$testdir = CoreDev::testdir(__FILE__);
		$logfile = $testdir . '/zapstore-redis.log';
		$cnffile = $testdir . '/zapstore-redis.json';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = prepare_config_redis('redis', $cnffile);
		$sql = new Redis($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['redistype'], 'redis');
	}

}
