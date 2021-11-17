<?php
/**
 * @copyright Copyright (C) 2010-2021, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Module;

use Friendica\App\BaseURL;
use Friendica\BaseModule;
use Friendica\Content\Nav;
use Friendica\Core\Config\Capability\IManageConfigValues;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;

/**
 * Shows the App menu
 */
class Apps extends BaseModule
{
	public function __construct(L10n $l10n, IManageConfigValues $config, BaseURL $baseUrl, array $parameters = [])
	{
		parent::__construct($l10n, $parameters);

		$privateaddons = $config->get('config', 'private_addons');
		if ($privateaddons === "1" && !local_user()) {
			$baseUrl->redirect();
		}
	}

	public function content(): string
	{
		$apps = Nav::getAppMenu();

		if (count($apps) == 0) {
			notice($this->l10n->t('No installed applications.'));
		}

		$tpl = Renderer::getMarkupTemplate('apps.tpl');
		return Renderer::replaceMacros($tpl, [
			'$title' => $this->l10n->t('Applications'),
			'$apps'  => $apps,
		]);
	}
}
