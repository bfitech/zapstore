<?php


namespace BFITech\ZapStore;


class SQLError extends \Exception {

	const DBTYPE_ERROR = 0x10;
	const CONNECTION_ARGS_ERROR = 0x20;
	const CONNECTION_ERROR = 0x30;
	const EXECUTION_ERROR = 0x40;

	public $code = 0;
	public $message = null;
	private $stmt = null;
	private $args = [];

	public function __construct(
		$code, $message, $stmt=null, $args=[]
	) {
		$this->code = $code;
		$this->message = $message;
		$this->stmt = $stmt;
		$this->args = $args;
		parent::__construct($message, $code, null);
	}

	public function getStmt() {
		return $this->stmt;
	}

	public function getArgs() {
		return $this->args;
	}
}


class SQL {

	private $dbtype = null;
	private $dbhost = null;
	private $dbport = null;
	private $dbuser = null;
	private $dbpass = null;
	private $dbname = null;

	private $verified_params = null;

	private $connection_string = '';
	private $connection = false;

	# postgres-specific, since lastinsertid is fetched from statement
	# object instead of connection
	private $is_insert = false;
	private $lastinsertid = null;

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict.
	 */
	public function __construct($params) {

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
			if (!$this->$key)
				throw new SQLError(
					SQLError::CONNECTION_ARGS_ERROR,
					sprintf("'%s' not supplied.", $key));
		}

		if ($this->dbtype == 'sqlite3') {
			$this->connection_string = 'sqlite:' . $this->dbname;
			$this->verified_params = $verified_params;
		} elseif ($this->dbtype == 'mysql') {
			if (!$this->dbuser)
				throw new SQLError(
					SQLError::CONNECTION_ARGS_ERROR,
					"'dbuser' not supplied.");
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
			if (!$this->dbuser)
				throw new SQLError(
					SQLError::CONNECTION_ARGS_ERROR,
					"'dbuser' not supplied.");
			$cstr = 'pgsql:';
			$cstr.= sprintf("dbname=%s", $this->dbname);
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
			throw new SQLError(SQLError::DBTYPE_ERROR,
				$this->dbtype . " not supported.");
		}

