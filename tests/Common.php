<?php


use BFITech\ZapCore\Config;
use BFITech\ZapCoreDev\TestCase;


/**
 * Common test class.
 *
 * Tests are using the same configuration files for all databases,
 * located at `./testdata/zapstore.json`.
 *
 * For debugging and development purposes, logs are located at
 * `./testdata/zapstore*.log`.
 */
abstract class Common extends TestCase {

	public static $cfile;

	/**
	 * Default configuration and environment variable looup.
	 *
	 * Watch out the env var names. They come mostly from various
	 * official docker images hence the irregularities. In case of no
	 * env vars involved, e.g. on Travis, the setup must match
	 * the default values.
	 */
	private static function config_default() {
		return [
			'mysql' => [
				['dbhost', 'MYSQL_HOST', 'localhost'],
				['dbport', 'MYSQL_PORT', 3306],
				['dbuser', 'MYSQL_USER', 'root'],
				['dbpass', 'MYSQL_PASSWORD', ''],
				['dbname', 'MYSQL_DATABASE', 'zapstore_test_db'],
			],
			'pgsql' => [
				['dbhost', 'POSTGRES_HOST', 'localhost'],
				['dbport', 'POSTGRES_PORT', 5432],
				['dbuser', 'POSTGRES_USER', 'root'],
				['dbpass', 'POSTGRES_PASSWORD', ''],
				['dbname', 'POSTGRES_DB', 'zapstore_test_db'],
			],
			'sqlite3' => [
				# relative to make it portable across fs mounts, but
				# must be resolved prior to using it on constructor
				['dbname', null, './testdata/zapstore.sq3'],
			],
			'redis' => [
				['redishost', 'REDISHOST', 'localhost'],
				['redisport', 'REDISPORT', 6379],
				['redispassword', 'REDISPASSWORD', 'xoxo'],
				['redisdatabase', 'REDISDATABASE', 10],
			],
			'predis' => [
				['redishost', 'REDISHOST', 'localhost'],
				['redisport', 'REDISPORT', 6379],
				['redispassword', 'REDISPASSWORD', 'xoxo'],
				['redisdatabase', 'REDISDATABASE', 10],
			],
		];
	}

	public static function conn_bail($type, $logfile) {
		printf(
			"\nERROR: Cannot connect to '%s' test database.\n\n" .
			"- Check extensions for interpreter: '%s'.\n" .
			"- Fix test configuration '%s': %s\n" .
			"- Inspect test log: %s.\n\n",
			$type, PHP_BINARY, self::$cfile,
			file_get_contents(self::$cfile), $logfile);
		exit(1);
	}

	/**
	 * Open configuration file.
	 */
	public static function open_config($engine) {
		$cfile = self::tdir(__FILE__) . "/zapstore.json";
		self::$cfile = $cfile;

		# use existing config
		if (file_exists($cfile))
			return (new Config($cfile))->get($engine);

		# create new
		file_put_contents($cfile, '[]');
		$cnf = new Config($cfile);

		# load from default values or from env vars if applicable
		foreach (self::config_default() as $section => $sval) {
			foreach ($sval as $val) {
				list($key, $env, $dfl) = $val;
				$ckey = sprintf('%s.%s', $section, $key);
				$cval = $dfl;
				if ($env !== null && getenv($env))
					$cval = getenv($env);
				$cnf->add($ckey, $cval);
			}
		}

		return $cnf->get($engine);
	}

}
