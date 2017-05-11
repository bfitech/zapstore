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
			$qrystr = '';
			if ($this->redispass) 
				$qrystr .= sprintf("?password=%s", $this->redispass);
			if ($this->redisdb) {
				$qrystr .= (empty($qrystr)) ? '?' : '&';
				$qrystr .= sprintf("database=%s", $this->redisdb);
			}
			if ($this->redistimeout) {
				$qrystr .= (empty($qrystr)) ? '?' : '&';
				$qrystr .= sprintf("timeout=%s", $this->redistimeout);	
			}
			$this->connection_string = $cstr . $qrystr;
		}

		try {
			if ($this->redistype == 'predis')) {
				$this->connection = new \Predis\CLient(
					$this->connection_string);
			} else {
				$this->connection = new Redis();
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
		} catch (\PDOException $e) {
			self::$logger->error(sprintf(
				"Redis: connection failed: '%s'.",
				json_encode($this->verified_params)));
			throw new SQLError(SQLError::CONNECTION_ERROR,
				$this->dbtype . " connection error.");
		}
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