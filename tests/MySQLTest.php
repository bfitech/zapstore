<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\MySQL;


/**
 * MySQL-specific.
 */
class MySQLTest extends Common {

	public function test_mysql() {
		$testdir = self::tdir(__FILE__);
		$logfile = $testdir . '/zapstore-sql.log';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = self::open_config('mysql');
		$sql = new MySQL($args, $logger);
		$this->eq()($sql->get_connection_params()['dbtype'], 'mysql');
	}

}
