<?php

use Friendica\App;
use Friendica\Core\System;
use Friendica\Core\Config;
use Friendica\Database\DBM;
use Friendica\Model\User;

require_once 'include/security.php';
require_once 'include/datetime.php';

// When the "Friendica" cookie is set, take the value to authenticate and renew the cookie.
if (isset($_COOKIE["Friendica"])) {
	$data = json_decode($_COOKIE["Friendica"]);
	if (isset($data->uid)) {

		$user = dba::select('user',
			[],
			[
				'uid'             => $data->uid,
				'blocked'         => false,
				'account_expired' => false,
				'account_removed' => false,
				'verified'        => true,
			],
			['limit' => 1]
		);

		if (DBM::is_result($user)) {
			if ($data->hash != cookie_hash($user)) {
				logger("Hash for user " . $data->uid . " doesn't fit.");
				nuke_session();
				goaway(System::baseUrl());
			}

			// Renew the cookie
			// Expires after 7 days by default,
			// can be set via system.auth_cookie_lifetime
			$authcookiedays = Config::get('system', 'auth_cookie_lifetime', 7);
			new_cookie($authcookiedays * 24 * 60 * 60, $user);

			// Do the authentification if not done by now
			if (!isset($_SESSION) || !isset($_SESSION['authenticated'])) {
				authenticate_success($user);

				if (Config::get('system', 'paranoia')) {
					$_SESSION['addr'] = $data->ip;
				}
			}
		}
	}
}


// login/logout

if (isset($_SESSION) && x($_SESSION, 'authenticated') && (!x($_POST, 'auth-params') || ($_POST['auth-params'] !== 'login'))) {
	if ((x($_POST, 'auth-params') && ($_POST['auth-params'] === 'logout')) || ($a->module === 'logout')) {
		// process logout request
		call_hooks("logging_out");
		nuke_session();
		info(t('Logged out.') . EOL);
		goaway(System::baseUrl());
	}

	if (x($_SESSION, 'visitor_id') && !x($_SESSION, 'uid')) {
		$r = q("SELECT * FROM `contact` WHERE `id` = %d LIMIT 1",
			intval($_SESSION['visitor_id'])
		);
		if (DBM::is_result($r)) {
			$a->contact = $r[0];
		}
	}

	if (x($_SESSION, 'uid')) {
		// already logged in user returning
		$check = Config::get('system', 'paranoia');
		// extra paranoia - if the IP changed, log them out
		if ($check && ($_SESSION['addr'] != $_SERVER['REMOTE_ADDR'])) {
			logger('Session address changed. Paranoid setting in effect, blocking session. ' .
				$_SESSION['addr'] . ' != ' . $_SERVER['REMOTE_ADDR']);
			nuke_session();
			goaway(System::baseUrl());
		}

		$user = dba::select('user',
			[],
			[
				'uid'             => $_SESSION['uid'],
				'blocked'         => false,
				'account_expired' => false,
				'account_removed' => false,
				'verified'        => true,
			],
			['limit' => 1]
		);
		if (!DBM::is_result($user)) {
			nuke_session();
			goaway(System::baseUrl());
		}

		// Make sure to refresh the last login time for the user if the user
		// stays logged in for a long time, e.g. with "Remember Me"
		$login_refresh = false;
		if (!x($_SESSION['last_login_date'])) {
			$_SESSION['last_login_date'] = datetime_convert('UTC', 'UTC');
		}
		if (strcmp(datetime_convert('UTC', 'UTC', 'now - 12 hours'), $_SESSION['last_login_date']) > 0) {
			$_SESSION['last_login_date'] = datetime_convert('UTC', 'UTC');
			$login_refresh = true;
		}
		authenticate_success($user, false, false, $login_refresh);
	}
} else {
	session_unset();
	if (
		!(x($_POST, 'password') && strlen($_POST['password']))
		&& (
			x($_POST, 'openid_url') && strlen($_POST['openid_url'])
			|| x($_POST, 'username') && strlen($_POST['username'])
		)
	) {
		$noid = Config::get('system', 'no_openid');

		$openid_url = trim(strlen($_POST['openid_url']) ? $_POST['openid_url'] : $_POST['username']);

		// validate_url alters the calling parameter

		$temp_string = $openid_url;

		// if it's an email address or doesn't resolve to a URL, fail.

		if ($noid || strpos($temp_string, '@') || !validate_url($temp_string)) {
			$a = get_app();
			notice(t('Login failed.') . EOL);
			goaway(System::baseUrl());
			// NOTREACHED
		}

		// Otherwise it's probably an openid.

		try {
			require_once('library/openid.php');
			$openid = new LightOpenID;
			$openid->identity = $openid_url;
			$_SESSION['openid'] = $openid_url;
			$_SESSION['remember'] = $_POST['remember'];
			$openid->returnUrl = System::baseUrl(true) . '/openid';
			goaway($openid->authUrl());
		} catch (Exception $e) {
			notice(t('We encountered a problem while logging in with the OpenID you provided. Please check the correct spelling of the ID.') . '<br /><br >' . t('The error message was:') . ' ' . $e->getMessage());
		}
		// NOTREACHED
	}

	if (x($_POST, 'auth-params') && $_POST['auth-params'] === 'login') {
		$record = null;

		$addon_auth = array(
			'username' => trim($_POST['username']),
			'password' => trim($_POST['password']),
			'authenticated' => 0,
			'user_record' => null
		);

		/**
		 *
		 * A plugin indicates successful login by setting 'authenticated' to non-zero value and returning a user record
		 * Plugins should never set 'authenticated' except to indicate success - as hooks may be chained
		 * and later plugins should not interfere with an earlier one that succeeded.
		 *
		 */
		call_hooks('authenticate', $addon_auth);

		if ($addon_auth['authenticated'] && count($addon_auth['user_record'])) {
			$record = $addon_auth['user_record'];
		} else {
			$user_id = User::authenticate(trim($_POST['username']), trim($_POST['password']));
			if ($user_id) {
				$record = dba::select('user', [], ['uid' => $user_id], ['limit' => 1]);
			}
		}

		if (!$record || !count($record)) {
			logger('authenticate: failed login attempt: ' . notags(trim($_POST['username'])) . ' from IP ' . $_SERVER['REMOTE_ADDR']);
			notice(t('Login failed.') . EOL);
			goaway(System::baseUrl());
		}

		if (!$_POST['remember']) {
			new_cookie(0); // 0 means delete on browser exit
		}

		// if we haven't failed up this point, log them in.
		$_SESSION['remember'] = $_POST['remember'];
		$_SESSION['last_login_date'] = datetime_convert('UTC', 'UTC');
		authenticate_success($record, true, true);
	}
}

