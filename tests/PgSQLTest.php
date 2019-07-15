<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\PgSQL;


/**
 * Postgres-specific.
 */
class PgSQLTest extends Common {

	public function test_pgsql() {
		$logfile = self::tdir(__FILE__) . '/zapstore-pgsql.log';
		$logger = new Logger(Logger::ERROR, $logfile);
		$params = self::open_config('pgsql');
		$sql = new PgSQL($params, $logger);
		$this->eq()($sql->get_connection_params()['dbtype'], 'pgsql');
	}

}
