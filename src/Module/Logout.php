<?php
/**
 * @file src/Module/Logout.php
 */

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Core\Authentication;
use Friendica\Core\Hook;
use Friendica\Core\L10n;
use Friendica\Core\System;
use Friendica\Model\Profile;

/**
 * Logout module
 *
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
class Logout extends BaseModule
{
	/**
	 * @brief Process logout requests
	 */
	public static function init()
	{
		$visitor_home = null;
		if (remote_user()) {
			$visitor_home = Profile::getMyURL();
		}

		Hook::callAll("logging_out");
		Authentication::deleteSession();

		if ($visitor_home) {
			System::externalRedirect($visitor_home);
		} else {
			info(L10n::t('Logged out.'));
			self::getApp()->internalRedirect();
		}
	}
}
