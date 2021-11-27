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

namespace Friendica\App;

use ArrayAccess;
use DOMDocument;
use DOMXPath;
use Friendica\App;
use Friendica\Capabilities\IRespondToRequests;
use Friendica\Content\Nav;
use Friendica\Core\Config\Capability\IManageConfigValues;
use Friendica\Core\PConfig\Capability\IManagePersonalConfigValues;
use Friendica\Core\Hook;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Core\Theme;
use Friendica\Network\HTTPException;
use Friendica\Util\Network;
use Friendica\Util\Strings;
use Friendica\Util\Profiler;

/**
 * Contains the page specific environment variables for the current Page
 * - Contains all stylesheets
 * - Contains all footer-scripts
 * - Contains all page specific content (header, footer, content, ...)
 *
 * The run() method is the single point where the page will get printed to the screen
 */
class Page implements ArrayAccess
{
	/**
	 * @var array Contains all stylesheets, which should get loaded during page
	 */
	private $stylesheets = [];
	/**
	 * @var array Contains all scripts, which are added to the footer at last
	 */
	private $footerScripts = [];
	/**
	 * @var array The page content, which are showed directly
	 */
	private $page = [
		'aside'       => '',
		'bottom'      => '',
		'content'     => '',
		'footer'      => '',
		'htmlhead'    => '',
		'nav'         => '',
		'page_title'  => '',
		'right_aside' => '',
		'template'    => '',
		'title'       => '',
	];
	/**
	 * @var string The basepath of the page
	 */
	private $basePath;

	/**
	 * @param string $basepath The Page basepath
	 */
	public function __construct(string $basepath)
	{
		$this->basePath = $basepath;
	}

	/**
	 * Whether a offset exists
	 *
	 * @link  https://php.net/manual/en/arrayaccess.offsetexists.php
	 *
	 * @param mixed $offset <p>
	 *                      An offset to check for.
	 *                      </p>
	 *
	 * @return boolean true on success or false on failure.
	 * </p>
	 * <p>
	 * The return value will be casted to boolean if non-boolean was returned.
	 * @since 5.0.0
	 */
	public function offsetExists($offset)
	{
		return isset($this->page[$offset]);
	}

	/**
	 * Offset to retrieve
	 *
	 * @link  https://php.net/manual/en/arrayaccess.offsetget.php
	 *
	 * @param mixed $offset <p>
	 *                      The offset to retrieve.
	 *                      </p>
	 *
	 * @return mixed Can return all value types.
	 * @since 5.0.0
	 */
	public function offsetGet($offset)
	{
		return $this->page[$offset] ?? null;
	}

	/**
	 * Offset to set
	 *
	 * @link  https://php.net/manual/en/arrayaccess.offsetset.php
	 *
	 * @param mixed $offset <p>
	 *                      The offset to assign the value to.
	 *                      </p>
	 * @param mixed $value  <p>
	 *                      The value to set.
	 *                      </p>
	 *
	 * @return void
	 * @since 5.0.0
	 */
	public function offsetSet($offset, $value)
	{
		$this->page[$offset] = $value;
	}

	/**
	 * Offset to unset
	 *
	 * @link  https://php.net/manual/en/arrayaccess.offsetunset.php
	 *
	 * @param mixed $offset <p>
	 *                      The offset to unset.
	 *                      </p>
	 *
	 * @return void
	 * @since 5.0.0
	 */
	public function offsetUnset($offset)
	{
		if (isset($this->page[$offset])) {
			unset($this->page[$offset]);
		}
	}

	/**
	 * Register a stylesheet file path to be included in the <head> tag of every page.
	 * Inclusion is done in App->initHead().
	 * The path can be absolute or relative to the Friendica installation base folder.
	 *
	 * @param string $path
	 * @param string $media
	 * @see Page::initHead()
	 */
	public function registerStylesheet($path, string $media = 'screen')
	{
		$path = Network::appendQueryParam($path, ['v' => FRIENDICA_VERSION]);

		if (mb_strpos($path, $this->basePath . DIRECTORY_SEPARATOR) === 0) {
			$path = mb_substr($path, mb_strlen($this->basePath . DIRECTORY_SEPARATOR));
		}

		$this->stylesheets[trim($path, '/')] = $media;
	}

