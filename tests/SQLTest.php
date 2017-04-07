<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger as Logger;
use BFITech\ZapStore as zs;


/**
 * Database-specific test.
 *
 * All tests are written in single class, but only one can be
 * activated at a time by setting static::$engine to the
 * driver of choice. Change static::$engine in database-
 * specific packages, otherwise tests for all drivers will be
 * run. Such change must also be reflected on composer 'require'
 * directive.
 */
class SQLTest extends TestCase {

	public static $args = [];
	public static $sql = [];
	public static $config_file = null;
	public static $logger = null;

	public static $engine = null;

	private $time_stmt_test = null;

	public static function prepare_config() {
		self::$config_file = getcwd() . '/zapstore-test.config.json';
		if (file_exists(self::$config_file)) {
			$args = @json_decode(
				file_get_contents(self::$config_file), true);
			if ($args) {
				self::$args = $args;
				return;
			}
		}
		# connection parameter stub
		$args = [
			'sqlite3' => [
				'dbtype' => 'sqlite3',
				'dbname' => getcwd() . '/zapstore-test.sq3',
			],
			'pgsql' => [
				'dbtype' => 'pgsql',
				'dbname' => 'zapstore_test_db',
				'dbhost' => 'localhost',
				'dbuser' => 'postgres',
				'dbpass' => '',
			],
			'mysql' => [
				'dbtype' => 'mysql',
				'dbname' => 'zapstore_test_db',
				# 'localhost' is for unix socket
				'dbhost' => '127.0.0.1',
				'dbuser' => 'root',
				'dbpass' => '',
			],
		];
		if (static::$engine) {
			foreach ($args as $key => $_) {
				if ($key != static::$engine)
					unset($args[$key]);
			}
		}
		file_put_contents(self::$config_file,
			json_encode($args, JSON_PRETTY_PRINT));
		self::$args = $args;
	}

	public static function setUpBeforeClass() {
		self::prepare_config();

		$logfile = getcwd() . '/zapstore-test.log';
		if (file_exists($logfile))
			@unlink($logfile);
		self::$logger = new Logger(Logger::DEBUG, $logfile);

		foreach (self::$args as $key => $val) {
			try {
				self::$sql[$key] = new zs\SQL($val, self::$logger);
			} catch(zs\SQLError $e) {
				printf(
					"ERROR: Cannot connect to '%s' test database.\n\n" .
					"- Check extensions for interpreter: %s.\n" .
					"- Fix test configuration: %s.\n" .
					"- Inspect test log: %s.\n\n",
				$key, PHP_BINARY, self::$config_file, $logfile);
				exit(1);
			}
		}
	}

	public static function tearDownAfterClass() {
		foreach (self::$sql as $sql) {
			try {
				$sql->query_raw("DROP TABLE test");
				$sql->query_raw("DROP TABLE try0");
				$sql->query_raw("DROP TABLE try1");
				$sql->query_raw("DROP TABLE try2");
			} catch(zs\SQLError $e) {}
		}
	}

	private function dbs($fn) {
		foreach (self::$args as $dbtype => $_) {
			$fn(self::$sql[$dbtype], $dbtype);
		}
	}

	public function test_raw() {
		$this->dbs(function($sql, $dbtype){

			$this->assertEquals($sql->stmt_fragment('unknown'), null);

			$expire_stmt = $sql->stmt_fragment(
				'datetime', ['delta' => '3600']);
			$expire_datetime = $sql->query(
				sprintf("SELECT (%s) AS time", $expire_stmt)
			)['time'];

			$dtobj = DateTime::createFromFormat(DateTime::ATOM,
				str_replace(' ', 'T', $expire_datetime) . 'Z');
			$this->assertNotEquals($dtobj, false);

			# this assumes each database server is correctly
			# timed and there's no perceivable latency between
			# it and current interpreter node
			$sec_between_db = 2;
			if ($this->time_stmt_test != null) {
				$this->assertLessThan(
					$sec_between_db,
					$this->time_stmt_test->diff($dtobj)->s);
			}
			$this->time_stmt_test = $dtobj;

			if ($dbtype == 'mysql')
				$expire_stmt = 'CURRENT_TIMESTAMP';

			$sql->query_raw(sprintf(
				"CREATE TABLE test (" .
					" id %s, " .
					" name VARCHAR(64), " .
					" value INTEGER, " .
					" time TIMESTAMP NOT NULL DEFAULT %s " .
				") %s",
				$sql->stmt_fragment('index'),
				$expire_stmt,
				$sql->stmt_fragment('engine')
			));

			$this->assertEquals(
				$sql->get_connection_params(),
				self::$args[$dbtype]);
		});
	}

