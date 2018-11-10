<?php
/**
 * @file mod/removeme.php
 */

use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Model\User;

require_once 'include/enotify.php';

function removeme_post(App $a)
{
	if (!local_user()) {
		return;
	}

	if (x($_SESSION, 'submanage') && intval($_SESSION['submanage'])) {
		return;
	}

	if ((!x($_POST, 'qxz_password')) || (!strlen(trim($_POST['qxz_password'])))) {
		return;
	}

	if ((!x($_POST, 'verify')) || (!strlen(trim($_POST['verify'])))) {
		return;
	}

	if ($_POST['verify'] !== $_SESSION['remove_account_verify']) {
		return;
	}

	// send notification to admins so that they can clean um the backups
	// send email to admins
	$admin_mails = explode(",", str_replace(" ", "", Config::get('config', 'admin_email')));
	foreach ($admin_mails as $mail) {
		$admin = DBA::selectFirst('user', ['uid', 'language', 'email', 'username'], ['email' => $mail]);
		if (!DBA::isResult($admin)) {
			continue;
		}
		notification([
			'type'         => SYSTEM_EMAIL,
			'subject'      => L10n::t('[Friendica System Notify]') . ' ' . L10n::t('User deleted their account'),
			'preamble'     => L10n::t('On your Friendica node an user deleted their account. Please ensure that their data is removed from the backups.'),
			'body'         => L10n::t('The user id is %d', local_user()),
			'to_email'     => $admin['email'],
			'to_name'      => $admin['username'],
			'uid'          => $admin['uid'],
			'language'     => $admin['language'] ? $admin['language'] : 'en',
			'show_in_notification_page' => false
		]);
	}

	if (User::authenticate($a->user, trim($_POST['qxz_password']))) {
		User::remove($a->user['uid']);
		// NOTREACHED
	}
}

function removeme_content(App $a)
{
	if (!local_user()) {
		$a->internalRedirect();
	}

	$hash = Strings::getRandomHex();

	require_once("mod/settings.php");
	settings_init($a);

	$_SESSION['remove_account_verify'] = $hash;

	$tpl = Renderer::getMarkupTemplate('removeme.tpl');
	$o = Renderer::replaceMacros($tpl, [
		'$basedir' => $a->getBaseURL(),
		'$hash' => $hash,
		'$title' => L10n::t('Remove My Account'),
		'$desc' => L10n::t('This will completely remove your account. Once this has been done it is not recoverable.'),
		'$passwd' => L10n::t('Please enter your password for verification:'),
		'$submit' => L10n::t('Remove My Account')
	]);

	return $o;
}