		try {
			if (in_array($this->dbtype, ['sqlite3', 'pgsql'])) {
				$this->connection = new \PDO($this->connection_string);
			} elseif ($this->dbtype == 'mysql') {
				$this->connection = new \PDO(
					$this->connection_string, $this->dbuser, $this->dbpass);
			} else {
				throw new SQLError(SQLError::DBTYPE_ERROR,
					$this->dbtype . " not supported.");
			}
		} catch (Exception $e) {
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
	 */
	public function open() {
	}

	/**
	 * Convenience method to get Unix timestamp from database server.
	 *
	 * @return int Unix timestamp.
	 */
	public function unix_epoch() {
		switch($this->dbtype) {
			case 'pgsql':
				return $this->query(
					"SELECT EXTRACT('epoch' from CURRENT_TIMESTAMP) AS now"
				)['now'];
			case 'mysql':
				return $this->query(
					"SELECT UNIX_TIMESTAMP() AS now")['now'];
			case 'sqlite3':
				return $this->query(
					"SELECT strftime('%s', CURRENT_TIMESTAMP) AS now"
				)['now'];
			default:
				return null;
		}
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
		if ($part == 'engine') {
			if ($this->dbtype == 'mysql')
				# we don't support MyISAM
				return "ENGINE=InnoDB";
			return "";
		}
		if ($part == "index") {
			switch ($this->dbtype) {
				case 'pgsql':
					return 'SERIAL PRIMARY KEY';
				case 'mysql':
					return 'INTEGER PRIMARY KEY AUTO_INCREMENT';
				case 'sqlite3':
					return 'INTEGER PRIMARY KEY AUTOINCREMENT';
				default:
					return null;
			}
		}
		if ($part == 'datetime') {
			$delta = 0;
			if ($args && isset($args['delta']))
				$delta = (int)$args['delta'];
			$date = '';
			switch ($this->dbtype) {
				case 'sqlite3':
					$date = "(datetime('now', '+%s second'))";
					break;
				case 'pgsql':
					$date = "(" .
					          "now() at time zone 'utc' + " .
					          "interval '%s second'" .
					        ")::timestamp(0)";
					break;
				case 'mysql':
					# mysql cannot accept function default; do
					# not use this on DDL
					$date = "date_add(now(), interval %s second)";
					break;
				default:
					return null;
			}
			return sprintf($date, $delta);
		}
	}

	/**
	 * Close connection.
	 */
	public function close() {
		$this->connection = null;
	}

	/**
	 * Reset connection properties.
	 */
	private function reset_prop() {
		$this->lastinsertid = null;
	}

	/**
	 * Execute query.
	 *
	 * To disable autocommit:
	 *     $this->connection->beginTransaction();
	 *     $this->query(...);
	 *     $this->connection->commit();
	 * and when exception is thrown:
	 *     $this->connection->rollBack();
	 * Use $this->get_connection() to access private $this->connection.
	 *
	 * @param string $stmt SQL statement.
	 * @param array $args Arguments in numeric array.
	 * @param bool $multiple Whether returned result contains all rows.
	 * @param bool $raw Return connection if true, return rows otherwise.
	 * @return mixed Rows or connection depending on $raw switch.
	 *
	 * @note Since SQLite3 does not enforce type safety, make sure arguments
	 *     are cast properly before usage.
	 * @see https://archive.fo/vKBEz#selection-449.0-454.0
	 */
	public function query(
		$stmt, $args=[], $multiple=false,
		$raw=false, $autocommit=true
	) {
		$this->reset_prop();
		if (!$this->connection)
			throw new SQLError(SQLError::CONNECTION_ERROR,
				$this->dbtype . " connection error.");

		$conn = $this->connection;
		try {
			$pstmt = $conn->prepare($stmt);
		} catch (\PDOException $e) {
			throw new SQLError(
				SQLError::EXECUTION_ERROR,
				sprintf("Execution error: %s.", $e->getMessage()),
				$stmt, $args
			);
		}

		try {
			$pstmt->execute(array_values($args));
		} catch (\PDOException $e) {
			throw new SQLError(
				SQLError::EXECUTION_ERROR,
				sprintf("Execution error: %s.", $e->getMessage()),
				$stmt, $args
			);
		}

		if (!$raw) {
			$res = ($multiple)
				? $pstmt->fetchAll(\PDO::FETCH_ASSOC)
				: $pstmt->fetch(\PDO::FETCH_ASSOC);
		} else {
			if ($this->dbtype == 'pgsql' && $this->is_insert)
				$this->lastinsertid = $pstmt->fetch(\PDO::FETCH_ASSOC);
			$res = $conn;
		}

		return $res;
	}

	/**
	 * Raw query execution.
	 *
	 * @param string $stmt SQL statement.
	 */
	public function query_raw($stmt){
		return $this->query($stmt, [], false, true);
	}

	/**
	 * Insert statement.
	 *
	 * @param string $table Table name.
	 * @param array $args Associative array of what to INSERT.
	 * @param string $pk Primary key from which last insert ID should
	 *     be retrieved, postgres only.
	 * @return int|array Last insert ID or IDs on success. Exception
	 *     thrown on error.
	 */
	public function insert($table, $args=[], $pk=null) {

		$keys = $vals = [];
		foreach ($args as $key => $val) {
			$keys[] = $key;
			$vals[] = "?";
		}

		$columns = implode(',', $keys);
		$placeholders = implode(',', $vals);

		$stmt = "INSERT INTO $table ($columns) VALUES ($placeholders)";
		if ($this->dbtype == 'pgsql') {
			# postgres-specific
			$stmt .= " RETURNING ";
			$stmt .= $pk ? $pk : '*';
		}

		$this->is_insert = true;
		$this->query($stmt, $args, false, true);

		$ret = null;
		if ($this->dbtype == 'pgsql') {
			# postgres-specific
			$last = $this->lastinsertid;
			if (!$pk) {
				$this->is_insert = false;
				return $ret;
			}
			if (!isset($last[$pk]))
				# throw exception for invalid primary key, even
				# though insert has been done
				throw new SQLError(SQLError::EXECUTION_ERROR,
					sprintf(
						"Execution error: '%s' primary key not found.",
						$pk),
					$stmt, $args
				);
			$ret = $last[$pk];
		} else {
			$ret = $this->connection->lastInsertId();
		}

		$this->is_insert = false;
		return $ret;
	}

	/**
	 * Update statement.
	 *
	 * @param string $tab Table name.
	 * @param array $args Dict of what to UPDATE.
	 * @param array $where Dict of WHERE to UPDATE.
	 */
	public function update($table, $args, $where=[]) {
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

		$this->query($stmt, $params, false, true);
	}

	/**
	 * Delete statement.
	 *
	 * @param string $table Table name.
	 * @param array $where Dict of WHERE to delete.
	 */
	public function delete($table, $where=[]) {
		$stmt = "DELETE FROM $table";
		if ($where) {
			$pair_wheres = [];
			$params = [];
			foreach ($where as $key => $val) {
				$pair_wheres[] = "${key}=?";
				$params[] = $val;
			}
			$stmt .= " WHERE " . implode(" AND ", $pair_wheres);
		}
		$this->query($stmt, $params, false, true);
	}

	/* get properties */

	/**
	 * Retrieve connection.
	 *
	 * Useful for checking whether connection is open and other
	 * things, e.g. creating custom functions.
	 */
	public function get_connection() {
		return $this->connection;
	}

	/**
	 * Retrieve formatted connection string.
	 *
	 * Use this, e.g. for dblink connection in postgres.
	 */
	public function get_connection_string() {
		if (!$this->connection)
			return null;
		return $this->connection_string;
	}

	/**
	 * Retrive successful connection parameters.
	 */
	public function get_connection_params() {
		return $this->verified_params;
	}
}

