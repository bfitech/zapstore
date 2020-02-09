<?php


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * MySQL wrapper class.
 *
 * Do not use directly. Use metapackage `bfitech/zapstore-mysql`
 * instead for easier dependency management.
 *
 * @see https://packagist.org/packages/bfitech/zapstore-mysql
 */
class MySQL extends SQL {

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict in SQL::__construct without
	 *     '`dbtype`' key.
	 * @param Logger $logger Logger instance.
	 * @see SQL::__construct
	 */
	public function __construct(array $params, Logger $logger=null) {
		$params['dbtype'] = 'mysql';
		parent::__construct($params, $logger);
	}

}
