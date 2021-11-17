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

use Dice\Dice;
use Friendica\App;
use Friendica\Capabilities\ICanHandleRequests;
use Friendica\Core;
use Friendica\Core\Config\Capability\IManageConfigValues;
use Friendica\LegacyModule;
use Friendica\Module\Home;
use Friendica\Module\HTTPException\MethodNotAllowed;
use Friendica\Module\HTTPException\PageNotFound;
use Friendica\Network\HTTPException\MethodNotAllowedException;
use Friendica\Network\HTTPException\NoContentException;
use Friendica\Network\HTTPException\NotFoundException;
use Friendica\Util\Profiler;
use Psr\Log\LoggerInterface;

/**
 * Holds the common context of the current, loaded module
 */
class ModuleController
{
	const DEFAULT       = 'home';
	const DEFAULT_CLASS = Home::class;
	/**
	 * A list of modules, which are backend methods
	 *
	 * @var array
	 */
	const BACKEND_MODULES = [
		'_well_known',
		'api',
		'dfrn_notify',
		'feed',
		'fetch',
		'followers',
		'following',
		'hcard',
		'hostxrd',
		'inbox',
		'manifest',
		'nodeinfo',
		'noscrape',
		'objects',
		'outbox',
		'poco',
		'post',
		'pubsub',
		'pubsubhubbub',
		'receive',
		'rsd_xml',
		'salmon',
		'statistics_json',
		'xrd',
	];

	/**
	 * @var string The module name
	 */
	private $moduleName;

	/**
	 * @var ICanHandleRequests The module class
	 */
	private $module;

	/**
	 * @var bool true, if the module is a backend module
	 */
	private $isBackend;

	/**
	 * @var bool true, if the loaded addon is private, so we have to print out not allowed
	 */
	private $printNotAllowedAddon;

	/**
	 * @return string
	 */
	public function getName()
	{
		return $this->moduleName;
	}

	/**
	 * @return ICanHandleRequests The base module object
	 */
	public function getModule(): ICanHandleRequests
	{
		return $this->module;
	}

	/**
	 * @return bool True, if the current module is a backend module
	 * @see ModuleController::BACKEND_MODULES for a list
	 */
	public function isBackend()
	{
		return $this->isBackend;
	}

	public function __construct(string $moduleName = self::DEFAULT, ICanHandleRequests $module = null, bool $isBackend = false, bool $printNotAllowedAddon = false)
	{
		$defaultClass = static::DEFAULT_CLASS;

		$this->moduleName           = $moduleName;
		$this->module               = $module ?? new $defaultClass();
		$this->isBackend            = $isBackend;
		$this->printNotAllowedAddon = $printNotAllowedAddon;
	}

	/**
	 * Determines the current module based on the App arguments and the server variable
	 *
	 * @param Arguments $args   The Friendica arguments
	 *
	 * @return ModuleController The module with the determined module
	 */
	public function determineName(Arguments $args)
	{
		if ($args->getArgc() > 0) {
			$module = str_replace('.', '_', $args->get(0));
			$module = str_replace('-', '_', $module);
		} else {
			$module = self::DEFAULT;
		}

		// Compatibility with the Firefox App
		if (($module == "users") && ($args->getCommand() == "users/sign_in")) {
			$module = "login";
		}

		$isBackend = in_array($module, ModuleController::BACKEND_MODULES);

		return new ModuleController($module, null, $isBackend, $this->printNotAllowedAddon);
	}

	/**
	 * Determine the class of the current module
	 *
	 * @param Arguments           $args   The Friendica execution arguments
	 * @param Router              $router The Friendica routing instance
	 * @param IManageConfigValues $config The Friendica Configuration
	 * @param Dice                $dice   The Dependency Injection container
	 *
	 * @return ModuleController The determined module of this call
	 *
	 * @throws \Exception
	 */
	public function determineClass(Arguments $args, Router $router, IManageConfigValues $config, Dice $dice)
	{
		$printNotAllowedAddon = false;

		$module_class      = null;
		$module_parameters = [];
		/**
		 * ROUTING
		 *
		 * From the request URL, routing consists of obtaining the name of a BaseModule-extending class of which the
		 * post() and/or content() static methods can be respectively called to produce a data change or an output.
		 **/
		try {
			$module_class        = $router->getModuleClass($args->getCommand());
			$module_parameters[] = $router->getModuleParameters();
		} catch (MethodNotAllowedException $e) {
			$module_class = MethodNotAllowed::class;
		} catch (NotFoundException $e) {
			// Then we try addon-provided modules that we wrap in the LegacyModule class
			if (Core\Addon::isEnabled($this->moduleName) && file_exists("addon/{$this->moduleName}/{$this->moduleName}.php")) {
				//Check if module is an app and if public access to apps is allowed or not
				$privateapps = $config->get('config', 'private_addons', false);
				if ((!local_user()) && Core\Hook::isAddonApp($this->moduleName) && $privateapps) {
					$printNotAllowedAddon = true;
				} else {
					include_once "addon/{$this->moduleName}/{$this->moduleName}.php";
					if (function_exists($this->moduleName . '_module')) {
						$module_parameters[] = "addon/{$this->moduleName}/{$this->moduleName}.php";
						$module_class        = LegacyModule::class;
					}
				}
			}

			/* Finally, we look for a 'standard' program module in the 'mod' directory
			 * We emulate a Module class through the LegacyModule class
			 */
			if (!$module_class && file_exists("mod/{$this->moduleName}.php")) {
				$module_parameters[] = "mod/{$this->moduleName}.php";
				$module_class        = LegacyModule::class;
			}

			$module_class = $module_class ?: PageNotFound::class;
		}

		/** @var ICanHandleRequests $module */
		$module = $dice->create($module_class, $module_parameters);

		return new ModuleController($this->moduleName, $module, $this->isBackend, $printNotAllowedAddon);
	}

