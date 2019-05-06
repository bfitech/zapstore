<?php


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * Predis wrapper class.
 */
class Predis extends RedisConn {

	/**
	 * Constructor.
	 *
	 * @param array $params Redis connection dict exactly the same with
	 *     that in the parent class except that 'redistype' key can be
	 *     omitted.
	 * @param Logger $logger Logger instance.
	 */
	public function __construct(array $params, Logger $logger=null) {
		$params['redistype'] = 'predis';
		parent::__construct($params, $logger);
	}

}
