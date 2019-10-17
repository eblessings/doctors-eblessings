<?php

namespace Friendica\Module\Admin\Themes;

use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Module\BaseAdminModule;
use Friendica\Util\Strings;

class Embed extends BaseAdminModule
{
	public static function init()
	{
		$a = self::getApp();

		if ($a->argc > 2) {
			// @TODO: Replace with parameter from router
			$theme = $a->argv[2];
			$theme = Strings::sanitizeFilePathItem($theme);
			if (is_file("view/theme/$theme/config.php")) {
				$a->setCurrentTheme($theme);
			}
		}
	}

	public static function post()
	{
		parent::post();

		$a = self::getApp();

		if ($a->argc > 2) {
			// @TODO: Replace with parameter from router
			$theme = $a->argv[2];
			$theme = Strings::sanitizeFilePathItem($theme);
			if (is_file("view/theme/$theme/config.php")) {
				self::checkFormSecurityTokenRedirectOnError('/admin/themes/' . $theme . '/embed?mode=minimal', 'admin_theme_settings');

				require_once "view/theme/$theme/config.php";

				if (function_exists('theme_admin_post')) {
					theme_admin_post($a);
				}
			}

			info(L10n::t('Theme settings updated.'));

			if ($a->isAjax()) {
				return;
			}

			$a->internalRedirect('admin/themes/' . $theme . '/embed?mode=minimal');
		}
	}

	public static function content()
	{
		parent::content();

		$a = self::getApp();

		if ($a->argc > 2) {
			// @TODO: Replace with parameter from router
			$theme = $a->argv[2];
			$theme = Strings::sanitizeFilePathItem($theme);
			if (!is_dir("view/theme/$theme")) {
				notice(L10n::t('Unknown theme.'));
				return '';
			}

			$admin_form = '';
			if (is_file("view/theme/$theme/config.php")) {
				require_once "view/theme/$theme/config.php";

				if (function_exists('theme_admin')) {
					$admin_form = theme_admin($a);
				}
			}

			// Overrides normal theme style include to strip user param to show embedded theme settings
			Renderer::$theme['stylesheet'] = 'view/theme/' . $theme . '/style.pcss';

			$t = Renderer::getMarkupTemplate('admin/addons/embed.tpl');
			return Renderer::replaceMacros($t, [
				'$action' => '/admin/themes/' . $theme . '/embed?mode=minimal',
				'$form' => $admin_form,
				'$form_security_token' => parent::getFormSecurityToken("admin_theme_settings"),
			]);
		}

		return '';
	}
}
