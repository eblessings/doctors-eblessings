<?php
/**
 * @file mod/uimport.php
 * View for user import
 */

use Friendica\App;
use Friendica\Core\Logger;
use Friendica\Core\UserImport;
use Friendica\Core\Renderer;
use Friendica\DI;

function uimport_post(App $a)
{
	if ((DI::config()->get('config', 'register_policy') != \Friendica\Module\Register::OPEN) && !is_site_admin()) {
		notice(DI::l10n()->t('Permission denied.') . EOL);
		return;
	}

	if (!empty($_FILES['accountfile'])) {
		UserImport::importAccount($_FILES['accountfile']);
		return;
	}
}

function uimport_content(App $a)
{
	if ((DI::config()->get('config', 'register_policy') != \Friendica\Module\Register::OPEN) && !is_site_admin()) {
		notice(DI::l10n()->t('User imports on closed servers can only be done by an administrator.') . EOL);
		return;
	}

	$max_dailies = intval(DI::config()->get('system', 'max_daily_registrations'));
	if ($max_dailies) {
		$r = q("select count(*) as total from user where register_date > UTC_TIMESTAMP - INTERVAL 1 day");
		if ($r && $r[0]['total'] >= $max_dailies) {
			Logger::log('max daily registrations exceeded.');
			notice(DI::l10n()->t('This site has exceeded the number of allowed daily account registrations. Please try again tomorrow.') . EOL);
			return;
		}
	}

	$tpl = Renderer::getMarkupTemplate("uimport.tpl");
	return Renderer::replaceMacros($tpl, [
		'$regbutt' => DI::l10n()->t('Import'),
		'$import' => [
			'title' => DI::l10n()->t("Move account"),
			'intro' => DI::l10n()->t("You can import an account from another Friendica server."),
			'instruct' => DI::l10n()->t("You need to export your account from the old server and upload it here. We will recreate your old account here with all your contacts. We will try also to inform your friends that you moved here."),
			'warn' => DI::l10n()->t("This feature is experimental. We can't import contacts from the OStatus network \x28GNU Social/Statusnet\x29 or from Diaspora"),
			'field' => ['accountfile', DI::l10n()->t('Account file'), '<input id="id_accountfile" name="accountfile" type="file">', DI::l10n()->t('To export your account, go to "Settings->Export your personal data" and select "Export account"')],
		],
	]);
}
