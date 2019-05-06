<?php


require_once __DIR__ . '/SQLConfig.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\PgSQL;


class PgSQLTest extends TestCase {

	public function test_pgsql() {
		$testdir = RouterDev::testdir();
		$logfile = $testdir . '/zapstore-sql.log';
		$cnffile = $testdir . '/zapstore-sql.json';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = prepare_config('pgsql', $cnffile);
		$sql = new PgSQL($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['dbtype'], 'pgsql');
	}

}
