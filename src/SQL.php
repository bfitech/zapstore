<?php


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


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

		$this->verified_params = $verified_params;

		$this->format_connection_string();

		$this->open_pdo_connection();
	}

	/**
	 * Open PDO connection.
	 */
	private function open_pdo_connection() {
		try {
			$this->connection = in_array(
					$this->dbtype, ['sqlite3', 'pgsql'])
				? new \PDO($this->connection_string)
				: new \PDO(
					$this->connection_string,
					$this->dbuser, $this->dbpass);
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
	 * Verify and format connection string.
	 */
	private function format_connection_string() {
		if (!in_array($this->dbtype, ['sqlite3', 'mysql', 'pgsql'])) {
			$this->verified_params = null;
			self::$logger->error(sprintf(
				"SQL: database not supported: '%s'.",
				$this->dbtype));
			throw new SQLError(SQLError::DBTYPE_ERROR,
				$this->dbtype . " not supported.");
		}

		if ($this->dbtype == 'sqlite3')
			return $this->connection_string = 'sqlite:' . $this->dbname;

		if (!$this->dbuser) {
			$this->verified_params = null;
			self::$logger->error(
				"SQL: param not supplied: 'dbuser'.");
			throw new SQLError(
				SQLError::CONNECTION_ARGS_ERROR,
				"'dbuser' not supplied.");
		}

		$cstr = sprintf("%s:dbname=%s", $this->dbtype, $this->dbname);
		if ($this->dbhost) {
			$cstr .= sprintf(';host=%s', $this->dbhost);
			if ($this->dbport)
				$cstr .= sprintf(';port=%s', $this->dbport);
		}

		if ($this->dbtype == 'mysql')
			# mysql uses dbuser and dbpass on PDO constructor
			return $this->connection_string = $cstr;

		$cstr .= sprintf(";user=%s", $this->dbuser);
		if ($this->dbpass)
			$cstr .= sprintf(";password=%s", $this->dbpass);
		return $this->connection_string = $cstr;
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
	 * Check if a table or view exists.
	 *
	 * @param string $table Table or view name.
	 * @return bool True if table or view does exist.
	 * @fixme This will always re-activate logging at the end,
	 *     regardless the logging state. The fix must be in Logger
	 *     class itself where logging state must be exposed.
	 */
	public function table_exists($table) {
		# we can't use placeholder for table name
		if (!preg_match('!^[0-9a-z_]+$!i', $table))
			return false;
		self::$logger->deactivate();
		try {
			$this->query(sprintf("SELECT 1 FROM %s LIMIT 1", $table));
			self::$logger->activate();
			return true;
		} catch(SQLError $e) {
			self::$logger->activate();
			return false;
		}
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