	public function test_datetime_fragment() {
		$this->dbs(function($sql){
			$unix_ts = [];
			foreach ([
				'past' => -3600,
				'present' => 0,
				'future' => 3600,
			] as $tense => $delta) {
				$stmt = $sql->stmt_fragment(
					'datetime', ['delta' => $delta]);
				$datetime = $sql->query(
					sprintf("SELECT %s AS time", $stmt)
				)['time'];
				$dtobj = DateTime::createFromFormat(DateTime::ATOM,
					str_replace(' ', 'T', $datetime) . 'Z');
				$this->assertNotEquals($dtobj, false);
				$unix_ts[$tense] = $dtobj->getTimestamp();
			}
			$this->assertEquals(
				$unix_ts['future'] - $unix_ts['present'], 3600);
			$this->assertEquals(
				$unix_ts['present'] - $unix_ts['past'], 3600);
		});
	}

	/**
	 * @depends test_raw
	 */
	public function test_insert() {
		$this->dbs(function($sql, $dbtype){
			try {
				$sql->insert('wrong_table', ['a' => 'b']);
			} catch (zs\SQLError $e) {
				# wrong table
				$this->assertEquals($e->code,
					zs\SQLError::EXECUTION_ERROR);
			}
			try {
				$sql->insert('test', ['a' => 'b']);
			} catch(zs\SQLError $e) {
				# wrong column
				$this->assertEquals($e->code,
					zs\SQLError::EXECUTION_ERROR);
			}
			$id = $sql->insert('test', [
				'name' => 'apple',
				'value' => 2,
			], 'id');
			$this->assertEquals($id, 1);

			foreach ([
				['banana', 0],
				['cucumber', 3],
				['durian', 9],
			] as $dat) {
				$nid = $sql->insert('test', [
					'name' => $dat[0],
					'value' => $dat[1],
				], 'id');
				$this->assertEquals($nid, ++$id);
			}

			if ($dbtype == 'pgsql') {
				# postgres with one RETURNING key
				$val = $sql->insert('test', [
					'name' => 'eggplant',
					'value' => 8,
				], 'value');
				$this->assertEquals($val, 8);
				$sql->delete('test', ['name' => 'eggplant']);

				# postgres with all RETURNING key
				$val = $sql->insert('test', [
					'name' => 'eggplant',
					'value' => 8,
				]);
				$this->assertEquals($val['value'], 8);
				$sql->delete('test', ['name' => 'eggplant']);

				# postgres with wrong RETURNING key
				try {
					$val = $sql->insert('test', [
						'name' => 'eggplant',
						'value' => 8,
					], 'address');
				} catch(zs\SQLError $e) {
					$this->assertEquals(
						$e->code, zs\SQLError::EXECUTION_ERROR);
				}
			}

			if ($dbtype == 'sqlite3') {
				# no type safety for sqlite3
				$id = $sql->insert('test', [
					'name' => 'eggplant',
					'value' => 'a',
				]);
				$sql->delete('test', ['id' => $id]);
			}
		});
	}

