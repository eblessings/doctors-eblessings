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

use Friendica\App;
use Friendica\BaseModule;
use Friendica\Core;
use Friendica\Core\Config\ValueObject\Cache;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Core\Theme;
use Friendica\DI;
use Friendica\Network\HTTPException;
use Friendica\Util\BasePath;
use Friendica\Util\Temporal;

class Install extends BaseModule
{
	/**
	 * Step one - System check
	 */
	const SYSTEM_CHECK = 1;
	/**
	 * Step two - Base information
	 */
	const BASE_CONFIG = 2;
	/**
	 * Step three - Database configuration
	 */
	const DATABASE_CONFIG = 3;
	/**
	 * Step four - Adapt site settings
	 */
	const SITE_SETTINGS = 4;
	/**
	 * Step five - All steps finished
	 */
	const FINISHED = 5;

	/**
	 * @var int The current step of the wizard
	 */
	private $currentWizardStep;

	/**
	 * @var Core\Installer The installer
	 */
	private $installer;

	/** @var App */
	protected $app;
	/** @var App\Mode */
	protected $mode;
	/** @var App\BaseURL */
	protected $baseUrl;

	public function __construct(App $app, App\Mode $mode, App\BaseURL $baseUrl, App\Arguments $args, Core\Installer $installer, L10n $l10n, array $parameters = [])
	{
		parent::__construct($l10n, $parameters);

		$this->app       = $app;
		$this->mode      = $mode;
		$this->baseUrl   = $baseUrl;
		$this->installer = $installer;

		if (!$this->mode->isInstall()) {
			throw new HTTPException\ForbiddenException();
		}

		// route: install/testrwrite
		// $baseurl/install/testrwrite to test if rewrite in .htaccess is working
		if ($args->get(1, '') == 'testrewrite') {
			// Status Code 204 means that it worked without content
			throw new HTTPException\NoContentException();
		}

		// get basic installation information and save them to the config cache
		$configCache = $this->app->getConfigCache();
		$basePath = new BasePath($this->app->getBasePath());
		$this->installer->setUpCache($configCache, $basePath->getPath());

		// We overwrite current theme css, because during install we may not have a working mod_rewrite
		// so we may not have a css at all. Here we set a static css file for the install procedure pages
		Renderer::$theme['stylesheet'] = $this->baseUrl->get() . '/view/install/style.css';

		$this->currentWizardStep = ($_POST['pass'] ?? '') ?: self::SYSTEM_CHECK;
	}

	public function post()
	{
		$configCache = $this->app->getConfigCache();

		switch ($this->currentWizardStep) {
			case self::SYSTEM_CHECK:
			case self::BASE_CONFIG:
				$this->checkSetting($configCache, $_POST, 'config', 'php_path');
				break;

			case self::DATABASE_CONFIG:
				$this->checkSetting($configCache, $_POST, 'config', 'php_path');

				$this->checkSetting($configCache, $_POST, 'config', 'hostname');
				$this->checkSetting($configCache, $_POST, 'system', 'ssl_policy');
				$this->checkSetting($configCache, $_POST, 'system', 'basepath');
				$this->checkSetting($configCache, $_POST, 'system', 'urlpath');
				break;

			case self::SITE_SETTINGS:
				$this->checkSetting($configCache, $_POST, 'config', 'php_path');

				$this->checkSetting($configCache, $_POST, 'config', 'hostname');
				$this->checkSetting($configCache, $_POST, 'system', 'ssl_policy');
				$this->checkSetting($configCache, $_POST, 'system', 'basepath');
				$this->checkSetting($configCache, $_POST, 'system', 'urlpath');

				$this->checkSetting($configCache, $_POST, 'database', 'hostname', Core\Installer::DEFAULT_HOST);
				$this->checkSetting($configCache, $_POST, 'database', 'username', '');
				$this->checkSetting($configCache, $_POST, 'database', 'password', '');
				$this->checkSetting($configCache, $_POST, 'database', 'database', '');

				// If we cannot connect to the database, return to the previous step
				if (!$this->installer->checkDB(DI::dba())) {
					$this->currentWizardStep = self::DATABASE_CONFIG;
				}

				break;

			case self::FINISHED:
				$this->checkSetting($configCache, $_POST, 'config', 'php_path');

				$this->checkSetting($configCache, $_POST, 'config', 'hostname');
				$this->checkSetting($configCache, $_POST, 'system', 'ssl_policy');
				$this->checkSetting($configCache, $_POST, 'system', 'basepath');
				$this->checkSetting($configCache, $_POST, 'system', 'urlpath');

				$this->checkSetting($configCache, $_POST, 'database', 'hostname', Core\Installer::DEFAULT_HOST);
				$this->checkSetting($configCache, $_POST, 'database', 'username', '');
				$this->checkSetting($configCache, $_POST, 'database', 'password', '');
				$this->checkSetting($configCache, $_POST, 'database', 'database', '');

				$this->checkSetting($configCache, $_POST, 'system', 'default_timezone', Core\Installer::DEFAULT_TZ);
				$this->checkSetting($configCache, $_POST, 'system', 'language', Core\Installer::DEFAULT_LANG);
				$this->checkSetting($configCache, $_POST, 'config', 'admin_email', '');

				// If we cannot connect to the database, return to the Database config wizard
				if (!$this->installer->checkDB(DI::dba())) {
					$this->currentWizardStep = self::DATABASE_CONFIG;
					return;
				}

				if (!$this->installer->createConfig($configCache)) {
					return;
				}

				$this->installer->installDatabase($configCache->get('system', 'basepath'));
			
				// install allowed themes to register theme hooks
				// this is same as "Reload active theme" in /admin/themes
				$allowed_themes = Theme::getAllowedList();
				$allowed_themes = array_unique($allowed_themes);
				foreach ($allowed_themes as $theme) {
					Theme::uninstall($theme);
					Theme::install($theme);
				}
				Theme::setAllowedList($allowed_themes);

				break;
		}
	}

