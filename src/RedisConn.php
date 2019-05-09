<?php


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * Redis generic class.
 *
 * This wraps all underlying libraries into unified interface.
 */
class RedisConn {

	private $verified_params = null;
	private $connection = null;

	/** Logging service. */
	public static $logger = null;

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict with keys:<br>
	 *     - `redistype`, one of: `redis`, `predis`
	 *     - `redishost`, TCP only
	 *     - `redisport`, do not set to use default
	 *     - `redispassword`, do not for passwordless server
	 *     - `redisdatabase`, do not set to use default
	 *     - `redistimeout`, do not set to use default
	 * @param Logger $logger Logger instance.
	 */
	public function __construct(array $params, Logger $logger=null) {

		self::$logger = $logger ?? new Logger();
		self::$logger->debug("Redis: object instantiated.");

		$verified_params = [];
		$propkeys = [
			'redistype', 'redishost', 'redisport',
			'redispassword', 'redisdatabase', 'redistimeout',
		];
		foreach ($propkeys as $key) {
			if (!isset($params[$key]))
				continue;
			$verified_params[$key] = $params[$key];
		}

		foreach (['redistype', 'redishost'] as $key) {
			if (isset($verified_params[$key]))
				continue;
			$this->throw_error(
				RedisError::CONNECTION_ARGS_ERROR,
				sprintf("Redis: param not supplied: '%s'.", $key)
			);
		}

		if (!in_array(
			$verified_params['redistype'], ['redis', 'predis']
		)) {
			$this->throw_error(
				RedisError::REDISTYPE_ERROR,
				sprintf(
					"Redis: redis library not supported: '%s'.",
					$verified_params['redistype'])
			);
		}

		$this->verified_params = $verified_params;

		if ($verified_params['redistype'] == 'predis')
			return $this->connection__predis();
		return $this->connection__redis();
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
		$args = [];
		foreach (array_keys($this->verified_params) as $key) {
			if ($key == 'redistype' || !$this->verified_params[$key])
				continue;
			$args[substr($key, 5)] = $this->verified_params[$key];
		}
		try {
			$this->connection = new \Predis\Client($args);
			$this->connection->ping();
			return $this->connection_open_ok();
		} catch(\Predis\Connection\ConnectionException $e) {
			return $this->connection_open_fail($e->getMessage());
		}
	}

