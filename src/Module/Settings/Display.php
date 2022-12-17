<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
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

namespace Friendica\Module\Settings;

use Friendica\Content\Text\BBCode;
use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\Renderer;
use Friendica\Core\Theme;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\User;
use Friendica\Module\BaseSettings;
use Friendica\Network\HTTPException;

/**
 * Module to update user settings
 */
class Display extends BaseSettings
{
	protected function post(array $request = [])
	{
		if (!DI::app()->isLoggedIn()) {
			throw new HTTPException\ForbiddenException(DI::l10n()->t('Permission denied.'));
		}

		self::checkFormSecurityTokenRedirectOnError('/settings/display', 'settings_display');

		$user = User::getById(DI::userSession()->getLocalUserId());

		$theme                  = !empty($_POST['theme'])                  ? trim($_POST['theme'])                : $user['theme'];
		$mobile_theme           = !empty($_POST['mobile_theme'])           ? trim($_POST['mobile_theme'])         : '';
		$enable_smile           = !empty($_POST['enable_smile'])           ? intval($_POST['enable_smile'])       : 0;
		$first_day_of_week      = !empty($_POST['first_day_of_week'])      ? intval($_POST['first_day_of_week'])  : 0;
		$calendar_default_view  = !empty($_POST['calendar_default_view'])  ? trim($_POST['calendar_default_view']): 'month';
		$infinite_scroll        = !empty($_POST['infinite_scroll'])        ? intval($_POST['infinite_scroll'])    : 0;
		$no_auto_update         = !empty($_POST['no_auto_update'])         ? intval($_POST['no_auto_update'])     : 0;
		$enable_smart_threading = !empty($_POST['enable_smart_threading']) ? intval($_POST['enable_smart_threading']) : 0;
		$enable_dislike         = !empty($_POST['enable_dislike'])         ? intval($_POST['enable_dislike'])       : 0;
		$display_resharer       = !empty($_POST['display_resharer'])       ? intval($_POST['display_resharer'])   : 0;
		$stay_local             = !empty($_POST['stay_local'])             ? intval($_POST['stay_local'])         : 0;
		$preview_mode           = !empty($_POST['preview_mode'])           ? intval($_POST['preview_mode'])  : 0;
		$browser_update         = !empty($_POST['browser_update'])         ? intval($_POST['browser_update'])     : 0;
		if ($browser_update != -1) {
			$browser_update = $browser_update * 1000;
			if ($browser_update < 10000) {
				$browser_update = 10000;
			}
		}

		$itemspage_network = !empty($_POST['itemspage_network']) ?
			intval($_POST['itemspage_network']) :
			DI::config()->get('system', 'itemspage_network');
		if ($itemspage_network > 100) {
			$itemspage_network = 100;
		}
		$itemspage_mobile_network = !empty($_POST['itemspage_mobile_network']) ?
			intval($_POST['itemspage_mobile_network']) :
			DI::config()->get('system', 'itemspage_network_mobile');
		if ($itemspage_mobile_network > 100) {
			$itemspage_mobile_network = 100;
		}

		if ($mobile_theme !== '') {
			DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'mobile_theme', $mobile_theme);
		}

		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'itemspage_network'       , $itemspage_network);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'itemspage_mobile_network', $itemspage_mobile_network);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'update_interval'         , $browser_update);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'no_auto_update'          , $no_auto_update);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'no_smilies'              , !$enable_smile);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'infinite_scroll'         , $infinite_scroll);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'no_smart_threading'      , !$enable_smart_threading);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'hide_dislike'            , !$enable_dislike);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'display_resharer'        , $display_resharer);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'stay_local'              , $stay_local);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'system', 'preview_mode'            , $preview_mode);

		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'calendar', 'first_day_of_week'     , $first_day_of_week);
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'calendar', 'default_view'           , $calendar_default_view);

		if (in_array($theme, Theme::getAllowedList())) {
			if ($theme == $user['theme']) {
				// call theme_post only if theme has not been changed
				if (($themeconfigfile = Theme::getConfigFile($theme)) !== null) {
					require_once $themeconfigfile;
					theme_post(DI::app());
				}
			} else {
				DBA::update('user', ['theme' => $theme], ['uid' => DI::userSession()->getLocalUserId()]);
			}
		} else {
			DI::sysmsg()->addNotice(DI::l10n()->t('The theme you chose isn\'t available.'));
		}

		Hook::callAll('display_settings_post', $_POST);

		DI::baseUrl()->redirect('settings/display');
	}

	protected function content(array $request = []): string
	{
		parent::content();

		if (!DI::userSession()->getLocalUserId()) {
			throw new HTTPException\ForbiddenException(DI::l10n()->t('Permission denied.'));
		}

		$default_theme = DI::config()->get('system', 'theme');
		if (!$default_theme) {
			$default_theme = 'default';
		}

		$default_mobile_theme = DI::config()->get('system', 'mobile-theme');
		if (!$default_mobile_theme) {
			$default_mobile_theme = 'none';
		}

		$user = User::getById(DI::userSession()->getLocalUserId());

		$allowed_themes = Theme::getAllowedList();

		$themes = [];
		$mobile_themes = ["---" => DI::l10n()->t('No special theme for mobile devices')];
		foreach ($allowed_themes as $theme) {
			$is_experimental = file_exists('view/theme/' . $theme . '/experimental');
			$is_unsupported  = file_exists('view/theme/' . $theme . '/unsupported');
			$is_mobile       = file_exists('view/theme/' . $theme . '/mobile');
			if (!$is_experimental || (DI::config()->get('experimentals', 'exp_themes') || is_null(DI::config()->get('experimentals', 'exp_themes')))) {
				$theme_name = ucfirst($theme);
				if ($is_unsupported) {
					$theme_name = DI::l10n()->t('%s - (Unsupported)', $theme_name);
				} elseif ($is_experimental) {
					$theme_name = DI::l10n()->t('%s - (Experimental)', $theme_name);
				}

				if ($is_mobile) {
					$mobile_themes[$theme] = $theme_name;
				} else {
					$themes[$theme] = $theme_name;
				}
			}
		}

		$theme_selected        = $user['theme'] ?: $default_theme;
		$mobile_theme_selected = DI::session()->get('mobile-theme', $default_mobile_theme);

		$itemspage_network = intval(DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'itemspage_network'));
		$itemspage_network = (($itemspage_network > 0 && $itemspage_network < 101) ? $itemspage_network : DI::config()->get('system', 'itemspage_network'));
		$itemspage_mobile_network = intval(DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'itemspage_mobile_network'));
		$itemspage_mobile_network = (($itemspage_mobile_network > 0 && $itemspage_mobile_network < 101) ? $itemspage_mobile_network : DI::config()->get('system', 'itemspage_network_mobile'));

		$browser_update = intval(DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'update_interval'));
		if (intval($browser_update) != -1) {
			$browser_update = (($browser_update == 0) ? 40 : $browser_update / 1000); // default if not set: 40 seconds
		}

		$no_auto_update         =  DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'no_auto_update', 0);
		$enable_smile           = !DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'no_smilies', 0);
		$infinite_scroll        =  DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'infinite_scroll', 0);
		$enable_smart_threading = !DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'no_smart_threading', 0);
		$enable_dislike         = !DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'hide_dislike', 0);
		$display_resharer       =  DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'display_resharer', 0);
		$stay_local             =  DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'stay_local', 0);
		$preview_mode           =  DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'preview_mode', BBCode::PREVIEW_LARGE);

		$first_day_of_week = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'system', 'first_day_of_week', 0);
		$weekdays = [
			0 => DI::l10n()->t("Sunday"),
			1 => DI::l10n()->t("Monday"),
			2 => DI::l10n()->t("Tuesday"),
			3 => DI::l10n()->t("Wednesday"),
			4 => DI::l10n()->t("Thursday"),
			5 => DI::l10n()->t("Friday"),
			6 => DI::l10n()->t("Saturday")
		];

		$preview_modes = [
			BBCode::PREVIEW_NONE     => DI::l10n()->t('No preview'),
			BBCode::PREVIEW_NO_IMAGE => DI::l10n()->t('No image'),
			BBCode::PREVIEW_SMALL    => DI::l10n()->t('Small Image'),
			BBCode::PREVIEW_LARGE    => DI::l10n()->t('Large Image'),
		];

		$calendar_default_view = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'calendar', 'default_view', 'month');
		$calendarViews = [
			'month'      => DI::l10n()->t('month'),
			'agendaWeek' => DI::l10n()->t('week'),
			'agendaDay'  => DI::l10n()->t('day'),
			'listMonth'  => DI::l10n()->t('list')
		];

		$theme_config = '';
		if ($themeconfigfile = Theme::getConfigFile($theme_selected)) {
			require_once $themeconfigfile;
			$theme_config = theme_content(DI::app());
		}

		$tpl = Renderer::getMarkupTemplate('settings/display.tpl');
		$o = Renderer::replaceMacros($tpl, [
			'$ptitle'         => DI::l10n()->t('Display Settings'),
			'$submit'         => DI::l10n()->t('Save Settings'),
			'$d_tset'         => DI::l10n()->t('General Theme Settings'),
			'$d_ctset'        => DI::l10n()->t('Custom Theme Settings'),
			'$d_cset'         => DI::l10n()->t('Content Settings'),
			'$stitle'         => DI::l10n()->t('Theme settings'),
			'$calendar_title' => DI::l10n()->t('Calendar'),

			'$form_security_token' => self::getFormSecurityToken('settings_display'),
			'$baseurl' => DI::baseUrl()->get(true),
			'$uid'     => DI::userSession()->getLocalUserId(),

			'$theme'	    => ['theme', DI::l10n()->t('Display Theme:'), $theme_selected, '', $themes, true],
			'$mobile_theme'	=> ['mobile_theme', DI::l10n()->t('Mobile Theme:'), $mobile_theme_selected, '', $mobile_themes, false],
			'$theme_config' => $theme_config,

			'$itemspage_network'        => ['itemspage_network'       , DI::l10n()->t('Number of items to display per page:'), $itemspage_network, DI::l10n()->t('Maximum of 100 items')],
			'$itemspage_mobile_network' => ['itemspage_mobile_network', DI::l10n()->t('Number of items to display per page when viewed from mobile device:'), $itemspage_mobile_network, DI::l10n()->t('Maximum of 100 items')],
			'$ajaxint'                  => ['browser_update'          , DI::l10n()->t('Update browser every xx seconds'), $browser_update, DI::l10n()->t('Minimum of 10 seconds. Enter -1 to disable it.')],
			'$no_auto_update'           => ['no_auto_update'          , DI::l10n()->t('Automatic updates only at the top of the post stream pages'), $no_auto_update, DI::l10n()->t('Auto update may add new posts at the top of the post stream pages, which can affect the scroll position and perturb normal reading if it happens anywhere else the top of the page.')],
			'$enable_smile'	            => ['enable_smile'            , DI::l10n()->t('Display emoticons'), $enable_smile, DI::l10n()->t('When enabled, emoticons are replaced with matching symbols.')],
			'$infinite_scroll'          => ['infinite_scroll'         , DI::l10n()->t('Infinite scroll'), $infinite_scroll, DI::l10n()->t('Automatic fetch new items when reaching the page end.')],
			'$enable_smart_threading'   => ['enable_smart_threading'  , DI::l10n()->t('Enable Smart Threading'), $enable_smart_threading, DI::l10n()->t('Enable the automatic suppression of extraneous thread indentation.')],
			'$enable_dislike'           => ['enable_dislike'          , DI::l10n()->t('Display the Dislike feature'), $enable_dislike, DI::l10n()->t('Display the Dislike button and dislike reactions on posts and comments.')],
			'$display_resharer'         => ['display_resharer'        , DI::l10n()->t('Display the resharer'), $display_resharer, DI::l10n()->t('Display the first resharer as icon and text on a reshared item.')],
			'$stay_local'               => ['stay_local'              , DI::l10n()->t('Stay local'), $stay_local, DI::l10n()->t("Don't go to a remote system when following a contact link.")],
			'$preview_mode'             => ['preview_mode'            , DI::l10n()->t('Link preview mode'), $preview_mode, 'Appearance of the link preview that is added to each post with a link.', $preview_modes, false],

			'$first_day_of_week'     => ['first_day_of_week'    , DI::l10n()->t('Beginning of week:')    , $first_day_of_week    , '', $weekdays     , false],
			'$calendar_default_view' => ['calendar_default_view', DI::l10n()->t('Default calendar view:'), $calendar_default_view, '', $calendarViews, false],
		]);

		return $o;
	}
}