	public function content(): string
	{
		$configCache = $this->app->getConfigCache();

		$output = '';

		$install_title = $this->t('Friendica Communications Server - Setup');

		switch ($this->currentWizardStep) {
			case self::SYSTEM_CHECK:
				$php_path = $configCache->get('config', 'php_path');

				$status = $this->installer->checkEnvironment($this->baseUrl->get(), $php_path);

				$tpl    = Renderer::getMarkupTemplate('install_checks.tpl');
				$output .= Renderer::replaceMacros($tpl, [
					'$title'       => $install_title,
					'$pass'        => $this->t('System check'),
					'$required'    => $this->t('Required'),
					'$requirement_not_satisfied' => $this->t('Requirement not satisfied'),
					'$optional_requirement_not_satisfied' => $this->t('Optional requirement not satisfied'),
					'$ok'          => $this->t('OK'),
					'$checks'      => $this->installer->getChecks(),
					'$passed'      => $status,
					'$see_install' => $this->t('Please see the file "doc/INSTALL.md".'),
					'$next'        => $this->t('Next'),
					'$reload'      => $this->t('Check again'),
					'$php_path'    => $php_path,
				]);
				break;

			case self::BASE_CONFIG:
				$ssl_choices = [
					App\BaseURL::SSL_POLICY_NONE     => $this->t("No SSL policy, links will track page SSL state"),
					App\BaseURL::SSL_POLICY_FULL     => $this->t("Force all links to use SSL"),
					App\BaseURL::SSL_POLICY_SELFSIGN => $this->t("Self-signed certificate, use SSL for local links only \x28discouraged\x29")
				];

				$tpl    = Renderer::getMarkupTemplate('install_base.tpl');
				$output .= Renderer::replaceMacros($tpl, [
					'$title'      => $install_title,
					'$pass'       => $this->t('Base settings'),
					'$ssl_policy' => ['system-ssl_policy',
						$this->t("SSL link policy"),
						$configCache->get('system', 'ssl_policy'),
						$this->t("Determines whether generated links should be forced to use SSL"),
						$ssl_choices],
					'$hostname'   => ['config-hostname',
						$this->t('Host name'),
						$configCache->get('config', 'hostname'),
						$this->t('Overwrite this field in case the determinated hostname isn\'t right, otherweise leave it as is.'),
						$this->t('Required')],
					'$basepath'   => ['system-basepath',
						$this->t("Base path to installation"),
						$configCache->get('system', 'basepath'),
						$this->t("If the system cannot detect the correct path to your installation, enter the correct path here. This setting should only be set if you are using a restricted system and symbolic links to your webroot."),
						$this->t('Required')],
					'$urlpath'    => ['system-urlpath',
						$this->t('Sub path of the URL'),
						$configCache->get('system', 'urlpath'),
						$this->t('Overwrite this field in case the sub path determination isn\'t right, otherwise leave it as is. Leaving this field blank means the installation is at the base URL without sub path.'),
						''],
					'$php_path'   => $configCache->get('config', 'php_path'),
					'$submit'     => $this->t('Submit'),
				]);
				break;

			case self::DATABASE_CONFIG:
				$tpl    = Renderer::getMarkupTemplate('install_db.tpl');
				$output .= Renderer::replaceMacros($tpl, [
					'$title'      => $install_title,
					'$pass'       => $this->t('Database connection'),
					'$info_01'    => $this->t('In order to install Friendica we need to know how to connect to your database.'),
					'$info_02'    => $this->t('Please contact your hosting provider or site administrator if you have questions about these settings.'),
					'$info_03'    => $this->t('The database you specify below should already exist. If it does not, please create it before continuing.'),
					'$required'   => $this->t('Required'),
					'$requirement_not_satisfied' => $this->t('Requirement not satisfied'),
					'$checks'     => $this->installer->getChecks(),
					'$hostname'   => $configCache->get('config', 'hostname'),
					'$ssl_policy' => $configCache->get('system', 'ssl_policy'),
					'$basepath'   => $configCache->get('system', 'basepath'),
					'$urlpath'    => $configCache->get('system', 'urlpath'),
					'$dbhost'     => ['database-hostname',
						$this->t('Database Server Name'),
						$configCache->get('database', 'hostname'),
						'',
						$this->t('Required')],
					'$dbuser'     => ['database-username',
						$this->t('Database Login Name'),
						$configCache->get('database', 'username'),
						'',
						$this->t('Required'),
						'autofocus'],
					'$dbpass'     => ['database-password',
						$this->t('Database Login Password'),
						$configCache->get('database', 'password'),
						$this->t("For security reasons the password must not be empty"),
						$this->t('Required')],
					'$dbdata'     => ['database-database',
						$this->t('Database Name'),
						$configCache->get('database', 'database'),
						'',
						$this->t('Required')],
					'$lbl_10'     => $this->t('Please select a default timezone for your website'),
					'$php_path'   => $configCache->get('config', 'php_path'),
					'$submit'     => $this->t('Submit')
				]);
				break;

			case self::SITE_SETTINGS:
				/* Installed langs */
				$lang_choices = $this->l10n->getAvailableLanguages();

				$tpl    = Renderer::getMarkupTemplate('install_settings.tpl');
				$output .= Renderer::replaceMacros($tpl, [
					'$title'      => $install_title,
					'$required'   => $this->t('Required'),
					'$checks'     => $this->installer->getChecks(),
					'$pass'       => $this->t('Site settings'),
					'$hostname'   => $configCache->get('config', 'hostname'),
					'$ssl_policy' => $configCache->get('system', 'ssl_policy'),
					'$basepath'   => $configCache->get('system', 'basepath'),
					'$urlpath'    => $configCache->get('system', 'urlpath'),
					'$dbhost'     => $configCache->get('database', 'hostname'),
					'$dbuser'     => $configCache->get('database', 'username'),
					'$dbpass'     => $configCache->get('database', 'password'),
					'$dbdata'     => $configCache->get('database', 'database'),
					'$adminmail'  => ['config-admin_email',
						$this->t('Site administrator email address'),
						$configCache->get('config', 'admin_email'),
						$this->t('Your account email address must match this in order to use the web admin panel.'),
						$this->t('Required'), 'autofocus', 'email'],
					'$timezone'   => Temporal::getTimezoneField('system-default_timezone',
						$this->t('Please select a default timezone for your website'),
						$configCache->get('system', 'default_timezone'),
						''),
					'$language'   => ['system-language',
						$this->t('System Language:'),
						$configCache->get('system', 'language'),
						$this->t('Set the default language for your Friendica installation interface and to send emails.'),
						$lang_choices],
					'$php_path'   => $configCache->get('config', 'php_path'),
					'$submit'     => $this->t('Submit')
				]);
				break;

			case self::FINISHED:
				$db_return_text = "";

				if (count($this->installer->getChecks()) == 0) {
					$txt            = '<p style="font-size: 130%;">';
					$txt            .= $this->t('Your Friendica site database has been installed.') . EOL;
					$db_return_text .= $txt;
				}

				$tpl    = Renderer::getMarkupTemplate('install_finished.tpl');
				$output .= Renderer::replaceMacros($tpl, [
					'$title'    => $install_title,
					'$required' => $this->t('Required'),
					'$requirement_not_satisfied' => $this->t('Requirement not satisfied'),
					'$checks'   => $this->installer->getChecks(),
					'$pass'     => $this->t('Installation finished'),
					'$text'     => $db_return_text . $this->whatNext(),
				]);

				break;
		}

		return $output;
	}

