<?php


namespace BFITech\ZapStore;

class SQL {

	/**
	 * Error number.
	 *
	 * 0 := OK
	 * 1 := database type not supported
	 * 2 := failed connection
	 * 3 := invalid query
	 * 4 := argument binding error
	 * 5 := PDO exception
	 */
	public $errno = 0;
	public $errmsg = '';

	private $dbtype = null;
	private $dbhost = false;
	private $dbport = false;
	private $dbuser = false;
	private $dbpass = false;
	private $dbname = false;

	private $_verified_params = null;

	private $_connection_string = '';
	private $_connection = false;

	# pgsql-specific, since lastinsertid is fetched from statement object
	# instead of connection
	private $_isinsert = false;
	private $_lastinsertid = null;

	/**
	 * Constructor.
	 *
	 * @param array $params Connection associative array.
	 */
	public function __construct($params) {

		$verified_params = [];
		foreach ([
			'dbtype', 'dbhost', 'dbport',
			'dbuser', 'dbpass', 'dbname'
		] as $k) {
			if (isset($params[$k])) {
				if ($k == 'dbtype' && $params[$k] == 'postgresql')
					$c[$k] = 'pgsql';
				$this->$k = $params[$k];
				$verified_params[$k] = $params[$k];
			}
		}
		if (!$this->dbname)
			return;
		if ($this->dbtype == 'sqlite3') {
			$this->_connection_string = 'sqlite:' . $this->dbname;
			$this->_verified_params = $verified_params;
			return;
		}
		if (!$this->dbuser)
			return;
		if ($this->dbtype == 'mysql') {
			$conn = 'mysql:';
			$conn .= sprintf("dbname=%s", $this->dbname);
			if ($this->dbhost) {
				$conn .= sprintf(';host=%s', $this->dbhost);
				if ($this->dbport)
					$conn .= sprintf(';port=%s', $this->dbhost);
			}
			$this->_connection_string = $conn;
			$this->_verified_params = $verified_params;
			return;
		}
		if ($this->dbtype == 'pgsql') {
			$conn = 'pgsql:';
			$conn.= sprintf("dbname=%s", $this->dbname);
			if ($this->dbhost) {
				$conn.= sprintf(";host=%s", $this->dbhost);
				if ($this->dbport)
					$conn.= sprintf(";port=%s", $this->dbport);
			}
			$conn .= sprintf(";user=%s", $this->dbuser);
			if ($this->dbpass)
				$conn .= sprintf(";password=%s", $this->dbpass);
			$this->_connection_string = $conn;
			$this->_verified_params = $verified_params;
		}
	}

