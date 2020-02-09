<?php declare(strict_types=1);


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * SQLite3 wrapper class.
 *
 * Do not use directly. Use metapackage `bfitech/zapstore-sqlite3`
 * instead for easier dependency management.
 *
 * @see https://packagist.org/packages/bfitech/zapstore-sqlite3
 */
class SQLite3 extends SQL {

	/**
	 * Constructor.
	 *
	 * @param array $params Connection dict in SQL::__construct without
	 *     '`dbtype`' key.
	 * @param Logger $logger Logger instance.
	 * @see SQL::__construct
	 */
	public function __construct(array $params, Logger $logger=null) {
		$params['dbtype'] = 'sqlite3';
		parent::__construct($params, $logger);
	}

}