	/**
	 * Initializes Page->page['htmlhead'].
	 *
	 * Includes:
	 * - Page title
	 * - Favicons
	 * - Registered stylesheets (through App->registerStylesheet())
	 * - Infinite scroll data
	 * - head.tpl template
	 *
	 * @param App                         $app     The Friendica App instance
	 * @param Arguments                   $args    The Friendica App Arguments
	 * @param L10n                        $l10n    The l10n language instance
	 * @param IManageConfigValues         $config  The Friendica configuration
	 * @param IManagePersonalConfigValues $pConfig The Friendica personal configuration (for user)
	 *
	 * @throws HTTPException\InternalServerErrorException
	 */
	private function initHead(App $app, Arguments $args, L10n $l10n, IManageConfigValues $config, IManagePersonalConfigValues $pConfig)
	{
		$interval = ((local_user()) ? $pConfig->get(local_user(), 'system', 'update_interval') : 40000);

		// If the update is 'deactivated' set it to the highest integer number (~24 days)
		if ($interval < 0) {
			$interval = 2147483647;
		}

		if ($interval < 10000) {
			$interval = 40000;
		}

		// Default title: current module called
		if (empty($this->page['title']) && $args->getModuleName()) {
			$this->page['title'] = ucfirst($args->getModuleName());
		}

		// Prepend the sitename to the page title
		$this->page['title'] = $config->get('config', 'sitename', '') . (!empty($this->page['title']) ? ' | ' . $this->page['title'] : '');

		if (!empty(Renderer::$theme['stylesheet'])) {
			$stylesheet = Renderer::$theme['stylesheet'];
		} else {
			$stylesheet = $app->getCurrentThemeStylesheetPath();
		}

		$this->registerStylesheet($stylesheet);

		$shortcut_icon = $config->get('system', 'shortcut_icon');
		if ($shortcut_icon == '') {
			$shortcut_icon = 'images/friendica-32.png';
		}

		$touch_icon = $config->get('system', 'touch_icon');
		if ($touch_icon == '') {
			$touch_icon = 'images/friendica-192.png';
		}

		Hook::callAll('head', $this->page['htmlhead']);

		$tpl = Renderer::getMarkupTemplate('head.tpl');
		/* put the head template at the beginning of page['htmlhead']
		 * since the code added by the modules frequently depends on it
		 * being first
		 */
		$this->page['htmlhead'] = Renderer::replaceMacros($tpl, [
			'$local_user'      => local_user(),
			'$generator'       => 'Friendica' . ' ' . FRIENDICA_VERSION,
			'$delitem'         => $l10n->t('Delete this item?'),
			'$blockAuthor'     => $l10n->t('Block this author? They won\'t be able to follow you nor see your public posts, and you won\'t be able to see their posts and their notifications.'),
			'$update_interval' => $interval,
			'$shortcut_icon'   => $shortcut_icon,
			'$touch_icon'      => $touch_icon,
			'$block_public'    => intval($config->get('system', 'block_public')),
			'$stylesheets'     => $this->stylesheets,
		]) . $this->page['htmlhead'];
	}

	/**
	 * Returns the complete URL of the current page, e.g.: http(s)://something.com/network
	 *
	 * Taken from http://webcheatsheet.com/php/get_current_page_url.php
	 */
	private function curPageURL()
	{
		$pageURL = 'http';
		if (!empty($_SERVER["HTTPS"]) && ($_SERVER["HTTPS"] == "on")) {
			$pageURL .= "s";
		}

		$pageURL .= "://";

		if ($_SERVER["SERVER_PORT"] != "80" && $_SERVER["SERVER_PORT"] != "443") {
			$pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
		} else {
			$pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
		}
		return $pageURL;
	}
      
