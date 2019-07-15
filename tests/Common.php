<?php


use BFITech\ZapCore\Config;
use BFITech\ZapCoreDev\TestCase;


abstract class Common extends TestCase {

	private static function config_default() {

		$ref = [
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
				['dbname', null,
					self::tdir(__FILE__) . '/zapstore.sq3'],
			],
			'redis' => [
				['redishost', 'REDISHOST', 'localhost'],
				['redisport', 'REDISPORT', 6379],
				['redispassword', 'REDISPASSWORD', ''],
				['redisdatabase', 'REDISDATABASE', 10],
			],
			'predis' => [
				['redishost', 'REDISHOST', 'localhost'],
				['redisport', 'REDISPORT', 6379],
				['redispassword', 'REDISPASSWORD', ''],
				['redisdatabase', 'REDISDATABASE', 10],
			],
		];
		return $ref;
	}

	public static function open_config($engine, $cfile=null) {
		if ($cfile === null)
			$cfile = self::tdir(__FILE__) . "/zapstore-$engine.json";
		if (file_exists($cfile))
			return (new Config($cfile))->get($engine);
		file_put_contents($cfile, '[]');
		$cnf = new Config($cfile);
		foreach (self::config_default() as $section => $sval) {
			foreach ($sval as $val) {
				list($key, $env, $dfl) = $val;
				$ckey = sprintf('%s.%s', $section, $key);
				$cval = !$env ? $dfl : (getenv($env) ?? $dfl);
				$cnf->add($ckey, $cval);
			}
		}
		return $cnf->get($engine);
	}

}
