<?php


require_once __DIR__ . '/SQLConfig.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\PgSQL;


class PgSQLTest extends TestCase {

	public function test_pgsql() {
		$logger = new Logger(
			Logger::ERROR, getcwd() . '/zapstore-test.log');
		$args = prepare_config('pgsql');
		$sql = new PgSQL($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['dbtype'], 'pgsql');
	}

}