	/**
	 * Initializes Page->page['footer'].
	 *
	 * Includes:
	 * - Javascript homebase
	 * - Mobile toggle link
	 * - Registered footer scripts (through App->registerFooterScript())
	 * - footer.tpl template
	 *
	 * @param App  $app  The Friendica App instance
	 * @param Mode $mode The Friendica runtime mode
	 * @param L10n $l10n The l10n instance
	 *
	 * @throws HTTPException\InternalServerErrorException
	 */
	private function initFooter(App $app, Mode $mode, L10n $l10n)
	{
		// If you're just visiting, let javascript take you home
		if (!empty($_SESSION['visitor_home'])) {
			$homebase = $_SESSION['visitor_home'];
		} elseif (!empty($app->getLoggedInUserNickname())) {
			$homebase = 'profile/' . $app->getLoggedInUserNickname();
		}

		if (isset($homebase)) {
			$this->page['footer'] .= '<script>var homebase="' . $homebase . '";</script>' . "\n";
		}

		/*
		 * Add a "toggle mobile" link if we're using a mobile device
		 */
		if ($mode->isMobile() || $mode->isTablet()) {
			if (isset($_SESSION['show-mobile']) && !$_SESSION['show-mobile']) {
				$link = 'toggle_mobile?address=' . urlencode($this->curPageURL());
			} else {
				$link = 'toggle_mobile?off=1&address=' . urlencode($this->curPageURL());
			}
			$this->page['footer'] .= Renderer::replaceMacros(Renderer::getMarkupTemplate("toggle_mobile_footer.tpl"), [
				'$toggle_link' => $link,
				'$toggle_text' => $l10n->t('toggle mobile')
			]);
		}

		Hook::callAll('footer', $this->page['footer']);

		$tpl                  = Renderer::getMarkupTemplate('footer.tpl');
		$this->page['footer'] = Renderer::replaceMacros($tpl, [
			'$footerScripts' => array_unique($this->footerScripts),
		]) . $this->page['footer'];
	}

	/**
	 * Initializes Page->page['content'].
	 *
	 * Includes:
	 * - module content
	 * - hooks for content
	 *
	 * @param IRespondToRequests $response The Module response class
	 * @param Mode               $mode     The Friendica execution mode
	 *
	 * @throws HTTPException\InternalServerErrorException
	 */
	private function initContent(IRespondToRequests $response, Mode $mode)
	{
		// initialise content region
		if ($mode->isNormal()) {
			Hook::callAll('page_content_top', $this->page['content']);
		}

		$this->page['content'] .= $response->getContent();
	}

	/**
	 * Register a javascript file path to be included in the <footer> tag of every page.
	 * Inclusion is done in App->initFooter().
	 * The path can be absolute or relative to the Friendica installation base folder.
	 *
	 * @param string $path
	 *
	 * @see Page::initFooter()
	 *
	 */
	public function registerFooterScript($path)
	{
		$path = Network::appendQueryParam($path, ['v' => FRIENDICA_VERSION]);

		$url = str_replace($this->basePath . DIRECTORY_SEPARATOR, '', $path);

		$this->footerScripts[] = trim($url, '/');
	}

