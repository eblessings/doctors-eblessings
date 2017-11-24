<?php

/**
 * Throwable exceptions to return HTTP status code
 *
 * This list of Exception has be extracted from
 * here http://racksburg.com/choosing-an-http-status-code/
 */

namespace Friendica\Network;

use Exception;

class HTTPException extends Exception {
	var $httpcode = 200;
	var $httpdesc = "";
	public function __construct($message = "", $code = 0, Exception $previous = null) {
		if ($this->httpdesc == "") {
			$this->httpdesc = preg_replace("|([a-z])([A-Z])|",'$1 $2', str_replace("Exception","",get_class($this)));
		}
		parent::__construct($message, $code, $previous);
	}
}
