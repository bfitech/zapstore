<?php


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger as Logger;


/**
 * SQL exception class.
 */
class SQLError extends \Exception {

	/** Database not supported. */
	const DBTYPE_ERROR = 0x10;
	/** Connection arguments invalid. */
	const CONNECTION_ARGS_ERROR = 0x20;
	/** Connection failed. */
	const CONNECTION_ERROR = 0x30;
	/** SQL execution failed. */
	const EXECUTION_ERROR = 0x40;

	/** Default errno. */
	public $code = 0;
	/** Default errmsg. */
	public $message = null;

	private $stmt = null;
	private $args = [];

	/**
	 * Constructor.
	 */
	public function __construct(
		$code, $message, $stmt=null, $args=[]
	) {
		$this->code = $code;
		$this->message = $message;
		$this->stmt = $stmt;
		$this->args = $args;
		parent::__construct($message, $code, null);
	}

	/**
	 * Get SQL statement from exception.
	 */
	public function getStmt() {
		return $this->stmt;
	}

	/**
	 * Get SQL parameters from exception.
	 */
	public function getArgs() {
		return $this->args;
	}

}


/**
 * SQL class.
 */
class SQL {

	private $dbtype = null;
	private $dbhost = null;
	private $dbport = null;
	private $dbuser = null;
	private $dbpass = null;
	private $dbname = null;

	private $verified_params = null;

	private $connection = null;
	private $connection_string = '';