	/**
	 * Open connection with ext-redis.
	 */
	private function connection__redis() {
		$redispassword = $redisdatabase  = null;
		$redishost = $redisport = $redistimeout = null;
		extract($this->verified_params);

		$this->connection = new \Redis();
		# @note: This emits warning on failure instead of throwing
		# exception, hence the @ sign.
		if (!@$this->connection->connect(
			$redishost, $redisport, $redistimeout
		))
			// @codeCoverageIgnoreStart
			return $this->connection_open_fail();
			// @codeCoverageIgnoreEnd
		if ($redispassword || $redisdatabase != null) {
			try {
				if ($redispassword)
					$this->connection->auth($redispassword);
				if ($redisdatabase)
					$this->connection->select($redisdatabase);
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
	 * set
	 *
	 * Set the string value in argument as value of the key. If you're
	 * using Redis >= 2.6.12, you can pass extended options as explained
	 * below.
	 *
	 * @param string $key Key.
	 * @param string $value Value.
	 * @param mixed $options Expiration or phpredis options array. If
	 *     you pass an integer, phpredis will redirect to SETEX and set
	 *     the expiration. If you pass an array, it will try to use
	 *     Redis >= 2.6.12 extended options if value is valid. This is
	 *     ignored if you're using Predis.
	 *     @see https://git.io/vHJhl.
	 * @return bool True if the command is successful.
	 */
	final public function set(
		string $key, string $value, $options=null
	) {
		$res = $this->get_driver() == 'redis'
			? $this->connection->set($key, $value, $options)
			: $this->connection->set($key, $value);
		$res_log = $res ? 'ok': 'fail';
		self::$logger->info(sprintf(
			"Redis: set %s: %s -> '%s'.",
			$res_log, $key, $value));
		return $res;
	}

	/**
	 * hset
	 *
	 * Add a value to the hash stored at a key.
	 *
	 * @param string $key Key.
	 * @param string $hkey Hash key.
	 * @param string $value Value.
	 * @return long 1 if old value doesn't exist and new value is added
	 *     successfully, 0 if the value is already present and replaced,
	 *     false on error.
	 */
	final public function hset(
		string $key, string $hkey, string $value
	) {
		$res = $this->connection->hset($key, $hkey, $value);
		$res_log = $res === false ? 'fail' : 'ok';
		self::$logger->info(sprintf(
			"Redis: hset %s: %s.%s -> '%s'.",
			$res_log, $key, $hkey, $value));
		return $res;

	}

	/**
	 * del
	 *
	 * Remove specified keys.
	 *
	 * @param array $keys An array of keys, or variadic parameters,
	 *     each corresponding to a Redis key.
	 * @return long Number of keys deleted.
	 */
	final public function del($keys) {
		$res = $this->connection->del($keys);
		$res_log = $res ? 'ok' : 'fail';
		$res_keys = $keys;
		if (!is_array($keys))
			$keys = func_get_args();
		$res_keys = json_encode($keys);
		self::$logger->info(sprintf(
			"Redis: delete %s: '%s'.",
			$res_log, $res_keys));
		return $res;
	}

	/**
	 * expire
	 *
	 * Sets an expiration date (a timeout) on an item.
	 *
	 * @param string $key The key that will disappear.
	 * @param integer $ttl The key's remaining ttl, in seconds.
	 * @return bool True on success, false otherwise.
	 */
	final public function expire(string $key, int $ttl) {
		$method = $this->get_driver() == 'predis'
			? 'expire' : 'setTimeout';
		$res = $this->connection->$method($key, $ttl);
		self::$logger->info(sprintf(
			"Redis: expire %s: %s.", $key, $ttl));
		return $res;
	}

	/**
	 * expireat
	 *
	 * Sets an expiration timestamp of an item.
	 *
	 * @param string $key The key that will disappear.
	 * @param integer $ttl Unix timestamp. The key's date of death, in
	 *     seconds after Unix epoch.
	 * @return bool True on suceess, false otherwise.
	 */
	final public function expireat(string $key, int $ttl) {
		$res = $this->connection->expireat($key, $ttl);
		self::$logger->info(sprintf(
			"Redis: expireat %s: %s.", $key, $ttl));
		return $res;
	}

	/**
	 * get
	 *
	 * Get the value related to the specified key.
	 *
	 * @param string $key Key.
	 * @return string|bool If key doesn't exist, false is returned.
	 *     Otherwise, the value related to this key is returned.
	 */
	final public function get(string $key) {
		$res = $this->connection->get($key);
		self::$logger->info(sprintf(
			"Redis: get %s: '%s'.", $key, $res));
		if ($res === null && $this->get_driver() == 'predis')
			$res = false;
		return $res;
	}

	/**
	 * hget
	 *
	 * Get a value from the hash stored at key. If the hash table or
	 * the key doesn't exist, false is returned.
	 *
	 * @param string $key Key.
	 * @param string $hkey Hash key.
	 * @return string The value, if the command executed successfully.
	 *     False otherwise.
	 */
	final public function hget(string $key, string $hkey=null) {
		$res = $this->connection->hget($key, $hkey);
		self::$logger->info(sprintf(
			"Redis: hget %s.%s: '%s'.", $key, $hkey, $res));
		return $res;
	}

	/**
	 * ttl
	 *
	 * Get ttl of a given key, in seconds.
	 *
	 * @param string $key
	 * @return long The time to live in seconds. If the key has no ttl,
	 *     -1 will be returned, and -2 if the key doesn't exist.
	 */
	final public function ttl(string $key) {
		$res = $this->connection->ttl($key);
		self::$logger->info(sprintf(
			"Redis: ttl %s: %s.", $key, $res));
		return $res;
	}

	/**
	 * time
	 *
	 * Get Redis server time. Always use server time as a reference to
	 * do RedisConn::expireat in case of PHP interpreter's or Redis
	 * server's clock not being properly synched.
	 *
	 * @param bool $with_mcs If true, returned time includes microsecond
	 *     fraction.
	 * @return int|float Redis server time in Unix epoch.
	 */
	final public function time(bool $with_mcs=false) {
		$time = $this->connection->time();
		if (!$with_mcs)
			return $time[0];
		return $time[0] + ($time[1] / 1e6);
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
	public function get_driver() {
		return $this->verified_params['redistype'];
	}

}
