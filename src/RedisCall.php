<?php declare(strict_types=1);


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * Redis API calls.
 *
 * This is just a very small subset of what Redis can do. If you want
 * something advanced, use RedisConn::get_connection to retrieve the
 * connection and go on with your business with it, but of course, the
 * connection object is driver-dependent.
 *
 * Do not subclass directly. Use RedisConn instead.
 */
abstract class RedisCall {

	private $connection = null;
	private static $logger = null;

	/**
	 * Constructor.
	 *
	 * Do not call. Use subclass RedisConn instead.
	 *
	 * @param object $connection Redis connection.
	 * @param Logger $logger Logger instance.
	 */
	public function __construct($connection, Logger $logger) {
		$this->connection = $connection;
		self::$logger = $logger;
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
	 * @return bool True if the command is successful.
	 * @see https://git.io/vHJhl.
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
			"Redis: delete %s: '%s'.", $res_log, $res_keys));
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
		$res = $this->connection->expire($key, $ttl);
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

}
