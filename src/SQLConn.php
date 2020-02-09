<?php declare(strict_types=1);


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * SQL connection management.
 *
 * Do not use directly. Use SQL class instead.
 */
abstract class SQLConn {

	private $verified_params = [];
	private $connection_string = '';
	private $connection = null;

	/** Logging service. */
	public static $logger = null;

	/**
	 * Verify and format connection string.
	 */
	private function format_connection_string() {
		$dbtype = $dbhost = $dbport = null;
		$dbuser = $dbpass = $dbname = null;
		extract($this->verified_params);

		$logger = self::$logger;

		if (!in_array($dbtype, ['sqlite3', 'mysql', 'pgsql'])) {
			$this->verified_params = [];
			$logger->error(sprintf(
				"SQL: database not supported: '%s'.",
				$dbtype));
			throw new SQLError(SQLError::DBTYPE_ERROR,
				$dbtype . " not supported.");
		}

		if ($dbtype == 'sqlite3') {
			$this->connection_string = 'sqlite:' . $dbname;
			return $this->get_connection_string();
		}

		if (!$dbuser) {
			$this->verified_params = [];
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
			$this->connection_string = $cstr;
			return $this->get_connection_string();
		}

		$cstr .= sprintf(";user=%s", $dbuser);
		if ($dbpass)
			$cstr .= sprintf(";password=%s", $dbpass);
		$this->connection_string = $cstr;
		return $this->get_connection_string();
	}

	/**
	 * Open PDO connection.
	 */
	private function open_pdo_connection() {
		$dbtype = $dbuser = $dbpass = null;
		extract($this->verified_params);

		$safe_params = $this->get_safe_params();
		$logger = self::$logger;
		try {
			$connection = in_array($dbtype, ['sqlite3', 'pgsql'])
				? new \PDO($this->get_connection_string())
				: new \PDO(
					$this->get_connection_string(),
					$dbuser, $dbpass);
			$this->connection = $connection;
			$logger->debug(sprintf(
				"SQL: connection opened: '%s'.",
				json_encode($safe_params)));
		} catch (\PDOException $err) {
			$logger->error(sprintf(
				"SQL: connection failed: '%s'.",
				json_encode($safe_params)));
			throw new SQLError(SQLError::CONNECTION_ERROR,
				$dbtype . " connection error.");
		}

		$this->get_connection()->setAttribute(
			\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

		# set pragma for SQLite3
		if ($dbtype == 'sqlite3')
			$this->get_connection()->exec("PRAGMA foreign_keys=ON");
	}

	/**
	 * Open connection.
	 *
	 * Called internally by SQL::__construct. Do not use directly.
	 */
	final protected function open(array $params, Logger $logger) {
		self::$logger = $logger;
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
			$verified_params[$key] = $params[$key];
		}

		foreach (['dbtype', 'dbname'] as $key) {
			if (!isset($verified_params[$key])) {
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
	 * Close connection.
	 *
	 * Closed connection cannot be reopened with SQLConn::open or any
	 * other way. New connection must be reinstantiated.
	 *
	 * @throws SQLError if connection is not open.
	 */
	public function close() {
		if ($this->connection === null) {
			throw new SQLError(
				SQLError::CONNECTION_ERROR, "connection not open");
		}
		$this->connection = null;
		$this->connection_string = '';
		$this->verified_params = [];
		self::$logger->debug("SQL: connection closed.");
	}

	/* getters */

	/**
	 * Retrieve connection.
	 *
	 * Useful for checking whether connection is open and other
	 * things, e.g. creating custom functions on SQLite3.
	 *
	 * @return \\PDO Raw PDO connection instance on opened connection,
	 *     null otherwise.
	 */
	public function get_connection() {
		return $this->connection;
	}

	/**
	 * Retrieve formatted connection string.
	 *
	 * Use this, e.g. for dblink connection on Postgres.
	 *
	 * @return string Connection string if connection is open. Empty
	 *     string otherwise.
	 * @see https://www.postgresql.org/docs/11/dblink.html
	 */
	public function get_connection_string(): string {
		return $this->connection_string;
	}

	/**
	 * Get database type.
	 *
	 * @return string Database type. Empty if connection is not open.
	 */
	public function get_dbtype(): string {
		if (!$this->verified_params)
			return '';
		return $this->verified_params['dbtype'];
	}

	/**
	 * Retrieve successful connection parameters.
	 *
	 * @return array Dict of connection parameters. Empty if connection
	 *     is not open.
	 */
	public function get_connection_params(): array {
		return $this->verified_params;
	}

	/**
	 * Retrieve successful connection parameters without password.
	 * Useful for logging.
	 *
	 * @return array Dict of safe connection parameters. Empty if
	 *     connection is not open.
	 */
	public function get_safe_params(): array {
		$params = $this->get_connection_params();
		if (isset($params['dbpass']))
			$params['dbpass'] = 'XxXxXxXxXx';
		return $params;
	}

}
