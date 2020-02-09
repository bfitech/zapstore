<?php declare(strict_types=1);


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * phpredis a.k.a ext-redis wrapper class.
 *
 * Do not use directly. Use metapackage `bfitech/zapstore-redis`
 * instead for easier dependency management.
 *
 * @see https://packagist.org/packages/bfitech/zapstore-redis
 */
class Redis extends RedisConn {

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict in RedisConn::__construct
	 *      without '`redistype`' key.
	 * @param Logger $logger Logger instance.
	 * @see RedisConn::__construct
	 */
	public function __construct(array $params, Logger $logger=null) {
		$params['redistype'] = 'redis';
		parent::__construct($params, $logger);
	}

}
