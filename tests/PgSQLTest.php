<?php


require_once __DIR__ . '/Common.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\PgSQL;


class PgSQLTest extends TestCase {

	public function test_pgsql() {
		$testdir = testdir();
		$logfile = $testdir . '/zapstore-sql.log';
		$cnffile = $testdir . '/zapstore-sql.json';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = prepare_config_sql('pgsql', $cnffile);
		$sql = new PgSQL($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['dbtype'], 'pgsql');
	}

}
