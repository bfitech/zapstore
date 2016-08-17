<?php

/**
 * RDBMS PDO Wrapper 
 */

class db {

	/**
	 * Error number.
	 *
	 * 0 := OK
	 * 1 := database type not supported
	 * 2 := failed connection
	 * 3 := invalid query
	 * 4 := argument binding error
	 */
	public $errno = 0;
	public $errmsg = '';

	/**
	 * Connection string.
	 *
	 * Use this, e.g. for dblink connection in postgres.
	 */
	public $connection_string = '';

	private $dbtype = null;
	private $dbhost = false;
	private $dbport = false;
	private $dbuser = false;
	private $dbpass = false;
	private $dbname = false;

	private $_connection_string = '';
	private $_connection = false;

	# pgsql-specific, since lastinsertid is fetched from statement object
	# instead of connection
	private $_isinsert = false;
	private $_lastinsertid = null;

	/**
	 * Constructor.
	 *
	 * @param array $c Connection associative array.
	 */
	public function __construct($c) {

		foreach (array('dbtype','dbhost','dbport','dbuser','dbpass','dbname') as $k) {
			if (isset($c[$k])) {
				if ($k == 'dbtype' && in_array($c[$k],array('postgresql')))
					$c[$k] = 'pgsql';
				$this->$k = $c[$k];
			}
		}
		if (!$this->dbname)
			return;
		if ($this->dbtype == 'sqlite3') {
			$this->_connection_string = 'sqlite:'.(string)$this->dbname;
			return;
		}
		if (!$this->dbuser)
			return;
		if ($this->dbtype == 'mysql') {
			$conn = 'mysql:';
			$conn.= sprintf("dbname=%s",$this->dbname);
			if ($this->dbhost) {
				$conn.= sprintf(';host=%s',$this->dbhost);
				if ($this->dbport)
					$conn.= sprintf(';port=%s',$this->dbhost);
			}
			$this->_connection_string = $conn;
			return;
		}
		if ($this->dbtype == 'pgsql') {
			$conn = 'pgsql:';
			$conn.= sprintf("dbname=%s",$this->dbname);
			if ($this->dbhost) {
				$conn.= sprintf(";host=%s",$this->dbhost);
				if ($this->dbport)
					$conn.= sprintf(";port=%s",$this->dbport);
			}
			$conn.= sprintf(";user=%s",$this->dbuser);
			if ($this->dbpass)
				$conn.= sprintf(";password=%s",$this->dbpass);
			$this->_connection_string = $conn;
		}
	}

	/**
	 * Open connection.
	 */
	public function open() {
		$this->_reset_prop();
		$this->connection_string = $this->_connection_string;
		try {
			if (in_array($this->dbtype,array('sqlite3','pgsql')))
				$this->_connection = new PDO($this->_connection_string);
			elseif ($this->dbtype == 'mysql') {
				$passwd = $this->dbpass ? $this->dbpass : null;
				$this->_connection = new PDO($this->_connection_string,$this->dbuser,$passwd);
			}
			else {
				$this->_format_error(1,$this->dbtype." not available.");
				return false;
			}
		} catch (Exception $e) {
			$this->_format_error(2,$this->dbtype." connection error.");
			return false;
		}
		if (!defined('NOW')) {
			# timestamp is always on sql server side
			if ($this->dbtype == 'pgsql')
				$now = $this->query(
					"SELECT EXTRACT('epoch' from CURRENT_TIMESTAMP) AS now");
			elseif ($this->dbtype == 'mysql')
				$now = $this->query(
					"SELECT UNIX_TIMESTAMP() AS now");
			elseif ($this->dbtype == 'sqlite3')
				$now = $this->query(
					"SELECT strftime('%s',CURRENT_TIMESTAMP) AS now");
			else
				$now = time();
			define('NOW',intval($now['now']));
		}
		return true;
	}

	/**
	 * Close connection.
	 */
	public function close() {
		$this->_connection = null;
	}

	private function _reset_prop() {
		$this->errno = 0;
		$this->errmsg = '';
		$this->_lastinsertid = null;
	}

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
			return $this->_format_error(3, "Query error: " . $stmt . ': ' . $err[2]);

		$pstmt->execute($arg);

		if (!$raw) {
			$res = ($multiple)
				? $pstmt->fetchAll(PDO::FETCH_ASSOC)
				: $pstmt->fetch(PDO::FETCH_ASSOC);
		}
		else {
			if ($this->dbtype == 'pgsql' && $this->_isinsert)
				$this->_lastinsertid = $pstmt->fetch(PDO::FETCH_ASSOC);
			$res = $qc;
		}

		$err = $qc->errorInfo();
		if (isset($err[2]) && !empty($err[2]))
			return $this->_format_error(4, sprintf('[%s] %s', $err[1], $err[2]));

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
		$this->query($stmt, [], false, true);
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

			$qt = "UPDATE {$tab} SET $qa";
			
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

		if ($this->errno != 0)
			return false;

		$ret = false;
		if ($case == 'insert') {
			if ($this->dbtype == 'pgsql' && $pk != '') {
				$r = $this->_lastinsertid;
				if (isset($r[$pk]))
					$ret = $r[$pk];
				$this->_isinsert = false;
			}
			else
				$ret = $this->_connection->lastInsertId();
		}
		else
			$ret = true;

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
	public function update($tab,$args,$where) {
		return $this->_exec('update', $tab, $args, $where);
	}

	/**
	 * Delete statement.
	 *
	 * @param string $tag Table name.
	 * @param array $args Associative array of WHERE to delete.
	 * @return bool True on success.
	 */
	public function delete($tab,$args) {
		return $this->_exec('delete', $tab, $args);
	}

	/**
	 * Retrieve connection. Useful for e.g. creating custom function.
	 *
	 */

	public function get_connection() {
		return $this->_connection;
	}
}

