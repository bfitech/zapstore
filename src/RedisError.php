<?php


namespace BFITech\ZapStore;


/**
 * Redis exception class.
 */
class RedisError extends \Exception {

	/** Library not supported. */
	const REDISTYPE_ERROR = 0x10;
	/** Connection arguments invalid. */
	const CONNECTION_ARGS_ERROR = 0x20;
	/** Connection failed. */
	const CONNECTION_ERROR = 0x30;

	/** Default errno. */
	public $code = 0;
	/** Default errmsg. */
	public $message = null;

	/**
	 * Constructor.
	 *
	 * @param int $code Error number. See class constants.
	 * @param string $message Eror message.
	 */
	public function __construct(int $code, string $message) {
		$this->code = $code;
		$this->message = $message;
		parent::__construct($message, $code, null);
	}

}