	/**
	 * Executes the creation of the current page and prints it to the screen
	 *
	 * @param App                         $app      The Friendica App
	 * @param BaseURL                     $baseURL  The Friendica Base URL
	 * @param Arguments                   $args     The Friendica App arguments
	 * @param Mode                        $mode     The current node mode
	 * @param IRespondToRequests          $response The Response of the module class, including type, content & headers
	 * @param L10n                        $l10n     The l10n language class
	 * @param IManageConfigValues         $config   The Configuration of this node
	 * @param IManagePersonalConfigValues $pconfig  The personal/user configuration
	 *
	 * @throws HTTPException\InternalServerErrorException|HTTPException\ServiceUnavailableException
	 */
	public function run(App $app, BaseURL $baseURL, Arguments $args, Mode $mode, IRespondToRequests $response, L10n $l10n, Profiler $profiler, IManageConfigValues $config, IManagePersonalConfigValues $pconfig)
	{
		$moduleName = $args->getModuleName();

		/* Create the page content.
		 * Calls all hooks which are including content operations
		 *
		 * Sets the $Page->page['content'] variable
		 */
		$timestamp = microtime(true);
		$this->initContent($response, $mode);
		$profiler->set(microtime(true) - $timestamp, 'content');

		// Load current theme info after module has been initialized as theme could have been set in module
		$currentTheme = $app->getCurrentTheme();
		$theme_info_file = 'view/theme/' . $currentTheme . '/theme.php';
		if (file_exists($theme_info_file)) {
			require_once $theme_info_file;
		}

		if (function_exists(str_replace('-', '_', $currentTheme) . '_init')) {
			$func = str_replace('-', '_', $currentTheme) . '_init';
			$func($app);
		}

		/* Create the page head after setting the language
		 * and getting any auth credentials.
		 *
		 * Moved initHead() and initFooter() to after
		 * all the module functions have executed so that all
		 * theme choices made by the modules can take effect.
		 */
		$this->initHead($app, $args, $l10n, $config, $pconfig);

		/* Build the page ending -- this is stuff that goes right before
		 * the closing </body> tag
		 */
		$this->initFooter($app, $mode, $l10n);

		if (!$mode->isAjax()) {
			Hook::callAll('page_end', $this->page['content']);
		}

		// Add the navigation (menu) template
		if ($moduleName != 'install' && $moduleName != 'maintenance') {
			$this->page['htmlhead'] .= Renderer::replaceMacros(Renderer::getMarkupTemplate('nav_head.tpl'), []);
			$this->page['nav']      = Nav::build($app);
		}

		foreach ($response->getHeaders() as $key => $values) {
			if (is_array($values)) {
				foreach ($values as $value) {
					header($key, $value);
				}
			} else {
				header($key, $values);
			}
		}

		// Build the page - now that we have all the components
		if (isset($_GET["mode"]) && (($_GET["mode"] == "raw") || ($_GET["mode"] == "minimal"))) {
			$doc = new DOMDocument();

			$target = new DOMDocument();
			$target->loadXML("<root></root>");

			$content = mb_convert_encoding($this->page["content"], 'HTML-ENTITIES', "UTF-8");

			/// @TODO one day, kill those error-surpressing @ stuff, or PHP should ban it
			@$doc->loadHTML($content);

			$xpath = new DOMXPath($doc);

			$list = $xpath->query("//*[contains(@id,'tread-wrapper-')]");  /* */

			foreach ($list as $item) {
				$item = $target->importNode($item, true);

				// And then append it to the target
				$target->documentElement->appendChild($item);
			}

			if ($_GET["mode"] == "raw") {
				header("Content-type: text/html; charset=utf-8");

				echo substr($target->saveHTML(), 6, -8);

				exit();
			}
		}

		$page    = $this->page;

		header("X-Friendica-Version: " . FRIENDICA_VERSION);
		header("Content-type: text/html; charset=utf-8");

		if ($config->get('system', 'hsts') && ($baseURL->getSSLPolicy() == BaseURL::SSL_POLICY_FULL)) {
			header("Strict-Transport-Security: max-age=31536000");
		}

		// Some security stuff
		header('X-Content-Type-Options: nosniff');
		header('X-XSS-Protection: 1; mode=block');
		header('X-Permitted-Cross-Domain-Policies: none');
		header('X-Frame-Options: sameorigin');

		// Things like embedded OSM maps don't work, when this is enabled
		// header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; connect-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' https: data:; media-src 'self' https:; child-src 'self' https:; object-src 'none'");

		/* We use $_GET["mode"] for special page templates. So we will check if we have
		 * to load another page template than the default one.
		 * The page templates are located in /view/php/ or in the theme directory.
		 */
		if (isset($_GET['mode'])) {
			$template = Theme::getPathForFile('php/' . Strings::sanitizeFilePathItem($_GET['mode']) . '.php');
		}

		// If there is no page template use the default page template
		if (empty($template)) {
			$template = Theme::getPathForFile('php/default.php');
		}

		// Theme templates expect $a as an App instance
		$a = $app;

		// Used as is in view/php/default.php
		$lang = $l10n->getCurrentLang();

		require_once $template;
	}
}
