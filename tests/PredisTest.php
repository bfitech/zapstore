<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\Predis;


/**
 * Predis-specific.
 */
class PredisTest extends Common {

	public function test_predis() {
		$testdir = self::tdir(__FILE__);
		$logfile = $testdir . '/zapstore-redis.log';
		$logger = new Logger(Logger::ERROR, $logfile);
		$args = self::open_config('predis');
		$sql = new Predis($args, $logger);
		$this->eq()(
			$sql->get_connection_params()['redistype'], 'predis');
	}

}
