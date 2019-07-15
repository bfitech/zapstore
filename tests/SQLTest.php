<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\MySQL;
use BFITech\ZapStore\PgSQL;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapStore\SQL;
use BFITech\ZapStore\SQLError;


/**
 * Backend-specific test.
 *
 * This loops over all supported backends.
 */
class SQLTest extends Common {

	public static $types = ['mysql', 'pgsql', 'sqlite3'];
	public static $conns = [];

	private $time_stmt_test = null;

	public static function setUpBeforeClass() {
		$cfile = self::tdir(__FILE__) . "/zapstore-sql.json";
		$cnf = self::open_config(null, $cfile);

		$logfile = self::tdir(__FILE__) . "/zapstore-sql.log";
		if (file_exists($logfile))
			@unlink($logfile);
		$logger = new Logger(Logger::DEBUG, $logfile);

		foreach (self::$types as $type) {
			try {
				$params = $cnf[$type];
				$params['dbtype'] = $type;
				self::$conns[$type] = new SQL($params, $logger);
			} catch(SQLError $err) {
				printf(
					"ERROR: Cannot connect to '%s' test database.\n\n" .
					"- Check extensions for interpreter: %s.\n" .
					"- Fix test configuration '%s': %s\n" .
					"- Inspect test log: %s.\n\n",
					$type, PHP_BINARY, $cfile,
					file_get_contents($cfile), $logfile);
				exit(1);
			}
		}
	}

	public static function tearDownAfterClass() {
		foreach (self::$conns as $sql) {
			try {
				$sql->query_raw("DROP TABLE test");
				$sql->query_raw("DROP TABLE try0");
				$sql->query_raw("DROP TABLE try1");
				$sql->query_raw("DROP TABLE try2");
			} catch(SQLError $err) {
			}
		}
	}

	private function loop($fn) {
		foreach (self::$types as $type) {
			$conn = self::$conns[$type];
			$fn($conn, $type);
			self::eq()($conn->get_connection_params()['dbtype'], $type);
		}
	}

	public function test_raw() {
		$this->loop(function($sql, $type){
			extract($this->vars());

			# uknown fragment gives null
			$eq($sql->stmt_fragment('unknown'), null);

			### generate datetime query
			$expire_stmt = $sql->stmt_fragment(
				'datetime', ['delta' => '3600']);
			$expire_datetime = $sql->query(
				sprintf("SELECT (%s) AS time", $expire_stmt)
			)['time'];
			# verify resulted datetime with DateTime class
			$dtobj = DateTime::createFromFormat(DateTime::ATOM,
				str_replace(' ', 'T', $expire_datetime) . 'Z');
			$ne($dtobj, false);

			# this assumes each database server is correctly
			# timed and there's no perceivable latency between
			# it and current PHP interpreter node
			$sec_between_db = 2;
			if ($this->time_stmt_test != null) {
				$this->assertLessThan(
					$sec_between_db,
					$this->time_stmt_test->diff($dtobj)->s
				);
			}
			$this->time_stmt_test = $dtobj;

			### mysql doesn't have function as default value
			if ($dbtype == 'mysql')
				$expire_stmt = 'CURRENT_TIMESTAMP';

			# use fragment to create table, should invoke no error
			$sql->query_raw(sprintf("
				CREATE TABLE test (
					id %s,
					name VARCHAR(64),
					value INTEGER,
					time TIMESTAMP NOT NULL DEFAULT %s 
				) %s",
				$sql->stmt_fragment('index'),
				$expire_stmt,
				$sql->stmt_fragment('engine')
			));

			# check connection string wellformedness
			$type_test = substr($type, 0, 5);
			$cstr = $sql->get_connection_string();
			$eq(strpos($cstr, $type_test), 0);
		});
	}

