<?php


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * SQLite3 wrapper class.
 */
class SQLite3 extends SQL {

	/**
	 * Constructor.
	 *
	 * @param array $params SQL connection dict exactly the same with
	 *     that in the parent class except that 'dbtype' key can be
	 *     omitted.
	 * @param Logger $logger Logger instance.
	 */
	public function __construct(array $params, Logger $logger=null) {
		$params['dbtype'] = 'sqlite3';
		parent::__construct($params, $logger);
	}

}
