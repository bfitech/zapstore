<?php declare(strict_types=1);


namespace BFITech\ZapStore;


use BFITech\ZapCore\Common;
use BFITech\ZapCore\Logger;


/**
 * SQL class.
 */
class SQL extends SQLConn {

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict with keys:<br>
	 *     - `dbtype`, database type: one of 'sqlite3', 'mysql',
	 *       'pgsql'; 'postgresql' is an alias to 'pgsql'
	 *     - `dbhost`, Unix socket is not accepted, ignored on
	 *        SQLite3<br>
	 *     - `dbport`, ignored on SQLite3, do not set to use default<br>
	 *     - `dbname`, absolute path to database file on SQLite3
	 *     - `dbuser`, ignored on SQLite3<br>
	 *     - `dbpass`, ignored on SQLite3<br>
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
	final public function time() {
		$type = $this->get_dbtype();
		if ($type == 'pgsql')
			return $this->query(
				"SELECT EXTRACT('epoch' from CURRENT_TIMESTAMP) AS now"
			)['now'];
		if ($type == 'mysql')
			return $this->query(
				"SELECT UNIX_TIMESTAMP() AS now")['now'];
		return $this->query(
			"SELECT strftime('%s', CURRENT_TIMESTAMP) AS now"
		)['now'];
	}

	/**
	 * SQL statement fragment.
	 *
	 * @param string $part A part sensitive to the database being used,
	 *     one of these: 'engine', 'index', 'datetime'.
	 * @param array $args Dict of parameters for $part, for 'datetime'
	 *     only.
	 * @return string Fragment of database-sensitive SQL statement
	 *     fragment.
	 */
	public function stmt_fragment(string $part, array $args=[]) {
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
	 * @param int $delta Delta time, in second.
	 * @return string Delta time clause for use in SQL statements.
	 */
	public function stmt_fragment_datetime(int $delta) {
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
	public function table_exists(string $table) {
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
	 * @param string $stmt SQL statement.
	 * @param array $args Arguments in numeric array.
	 * @param bool $multiple Whether returned result contains all rows.
	 * @return mixed Rows or connection depending on $raw switch.
	 * @note Since SQLite3 does not enforce type safety, make sure
	 *     arguments are cast properly before usage.
	 * @see https://archive.fo/vKBEz#selection-449.0-454.0
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
	 * This will execute arbitray single SQL queries. Do not execute
	 * multiple queries at once to avoid undocumented side effects.
	 * To execute successive raw queries safely, disable autocommit as
	 * follows:
	 *
	 * @code
	 * $connection = new SQL(...);
	 * try {
	 *     $connection = $this->get_connection();
	 *     $connection->beginTransaction();
	 *     $this->query_raw(...);
	 *     $this->query_raw(...);
	 *     $this->query_raw(...);
	 *     $connection->commit();
	 * } catch(SQLError $e) {
	 *     $connection->rollBack();
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
	 * @return int|array Last insert ID or IDs on success. Exception
	 *     thrown on error.
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
