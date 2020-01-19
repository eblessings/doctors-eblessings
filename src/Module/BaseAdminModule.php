<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Core\Addon;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Core\Session;
use Friendica\DI;
use Friendica\Network\HTTPException\ForbiddenException;

require_once 'boot.php';

/**
 * This abstract module is meant to be extended by all modules that are reserved to administrator users.
 *
 * It performs a blanket permission check in all the module methods as long as the relevant `parent::method()` is
 * called in the inheriting module.
 *
 * Additionally, it puts together the administration page aside with all the administration links.
 *
 * @package Friendica\Module
 */
abstract class BaseAdminModule extends BaseModule
{
	public static function post(array $parameters = [])
	{
		if (!is_site_admin()) {
			return;
		}

		// do not allow a page manager to access the admin panel at all.
		if (!empty($_SESSION['submanage'])) {
			return;
		}
	}

	public static function rawContent(array $parameters = [])
	{
		if (!is_site_admin()) {
			return '';
		}

		if (!empty($_SESSION['submanage'])) {
			return '';
		}

		return '';
	}

	public static function content(array $parameters = [])
	{
		if (!is_site_admin()) {
			notice(DI::l10n()->t('Please login to continue.'));
			Session::set('return_path', DI::args()->getQueryString());
			DI::baseUrl()->redirect('login');
		}

		if (!empty($_SESSION['submanage'])) {
			throw new ForbiddenException(DI::l10n()->t('Submanaged account can\'t access the administation pages. Please log back in as the master account.'));
		}

		// Header stuff
		DI::page()['htmlhead'] .= Renderer::replaceMacros(Renderer::getMarkupTemplate('admin/settings_head.tpl'), []);

		/*
		 * Side bar links
		 */

		// array(url, name, extra css classes)
		// not part of $aside to make the template more adjustable
		$aside_sub = [
			'information' => [DI::l10n()->t('Information'), [
				'overview'     => ['admin'             , DI::l10n()->t('Overview')                , 'overview'],
				'federation'   => ['admin/federation'  , DI::l10n()->t('Federation Statistics')   , 'federation']
			]],
			'configuration' => [DI::l10n()->t('Configuration'), [
				'site'         => ['admin/site'        , DI::l10n()->t('Site')                    , 'site'],
				'users'        => ['admin/users'       , DI::l10n()->t('Users')                   , 'users'],
				'addons'       => ['admin/addons'      , DI::l10n()->t('Addons')                  , 'addons'],
				'themes'       => ['admin/themes'      , DI::l10n()->t('Themes')                  , 'themes'],
				'features'     => ['admin/features'    , DI::l10n()->t('Additional features')     , 'features'],
				'tos'          => ['admin/tos'         , DI::l10n()->t('Terms of Service')        , 'tos'],
			]],
			'database' => [DI::l10n()->t('Database'), [
				'dbsync'       => ['admin/dbsync'      , DI::l10n()->t('DB updates')              , 'dbsync'],
				'deferred'     => ['admin/queue/deferred', DI::l10n()->t('Inspect Deferred Workers'), 'deferred'],
				'workerqueue'  => ['admin/queue'       , DI::l10n()->t('Inspect worker Queue')    , 'workerqueue'],
			]],
			'tools' => [DI::l10n()->t('Tools'), [
				'contactblock' => ['admin/blocklist/contact', DI::l10n()->t('Contact Blocklist')  , 'contactblock'],
				'blocklist'    => ['admin/blocklist/server' , DI::l10n()->t('Server Blocklist')   , 'blocklist'],
				'deleteitem'   => ['admin/item/delete' , DI::l10n()->t('Delete Item')             , 'deleteitem'],
			]],
			'logs' => [DI::l10n()->t('Logs'), [
				'logsconfig'   => ['admin/logs/', DI::l10n()->t('Logs')                           , 'logs'],
				'logsview'     => ['admin/logs/view'    , DI::l10n()->t('View Logs')              , 'viewlogs'],
			]],
			'diagnostics' => [DI::l10n()->t('Diagnostics'), [
				'phpinfo'      => ['admin/phpinfo'           , DI::l10n()->t('PHP Info')          , 'phpinfo'],
				'probe'        => ['probe'             , DI::l10n()->t('probe address')           , 'probe'],
				'webfinger'    => ['webfinger'         , DI::l10n()->t('check webfinger')         , 'webfinger'],
				'itemsource'   => ['admin/item/source' , DI::l10n()->t('Item Source')             , 'itemsource'],
				'babel'        => ['babel'             , DI::l10n()->t('Babel')                   , 'babel'],
			]],
		];

		$t = Renderer::getMarkupTemplate('admin/aside.tpl');
		DI::page()['aside'] .= Renderer::replaceMacros($t, [
			'$admin' => ['addons_admin' => Addon::getAdminList()],
			'$subpages' => $aside_sub,
			'$admtxt' => DI::l10n()->t('Admin'),
			'$plugadmtxt' => DI::l10n()->t('Addon Features'),
			'$h_pending' => DI::l10n()->t('User registrations waiting for confirmation'),
			'$admurl' => 'admin/'
		]);

		return '';
	}
}
