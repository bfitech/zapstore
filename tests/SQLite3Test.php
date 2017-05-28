<?php


require_once __DIR__ . '/SQLConfig.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\SQLite3;


class SQLite3Test extends TestCase {

	public function test_sqlite3() {
		$logger = new Logger(
			Logger::ERROR, getcwd() . '/zapstore-test.log');
		$args = prepare_config('sqlite3');
		$sql = new SQLite3($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['dbtype'], 'sqlite3');
	}

}
