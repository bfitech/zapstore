<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\PgSQL;


/**
 * Postgres-specific.
 */
class PgSQLTest extends Common {

	public function test_pgsql() {
		$testdir = self::tdir(__FILE__);
		$logfile = $testdir . '/zapstore-sql.log';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = self::open_config('pgsql');
		$sql = new PgSQL($args, $logger);
		$this->eq()($sql->get_connection_params()['dbtype'], 'pgsql');
	}

}
