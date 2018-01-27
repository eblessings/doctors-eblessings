<?php

/**
 * @file src/Worker/CheckVersion.php
 *
 * @brief save Friendica upstream version to the DB
 **/
namespace Friendica\Worker;

use Friendica\Core\Config;
use Friendica\Util\Network;

/**
 * @brief check the git repository VERSION file and save the version to the DB
 *
 * Checking the upstream version is optional (opt-in) and can be done to either
 * the master or the develop branch in the repository.
 */
class CheckVersion {
	public static function execute() {
		global $a;

		logger('checkversion: start');

		$checkurl = Config::get('system', 'check_new_version_url', 'none');

		switch ($checkurl) {
			case 'master':
				$checked_url = 'https://raw.githubusercontent.com/friendica/friendica/master/VERSION';
				break;
			case 'develop':
				$checked_url = 'https://raw.githubusercontent.com/friendica/friendica/develop/VERSION';
				break;
			default:
				// don't check
				return;
		}
		logger("Checking VERSION from: ".$checked_url, LOGGER_DEBUG);

		// fetch the VERSION file
		$gitversion = dbesc(trim(Network::fetchURL($checked_url)));
		logger("Upstream VERSION is: ".$gitversion, LOGGER_DEBUG);

		Config::set('system', 'git_friendica_version', $gitversion);

		logger('checkversion: end');

		return;
	}
}
