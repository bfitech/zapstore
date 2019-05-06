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
		int $code, string $message, string $stmt=null, array $args=[]
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
 * SQL Utilities
 */
class SQLUtils {

	private $verified_params = null;
	private $connection = null;
	private $connection_string = '';

	/* set properties */

	/**
	 * Set Connection
	 *
	 * @param Connection $connection.
	 */
	public function set_connection($connection=null) {
		$this->connection = $connection;
	}

	/**
	 * Set Connection String
	 *
	 * @param string $connection_string.
	 */
	public function set_connection_string(string $connection_string) {
		$this->connection_string = $connection_string;
	}

	/**
	 * Set Connection Params
	 *
	 * @param array $verified_params.
	 */
	public function set_connection_params(array $verified_params=null) {
		$this->verified_params = $verified_params;
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

	/**
	 * SQL datetime fragment.
	 */
	public function stmt_fragment_datetime($delta, string $type) {
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
	 * Verify and format connection string.
	 */
	public function format_connection_string(
		string $dbtype, string $dbhost=null, string $dbport=null,
		string $dbuser=null, string $dbpass=null, string $dbname=null,
		Logger $logger
	) {
		if (!in_array($dbtype, ['sqlite3', 'mysql', 'pgsql'])) {
			self::set_connection_params(null);
			$logger->error(sprintf(
				"SQL: database not supported: '%s'.",
				$dbtype));
			throw new SQLError(SQLError::DBTYPE_ERROR,
				$dbtype . " not supported.");
		}

		if ($dbtype == 'sqlite3') {
			self::set_connection_string('sqlite:' . $dbname);
			return self::get_connection_string();
		}

		if (!$dbuser) {
			self::set_connection_params(null);
			$logger->error(
				"SQL: param not supplied: 'dbuser'.");
			throw new SQLError(
				SQLError::CONNECTION_ARGS_ERROR,
				"'dbuser' not supplied.");
		}

		$cstr = sprintf("%s:dbname=%s", $dbtype, $dbname);
		if ($dbhost) {
			$cstr .= sprintf(';host=%s', $dbhost);
			if ($dbport)
				$cstr .= sprintf(';port=%s', $dbport);
		}

		if ($dbtype == 'mysql') {
			# mysql uses dbuser and dbpass on PDO constructor
			self::set_connection_string($cstr);
			return self::get_connection_string();
		}

		$cstr .= sprintf(";user=%s", $dbuser);
		if ($dbpass)
			$cstr .= sprintf(";password=%s", $dbpass);
		self::set_connection_string($cstr);
		return self::get_connection_string();
	}

}


/**
 * SQL class.
 */
class SQL extends SQLUtils {

	private $dbtype = null;
	private $dbhost = null;
	private $dbport = null;
	private $dbuser = null;
	private $dbpass = null;
	private $dbname = null;

	/** Logging service. */
	public static $logger = null;

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict.
	 * @param Logger $logger Logger instance.
	 */
	public function __construct(array $params, Logger $logger=null) {

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

		self::set_connection_params($verified_params);

		self::format_connection_string(
			$this->dbtype, $this->dbhost, $this->dbport,
			$this->dbuser, $this->dbpass, $this->dbname,
			self::$logger);

		$this->open_pdo_connection();
	}

	/**
	 * Open PDO connection.
	 */
	private function open_pdo_connection() {
		$verified_params = self::get_connection_params();
		unset($verified_params['dbpass']);
		try {
			$connection = in_array(
					$this->dbtype, ['sqlite3', 'pgsql'])
				? new \PDO(self::get_connection_string())
				: new \PDO(
					self::get_connection_string(),
					$this->dbuser, $this->dbpass);
			self::set_connection($connection);
			self::$logger->debug(sprintf(
				"SQL: connection opened: '%s'.",
				json_encode($verified_params)));
		} catch (\PDOException $e) {
			self::$logger->error(sprintf(
				"SQL: connection failed: '%s'.",
				json_encode($verified_params)));
			throw new SQLError(SQLError::CONNECTION_ERROR,
				$this->dbtype . " connection error.");
		}

		self::get_connection()->setAttribute(
			\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

		# set pragma for SQLite3
		if ($this->dbtype == 'sqlite3')
			self::get_connection()->exec("PRAGMA foreign_keys=ON");
	}

	/**
	 * Close connection.
	 */
	public function close() {
		self::set_connection(null);
		self::set_connection_string('');
		self::set_connection_params(null);
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
	 * SQL statement fragment.
	 *
	 * @param string $part A part sensitive to the database being used,
	 *     one of these: 'engine', 'index', 'datetime'.
	 * @param array $args Dict of parameters for $part, for 'datetime'
	 *     only.
	 */
	public function stmt_fragment(string $part, array $args=[]) {
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
			return self::stmt_fragment_datetime($delta, $type);
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
		if (!self::get_connection()) {
			$verified_params = self::get_connection_params();
			unset($verified_params['dbpass']);
			self::$logger->error(sprintf(
				"SQL: connection failed: '%s'.",
				json_encode($verified_params)));
			throw new SQLError(SQLError::CONNECTION_ERROR,
				$this->dbtype . " connection error.");
		}

		$conn = self::get_connection();
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
		$keys = $vals = [];
		$keys = array_keys($args);
		$vals = array_fill(0, count($args), '?');

		$columns = implode(',', $keys);
		$placeholders = implode(',', $vals);

		$stmt = "INSERT INTO $table ($columns) VALUES ($placeholders)";
		if ($this->dbtype == 'pgsql')
			$stmt .= " RETURNING " . ($pkey ? $pkey : '*');

		$pstmt = $this->prepare_statement($stmt, $args);

		if ($this->dbtype == 'pgsql') {
			$last = $pstmt->fetch(\PDO::FETCH_ASSOC);
			$ret = $pkey ? $last[$pkey] : $last;
		} else {
			$ret = self::get_connection()->lastInsertId();
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
