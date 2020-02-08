<?php declare(strict_types=1);


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * Redis generic connection manager.
 *
 * This wraps all underlying drivers into unified interface.
 *
 * @see RedisCall for available Redis API calls.
 */
class RedisConn extends RedisCall {

	private $verified_params = [];
	private $connection = null;

	/** Logging service. */
	public static $logger = null;

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict with key-value types:<br>
	 *     - `string` **redistype**, one of: `redis`, `predis`
	 *     - `string` **redishost**, TCP only
	 *     - `int` **redisport**, do not set to use default
	 *     - `string` **redispassword**, do not for passwordless server
	 *     - `int` **redisdatabase**, do not set to use default
	 *     - `float` **redistimeout**, do not set to use default
	 * @param Logger $logger Logger instance.
	 */
	public function __construct(array $params, Logger $logger=null) {

		self::$logger = $logger ?? new Logger();
		self::$logger->debug("Redis: object instantiated.");

		# initialize params; type cast if necessary since often times we
		# read configuration from JSON that's not properly type-aware
		$verified_params = [];
		$propkeys = [
			'redistype', 'redishost', 'redisport',
			'redispassword', 'redisdatabase', 'redistimeout',
		];
		foreach ($propkeys as $key) {
			if (!isset($params[$key]))
				continue;
			$val = $params[$key];
			if (in_array($key, ['redisport', 'redisdatabase']))
				$val = intval($val);
			elseif (in_array($key, ['redistimeout']))
				$val = floatval($val);
			else
				$val = (string)$val;
			$verified_params[$key] = $val;
		}

		# mandatory params
		foreach (['redistype', 'redishost'] as $key) {
			if (isset($verified_params[$key]))
				continue;
			$this->throw_error(
				RedisError::CONNECTION_ARGS_ERROR,
				sprintf("Redis: param not supplied: '%s'.", $key)
			);
		}

		# driver check
		if (!in_array(
			$verified_params['redistype'], ['redis', 'predis']
		)) {
			$this->throw_error(
				RedisError::REDISTYPE_ERROR, sprintf(
					"Redis: redis library not supported: '%s'.",
					$verified_params['redistype']
				)
			);
		}

		$this->verified_params = $verified_params;

		if ($verified_params['redistype'] == 'predis')
			$this->connection__predis();
		else
			$this->connection__redis();
		parent::__construct($this->connection, $logger);
	}

	/**
	 * Write to log and throw exception on error.
	 */
	private function throw_error(int $errno, string $logline) {
		self::$logger->error($logline);
		throw new RedisError($errno, $logline);
	}

	/**
	 * Open connection with predis.
	 */
	private function connection__predis() {
		$conn =& $this->connection;

		$args = [];
		foreach (array_keys($this->verified_params) as $key) {
			if ($key == 'redistype' || !$this->verified_params[$key])
				continue;
			$args[substr($key, 5)] = $this->verified_params[$key];
		}

		try {
			$conn = new \Predis\Client($args);
			$conn->ping();
			return $this->connection_open_ok();
		} catch(\Predis\Connection\ConnectionException $e) {
			return $this->connection_open_fail($e->getMessage());
		}
	}

	/**
	 * Open connection with ext-redis.
	 *
	 * This overrides timeout to 0.2 second from 0 to avoid hanging
	 * conection due to non-reachable server. Timeout becomes quiet
	 * short but that is the point of using Redis anyway.
	 */
	private function connection__redis() {
		$conn =& $this->connection;

		$redishost = $redispassword = '';
		$redistimeout = 0.2;
		$redisdatabase = 0;
		$redisport = 6379;
		extract($this->verified_params);

		# connect
		$conn = new \Redis();
		// @codeCoverageIgnoreStart
		try {
			# @note: On older versions of phpredis, invalid host causes
			# false return while on at least 5.0, it raises exception.
			# phpredis as old as 2.2.8 under PHP 7.0 still works with
			# this package so let's just ignore the coverage.
			if (!@$conn->connect($redishost, $redisport, $redistimeout))
				return $this->connection_open_fail();
		} catch(\RedisException $e) {
			return $this->connection_open_fail($e->getMessage());
		}
		// @codeCoverageIgnoreEnd

		# try auth and select
		if ($redispassword || $redisdatabase) {
			try {
				if ($redispassword)
					$conn->auth($redispassword);
				if ($redisdatabase)
					$conn->select($redisdatabase);
			} catch(\RedisException $e) {
				return $this->connection_open_fail($e->getMessage());
			}
		}

		return $this->connection_open_ok();
	}

	/**
	 * Copy verified params properties and obfuscate the password
	 * part. Useful for logging.
	 */
	private function get_safe_params() {
		$params = $this->verified_params;
		if (isset($params['redispassword']))
			$params['redispassword'] = 'XxXxXxXxXx';
		return $params;
	}

	/**
	 * Write to log and throw exception on failing connection.
	 *
	 * @codeCoverageIgnore
	 */
	private function connection_open_fail(string $message='') {
		$logline = sprintf('Redis: %s connection failed',
			$this->get_driver());
		if ($message)
			$logline .= ': ' . $message;
		$logline .= ' <- ' . json_encode($this->get_safe_params());
		$this->throw_error(RedisError::CONNECTION_ERROR, $logline);
	}

	/**
	 * Log on successful connection.
	 */
	private function connection_open_ok() {
		self::$logger->info(sprintf(
			"Redis: connection opened. <- '%s'.",
			json_encode($this->get_safe_params())));
	}

	/**
	 * Close connection.
	 */
	public function close() {
		if ($this->get_driver() == 'redis')
			$this->connection->close();
		$this->connection = null;
		$this->verified_params = null;
	}

	/* get properties */

	/**
	 * Retrieve connection.
	 *
	 * Use this to do anything with the connection without the help of
	 * the wrappers this class provides.
	 *
	 * @return object Redis connection.
	 */
	public function get_connection() {
		return $this->connection;
	}

	/**
	 * Retrive successful connection parameters.
	 *
	 * @return array Dict of verified connection parameters.
	 */
	public function get_connection_params() {
		return $this->verified_params;
	}

	/**
	 * Get driver, i.e. the underlying library to connect to
	 * the backend.
	 *
	 * @return string 'redis' or 'predis', depending on the setup by
	 *     constructor.
	 */
	public function get_driver(): string {
		return $this->verified_params['redistype'];
	}

}
