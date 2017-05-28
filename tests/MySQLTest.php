<?php


require_once(__DIR__ . '/SQLTest.php');


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\MySQL;


class MySQLTest extends TestCase {

	public function test_mysql() {
		$logger = new Logger(
			Logger::ERROR, getcwd() . '/zapstore-test.log');
		$args = prepare_config('mysql');
		$sql = new MySQL($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['dbtype'], 'mysql');
	}

}
