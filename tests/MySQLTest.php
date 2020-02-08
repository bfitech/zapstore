<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\MySQL;


/**
 * MySQL-specific.
 */
class MySQLTest extends Common {

	public function test_mysql() {
		$logfile = self::tdir(__FILE__) . '/zapstore-mysql.log';
		$logger = new Logger(Logger::ERROR, $logfile);
		$params = self::open_config('mysql');
		$sql = new MySQL($params, $logger);
		self::eq()($sql->get_connection_params()['dbtype'], 'mysql');
	}

}