	/** Logging service. */
	public static $logger = null;

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict.
	 * @param Logger $logger Logger instance.
	 */
	public function __construct($params, Logger $logger=null) {

		self::$logger = $logger ? $logger : new Logger();
		self::$logger->debug("SQL: object instantiated.");

		$verified_params = [];
		foreach ([
			'dbtype', 'dbhost', 'dbport',
			'dbuser', 'dbpass', 'dbname'
		] as $key) {
			if (!isset($params[$key]))
				continue;
			if ($key == 'dbtype' && $params[$key] == 'postgresql')
				$params[$key] = 'pgsql';
			$this->$key = $params[$key];
			$verified_params[$key] = $params[$key];
		}

		foreach (['dbtype', 'dbname'] as $key) {
			if (!$this->$key) {
				self::$logger->error(sprintf(
					"SQL: param not supplied: '%s'.", $key));
				throw new SQLError(
					SQLError::CONNECTION_ARGS_ERROR,
					sprintf("'%s' not supplied.", $key));
			}
		}

		if ($this->dbtype == 'sqlite3') {
			$this->connection_string = 'sqlite:' . $this->dbname;
			$this->verified_params = $verified_params;
		} elseif ($this->dbtype == 'mysql') {
			if (!$this->dbuser) {
				self::$logger->error(
					"SQL: param not supplied: 'dbuser'.");
				throw new SQLError(
					SQLError::CONNECTION_ARGS_ERROR,
					"'dbuser' not supplied.");
			}
			$cstr = 'mysql:';
			$cstr .= sprintf("dbname=%s", $this->dbname);
			if ($this->dbhost) {
				$cstr .= sprintf(';host=%s', $this->dbhost);
				if ($this->dbport)
					$cstr .= sprintf(';port=%s', $this->dbhost);
			}
			$this->connection_string = $cstr;
			$this->verified_params = $verified_params;
		} elseif ($this->dbtype == 'pgsql') {
			if (!$this->dbuser) {
				self::$logger->error(
					"SQL: param not supplied: 'dbuser'.");
				throw new SQLError(
					SQLError::CONNECTION_ARGS_ERROR,
					"'dbuser' not supplied.");
			}
			$cstr = 'pgsql:';
			$cstr .= sprintf("dbname=%s", $this->dbname);
			if ($this->dbhost) {
				$cstr .= sprintf(";host=%s", $this->dbhost);
				if ($this->dbport)
					$cstr .= sprintf(";port=%s", $this->dbport);
			}
			$cstr .= sprintf(";user=%s", $this->dbuser);
			if ($this->dbpass)
				$cstr .= sprintf(";password=%s", $this->dbpass);
			$this->connection_string = $cstr;
			$this->verified_params = $verified_params;
		} else {
			self::$logger->error(sprintf(
				"SQL: database not supported: '%s'.",
				$this->dbtype));
			throw new SQLError(SQLError::DBTYPE_ERROR,
				$this->dbtype . " not supported.");
		}

		try {
			if (in_array($this->dbtype, ['sqlite3', 'pgsql'])) {
				$this->connection = new \PDO(
					$this->connection_string);
			} else {
				$this->connection = new \PDO(
					$this->connection_string,
					$this->dbuser, $this->dbpass);
			}
			self::$logger->debug(sprintf(
				"SQL: connection opened: '%s'.",
				json_encode($this->verified_params)));
		} catch (\PDOException $e) {
			self::$logger->error(sprintf(
				"SQL: connection failed: '%s'.",
				json_encode($this->verified_params)));
			throw new SQLError(SQLError::CONNECTION_ERROR,
				$this->dbtype . " connection error.");
		}

		$this->connection->setAttribute(
			\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

		# set pragma for SQLite3
		if ($this->dbtype == 'sqlite3')
			$this->connection->exec("PRAGMA foreign_keys=ON");
	}

	/**
	 * Open connection.
	 *
	 * @deprecated
	 *     Opening connection now is automatically done by constructor.
	 *     When connection fails, throw an exception instead of modify
	 *     certain property.
	 *
	 */
	public function open() {
	}

	/**
	 * Get Unix timestamp from database server.
	 *
	 * @return int Unix epoch.
	 */
	final public function time() {
		if ($this->dbtype == 'pgsql')
			return $this->query(
				"SELECT EXTRACT('epoch' from CURRENT_TIMESTAMP) AS now"
			)['now'];
		if ($this->dbtype == 'mysql')
			return $this->query(
				"SELECT UNIX_TIMESTAMP() AS now")['now'];
		return $this->query(
			"SELECT strftime('%s', CURRENT_TIMESTAMP) AS now"
		)['now'];
	}

	/**
	 * Get Unix timestamp from database server.
	 *
	 * @return int Unix epoch.
	 * @deprecated Renamed to SQL::time.
	 */
	public function unix_epoch() {
		return $this->time();
	}

	/**
	 * SQL datetime fragment.
	 */
	private function stmt_fragment_datetime($delta) {
		$sign = $delta >= 0 ? '+' : '-';
		$delta = abs($delta);
		$date = '';
		switch ($this->dbtype) {
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
	 * SQL statement fragment.
	 *
	 * @param string $part A part sensitive to the database being used,
	 *     one of these: 'engine', 'index', 'datetime'.
	 * @param array $args Dict of parameters for $part, for 'datetime'
	 *     only.
	 */
	public function stmt_fragment($part, $args=[]) {
		$type = $this->dbtype;
		if ($part == 'engine') {
			if ($type == 'mysql')
				# we only intent to support FOREIGN KEY-capable engines
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
			return $this->stmt_fragment_datetime($delta);
		}
		return "";
	}

	/**
	 * Close connection.
	 */
	public function close() {
		$this->connection = null;
		$this->connection_string = '';
		$this->verified_params = null;
	}

	/**
	 * Prepare and execute statement.
	 */
	private function prepare_statement($stmt, $args=[]) {
		if (!$this->connection) {
			self::$logger->error(sprintf(
				"SQL: connection failed: '%s'.",
				json_encode($this->verified_params)));
			throw new SQLError(SQLError::CONNECTION_ERROR,
				$this->dbtype . " connection error.");
		}

		$conn = $this->connection;
		try {
			$pstmt = $conn->prepare($stmt);
		} catch (\PDOException $e) {
			self::$logger->error(sprintf(
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
			self::$logger->error(sprintf(
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
	final public function query($stmt, $args=[], $multiple=null) {
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
	final public function query_raw($stmt, $args=[]) {
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
	 * @param string $pk Primary key from which last insert ID should
	 *     be retrieved. This won't take any effect on databases other
	 *     than Postgres. This can take any column name, not necessarily
	 *     column with PRIMARY KEY attributes. If left null, the whole
	 *     new row is returned as an array. Using invalid column will
	 *     throw exception.
	 * @return int|array Last insert ID or IDs on success. Exception
	 *     thrown on error.
	 */
	final public function insert($table, $args=[], $pk=null) {

		$keys = $vals = [];
		$keys = array_keys($args);
		$vals = array_fill(0, count($args), '?');

		$columns = implode(',', $keys);
		$placeholders = implode(',', $vals);

		$stmt = "INSERT INTO $table ($columns) VALUES ($placeholders)";
		if ($this->dbtype == 'pgsql')
			$stmt .= " RETURNING " . ($pk ? $pk : '*');

		$pstmt = $this->prepare_statement($stmt, $args);

		if ($this->dbtype == 'pgsql') {
			$last = $pstmt->fetch(\PDO::FETCH_ASSOC);
			$ret = $pk ? $last[$pk] : $last;
		} else {
			$ret = $this->connection->lastInsertId();
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
	final public function update($table, $args, $where=[]) {
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
	final public function delete($table, $where=[]) {
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

	/* get properties */

	/**
	 * Retrieve connection.
	 *
	 * Useful for checking whether connection is open and other
	 * things, e.g. creating custom functions on SQLite3.
	 */
	public function get_connection() {
		return $this->connection;
	}

	/**
	 * Retrieve formatted connection string.
	 *
	 * Use this, e.g. for dblink connection on Postgres.
	 */
	public function get_connection_string() {
		return $this->connection_string;
	}

	/**
	 * Retrive successful connection parameters.
	 */
	public function get_connection_params() {
		return $this->verified_params;
	}

}
