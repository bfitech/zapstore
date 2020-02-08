<?php


require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\Predis;


/**
 * Predis-specific.
 */
class PredisTest extends Common {

	public function test_predis() {
		$logfile = self::tdir(__FILE__) . '/zapstore-predis.log';
		$logger = new Logger(Logger::ERROR, $logfile);
		$params = self::open_config('predis');
		$sql = new Predis($params, $logger);
		self::eq()(
			$sql->get_connection_params()['redistype'], 'predis');
	}

}
