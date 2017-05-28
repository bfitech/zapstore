<?php


require_once(__DIR__ . '/SQLTest.php');


use BFITech\ZapCore\Logger;
use BFITech\ZapStore as zs;


class SQLite3Test extends SQLTest {

	public static $engine = 'sqlite3';

	public function test_sqlite3() {
		$logger = new Logger(
			Logger::ERROR, getcwd() . '/zapstore-test.log');
		$sql = new zs\SQLite3(['dbname' => ':memory:'], $logger);
		$this->assertEquals(
			$sql->get_connection_params()['dbtype'], 'sqlite3');
	}

}
