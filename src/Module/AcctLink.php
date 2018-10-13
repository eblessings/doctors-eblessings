<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Network\Probe;

/**
 * Redirects to another URL based on the parameter 'addr'
 */
class AcctLink extends BaseModule
{
	public static function content()
	{
		$addr = defaults($_REQUEST, 'addr', false);

		if ($addr) {
			$url = defaults(Probe::uri($addr), 'url', false);

			if ($url) {
				goaway($url);
				killme();
			}
		}
	}
}
