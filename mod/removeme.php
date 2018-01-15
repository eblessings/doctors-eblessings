<?php

use Friendica\App;
use Friendica\Core\System;
use Friendica\Model\User;

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

	if (User::authenticate($a->user['uid'], trim($_POST['qxz_password']))) {
		User::remove($a->user['uid']);
		// NOTREACHED
	}
}

function removeme_content(App $a)
{
	if (!local_user()) {
		goaway(System::baseUrl());
	}

	$hash = random_string();

	require_once("mod/settings.php");
	settings_init($a);

	$_SESSION['remove_account_verify'] = $hash;

	$tpl = get_markup_template('removeme.tpl');
	$o .= replace_macros($tpl, [
		'$basedir' => System::baseUrl(),
		'$hash' => $hash,
		'$title' => t('Remove My Account'),
		'$desc' => t('This will completely remove your account. Once this has been done it is not recoverable.'),
		'$passwd' => t('Please enter your password for verification:'),
		'$submit' => t('Remove My Account')
	]);

	return $o;
}