	/**
	 * Open connection.
	 */
	public function open() {
		$this->_reset_prop();
		try {
			if (in_array($this->dbtype, ['sqlite3', 'pgsql'])) {
				$this->_connection = new \PDO($this->_connection_string);
			} elseif ($this->dbtype == 'mysql') {
				$passwd = $this->dbpass ? $this->dbpass : null;
				$this->_connection = new \PDO(
					$this->_connection_string, $this->dbuser, $passwd);
			} else {
				$this->_format_error(1, $this->dbtype . " not available.");
				$this->_verified_params = null;
				return false;
			}
		} catch (Exception $e) {
			$this->_format_error(2, $this->dbtype . " connection error.");
			$this->_verified_params = null;
			return false;
		}
		$this->_connection->setAttribute(
			\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		if ($this->dbtype == 'sqlite3')
			$this->_connection->exec("PRAGMA foreign_keys=ON");
		return true;
	}

	/**
	 * Convenient method to get server time.
	 *
	 * @return int Unix timestamp.
	 */
	public function sql_now() {
		switch($this->query) {
			case 'pgsql':
				return $this->query(
					"SELECT EXTRACT('epoch' from CURRENT_TIMESTAMP) AS now");
			case 'mysql':
				return $this->query(
					"SELECT UNIX_TIMESTAMP() AS now");
			case 'sqlite':
				return $this->query(
					"SELECT strftime('%s', CURRENT_TIMESTAMP) AS now");
			default:
				return null;
		}
	}

	/**
	 * SQL statement fragment.
	 *
	 * @param string $part A part sensitive to the database being use, one
	 *     of these: 'engine', 'index', 'datetime'.
	 * @param array $param Associative array of parameters for the part,
	 *     for 'datetime' only.
	 */
	public function stmt_fragment($part, $args=[]) {
		if ($part == 'engine') {
			if ($this->dbtype == 'mysql')
				# we don't support MyISAM
				return "ENGINE=INNODB";
			return "";
		}
		if ($part == "index") {
			switch ($this->dbtype) {
				case 'pgsql':
					return 'INTEGER SERIAL';
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
					$date = "timestamp 'now' + interval '%s second'";
					$date = explode('.', $date)[0];
					break;
				case 'mysql':
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
		$this->_connection = null;
	}

	/**
	 * Reset connection properties.
	 */
	private function _reset_prop() {
		$this->errno = 0;
		$this->errmsg = '';
		$this->_lastinsertid = null;
	}

	/**
	 * Format error message.
	 */
	private function _format_error($errno, $errmsg) {
		$this->errno = $errno;
		$this->errmsg = $errmsg;
		return [];
	}

	/**
	 * Execute query and return result.
	 *
	 * @param string $stmt SQL statement.
	 * @param array $arg Arguments in numeric array.
	 * @param bool $multiple Whether returned result contains all rows.
	 * @param bool $raw Return connection if true, return rows otherwise.
	 * @return mixed Rows or connection depending on $raw switch, 
	 */
	public function query($stmt, $arg=[], $multiple=false, $raw=false) {
		$this->_reset_prop();

		$qc = $this->_connection;

		$pstmt = $qc->prepare($stmt);
		$err = $qc->errorInfo();
		if (isset($err[2]) && !empty($err[2]) != '')
			return $this->_format_error(
				3, "Query error: " . $stmt . ': ' . $err[2]);

		try {
			$pstmt->execute($arg);
		} catch (\PDOException $e) {
			$err = ['8888', 127, $e->getMessage()];
			return $this->_format_error(
				5, sprintf('[%s] %s', $err[1], $err[2]));
		}

		if (!$raw) {
			$res = ($multiple)
				? $pstmt->fetchAll(\PDO::FETCH_ASSOC)
				: $pstmt->fetch(\PDO::FETCH_ASSOC);
		} else {
			if ($this->dbtype == 'pgsql' && $this->_isinsert)
				$this->_lastinsertid = $pstmt->fetch(\PDO::FETCH_ASSOC);
			$res = $qc;
		}

		$err = $qc->errorInfo();
		if (isset($err[2]) && !empty($err[2]))
			return $this->_format_error(
				4, sprintf('[%s] %s', $err[1], $err[2]));

		return $res;
	}

	/**
	 * Raw query execution.
	 *
	 * Use this for, e.g. DDL statements.
	 *
	 * @param string $stmt SQL statement.
	 */
	public function query_raw($stmt){
		return $this->query($stmt, [], false, true);
	}

	/**
	 * Prepare statement for INSERT, UPDATE, and DELETE.
	 *
	 * @param string $case Whether it's INSERT, UPDATE or DELETE.
	 * @param string $tab Table name.
	 * @param array $args Arguments in associative array.
	 * @param array $where WHERE arguments in associative array, for UPDATE only.
	 * @param string $pk Primary key the last insert id should be look on, postgres only.
	 * @return bool|string False on failure, SQL statement otherwise.
	 */
	private function _prepare($case='update', $tab, $args=[], $where=[], $pk='') {
		if (!$args)
			return false;

		$qt = '';

		if ($case == 'insert') {
			$qtk = $qtv = '';
			foreach ($args as $k => $v) {
				$qtk.= "$k,";
				$qtv.= "?,";
			}
			$qtk = rtrim($qtk, ',');
			$qtv = rtrim($qtv, ',');
			$qt = "INSERT INTO {$tab} ($qtk) VALUES ($qtv)";
			# postgres only
			if ($this->dbtype == 'pgsql') {
				$qt.= " RETURNING ";
				$qt.= $pk != '' ? $pk : '*';
			}
			return $qt;
		}
		if ($case == 'update') {
			$qa = '';
			foreach ($args as $k => $v)
				$qa.= "$k=?,";
			$qa = rtrim($qa, ',');

			$qt = "UPDATE $tab SET $qa";

			if ($where) {
				$qt.= " WHERE ";
				$wheres = array();
				foreach ($where as $k => $v)
					$wheres[] = "$k=?";
				$qt.= implode(" AND ",$wheres);
			}

			return $qt;
		}
		if ($case == 'delete') {
			$qt.= "DELETE FROM {$tab} WHERE ";
			$wheres = array();
			foreach ($args as $k => $v)
				$wheres[] = "$k=?";
			$qt.= implode(" AND ",$wheres);
			return $qt;
		}
		return false;
	}

	/**
	 * Generic function for INSERT, UPDATE and DELETE.
	 */
	private function _exec($case='update', $tab, $args=[], $where=[], $pk='') {
		$this->_reset_prop();

		if ($this->dbtype == 'pgsql' && $case == 'insert')
			$this->_isinsert = true;

		$stmt = $this->_prepare($case, $tab, $args, $where, $pk);
		if (!$stmt)
			return $this->_format_error(3, "Query error.");

		$argsval = array();
		foreach ($args as $av)
			$argsval[] = $av;
		foreach ($where as $aw)
			$argsval[] = $aw;

		$this->query($stmt, $argsval, false, true);

		if ($this->errno !== 0)
			return false;

		$ret = false;
		if ($case == 'insert') {
			if ($this->dbtype == 'pgsql' && $pk != '') {
				$r = $this->_lastinsertid;
				if (isset($r[$pk]))
					$ret = $r[$pk];
				$this->_isinsert = false;
			} else {
				$ret = $this->_connection->lastInsertId();
			}
		} else {
			$ret = true;
		}

		return $ret;
	}

	/**
	 * Insert statement.
	 *
	 * @param string $tab Table name.
	 * @param array $args Associative array of what to INSERT.
	 * @param string $pk Primary key from which last insert ID should be
	 *   returned, postgres only.
	 * @return int|bool Last insert ID on success, false on failure.
	 */
	public function insert($tab, $args, $pk='') {
		return $this->_exec('insert', $tab, $args, [], $pk);
	}

	/**
	 * Update statement.
	 *
	 * @param string $tab Table name.
	 * @param array $args Associative array of what to UPDATE.
	 * @param array $where Associative array of WHERE to UPDATE.
	 * @return bool True on success.
	 */
	public function update($tab, $args, $where) {
		return $this->_exec('update', $tab, $args, $where);
	}

	/**
	 * Delete statement.
	 *
	 * @param string $tag Table name.
	 * @param array $args Associative array of WHERE to delete.
	 * @return bool True on success.
	 */
	public function delete($tab, $args) {
		return $this->_exec('delete', $tab, $args);
	}

	/* get properties */

	/**
	 * Retrieve connection.
	 *
	 * Useful for checking whether connection is open or other
	 * things, e.g. creating custom functions.
	 */
	public function get_connection() {
		return $this->_connection;
	}

	/**
	 * Retrieve formatted connection string.
	 *
	 * Use this, e.g. for dblink connection in postgres.
	 */
	public function get_connection_string() {
		if (!$this->_connection)
			return null;
		return $this->_connection_string;
	}

	/**
	 * Retrive successful connection parameters.
	 */
	public function get_connection_params() {
		return $this->_verified_params;
	}
}

