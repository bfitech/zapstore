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
class Redis {

	private $redistype = null;
	private $redishost = null;
	private $redisport = null;
	private $redispass = null;
	private $redisdb = null;
	private $redisscheme = null;
	private $redistimeout = null;

	private $verified_params = null;

	private $connection = null;
	private $connection_string = '';

	/** Logging service. */
	public static $logger = null;

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict.
	 * @param Logger $logger Instance of BFITech\\ZapCore\\Logger.
	 */
	public function __construct($params, Logger $logger=null) {
		self::$logger = $logger instanceof Logger
			? $logger : new Logger();
		self::$logger->debug("Redis: object instantiated.");

		$verified_params = [];
		foreach ([
			'redistype', 'redishost', 'redisport', 
			'redispass', 'redisdb', 'redisscheme', 'redistimeout',
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
			/**
			 * # Connecting to a server
			 *
			 * Predis offers various means to connect to a single server 
			 * or a cluster of servers. By specifying two or more servers, 
			 * Predis automatically switches over a clustered connection 
			 * that transparently handles client-side sharding over multiple 
			 * connections. It should be noted that clustered connections 
			 * have a little more overhead when compared to single connections
			 * (that is, when Predis is connected only to a single server) 
			 * due to a more complex internal structure needed to support 
			 * consistent hashing.
			 *
			 * #### Example:
			 * @code
			 * 
			 * # Connect to the default host (127.0.0.1) and port (6379)
			 * 
			 * $redis = new Predis\Client();
			 * 
			 * # Connect to a server using parameters
			 * 
			 * $redis = new Predis\Client(array(
			 *    'host' => '10.0.0.1', 
			 *    'port' => 6380, 
			 * ));
			 *
			 * # or
			 *
			 * $redis = new Predis\Client('redis://10.0.0.1:6380/');
			 * 
			 * # Automatically perform authentication and database selection 
			 * # when connecting
			 *
			 * $redis = new Predis\Client(array(
			 *    'host'     => '10.0.0.1', 
			 *    'password' => 'secret', 
			 *    'database' => 10, 
			 * ));
			 *
			 * # or
			 *
			 * $redis = new Predis\Client(
			 *     'redis://10.0.0.1/?password=secret&database=10');
			 * 
			 * @endcode
			 */
			$cstr = 'redis:';
			if ($this->redisscheme)
				$cstr = sprintf("%s:", $this->redisscheme);
			$cstr .= sprintf("//%s/", $this->redishost);
			$qry = [];
			if ($this->redispass) 
				$qry['password'] = $this->redispass;

			if ($this->redisdb) {
				$qry['database'] = $this->redisdb;
			}
			if ($this->redistimeout) {
				$qry['timeout'] = $this->redistimeout;	
			}
			$qrystr = http_build_query($qry);
			$this->connection_string = $cstr . '?' . $qrystr;
		}

		try {
			if ($this->redistype == 'predis') {
				$this->connection = new \Predis\Client(
					$this->connection_string);
			} else {
				$this->connection = new \Redis();
				if (!$this->redisport)
					$this->redisport = 6379;
				if (!$this->redistimeout)
					$this->redistimeout = 5;
				$this->connection->connect(
					$this->redishost, $this->redisport, $this->redistimeout);
			}
			self::$logger->debug(sprintf(
				"Redis: connection opened: '%s'.",
				json_encode($this->verified_params)));
		} catch (\RedisException $e) {
			self::$logger->error(sprintf(
				"Redis: connection failed: '%s'.",
				json_encode($this->verified_params)));
			throw new RedisError(RedisError::CONNECTION_ERROR,
				$this->dbtype . " connection error.");
		}
	}

	/**
	 * # set
	 * 
	 * Set the string value in argument as value of the key. 
	 * If you're using Redis >= 2.6.12, you can pass extended options 
	 * as explained below
	 *
	 * @param string $key key of the value
	 * @param string $val value of the key
	 * @param mixed Timeout or Options Array (optional). If you pass 
	 *    an integer, phpredis will redirect to SETEX, and will try to 
	 *    use Redis >= 2.6.12 extended options if you pass an array with
	 *    valid values
	 * @return bool TRUE if the command is successful
	 */
	final public function set($key, $value, $options=null) {
		if ($this->redistype == 'redis') 
			$res = $this->connection->set($key, $value, $options);
		else 
			$res = $this->connection->set($key, $value);
		$res_log = (!$res) ? 'not ok':'ok';
		self::$logger->info(sprintf(
			"Redis: set %s: %s -> '%s'.",
			$res_log, $key, $value));
		return $res;
	}

	/**
	 * # hset
	 *
	 * Adds a value to the hash stored at key.
	 *
	 * @param string $key
	 * @param string $hkey
	 * @param string $value
	 * @return long 1 if value didn't exist and was added successfully, 
	 *     0 if the value was already present and was replaced, 
	 *     FALSE if there was an error.
	 */
	final public function hset($key, $hkey, $value) {
		$method = 'hSet';
		if ($this->redistype == 'predis')
			$function = strtolower($method);
		$res = $this->connection->$method($key, $hkey, $value);
		$res_log = ($res === false) ? 'not ok':'ok';
		self::$logger->info(sprintf(
			"Redis: hset %s: %s, %s, '%s'.",
			$res_log, $key, $hkey, $value));
		return $res;

	}

	/**
	 * # del
	 *
	 * Remove specified keys.
	 * 
	 * @param array $key An array of keys, or an undefined number of parameters, 
	 *     each a key: key1 key2 key3 ... keyN
	 * @return long Number of keys deleted.
	 */
	final public function del($key) {
		$res = $this->connection->del($key);
		$res_log = (!$res) ? 'not ok':'ok';
		$res_key = $key;
		if (is_array($key))
			$res_key = json_encode($key);
		self::$logger->info(sprintf(
			"Redis: delete %s: %s.",
			$res_log, $res_key));
		return $res;
	}

	/**
	 * # expire
	 *
	 * Sets an expiration date (a timeout) on an item. 
	 * 
	 * @param string $key The key that will disappear.
	 * @param integer $ttl The key's remaining Time To Live, in seconds.
	 * @return bool TRUE in case of success, FALSE in case of failure.
	 */
	final public function expire($key, $ttl) {
		$method = 'setTimeout';
		if ($this->redistype == 'predis')
			$method = 'expire';
		$res = $this->connection->$method($key, $ttl);
		self::$logger->info(sprintf(
			"Redis: expire %s: %s.",
			$key, $ttl));
		return $res;
	}

	/**
	 * # expireat
	 * 
	 * Sets an expiration date (a timestamp) on an item. 
	 *
	 * @param string $key The key that will disappear.
	 * @param integer $ttl Unix timestamp. The key's date of death, 
	 *     in seconds from Epoch time.
	 * @return bool TRUE in case of success, FALSE in case of failure.
	 */
	final public function expireat($key, $ttl) {
		$method = 'expireAt';
		if ($this->redistype == 'predis')
			$method = strtolower($method);
		$res = $this->connection->$method($key, $ttl);
		self::$logger->info(sprintf(
			"Redis: %s expire at: %s.",
			$key, $ttl));
		return $res;
	}

	/**
	 * # get
	 * 
	 * Get the value related to the specified key
	 * 
	 * @param string $key 
	 * @return string or bool: If key didn't exist, FALSE is returned. 
	 *     Otherwise, the value related to this key is returned.
	 */
	final public function get($key) {
		$res = $this->connection->get($key);
		self::$logger->info(sprintf(
			"Redis: get value of %s: %s.",
			$key, $res));
		if ($this->redistype == 'predis')
			if($res == null)
				$res = false;
		return $res;
	}

	/**
	 * # hget
	 *
	 * Gets a value from the hash stored at key. If the hash table 
	 * doesn't exist, or the key doesn't exist, FALSE is returned.
	 *
	 * @param string $key 
	 * @param string $hkey
	 * @return string The value, if the command executed successfully 
	 *     BOOL FALSE in case of failure
	 */
	final public function hget($key, $hkey=null) {
		$method = 'hGet';
		if ($this->redistype == 'predis')
			$method = strtolower($method);
		$res = $this->connection->$method($key, $hkey);
		self::$logger->info(sprintf(
			"Redis: hget %s, %s: %s.",
			$key, $hkey, $res));
		return $res;
	}

	/**
	 * # ttl
	 * 
	 * Returns the time to live left for a given key in seconds (ttl)
	 *
	 * @param string $key
	 * @return long The time to live in seconds. If the key has no ttl, 
	 *     -1 will be returned, and -2 if the key doesn't exist.
	 */
	final public function ttl($key) {
		$res = $this->connection->ttl($key);
		self::$logger->info(sprintf(
			"Redis: ttl %s: %s.",
			$key, $res));
		return $res;
	}

	/**
	 * Close connection.
	 */
	public function close() {
		$this->connection = null;
		$this->connection_string = '';
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
	 * Retrieve formatted connection string.
	 *
	 * Use this, e.g. for redis connection in predis.
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