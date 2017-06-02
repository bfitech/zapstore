<?php


namespace BFITech\ZapStore;


use BFITech\ZapCore\Logger;


/**
 * MySQL class.
 */
class MySQL extends SQL {

	/**
	 * Constructor.
	 *
	 * @param array $params SQL connection dict exactly the same with
	 *     that in the parent class except that 'dbtype' key can be
	 *     omitted.
	 * @param Logger $logger Logger instance.
	 */
	public function __construct($params, Logger $logger=null) {
		$params['dbtype'] = 'mysql';
		parent::__construct($params, $logger);
	}

}
