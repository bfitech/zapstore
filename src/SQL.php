<?php declare(strict_types=1);


namespace BFITech\ZapStore;


use BFITech\ZapCore\Common;
use BFITech\ZapCore\Logger;


/**
 * SQL class.
 *
 * This wraps all underlying drivers into unified interface consisting
 * of most commonly-used SQL statements:
 *
 * - SQL::query for `SELECT`
 * - SQL::insert for basic `INSERT`
 * - SQL::update for basic `UPDATE`
 * - SQL::delete for basic `DELETE`
 * - SQL::query_raw for arbitray SQL statements
 *
 * and a few other helpers very useful for executing driver-dependent
 * DDL statements.
 *
 * You normally do not use this class directly unless you want to build
 * a driver-agnostic app. Use the driver-specific class instead or
 * better yet, their respective metapackages.
 *
 * @see SQL::get_connection
 * @see MySQL
 * @see PgSQL
 * @see SQLite3
 */
class SQL extends SQLConn {

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict with keys:<br>
	 *     - `string` **dbtype**: one of 'sqlite3', 'mysql', 'pgsql';
	 *       'postgresql' is an alias to 'pgsql'
	 *     - `string` **dbhost**: database host, Unix socket is not
	 *        accepted, ignored on SQLite3
	 *     - `int` **dbport**: database port, ignored on SQLite3, do not
	 *        set to use default
	 *     - `string` **dbname**: database name, absolute path to
	 *        database file on SQLite3
	 *     - `string` **dbuser**: database user, ignored on SQLite3
	 *     - `string` **dbpass**: database password, ignored on SQLite3
	 * @param Logger $logger Logger instance.
	 */
	public function __construct(array $params, Logger $logger=null) {
		$this->open($params, $logger ?? new Logger);
	}

