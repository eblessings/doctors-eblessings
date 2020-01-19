<?php

namespace Friendica\Module\Admin\Addons;

use Friendica\Core\Addon;
use Friendica\Core\Renderer;
use Friendica\DI;
use Friendica\Module\BaseAdminModule;

class Index extends BaseAdminModule
{
	public static function content(array $parameters = [])
	{
		parent::content($parameters);

		// reload active themes
		if (!empty($_GET['action'])) {
			parent::checkFormSecurityTokenRedirectOnError('/admin/addons', 'admin_addons', 't');

			switch ($_GET['action']) {
				case 'reload':
					Addon::reload();
					info('Addons reloaded');
					break;

				case 'toggle' :
					$addon = $_GET['addon'] ?? '';
					if (Addon::isEnabled($addon)) {
						Addon::uninstall($addon);
						info(DI::l10n()->t('Addon %s disabled.', $addon));
					} elseif (Addon::install($addon)) {
						info(DI::l10n()->t('Addon %s enabled.', $addon));
					} else {
						info(DI::l10n()->t('Addon %s failed to install.', $addon));
					}

					break;

			}

			DI::baseUrl()->redirect('admin/addons');
		}

		$addons = Addon::getAvailableList();

		$t = Renderer::getMarkupTemplate('admin/addons/index.tpl');
		return Renderer::replaceMacros($t, [
			'$title' => DI::l10n()->t('Administration'),
			'$page' => DI::l10n()->t('Addons'),
			'$submit' => DI::l10n()->t('Save Settings'),
			'$reload' => DI::l10n()->t('Reload active addons'),
			'$baseurl' => DI::baseUrl()->get(true),
			'$function' => 'addons',
			'$addons' => $addons,
			'$pcount' => count($addons),
			'$noplugshint' => DI::l10n()->t('There are currently no addons available on your node. You can find the official addon repository at %1$s and might find other interesting addons in the open addon registry at %2$s', 'https://github.com/friendica/friendica-addons', 'http://addons.friendi.ca'),
			'$form_security_token' => parent::getFormSecurityToken('admin_addons'),
		]);
	}
}
