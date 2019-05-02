<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Database\DBA;

abstract class BaseAdminModule extends BaseModule
{
	public static function post()
	{
		if (!is_site_admin()) {
			return;
		}

		// do not allow a page manager to access the admin panel at all.
		if (!empty($_SESSION['submanage'])) {
			return;
		}
	}

	public static function content()
	{
		if (!is_site_admin()) {
			return Login::form();
		}

		if (!empty($_SESSION['submanage'])) {
			return '';
		}

		$a = self::getApp();

		// APC deactivated, since there are problems with PHP 5.5
		//if (function_exists("apc_delete")) {
		// $toDelete = new APCIterator('user', APC_ITER_VALUE);
		// apc_delete($toDelete);
		//}
		// Header stuff
		$a->page['htmlhead'] .= Renderer::replaceMacros(Renderer::getMarkupTemplate('admin/settings_head.tpl'), []);

		/*
		 * Side bar links
		 */

		// array(url, name, extra css classes)
		// not part of $aside to make the template more adjustable
		$aside_sub = [
			'information' => [L10n::t('Information'), [
				'overview'     => ['admin'             , L10n::t('Overview')                , 'overview'],
				'federation'   => ['admin/federation'  , L10n::t('Federation Statistics')   , 'federation']
			]],
			'configuration' => [L10n::t('Configuration'), [
				'themes'       => ['admin/themes'      , L10n::t('Themes')                  , 'themes'],
				'tos'          => ['admin/tos'         , L10n::t('Terms of Service')        , 'tos'],
			]],
		];

		$addons_admin = [];
		$addonsAdminStmt = DBA::select('addon', ['name'], ['plugin_admin' => 1], ['order' => ['name']]);
		foreach (DBA::toArray($addonsAdminStmt) as $addon) {
			$addons_admin[] = ['admin/addons/' . $addon['name'], $addon['name'], 'addon'];
		}

		$t = Renderer::getMarkupTemplate('admin/aside.tpl');
		$a->page['aside'] .= Renderer::replaceMacros($t, [
			'$admin' => ['addons_admin' => $addons_admin],
			'$subpages' => $aside_sub,
			'$admtxt' => L10n::t('Admin'),
			'$plugadmtxt' => L10n::t('Addon Features'),
			'$h_pending' => L10n::t('User registrations waiting for confirmation'),
			'$admurl' => 'admin/'
		]);

		return '';
	}
}
