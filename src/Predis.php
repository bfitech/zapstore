<?php declare(strict_types=1);


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * Predis wrapper class.
 *
 * Do not use directly. Use metapackage `bfitech/zapstore-predis`
 * instead for easier dependency management.
 *
 * @see https://packagist.org/packages/bfitech/zapstore-predis
 */
class Predis extends RedisConn {

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict in RedisConn::__construct
	 *      without '`redistype`' key.
	 * @param Logger $logger Logger instance.
	 * @see RedisConn::__construct
	 */
	public function __construct(array $params, Logger $logger=null) {
		$params['redistype'] = 'predis';
		parent::__construct($params, $logger);
	}

}
