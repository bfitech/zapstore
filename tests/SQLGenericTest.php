<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\SQL;
use BFITech\ZapStore\SQLError;


/**
 * Generic tests.
 *
 * This doesn't loop over all supported backends.
 */
class SQLGenericTest extends Common {

	public static $logger;

	public static function setUpBeforeClass() {
		$logfile = self::tdir(__FILE__) . '/zapstore-sql.log';
		if (file_exists($logfile))
			unlink($logfile);
		self::$logger = new Logger(Logger::DEBUG, $logfile);
	}

	public function test_exception() {
		extract(self::vars());

		$args = ['dbname' => ':memory:', 'dbtype' => 'sqlite3'];
		$sql = new SQL($args, self::$logger);

		$invalid_stmt = "SELECT datetim() AS now, 1+? AS num";
		try {
			$sql->query($invalid_stmt, [2]);
		} catch(SQLError $err) {
			$eq($err->getStmt(), $invalid_stmt);
			$eq($err->getArgs(), [2]);
			$eq($err->code, SQLError::EXECUTION_ERROR);
		}

		# close
		$sql->close();
		$sm($sql->get_connection_params(), []);
		$sm($sql->get_dbtype(), '');
		$sm($sql->get_connection(), null);

		# cannot query
		$fail = false;
		try {
			$sql->query("SELECT datetime() AS now");
		} catch(SQLError $err) {
			$fail = true;
			$eq($err->code, SQLError::CONNECTION_ERROR);
		}
		$tr($fail);

		# cannot re-close
		$fail = false;
		try {
			$sql->close();
		} catch(SQLError $err) {
			$fail = true;
			$eq($err->code, SQLError::CONNECTION_ERROR);
		}
		$tr($fail);
	}

	public function test_connection_parameters() {
		$eq = self::eq();

		$args = ['dbname' => ':memory:'];
		try {
			$sql = new SQL($args, self::$logger);
		} catch(SQLError $err) {
			$eq($err->code, SQLError::CONNECTION_ARGS_ERROR);
		}

		$args['dbtype'] = 'sqlite';
		try {
			$sql = new SQL($args, self::$logger);
		} catch(SQLError $err) {
			$eq($err->code, SQLError::DBTYPE_ERROR);
		}

		$args = [
			'dbtype' => 'mysql',
			'dbname' => 'test',
		];
		try {
			$sql = new SQL($args, self::$logger);
		} catch(SQLError $err) {
			$eq($err->code, SQLError::CONNECTION_ARGS_ERROR);
		}
		$args['dbuser'] = 'root';
		$args['dbhost'] = '127.0.0.1';
		$args['dbport'] = 5698;
		try {
			$sql = new SQL($args, self::$logger);
		} catch(SQLError $err) {
			$eq($err->code, SQLError::CONNECTION_ERROR);
		}

		$args = [
			'dbtype' => 'postgresql',
			'dbname' => 'test',
			'dbpass' => 'x',
			'dbhost' => 'localhost',
			'dbport' => 5698,
		];
		try {
			$sql = new SQL($args, self::$logger);
		} catch(SQLError $err) {
			$eq($err->code, SQLError::CONNECTION_ARGS_ERROR);
		}
		$args['dbuser'] = 'non_ exitent or Bokren user';
		$args['dbhost'] = 'localhost';
		try {
			$sql = new SQL($args, self::$logger);
		} catch(SQLError $err) {
			$eq($err->code, SQLError::CONNECTION_ERROR);
		}

		$args['dbtype'] = 'mssql';
		try {
			$sql = new SQL($args, self::$logger);
		} catch(SQLError $err) {
			$eq($err->code, SQLError::DBTYPE_ERROR);
		}
	}

}