	/**
	 * Get Unix timestamp from database server.
	 *
	 * @return int Unix epoch.
	 */
	final public function time(): int {
		$now = -1;
		switch ($this->get_dbtype()) {
			case 'pgsql':
				$now =  $this->query("
					SELECT
						EXTRACT('epoch' from CURRENT_TIMESTAMP) AS now
				")['now'];
				break;
			case 'mysql':
				$now = $this->query("
					SELECT UNIX_TIMESTAMP() AS now
				")['now'];
				break;
			case 'sqlite3':
				$now = $this->query("
					SELECT strftime('%s', CURRENT_TIMESTAMP) AS now
				")['now'];
		}
		return intval($now);
	}

	/**
	 * SQL statement fragment.
	 *
	 * This method returns certain strings that are necessary for
	 * creating tables.
	 *
	 * @param string $part A part sensitive to the database being used,
	 *     one of these:
	 *     - 'engine': database engine, MySQL only
	 *     - 'index': primary key auto-increment fragment
	 *     - 'datetime': date-generating SQL function call; use
	 *       $args['delta'] to adjust to certain interval
	 * @param array $args Dict of parameters sensitive to $part value.
	 * @return string Fragment of database-sensitive SQL statement
	 *     fragment.
	 *
	 * #### Example
	 *
	 * @code
	 * <?php
	 * $sql = new SQL(...);
	 *
	 * $sql->query_raw(sprintf("
	 *     CREATE TABLE test (
	 *         id %s,
	 *         name VARCHAR(64),
	 *         value INTEGER,
	 *         time TIMESTAMP NOT NULL DEFAULT %s
	 *     ) %s",
	 *     $sql->stmt_fragment('index'),
	 *     $sql->stmt_fragment('datetime', ['delta' => -60]),
	 *     $sql->stmt_fragment('engine')
	 * ));
	 * #
	 * # on SQLite3, this executes:
	 * #
	 * #   CREATE TABLE test (
	 * #     id INTEGER PRIMARY KEY AUTOINCREMENT,
	 * #     value INTEGER,
	 * #     time TIMESTAMP NOT NULL DEFAULT
	 * #       (datetime('now', '-60 second'))
	 * #   )
	 * #
	 * @endcode
	 */
	public function stmt_fragment(
		string $part, array $args=[]
	): string {
		$type = $this->get_dbtype();
		if ($part == 'engine') {
			if ($type == 'mysql')
				# we only intend to support FOREIGN KEY-capable engines
				return "ENGINE=InnoDB";
			return '';
		}
		if ($part == "index") {
			if ($type == 'pgsql')
				return 'SERIAL PRIMARY KEY';
			if ($type == 'mysql')
				return 'INTEGER PRIMARY KEY AUTO_INCREMENT';
			return 'INTEGER PRIMARY KEY AUTOINCREMENT';
		}
		if ($part == 'datetime') {
			$delta = 0;
			if ($args && isset($args['delta']))
				$delta = (int)$args['delta'];
			return $this->stmt_fragment_datetime($delta, $type);
		}
		return "";
	}

	/**
	 * SQL datetime fragment.
	 *
	 * Do not use this to set default values on MySQL since it can't
	 * accept function as default values.
	 *
	 * @param int $delta Delta time, in second.
	 * @return string Delta time clause for use in SQL statements.
	 */
	public function stmt_fragment_datetime(int $delta): string {
		$type = $this->get_dbtype();
		$sign = $delta >= 0 ? '+' : '-';
		$delta = abs($delta);
		$date = '';
		switch ($type) {
			case 'sqlite3':
				$date = "(datetime('now', '%s%s second'))";
				break;
			case 'pgsql':
				$date = (
					"(" .
						"now() at time zone 'utc' %s " .
						"interval '%s second'" .
					")::timestamp(0)"
				);
				break;
			case 'mysql':
				# mysql cannot accept function default; do
				# not use this on DDL
				$date = (
					"(date_add(utc_timestamp(), interval %s%s second))"
				);
				break;
		}
		return sprintf($date, $sign, $delta);
	}

	/**
	 * Check if a table or view exists.
	 *
	 * @param string $table Table or view name.
	 * @return bool True if table or view does exist.
	 */
	public function table_exists(string $table): bool {
		# we can't use placeholder for table name
		if (!preg_match('!^[0-9a-z_]+$!i', $table))
			return false;
		try {
			$this->query(sprintf("SELECT 1 FROM %s LIMIT 1", $table));
			return true;
		} catch(SQLError $e) {
			return false;
		}
	}

	/**
	 * Prepare and execute statement.
	 */
	private function prepare_statement(string $stmt, array $args=[]) {
		$log = self::$logger;

		if (!$this->get_connection()) {
			$safe_params = $this->get_safe_params();
			$log->error(sprintf(
				"SQL: connection failed: '%s'.",
				json_encode($safe_params)));
			throw new SQLError(SQLError::CONNECTION_ERROR,
				$this->get_dbtype() . " connection error.");
		}

		$conn = $this->get_connection();
		try {
			$pstmt = $conn->prepare($stmt);
		} catch (\PDOException $e) {
			$log->error(sprintf(
				"SQL: execution failed: %s <- '%s': %s.",
				$stmt, json_encode($args),
				$e->getMessage()));
			throw new SQLError(
				SQLError::EXECUTION_ERROR,
				sprintf("Execution error: %s.", $e->getMessage()),
				$stmt, $args
			);
		}

		try {
			$pstmt->execute(array_values($args));
		} catch (\PDOException $e) {
			$log->error(sprintf(
				"SQL: execution failed: %s <- '%s': %s.",
				$stmt, json_encode($args),
				$e->getMessage()));
			throw new SQLError(
				SQLError::EXECUTION_ERROR,
				sprintf("Execution error: %s.", $e->getMessage()),
				$stmt, $args
			);
		}

		return $pstmt;
	}

	/**
	 * Select query.
	 *
	 * @param string $stmt SQL statement with '?' as positional
	 *     placeholder.
	 * @param array $args Arguments in numeric array.
	 * @param bool $multiple Whether returned result contains all rows.
	 * @return array Query result.
	 * @throws SQLError on syntax error, wrong table or column names, or
	 *     wrong $args types except for SQLite3.
	 * @note Since SQLite3 does not enforce $args type safety, make sure
	 *     $args are cast properly before usage.
	 * @see https://archive.fo/vKBEz#selection-449.0-454.0
	 *
	 * #### Example
	 *
	 * @code
	 * <?php
	 *
	 * // open connection
	 * $sql = new PgSQL(...);
	 *
	 * // retrieve single row of "SELECT * FROM mytable WHERE id=1"
	 * $result = $sql->query("
	 *     SELECT FROM mytable WHERE id=?
	 * ", [1]);
	 *
	 * // retrieve all rows of "SELECT * FROM mytable WHERE id=1"
	 * $result = $sql->query("
	 *     SELECT FROM mytable WHERE id=?
	 * ", [1], true);
	 *
	 * // close the connection
	 * $sql->close();
	 * @endcode
	 */
	final public function query(
		string $stmt, array $args=[], bool $multiple=null
	) {
		$pstmt = $this->prepare_statement($stmt, $args);
		$res = $multiple
			? $pstmt->fetchAll(\PDO::FETCH_ASSOC)
			: $pstmt->fetch(\PDO::FETCH_ASSOC);
		self::$logger->info(sprintf(
			"SQL: query ok: %s <- '%s'.",
			$stmt, json_encode($args)));
		return $res;
	}

	/**
	 * Execute raw query.
	 *
	 * This will execute arbitray SQL queries. Do not execute multiple
	 * queries at once to avoid undocumented side effects. To execute
	 * successive raw queries safely, disable autocommit as follows:
	 *
	 * @code
	 * $sql = new SQL(...);
	 * $conn = $sql->get_connection();
	 * try {
	 *     $conn->beginTransaction();
	 *     $sql->query_raw(...);
	 *     $sql->query_raw(...);
	 *     $sql->query_raw(...);
	 *     $conn->commit();
	 * } catch(SQLError $e) {
	 *     $conn->rollBack();
	 * }
	 * @endcode
	 *
	 * @param string $stmt SQL statement.
	 * @param array $args Arguments in numeric array.
	 * @return object Executed statement which, depending on `$stmt`,
	 *     can be used for later processing. If `$stmt` is a SELECT
	 *     statement, rows can be fetched from this.
	 */
	final public function query_raw(string $stmt, array $args=[]) {
		$pstmt = $this->prepare_statement($stmt, $args);
		self::$logger->info(sprintf(
			"SQL: query raw ok: %s.", $stmt));
		return $pstmt;
	}

	/**
	 * Insert statement.
	 *
	 * @param string $table Table name.
	 * @param array $args Dict of what to INSERT.
	 * @param string $pkey Primary key from which last insert ID should
	 *     be retrieved. This won't take any effect on databases other
	 *     than Postgres. This can take any column name, not necessarily
	 *     column with PRIMARY KEY attributes. If left null, the whole
	 *     new row is returned as an array. Using invalid column will
	 *     throw exception.
	 * @return int|array Last insert ID or IDs on success.
	 * @throws SQLError on wrong table or column names.
	 *
	 * #### Example
	 *
	 * @code
	 * <?php
	 *
	 * // open connection
	 * $sql = new PgSQL(...);
	 *
	 * // executes "INSERT INTO mytable (name) VALUES ('quux')"
	 * $sql->insert('mytable', ['name' => 'quux']);
	 *
	 * // close the connection
	 * $sql->close();
	 * @endcode
	 */
	final public function insert(
		string $table, array $args=[], string $pkey=null
	) {
		$type = $this->get_dbtype();

		$keys = $vals = [];
		$keys = array_keys($args);
		$vals = array_fill(0, count($args), '?');

		$columns = implode(',', $keys);
		$placeholders = implode(',', $vals);

		$stmt = "INSERT INTO $table ($columns) VALUES ($placeholders)";
		if ($type == 'pgsql')
			$stmt .= " RETURNING " . ($pkey ? $pkey : '*');

		$pstmt = $this->prepare_statement($stmt, $args);

		if ($type == 'pgsql') {
			$last = $pstmt->fetch(\PDO::FETCH_ASSOC);
			$ret = $pkey ? $last[$pkey] : $last;
		} else {
			$ret = $this->get_connection()->lastInsertId();
		}

		self::$logger->info(sprintf(
			"SQL: insert ok: %s <- '%s'.",
			$stmt, json_encode($args)));
		return $ret;
	}

	/**
	 * Update statement.
	 *
	 * @param string $table Table name.
	 * @param array $args Dict of what to UPDATE.
	 * @param array $where Dict of WHERE to UPDATE.
	 * @throws SQLError on wrong table name or columns.
	 *
	 * #### Example
	 *
	 * @code
	 * <?php
	 *
	 * // open connection
	 * $sql = new MySQL(...);
	 *
	 * // executes "UPDATE mytable SET name='quux' WHERE id=1"
	 * $sql->update('mytable', ['name' => 'quux'], ['id' => 1]);
	 *
	 * // close the connection
	 * $sql->close();
	 * @endcode
	 */
	final public function update(
		string $table, array $args, array $where=[]
	) {
		$pair_args = $params = [];
		foreach ($args as $key => $val) {
			$pair_args[] = "{$key}=?";
			$params[] = $val;
		}

		$stmt = sprintf("UPDATE $table SET %s",
			implode(",", $pair_args));

		if ($where) {
			$pair_wheres = [];
			foreach ($where as $key => $val) {
				$pair_wheres[] = "${key}=?";
				$params[] = $val;
			}
			$stmt .= " WHERE ";
			$stmt .= implode(' AND ', $pair_wheres);
		}

		self::$logger->info(sprintf(
			"SQL: update ok: %s <- '%s'.",
			$stmt, json_encode($args)));

		$this->prepare_statement($stmt, $params);
	}

	/**
	 * Delete statement.
	 *
	 * @param string $table Table name.
	 * @param array $where Dict of WHERE to delete.
	 * @throws SQLError on wrong table name or columns.
	 *
	 * #### Example
	 *
	 * @code
	 * <?php
	 *
	 * // open connection
	 * $sql = new SQLite3(...);
	 *
	 * // executes "DELETE FROM mytable WHERE id=1"
	 * $sql->delete('mytable', ['id' => 1]);
	 *
	 * // close the connection
	 * $sql->close();
	 * @endcode
	 */
	final public function delete(string $table, array $where=[]) {
		$stmt = "DELETE FROM $table";
		if ($where) {
			$pair_wheres = [];
			$params = [];
			foreach ($where as $key => $val) {
				$pair_wheres[] = "{$key}=?";
				$params[] = $val;
			}
			$stmt .= " WHERE " . implode(" AND ", $pair_wheres);
		}

		self::$logger->info(sprintf(
			"SQL: delete ok: %s <- '%s'.",
			$stmt, json_encode($where)));

		$this->prepare_statement($stmt, $params);
	}

}
