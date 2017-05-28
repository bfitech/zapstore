<?php


require_once(__DIR__ . '/SQLTest.php');


use BFITech\ZapCore\Logger;
use BFITech\ZapStore as zs;


class PgSQLTest extends SQLTest {

	public static $engine = 'pgsql';

	public function test_pgsql() {
		$logger = new Logger(
			Logger::ERROR, getcwd() . '/zapstore-test.log');
		$config = json_decode(
			file_get_contents(getcwd() . '/zapstore-test.config.json'),
			true);
		$sql = new zs\PgSQL($config['pgsql'], $logger);
		$this->assertEquals(
			$sql->get_connection_params()['dbtype'], 'pgsql');
	}

}
