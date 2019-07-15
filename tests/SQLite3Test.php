<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\SQLite3;


/**
 * SQLite3-specific.
 */
class SQLite3Test extends Common {

	public function test_sqlite3() {
		$logfile = self::tdir(__FILE__) . '/zapstore-sqlite3.log';
		$logger = new Logger(Logger::ERROR, $logfile);
		$params = self::open_config('sqlite3');
		$params['dbname'] = realpath($params['dbname']);
		$sql = new SQLite3($params, $logger);
		$this->eq()($sql->get_connection_params()['dbtype'], 'sqlite3');
	}

}
