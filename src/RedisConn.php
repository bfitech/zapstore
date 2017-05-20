<?php


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger as Logger;


/**
 * Redis exception class.
 */
class RedisError extends \Exception {

	/** Library not supported. */
	const REDISTYPE_ERROR = 0x10;
	/** Connection arguments invalid. */
	const CONNECTION_ARGS_ERROR = 0x20;
	/** Connection failed. */
	const CONNECTION_ERROR = 0x30;

	/** Default errno. */
	public $code = 0;
	/** Default errmsg. */
	public $message = null;

	/**
	 * Constructor.
	 */
	public function __construct(
		$code, $message
	) {
		$this->code = $code;
		$this->message = $message;
		parent::__construct($message, $code, null);
	}
}

/**
 * Redis class
 */
class RedisConn {

	private $redistype = null;
	private $redisscheme = 'tcp';
	private $redishost = 'localhost';
	private $redisport = 6379;
	private $redispassword = null;
	private $redisdatabase = null;
	private $redistimeout = 5;

	private $verified_params = null;

	private $connection = null;

	/** Logging service. */
	public static $logger = null;

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict.
	 * @param Logger $logger Instance of BFITech\\ZapCore\\Logger.
	 */
	public function __construct($params, Logger $logger=null) {

		self::$logger = $logger ? $logger : new Logger();
		self::$logger->debug("Redis: object instantiated.");

		$verified_params = [];
		foreach ([
			'redistype', 'redisscheme', 'redishost', 'redisport',
			'redispassword', 'redisdatabase', 'redistimeout',
		] as $key) {
			if (!isset($params[$key]))
				continue;
			$this->$key = $params[$key];
			$verified_params[$key] = $params[$key];
		}
		$this->verified_params = $verified_params;

		foreach (['redistype', 'redishost'] as $key) {
			if (!$this->$key) {
				self::$logger->error(sprintf(
					"Redis: param not supplied: '%s'.", $key));
				throw new RedisError(
					RedisError::CONNECTION_ARGS_ERROR,
					sprintf("'%s' not supplied.", $key));
			}
		}

		if (!in_array($this->redistype, ['redis', 'predis'])) {
			self::$logger->error(sprintf(
				"Redis: redis library not supported: '%s'.",
				$this->redistype));
			throw new RedisError(RedisError::REDISTYPE_ERROR,
				$this->redistype . " not supported.");
		}

		if ($this->redistype == 'predis') {
			$args = [];
			foreach ([
				'scheme', 'host', 'port', 'database',
				'password', 'timeout',
			] as $key) {
				$rkey = 'redis' . $key;
				if (!$this->$rkey)
					continue;
				$args[$key] = $this->$rkey;
			}
			try {
				$this->connection = new \Predis\Client($args);
				$this->connection->ping();
				return $this->connection_open_ok();
			} catch(\Predis\Connection\ConnectionException $e) {
				return $this->connection_open_fail($e->getMessage());
			}
		}

		$this->connection = new \Redis();
		# @note: This emits warning on failure instead of throwing
		# exception, hence the @ sign.
		if (!@$this->connection->connect(
			$this->redishost, $this->redisport,
			$this->redistimeout
		))
			return $this->connection_open_fail();
		if ($this->redispassword || $this->redisdatabase) {
			if ($this->redispassword)
				$this->connection->auth($this->redispassword);
			if ($this->redisdatabase)
				$this->connection->select($this->redisdatabase);
		}
		try {
			$this->connection->ping();
		} catch(\RedisException $e) {
			return $this->connection_open_fail($e->getMessage());
		}
		$this->connection_open_ok();
	}

	private function connection_open_fail($msg='') {
		$logline = sprintf('Redis: %s connection failed',
			$this->redistype);
		if ($msg)
			$logline .= ': ' . $msg;
		$logline .= ' <- ' . json_encode($this->verified_params);
		self::$logger->error($logline);
		throw new RedisError(RedisError::CONNECTION_ERROR,
			$logline);
	}

	private function connection_open_ok() {
		self::$logger->info(sprintf(
			"Redis: connection opened. <- '%s'.",
			json_encode($this->verified_params)));
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
	final public function set($key, $value, $options=null) {
		$res = $this->redistype == 'redis'
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
	final public function hset($key, $hkey, $value) {
		$method = 'hSet';
		if ($this->redistype == 'predis')
			$function = strtolower($method);
		$res = $this->connection->$method($key, $hkey, $value);
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
	final public function expire($key, $ttl) {
		$method = 'setTimeout';
		if ($this->redistype == 'predis')
			$method = 'expire';
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
	final public function expireat($key, $ttl) {
		$method = 'expireAt';
		if ($this->redistype == 'predis')
			$method = strtolower($method);
		$res = $this->connection->$method($key, $ttl);
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
	final public function get($key) {
		$res = $this->connection->get($key);
		self::$logger->info(sprintf(
			"Redis: get %s: '%s'.", $key, $res));
		if ($this->redistype == 'predis' && $res == null)
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
	final public function hget($key, $hkey=null) {
		$method = 'hGet';
		if ($this->redistype == 'predis')
			$method = strtolower($method);
		$res = $this->connection->$method($key, $hkey);
		self::$logger->info(sprintf(
			"Redis: hget %s.%s: '%s'.",
			$key, $hkey, $res));
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
	final public function ttl($key) {
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
	final public function time($with_mcs=false) {
		$time = $this->connection->time();
		if (!$with_mcs)
			return $time[0];
		return $time[0] + ($time[1] / 1e6);
	}

	/**
	 * Close connection.
	 */
	public function close() {
		if ($this->redistype == 'redis')
			$this->connection->close();
		$this->connection = null;
		$this->verified_params = null;
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
	 * Retrive successful connection parameters.
	 */
	public function get_connection_params() {
		return $this->verified_params;
	}
}