	/**
	 * Run the determined module class and calls all hooks applied to
	 *
	 * @param \Friendica\Core\L10n $l10n    The L10n instance
	 * @param App\BaseURL          $baseUrl The Friendica Base URL
	 * @param LoggerInterface      $logger  The Friendica logger
	 * @param array                $server  The $_SERVER variable
	 * @param array                $post    The $_POST variables
	 *
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public function run(Core\L10n $l10n, App\BaseURL $baseUrl, LoggerInterface $logger, Profiler $profiler, array $server, array $post)
	{
		if ($this->printNotAllowedAddon) {
			notice($l10n->t("You must be logged in to use addons. "));
		}

		/* The URL provided does not resolve to a valid module.
		 *
		 * On Dreamhost sites, quite often things go wrong for no apparent reason and they send us to '/internal_error.html'.
		 * We don't like doing this, but as it occasionally accounts for 10-20% or more of all site traffic -
		 * we are going to trap this and redirect back to the requested page. As long as you don't have a critical error on your page
		 * this will often succeed and eventually do the right thing.
		 *
		 * Otherwise we are going to emit a 404 not found.
		 */
		if ($this->module === PageNotFound::class) {
			$queryString = $server['QUERY_STRING'];
			// Stupid browser tried to pre-fetch our Javascript img template. Don't log the event or return anything - just quietly exit.
			if (!empty($queryString) && preg_match('/{[0-9]}/', $queryString) !== 0) {
				exit();
			}

			if (!empty($queryString) && ($queryString === 'q=internal_error.html') && isset($dreamhost_error_hack)) {
				$logger->info('index.php: dreamhost_error_hack invoked.', ['Original URI' => $server['REQUEST_URI']]);
				$baseUrl->redirect($server['REQUEST_URI']);
			}

			$logger->debug('index.php: page not found.', ['request_uri' => $server['REQUEST_URI'], 'address' => $server['REMOTE_ADDR'], 'query' => $server['QUERY_STRING']]);
		}

		// @see https://github.com/tootsuite/mastodon/blob/c3aef491d66aec743a3a53e934a494f653745b61/config/initializers/cors.rb
		if (substr($_REQUEST['pagename'] ?? '', 0, 12) == '.well-known/') {
			header('Access-Control-Allow-Origin: *');
			header('Access-Control-Allow-Headers: *');
			header('Access-Control-Allow-Methods: ' . Router::GET);
			header('Access-Control-Allow-Credentials: false');
		} elseif (substr($_REQUEST['pagename'] ?? '', 0, 8) == 'profile/') {
			header('Access-Control-Allow-Origin: *');
			header('Access-Control-Allow-Headers: *');
			header('Access-Control-Allow-Methods: ' . Router::GET);
			header('Access-Control-Allow-Credentials: false');
		} elseif (substr($_REQUEST['pagename'] ?? '', 0, 4) == 'api/') {
			header('Access-Control-Allow-Origin: *');
			header('Access-Control-Allow-Headers: *');
			header('Access-Control-Allow-Methods: ' . implode(',', Router::ALLOWED_METHODS));
			header('Access-Control-Allow-Credentials: false');
			header('Access-Control-Expose-Headers: Link');
		} elseif (substr($_REQUEST['pagename'] ?? '', 0, 11) == 'oauth/token') {
			header('Access-Control-Allow-Origin: *');
			header('Access-Control-Allow-Headers: *');
			header('Access-Control-Allow-Methods: ' . Router::POST);
			header('Access-Control-Allow-Credentials: false');
		}

		// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/OPTIONS
		// @todo Check allowed methods per requested path
		if ($server['REQUEST_METHOD'] === Router::OPTIONS) {
			header('Allow: ' . implode(',', Router::ALLOWED_METHODS));
			throw new NoContentException();
		}

		$placeholder = '';

		$profiler->set(microtime(true), 'ready');
		$timestamp = microtime(true);

		Core\Hook::callAll($this->moduleName . '_mod_init', $placeholder);

		$this->module->init();

		$profiler->set(microtime(true) - $timestamp, 'init');

		if ($server['REQUEST_METHOD'] === Router::DELETE) {
			$this->module->delete();
		}

		if ($server['REQUEST_METHOD'] === Router::PATCH) {
			$this->module->patch();
		}

		if ($server['REQUEST_METHOD'] === Router::POST) {
			Core\Hook::callAll($this->moduleName . '_mod_post', $post);
			$this->module->post();
		}

		if ($server['REQUEST_METHOD'] === Router::PUT) {
			$this->module->put();
		}

		// "rawContent" is especially meant for technical endpoints.
		// This endpoint doesn't need any theme initialization or other comparable stuff.
		$this->module->rawContent();
	}
}
