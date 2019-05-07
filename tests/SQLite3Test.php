<?php


require_once __DIR__ . '/Common.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\SQLite3;


class SQLite3Test extends TestCase {

	public function test_sqlite3() {
		$testdir = testdir();
		$logfile = $testdir . '/zapstore-sql.log';
		$cnffile = $testdir . '/zapstore-sql.json';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = prepare_config_sql('sqlite3', $cnffile);
		$sql = new SQLite3($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['dbtype'], 'sqlite3');
	}

}
