<?php


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * PPHRedis wrapper class.
 */
class Redis extends RedisConn {

	/**
	 * Constructor.
	 *
	 * @param array $params Redis connection dict exactly the same with
	 *     that in the parent class except that 'redistype' key can be
	 *     omitted.
	 * @param Logger $logger Logger instance.
	 */
	public function __construct($params, Logger $logger=null) {
		$params['redistype'] = 'redis';
		parent::__construct($params, $logger);
	}

}

