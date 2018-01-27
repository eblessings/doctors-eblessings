<?php
/**
 * @file mod/receive.php
 * @brief Diaspora endpoint
 */

use Friendica\App;
use Friendica\Core\Config;
use Friendica\Database\DBM;
use Friendica\Protocol\Diaspora;
use Friendica\Util\Network;

/**
 * @param object $a App
 * @return void
 */
function receive_post(App $a)
{
	$enabled = intval(Config::get('system', 'diaspora_enabled'));
	if (!$enabled) {
		logger('mod-diaspora: disabled');
		Network::httpStatusExit(500);
	}

	if (($a->argc == 2) && ($a->argv[1] === 'public')) {
		$public = true;
		$importer = false;
	} else {
		$public = false;

		if ($a->argc != 3 || $a->argv[1] !== 'users') {
			Network::httpStatusExit(500);
		}
		$guid = $a->argv[2];

		$importer = dba::selectFirst('user', [], ['guid' => $guid, 'account_expired' => false, 'account_removed' => false]);
		if (!DBM::is_result($importer)) {
			Network::httpStatusExit(500);
		}
	}

	// It is an application/x-www-form-urlencoded

	logger('mod-diaspora: receiving post', LOGGER_DEBUG);

	$xml = urldecode($_POST['xml']);

	if (!$xml) {
		$postdata = file_get_contents("php://input");
		if ($postdata == '') {
			Network::httpStatusExit(500);
		}

		logger('mod-diaspora: message is in the new format', LOGGER_DEBUG);
		$msg = Diaspora::decodeRaw($importer, $postdata);
	} else {
		logger('mod-diaspora: decode message in the old format', LOGGER_DEBUG);
		$msg = Diaspora::decode($importer, $xml);

		if ($public && !$msg) {
			logger('mod-diaspora: decode message in the new format', LOGGER_DEBUG);
			$msg = Diaspora::decodeRaw($importer, $xml);
		}
	}

	logger('mod-diaspora: decoded', LOGGER_DEBUG);

	logger('mod-diaspora: decoded msg: ' . print_r($msg, true), LOGGER_DATA);

	if (!is_array($msg)) {
		Network::httpStatusExit(500);
	}

	logger('mod-diaspora: dispatching', LOGGER_DEBUG);

	$ret = true;
	if ($public) {
		Diaspora::dispatchPublic($msg);
	} else {
		$ret = Diaspora::dispatch($importer, $msg);
	}

	Network::httpStatusExit(($ret) ? 200 : 500);
	// NOTREACHED
}
