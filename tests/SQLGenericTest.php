<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger as Logger;
use BFITech\ZapStore as zs;


/**
 * Generic tests.
 *
 * This assumes all supported database drivers are installed.
 * Do not subclass this in database-specific packages.
 */
class SQLGenericTest extends TestCase {

	public static $logger;

	public static function setUpBeforeClass() {
		$logfile = getcwd() . '/zapstore-test.log';
		self::$logger = new Logger(Logger::DEBUG, $logfile);
	}

	public function test_exception() {
		$args = ['dbname' => ':memory:', 'dbtype' => 'sqlite3'];
		$sql = new zs\SQL($args, self::$logger);
		# deprecated
		$sql->open();

		$invalid_stmt = "SELECT datetim() AS now, 1+? AS num";
		try {
			$sql->query($invalid_stmt, [2]);
		} catch(zs\SQLError $e) {
			$this->assertEquals($e->getStmt(), $invalid_stmt);
			$this->assertEquals($e->getArgs(), [2]);
			$this->assertEquals($e->code,
				zs\SQLError::EXECUTION_ERROR);
		}

		$sql->close();
		try {
			$sql->query("SELECT datetime() AS now");
		} catch(zs\SQLError $e) {
			$this->assertEquals($e->code,
				zs\SQLError::CONNECTION_ERROR);
		}
	}

	public function test_connection_parameters() {
		$args = ['dbname' => ':memory:'];
		try {
			$sql = new zs\SQL($args, self::$logger);
		} catch(zs\SQLError $e) {
			$this->assertEquals($e->code,
				zs\SQLError::CONNECTION_ARGS_ERROR);
		}

		$args['dbtype'] = 'sqlite';
		try {
			$sql = new zs\SQL($args, self::$logger);
		} catch(zs\SQLError $e) {
			$this->assertEquals($e->code,
				zs\SQLError::DBTYPE_ERROR);
		}

		$args = [
			'dbtype' => 'mysql',
			'dbname' => 'test',
		];
		try {
			$sql = new zs\SQL($args, self::$logger);
		} catch(zs\SQLError $e) {
			$this->assertEquals($e->code,
				zs\SQLError::CONNECTION_ARGS_ERROR);
		}
		$args['dbuser'] = 'root';
		$args['dbhost'] = '127.0.0.1';
		$args['dbport'] = 5698;
		try {
			$sql = new zs\SQL($args, self::$logger);
		} catch(zs\SQLError $e) {
			$this->assertEquals($e->code,
				zs\SQLError::CONNECTION_ERROR);
		}

		$args = [
			'dbtype' => 'postgresql',
			'dbname' => 'test',
			'dbpass' => 'x',
			'dbhost' => 'localhost',
			'dbport' => 5698,
		];
		try {
			$sql = new zs\SQL($args, self::$logger);
		} catch(zs\SQLError $e) {
			$this->assertEquals($e->code,
				zs\SQLError::CONNECTION_ARGS_ERROR);
		}
		$args['dbuser'] = 'root';
		$args['dbhost'] = '127.0.0.1';
		try {
			$sql = new zs\SQL($args, self::$logger);
		} catch(zs\SQLError $e) {
			$this->assertEquals($e->code,
				zs\SQLError::CONNECTION_ERROR);
		}

		$args['dbtype'] = 'mssql';
		try {
			$sql = new zs\SQL($args, self::$logger);
		} catch(zs\SQLError $e) {
			$this->assertEquals($e->code,
				zs\SQLError::DBTYPE_ERROR);
		}
	}

}

