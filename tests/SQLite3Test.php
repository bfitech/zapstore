<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\SQLite3;


/**
 * SQLite3-specific.
 */
class SQLite3Test extends Common {

	public function test_sqlite3() {
		$testdir = self::tdir(__FILE__);
		$logfile = $testdir . '/zapstore-sql.log';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = self::open_config('sqlite3');
		$sql = new SQLite3($args, $logger);
		$this->eq()($sql->get_connection_params()['dbtype'], 'sqlite3');
	}

}