	public function test_datetime_fragment() {
		$this->loop(function($sql){
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
				$this->ne()($dtobj, false);
				$unix_ts[$tense] = $dtobj->getTimestamp();
			}
			# allow 2-second delay
			$this->assertLessThan(2,
				$unix_ts['future'] - $unix_ts['present'] -  3600);
			$this->assertLessThan(2,
				$unix_ts['present'] - $unix_ts['past'] -  3600);
		});
	}

	/**
	 * @depends test_raw
	 */
	public function test_insert() {
		$this->loop(function($sql, $type){
			$eq = $this->eq();

			try {
				$sql->insert('wrong_table', ['a' => 'b']);
			} catch (SQLError $err) {
				# wrong table
				$eq($err->code, SQLError::EXECUTION_ERROR);
			}
			try {
				$sql->insert('test', ['a' => 'b']);
			} catch(SQLError $err) {
				# wrong column
				$eq($err->code, SQLError::EXECUTION_ERROR);
			}
			$id = $sql->insert('test', [
				'name' => 'apple',
				'value' => 2,
			], 'id');
			$eq($id, 1);

			foreach ([
				['banana', 0],
				['cucumber', 3],
				['durian', 9],
			] as $dat) {
				$nid = $sql->insert('test', [
					'name' => $dat[0],
					'value' => $dat[1],
				], 'id');
				$eq($nid, ++$id);
			}

			if ($type == 'pgsql') {

				# postgres with one RETURNING key
				$val = $sql->insert('test', [
					'name' => 'eggplant',
					'value' => 8,
				], 'value');
				$eq($val, 8);
				$sql->delete('test', ['name' => 'eggplant']);

				# postgres with all RETURNING keys
				$val = $sql->insert('test', [
					'name' => 'eggplant',
					'value' => 8,
				]);
				$eq($val['value'], 8);
				$sql->delete('test', ['name' => 'eggplant']);

				# postgres with wrong RETURNING key
				try {
					$val = $sql->insert('test', [
						'name' => 'eggplant',
						'value' => 8,
					], 'address');
				} catch(SQLError $err) {
					$eq($err->code, SQLError::EXECUTION_ERROR);
				}
			}

			if ($type == 'sqlite3') {
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
		$this->loop(function($sql){
			extract(self::vars());

			# table doesn't exist
			$fl($sql->table_exists('#wrong_table'));
			$fl($sql->table_exists('wrong_table'));

			try {
				$result = $sql->query("
					SELECT name, val
					FROM test
					WHERE name=? AND date=?
				", ['avocado'], true);
			} catch(SQLError $err) {
				$eq($err->code, SQLError::EXECUTION_ERROR);
			}

			# table does exist
			$tr($sql->table_exists('test'));

			try {
				# syntax error
				$sql->query("SELECT * FRO test ORDER BY id LIMIT 3");
			} catch(SQLError $err) {
				$eq($err->code, SQLError::EXECUTION_ERROR);
			}

			# single result returns dict
			$result = $sql->query(
				"SELECT * FROM test ORDER BY id LIMIT 3");
			$eq($result['id'], 1);

			# check date column integrity; watch out the slosh before
			# DateTime
			$datetime = \DateTime::createFromFormat(\DateTime::ATOM,
				str_replace(' ', 'T', $result['time']) . '+0000');
			$this->assertNotEquals($datetime, false);

			# multiple results return list
			$result = array_map(function($r){
				return $r['id'];
			}, $sql->query(
				"SELECT * FROM test ORDER BY id LIMIT 3", [], true));
			$eq($result, [1, 2, 3]);

			# check if database and interpreter time match to the hour
			$tstamp = gmdate('Y-m-d H', $sql->time());
			$eq($tstamp, gmdate('Y-m-d H'));
		});
	}

	/**
	 * @depends test_insert
	 */
	public function test_delete() {
		$this->loop(function($sql){
			try {
				$sql->delete("wrong_table", ['name' => 'banana']);
			} catch(SQLError $err) {
				$this->eq()($err->code, SQLError::EXECUTION_ERROR);
			}

			$sql->delete('test', ['name' => 'banana']);
			$count = $sql->query("
				SELECT count(id) AS cnt FROM test
			")['cnt'];
			$this->eq()($count, 3);
		});
	}

	/**
	 * @depends test_insert
	 */
	public function test_update() {
		$this->loop(function($sql){
			$eq = $this->eq();
			try {
				$sql->update("wrong_table",
					['name' => 'avocado'],
					['name' => 'apple']
				);
			} catch(SQLError $err) {
				$eq($err->code, SQLError::EXECUTION_ERROR);
			}

			$sql->update("test",
				['name' => 'avocado'],
				['name' => 'apple']
			);
			$id = $sql->query(
				"SELECT id FROM test WHERE name=?",
				['avocado'])['id'];
			$eq($id, 1);

			$sql->update("test", ['name' => 'jackfruit']);
			$cnt = $sql->query(
				"SELECT count(id) AS cnt FROM test WHERE name=?",
				['jackfruit'])['cnt'];
			$eq($cnt, 3);
		});
	}

	/**
	 * @depends test_insert
	 */
	public function test_transaction() {
		$this->loop(function($sql){
			$eq = $this->eq();

			try {
				$sql->query_raw("CREATE TABLE test (id INTEGER)");
			} catch(SQLError $err) {
				# table exists
				$eq($err->code, SQLError::EXECUTION_ERROR);
			}

			$conn = $sql->get_connection();

			# successful transaction
			$conn->beginTransaction();
			$sql->query_raw("CREATE TABLE try0 (id INTEGER)");
			$sql->query_raw("CREATE TABLE try1 (id INTEGER)");
			$conn->commit();
			# there should be no thrown exception
			foreach (['try0', 'try1'] as $table) {
				$id = $sql->query(sprintf(
					"SELECT id val FROM %s", $table));
				$eq(false, $id);
			}

			# failed transaction
			$conn->beginTransaction();
			try {
				$sql->query_raw("CREATE TABLE try2 (id INTEGER)");
				$sql->query_raw("CREATE TABLE try1 (id INTEGER)");
				$conn->commit();
			} catch(SQLError $err) {
				$eq($err->code, SQLError::EXECUTION_ERROR);
				$conn->rollBack();
			}
			try {
				# try2 should be rolled back
				$sql->query("SELECT 1 val FROM try2");
			} catch(SQLError $err) {
				$eq($err->code, SQLError::EXECUTION_ERROR);
			}
		});
	}
}
