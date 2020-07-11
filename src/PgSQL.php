<?php declare(strict_types=1);


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * PostgreSQL wrapper class.
 *
 * Do not use directly. Use metapackage `bfitech/zapstore-pgsql`
 * instead for easier dependency management.
 *
 * @see https://packagist.org/packages/bfitech/zapstore-pgsql
 */
class PgSQL extends SQL {

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict in SQL::__construct without
	 *     '`dbtype`' key.
	 * @param Logger $log Logger instance.
	 * @see SQL::__construct
	 */
	public function __construct(array $params, Logger $log=null) {
		$params['dbtype'] = 'pgsql';
		parent::__construct($params, $log);
	}

}
