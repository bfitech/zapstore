<?php declare(strict_types=1);


namespace BFITech\ZapStore;


/**
 * SQL exception class.
 */
class SQLError extends \Exception {

	/** Database not supported. */
	const DBTYPE_ERROR = 0x10;
	/** Connection arguments invalid. */
	const CONNECTION_ARGS_ERROR = 0x20;
	/** Connection failed. */
	const CONNECTION_ERROR = 0x30;
	/** SQL execution failed. */
	const EXECUTION_ERROR = 0x40;

	/** Default errno. */
	public $code = 0;
	/** Default errmsg. */
	public $message = null;

	private $stmt = null;
	private $args = [];

	/**
	 * Constructor.
	 *
	 * @param int $code Errno. See the class constants.
	 * @param string $message Errmsg.
	 * @param string $stmt SQL statement.
	 * @param  array $args Dict of SQL arguments.
	 */
	public function __construct(
		int $code, string $message, string $stmt=null, array $args=[]
	) {
		$this->code = $code;
		$this->message = $message;
		$this->stmt = $stmt;
		$this->args = $args;
		parent::__construct($message, $code, null);
	}

	/**
	 * Get SQL statement from exception.
	 */
	public function getStmt() {
		return $this->stmt;
	}

	/**
	 * Get SQL parameters from exception.
	 */
	public function getArgs() {
		return $this->args;
	}

}
