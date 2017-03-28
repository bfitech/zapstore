<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger as Logger;
use BFITech\ZapStore as zs;


class SQLTest extends TestCase {

	public static $args = [];
	public static $sql = [];
	public static $config_file = null;
	public static $logger = null;

	public static function prepare_config() {
		self::$config_file = __DIR__ . '/config.json';
		if (file_exists(self::$config_file)) {
			$args = @json_decode(
				file_get_contents(self::$config_file), true);
			if ($args) {
				self::$args = $args;
				return;
			}
		}
		$args = [
			'sqlite3' => [
				'dbtype' => 'sqlite3',
				'dbname' => __DIR__ . '/zapstore-test.sq3',
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
		file_put_contents(self::$config_file,
			json_encode($args, JSON_PRETTY_PRINT));
		self::$args = $args;
	}

	public static function setUpBeforeClass() {
		self::prepare_config();

		$logfile = __DIR__ . '/zapstore.log';
		if (file_exists($logfile))
			@unlink($logfile);
		self::$logger = new Logger(Logger::DEBUG, $logfile);

		foreach (self::$args as $key => $val) {
			try {
				self::$sql[$key] = new zs\SQL($val, self::$logger);
			} catch(zs\SQLError $e) {
				printf(
					"ERROR: Cannot connect to '%s' test database.\n",
					$key);
				printf(
					"       Fix test configuration: '%s'.\n",
					self::$config_file);
				printf(
					"       For details, see log: '%s'.\n",
					$logfile);
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

	public function test_connection() {
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

		$args['dbtype'] = 'sqlite3';
		$sql = new zs\SQL($args, self::$logger);
		$this->assertTrue(!empty($sql));
	}

	public function test_raw() {
		$this->dbs(function($sql, $dbtype){

			$default_timestamp = $sql->stmt_fragment(
				'datetime', 3600);
			if ($dbtype == 'mysql')
				$default_timestamp = 'CURRENT_TIMESTAMP';

			$sql->query_raw(sprintf(
				"CREATE TABLE test (" .
					" id %s, " .
					" name VARCHAR(64), " .
					" value INTEGER, " .
					" time TIMESTAMP NOT NULL DEFAULT %s " .
				") %s",
				$sql->stmt_fragment('index'),
				$default_timestamp,
				$sql->stmt_fragment('engine')
			));

			$cstr = $sql->get_connection_string();
			$this->assertEquals(
				$sql->get_connection_params(),
				self::$args[$dbtype]);
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
				# postgres needs correct RETURNING key
				$val = $sql->insert('test', [
					'name' => 'eggplant',
					'value' => 8,
				], 'value');
				$this->assertEquals($val, 8);
				$sql->delete('test', ['name' => 'eggplant']);
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