	/**
	 * Creates the text for the next steps
	 *
	 * @return string The text for the next steps
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private function whatNext()
	{
		$baseurl = $this->baseUrl->get();
		return
			$this->t('<h1>What next</h1>')
			. "<p>" . $this->t('IMPORTANT: You will need to [manually] setup a scheduled task for the worker.')
			. $this->t('Please see the file "doc/INSTALL.md".')
			. "</p><p>"
			. $this->t('Go to your new Friendica node <a href="%s/register">registration page</a> and register as new user. Remember to use the same email you have entered as administrator email. This will allow you to enter the site admin panel.', $baseurl)
			. "</p>";
	}

	/**
	 * Checks the $_POST settings and updates the config Cache for it
	 *
	 * @param \Friendica\Core\Config\ValueObject\Cache $configCache The current config cache
	 * @param array                                    $post        The $_POST data
	 * @param string                                   $cat         The category of the setting
	 * @param string                                   $key         The key of the setting
	 * @param null|string                              $default     The default value
	 */
	private function checkSetting(Cache $configCache, array $post, string $cat, string $key, ?string $default = null)
	{
		$value = null;

		if (isset($post[sprintf('%s-%s', $cat, $key)])) {
			$value = trim($post[sprintf('%s-%s', $cat, $key)]);
		}

		if (isset($value)) {
			$configCache->set($cat, $key, $value, Cache::SOURCE_ENV);
			return;
		}

		if (isset($default)) {
			$configCache->set($cat, $key, $default, Cache::SOURCE_ENV);
		}
	}
}