	/**
	 * @depends test_insert
	 */
	public function test_select() {
		$this->dbs(function($sql){
			try {
				# table doesn't exist
				# can be used to check table existence
				$sql->query("SELECT 1 FROM wrong_table");
			} catch(zs\SQLError $e) {
				$this->assertEquals($e->code,
					zs\SQLError::EXECUTION_ERROR);
			}

			try {
				$result = $sql->query(
					"SELECT name, val FROM test WHERE name=? AND date=?",
				['avocado'], true);
			} catch(zs\SQLError $e) {
				$this->assertEquals($e->code,
					zs\SQLError::EXECUTION_ERROR);
			}

			try {
				# syntax error
				$sql->query(
					"SELECT * FRO test ORDER BY id LIMIT 3");
			} catch(zs\SQLError $e) {
				$this->assertEquals($e->code,
					zs\SQLError::EXECUTION_ERROR);
			}

			# single result returns dict
			$result = $sql->query(
				"SELECT * FROM test ORDER BY id LIMIT 3");
			$this->assertEquals($result['id'], 1);

			# check date column integrity
			$datetime = \DateTime::createFromFormat(\DateTime::ATOM,
				str_replace(' ', 'T', $result['time']) . '+0000');
			$this->assertNotEquals($datetime, false);

			# multiple results return list
			$result = array_map(function($r){
				return $r['id'];
			}, $sql->query(
				"SELECT * FROM test ORDER BY id LIMIT 3", [], true));
			$this->assertEquals($result, [1, 2, 3]);

			# provided that database and interpreter servers have
			# correct clock, if this fails, then there must be
			# connection bottleneck
			$this->assertTrue(abs($sql->unix_epoch() - time()) < 1);
		});
	}

	/**
	 * @depends test_insert
	 */
	public function test_delete() {
		$this->dbs(function($sql){
			try {
				$sql->delete("wrong_table", ['name' => 'banana']);
			} catch(zs\SQLError $e) {
				$this->assertEquals($e->code,
					zs\SQLError::EXECUTION_ERROR);
			}

			$sql->delete('test', ['name' => 'banana']);
			$count = $sql->query(
				"SELECT count(id) AS cnt FROM test")['cnt'];
			$this->assertEquals($count, 3);
		});
	}

	/**
	 * @depends test_insert
	 */
	public function test_update() {
		$this->dbs(function($sql){
			try {
				$sql->update("wrong_table",
					['name' => 'avocado'],
					['name' => 'apple']
				);
			} catch(zs\SQLError $e) {
				$this->assertEquals($e->code,
					zs\SQLError::EXECUTION_ERROR);
			}

			$sql->update("test",
				['name' => 'avocado'],
				['name' => 'apple']
			);
			$id = $sql->query(
				"SELECT id FROM test WHERE name=?",
				['avocado'])['id'];
			$this->assertEquals($id, 1);

			$sql->update("test", ['name' => 'jackfruit']);
			$cnt = $sql->query(
				"SELECT count(id) AS cnt FROM test WHERE name=?",
				['jackfruit'])['cnt'];
			$this->assertEquals($cnt, 3);
		});
	}

	/**
	 * @depends test_insert
	 */
	public function test_transaction() {
		$this->dbs(function($sql){
			try {
				$sql->query_raw("CREATE TABLE test (id INTEGER)");
			} catch(zs\SQLError $e) {
				# table exists
				$this->assertEquals($e->code,
					zs\SQLError::EXECUTION_ERROR);
			}

			$conn = $sql->get_connection();

			$conn->beginTransaction();
			$sql->query_raw("CREATE TABLE try0 (id INTEGER)");
			$sql->query_raw("CREATE TABLE try1 (id INTEGER)");
			$conn->commit();
			# there should be no thrown exception
			foreach (['try0', 'try1'] as $table) {
				$id = $sql->query(sprintf(
					"SELECT id val FROM %s", $table));
				$this->assertEquals(false, $id);
			}

			$conn->beginTransaction();
			try {
				$sql->query_raw("CREATE TABLE try2 (id INTEGER)");
				$sql->query_raw("CREATE TABLE try1 (id INTEGER)");
				$conn->commit();
			} catch(zs\SQLError $e) {
				$this->assertEquals($e->code,
					zs\SQLError::EXECUTION_ERROR);
				$conn->rollBack();
			}
			try {
				# try2 should be rolled back
				$sql->query("SELECT 1 val FROM try2");
			} catch(zs\SQLError $e) {
				$this->assertEquals($e->code,
					zs\SQLError::EXECUTION_ERROR);
			}
		});
	}

}

