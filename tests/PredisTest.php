<?php


require_once __DIR__ . '/RedisConfig.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\Predis;


class PredisTest extends TestCase {

	public function test_predis() {
		$logger = new Logger(
			Logger::ERROR, getcwd() . '/zapstore-redis-test.log');
		$args = prepare_config_redis('predis');
		$sql = new Predis($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['redistype'], 'predis');
	}

}
