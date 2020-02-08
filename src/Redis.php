<?php declare(strict_types=1);


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * phpredis a.k.a ext-redis wrapper class.
 *
 * Do not use on production. Use metapackage `bfitech/zapstore-redis`
 * instead for easier dependency management.
 *
 * @see https://packagist.org/packages/bfitech/zapstore-redis
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
	public function __construct(array $params, Logger $logger=null) {
		$params['redistype'] = 'redis';
		parent::__construct($params, $logger);
	}

}
