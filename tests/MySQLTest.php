<?php


require_once(__DIR__ . '/SQLTest.php');


use BFITech\ZapCore\Logger;
use BFITech\ZapStore as zs;


class MySQLTest extends SQLTest {

	public static $engine = 'mysql';

	public function test_mysql() {
		$logger = new Logger(
			Logger::ERROR, getcwd() . '/zapstore-test.log');
		$config = json_decode(
			file_get_contents(getcwd() . '/zapstore-test.config.json'),
			true);
		$sql = new zs\MySQL($config['mysql'], $logger);
		$this->assertEquals(
			$sql->get_connection_params()['dbtype'], 'mysql');
	}

}
