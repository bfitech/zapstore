<?php


require_once __DIR__ . '/RedisConfig.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCoreDev\CoreDev;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\Predis;


class PredisTest extends TestCase {

	public function test_predis() {
		$testdir = CoreDev::testdir(__FILE__);
		$logfile = $testdir . '/zapstore-redis.log';
		$cnffile = $testdir . '/zapstore-redis.json';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = prepare_config_redis('predis', $cnffile);
		$sql = new Predis($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['redistype'], 'predis');
	}

}
