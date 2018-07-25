<?php


require_once __DIR__ . '/SQLConfig.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCommonDev\CommonDev;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\MySQL;


class MySQLTest extends TestCase {

	public function test_mysql() {
		$testdir = CommonDev::testdir(__FILE__);
		$logfile = $testdir . '/zapstore-sql.log';
		$cnffile = $testdir . '/zapstore-sql.json';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = prepare_config('mysql', $cnffile);
		$sql = new MySQL($args, $logger);
		$this->assertEquals(
			$sql->get_connection_params()['dbtype'], 'mysql');
	}

}
