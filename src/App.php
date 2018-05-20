<?php
/**
 * @file src/App.php
 */
namespace Friendica;

use Friendica\Core\Cache;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\System;

use Detection\MobileDetect;

use Exception;

require_once 'boot.php';
require_once 'include/text.php';

/**
 *
 * class: App
 *
 * @brief Our main application structure for the life of this page.
 *
 * Primarily deals with the URL that got us here
 * and tries to make some sense of it, and
 * stores our page contents and config storage
 * and anything else that might need to be passed around
 * before we spit the page out.
 *
 */
class App
{
	const MODE_NORMAL = 0;
	const MODE_INSTALL = 1;
	const MODE_MAINTENANCE = 2;

	public $module_loaded = false;
	public $module_class = null;
	public $query_string;
	public $config;
	public $page;
	public $page_offset;
	public $profile;
	public $profile_uid;
	public $user;
	public $cid;
	public $contact;
	public $contacts;
	public $page_contact;
	public $content;
	public $data = [];
	public $error = false;
	public $cmd;
	public $argv;
	public $argc;
	public $module;
	public $mode = App::MODE_NORMAL;
	public $pager;
	public $strings;
	public $basepath;
	public $path;
	public $hooks;
	public $timezone;
	public $interactive = true;
	public $addons;
	public $addons_admin = [];
	public $apps = [];
	public $identities;
	public $is_mobile = false;
	public $is_tablet = false;
	public $is_friendica_app;
	public $performance = [];
	public $callstack = [];
	public $theme_info = [];
	public $backend = true;
	public $nav_sel;
	public $category;
	// Allow themes to control internal parameters
	// by changing App values in theme.php

	public $sourcename = '';
	public $videowidth = 425;
	public $videoheight = 350;
	public $force_max_items = 0;
	public $theme_events_in_profile = true;

	/**
	 * @brief An array for all theme-controllable parameters
	 *
	 * Mostly unimplemented yet. Only options 'template_engine' and
	 * beyond are used.
	 */
	public $theme = [
		'sourcename' => '',
		'videowidth' => 425,
		'videoheight' => 350,
		'force_max_items' => 0,
		'stylesheet' => '',
		'template_engine' => 'smarty3',
	];

	/**
	 * @brief An array of registered template engines ('name'=>'class name')
	 */
	public $template_engines = [];

	/**
	 * @brief An array of instanced template engines ('name'=>'instance')
	 */
	public $template_engine_instance = [];
	public $process_id;
	public $queue;
	private $ldelim = [
		'internal' => '',
		'smarty3' => '{{'
	];
	private $rdelim = [
		'internal' => '',
		'smarty3' => '}}'
	];
	private $scheme;
	private $hostname;
	private $curl_code;
	private $curl_content_type;
	private $curl_headers;
	private static $a;

	/**
	 * @brief App constructor.
	 *
	 * @param string $basepath Path to the app base folder
	 */
	public function __construct($basepath)
	{
		global $default_timezone;

		if (!static::directory_usable($basepath, false)) {
			throw new Exception('Basepath ' . $basepath . ' isn\'t usable.');
		}

		$this->basepath = rtrim($basepath, DIRECTORY_SEPARATOR);

		if (file_exists($this->basepath . DIRECTORY_SEPARATOR . '.htpreconfig.php')) {
			include $this->basepath . DIRECTORY_SEPARATOR . '.htpreconfig.php';
		}

		$this->timezone = ((x($default_timezone)) ? $default_timezone : 'UTC');

		date_default_timezone_set($this->timezone);

		$this->performance['start'] = microtime(true);
		$this->performance['database'] = 0;
		$this->performance['database_write'] = 0;
		$this->performance['cache'] = 0;
		$this->performance['cache_write'] = 0;
		$this->performance['network'] = 0;
		$this->performance['file'] = 0;
		$this->performance['rendering'] = 0;
		$this->performance['parser'] = 0;
		$this->performance['marktime'] = 0;
		$this->performance['markstart'] = microtime(true);

		$this->callstack['database'] = [];
		$this->callstack['database_write'] = [];
		$this->callstack['cache'] = [];
		$this->callstack['cache_write'] = [];
		$this->callstack['network'] = [];
		$this->callstack['file'] = [];
		$this->callstack['rendering'] = [];
		$this->callstack['parser'] = [];

		$this->config = [];
		$this->page = [];
		$this->pager = [];

		$this->query_string = '';

		$this->process_id = uniqid('log', true);

		startup();

		$this->scheme = 'http';

		if ((x($_SERVER, 'HTTPS') && $_SERVER['HTTPS']) ||
			(x($_SERVER, 'HTTP_FORWARDED') && preg_match('/proto=https/', $_SERVER['HTTP_FORWARDED'])) ||
			(x($_SERVER, 'HTTP_X_FORWARDED_PROTO') && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') ||
			(x($_SERVER, 'HTTP_X_FORWARDED_SSL') && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') ||
			(x($_SERVER, 'FRONT_END_HTTPS') && $_SERVER['FRONT_END_HTTPS'] == 'on') ||
			(x($_SERVER, 'SERVER_PORT') && (intval($_SERVER['SERVER_PORT']) == 443)) // XXX: reasonable assumption, but isn't this hardcoding too much?
		) {
			$this->scheme = 'https';
		}

		if (x($_SERVER, 'SERVER_NAME')) {
			$this->hostname = $_SERVER['SERVER_NAME'];

			if (x($_SERVER, 'SERVER_PORT') && $_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443) {
				$this->hostname .= ':' . $_SERVER['SERVER_PORT'];
			}
			/*
			 * Figure out if we are running at the top of a domain
			 * or in a sub-directory and adjust accordingly
			 */

			/// @TODO This kind of escaping breaks syntax-highlightning on CoolEdit (Midnight Commander)
			$path = trim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
			if (isset($path) && strlen($path) && ($path != $this->path)) {
				$this->path = $path;
			}
		}

		set_include_path(
			get_include_path() . PATH_SEPARATOR
			. $this->basepath . DIRECTORY_SEPARATOR . 'include' . PATH_SEPARATOR
			. $this->basepath . DIRECTORY_SEPARATOR . 'library' . PATH_SEPARATOR
			. $this->basepath);

		if ((x($_SERVER, 'QUERY_STRING')) && substr($_SERVER['QUERY_STRING'], 0, 9) === 'pagename=') {
			$this->query_string = substr($_SERVER['QUERY_STRING'], 9);

			// removing trailing / - maybe a nginx problem
			$this->query_string = ltrim($this->query_string, '/');
		} elseif ((x($_SERVER, 'QUERY_STRING')) && substr($_SERVER['QUERY_STRING'], 0, 2) === 'q=') {
			$this->query_string = substr($_SERVER['QUERY_STRING'], 2);

			// removing trailing / - maybe a nginx problem
			$this->query_string = ltrim($this->query_string, '/');
		}

		if (x($_GET, 'pagename')) {
			$this->cmd = trim($_GET['pagename'], '/\\');
		} elseif (x($_GET, 'q')) {
			$this->cmd = trim($_GET['q'], '/\\');
		}

		// fix query_string
		$this->query_string = str_replace($this->cmd . '&', $this->cmd . '?', $this->query_string);

		// unix style "homedir"
		if (substr($this->cmd, 0, 1) === '~') {
			$this->cmd = 'profile/' . substr($this->cmd, 1);
		}

		// Diaspora style profile url
		if (substr($this->cmd, 0, 2) === 'u/') {
			$this->cmd = 'profile/' . substr($this->cmd, 2);
		}

		/*
		 * Break the URL path into C style argc/argv style arguments for our
		 * modules. Given "http://example.com/module/arg1/arg2", $this->argc
		 * will be 3 (integer) and $this->argv will contain:
		 *   [0] => 'module'
		 *   [1] => 'arg1'
		 *   [2] => 'arg2'
		 *
		 *
		 * There will always be one argument. If provided a naked domain
		 * URL, $this->argv[0] is set to "home".
		 */

		$this->argv = explode('/', $this->cmd);
		$this->argc = count($this->argv);
		if ((array_key_exists('0', $this->argv)) && strlen($this->argv[0])) {
			$this->module = str_replace('.', '_', $this->argv[0]);
			$this->module = str_replace('-', '_', $this->module);
		} else {
			$this->argc = 1;
			$this->argv = ['home'];
			$this->module = 'home';
		}

		// See if there is any page number information, and initialise pagination
		$this->pager['page'] = ((x($_GET, 'page') && intval($_GET['page']) > 0) ? intval($_GET['page']) : 1);
		$this->pager['itemspage'] = 50;
		$this->pager['start'] = ($this->pager['page'] * $this->pager['itemspage']) - $this->pager['itemspage'];

		if ($this->pager['start'] < 0) {
			$this->pager['start'] = 0;
		}
		$this->pager['total'] = 0;

		// Detect mobile devices
		$mobile_detect = new MobileDetect();
		$this->is_mobile = $mobile_detect->isMobile();
		$this->is_tablet = $mobile_detect->isTablet();

		// Friendica-Client
		$this->is_friendica_app = ($_SERVER['HTTP_USER_AGENT'] == 'Apache-HttpClient/UNAVAILABLE (java 1.4)');

		// Register template engines
		$this->register_template_engine('Friendica\Render\FriendicaSmartyEngine');

		/**
		 * Load the configuration file which contains our DB credentials.
		 * Ignore errors. If the file doesn't exist or is empty, we are running in
		 * installation mode.	 *
		 */
		$this->mode = ((file_exists('.htconfig.php') && filesize('.htconfig.php')) ? App::MODE_NORMAL : App::MODE_INSTALL);


		self::$a = $this;
	}

	/**
	 * @brief Returns the base filesystem path of the App
	 *
	 * It first checks for the internal variable, then for DOCUMENT_ROOT and
	 * finally for PWD
	 *
	 * @return string
	 */
	public function get_basepath()
	{
		$basepath = $this->basepath;

		if (!$basepath) {
			$basepath = Config::get('system', 'basepath');
		}

		if (!$basepath && x($_SERVER, 'DOCUMENT_ROOT')) {
			$basepath = $_SERVER['DOCUMENT_ROOT'];
		}

		if (!$basepath && x($_SERVER, 'PWD')) {
			$basepath = $_SERVER['PWD'];
		}

		return self::realpath($basepath);
	}

	/**
	 * @brief Returns a normalized file path
	 *
	 * This is a wrapper for the "realpath" function.
	 * That function cannot detect the real path when some folders aren't readable.
	 * Since this could happen with some hosters we need to handle this.
	 *
	 * @param string $path The path that is about to be normalized
	 * @return string normalized path - when possible
	 */
	public static function realpath($path)
	{
		$normalized = realpath($path);

		if (!is_bool($normalized)) {
			return $normalized;
		} else {
			return $path;
		}
	}

	public function get_scheme()
	{
		return $this->scheme;
	}

	/**
	 * @brief Retrieves the Friendica instance base URL
	 *
	 * This function assembles the base URL from multiple parts:
	 * - Protocol is determined either by the request or a combination of
	 * system.ssl_policy and the $ssl parameter.
	 * - Host name is determined either by system.hostname or inferred from request
	 * - Path is inferred from SCRIPT_NAME
	 *
	 * Note: $ssl parameter value doesn't directly correlate with the resulting protocol
	 *
	 * @param bool $ssl Whether to append http or https under SSL_POLICY_SELFSIGN
	 * @return string Friendica server base URL
	 */
	public function get_baseurl($ssl = false)
	{
		$scheme = $this->scheme;

		if (Config::get('system', 'ssl_policy') == SSL_POLICY_FULL) {
			$scheme = 'https';
		}

		//	Basically, we have $ssl = true on any links which can only be seen by a logged in user
		//	(and also the login link). Anything seen by an outsider will have it turned off.

		if (Config::get('system', 'ssl_policy') == SSL_POLICY_SELFSIGN) {
			if ($ssl) {
				$scheme = 'https';
			} else {
				$scheme = 'http';
			}
		}

		if (Config::get('config', 'hostname') != '') {
			$this->hostname = Config::get('config', 'hostname');
		}

		return $scheme . '://' . $this->hostname . ((isset($this->path) && strlen($this->path)) ? '/' . $this->path : '' );
	}

	/**
	 * @brief Initializes the baseurl components
	 *
	 * Clears the baseurl cache to prevent inconsistencies
	 *
	 * @param string $url
	 */
	public function set_baseurl($url)
	{
		$parsed = @parse_url($url);

		if (x($parsed)) {
			$this->scheme = $parsed['scheme'];

			$hostname = $parsed['host'];
			if (x($parsed, 'port')) {
				$hostname .= ':' . $parsed['port'];
			}
			if (x($parsed, 'path')) {
				$this->path = trim($parsed['path'], '\\/');
			}

			if (file_exists($this->basepath . DIRECTORY_SEPARATOR . '.htpreconfig.php')) {
				include $this->basepath . DIRECTORY_SEPARATOR . '.htpreconfig.php';
			}

			if (Config::get('config', 'hostname') != '') {
				$this->hostname = Config::get('config', 'hostname');
			}

			if (!isset($this->hostname) || ( $this->hostname == '')) {
				$this->hostname = $hostname;
			}
		}
	}

	public function get_hostname()
	{
		if (Config::get('config', 'hostname') != '') {
			$this->hostname = Config::get('config', 'hostname');
		}

		return $this->hostname;
	}

	public function get_path()
	{
		return $this->path;
	}

	public function set_pager_total($n)
	{
		$this->pager['total'] = intval($n);
	}

	public function set_pager_itemspage($n)
	{
		$this->pager['itemspage'] = ((intval($n) > 0) ? intval($n) : 0);
		$this->pager['start'] = ($this->pager['page'] * $this->pager['itemspage']) - $this->pager['itemspage'];
	}

	public function set_pager_page($n)
	{
		$this->pager['page'] = $n;
		$this->pager['start'] = ($this->pager['page'] * $this->pager['itemspage']) - $this->pager['itemspage'];
	}

	public function init_pagehead()
	{
		$interval = ((local_user()) ? PConfig::get(local_user(), 'system', 'update_interval') : 40000);

		// If the update is 'deactivated' set it to the highest integer number (~24 days)
		if ($interval < 0) {
			$interval = 2147483647;
		}

		if ($interval < 10000) {
			$interval = 40000;
		}

		// compose the page title from the sitename and the
		// current module called
		if (!$this->module == '') {
			$this->page['title'] = $this->config['sitename'] . ' (' . $this->module . ')';
		} else {
			$this->page['title'] = $this->config['sitename'];
		}

		/* put the head template at the beginning of page['htmlhead']
		 * since the code added by the modules frequently depends on it
		 * being first
		 */
		if (!isset($this->page['htmlhead'])) {
			$this->page['htmlhead'] = '';
		}

		// If we're using Smarty, then doing replace_macros() will replace
		// any unrecognized variables with a blank string. Since we delay
		// replacing $stylesheet until later, we need to replace it now
		// with another variable name
		if ($this->theme['template_engine'] === 'smarty3') {
			$stylesheet = $this->get_template_ldelim('smarty3') . '$stylesheet' . $this->get_template_rdelim('smarty3');
		} else {
			$stylesheet = '$stylesheet';
		}

		$shortcut_icon = Config::get('system', 'shortcut_icon');
		if ($shortcut_icon == '') {
			$shortcut_icon = 'images/friendica-32.png';
		}

		$touch_icon = Config::get('system', 'touch_icon');
		if ($touch_icon == '') {
			$touch_icon = 'images/friendica-128.png';
		}

		// get data wich is needed for infinite scroll on the network page
		$invinite_scroll = infinite_scroll_data($this->module);

		$tpl = get_markup_template('head.tpl');
		$this->page['htmlhead'] = replace_macros($tpl, [
			'$baseurl'         => $this->get_baseurl(),
			'$local_user'      => local_user(),
			'$generator'       => 'Friendica' . ' ' . FRIENDICA_VERSION,
			'$delitem'         => L10n::t('Delete this item?'),
			'$showmore'        => L10n::t('show more'),
			'$showfewer'       => L10n::t('show fewer'),
			'$update_interval' => $interval,
			'$shortcut_icon'   => $shortcut_icon,
			'$touch_icon'      => $touch_icon,
			'$stylesheet'      => $stylesheet,
			'$infinite_scroll' => $invinite_scroll,
		]) . $this->page['htmlhead'];
	}

	public function init_page_end()
	{
		if (!isset($this->page['end'])) {
			$this->page['end'] = '';
		}
		$tpl = get_markup_template('end.tpl');
		$this->page['end'] = replace_macros($tpl, [
			'$baseurl' => $this->get_baseurl()
		]) . $this->page['end'];
	}

	public function set_curl_code($code)
	{
		$this->curl_code = $code;
	}

	public function get_curl_code()
	{
		return $this->curl_code;
	}

	public function set_curl_content_type($content_type)
	{
		$this->curl_content_type = $content_type;
	}

	public function get_curl_content_type()
	{
		return $this->curl_content_type;
	}

	public function set_curl_headers($headers)
	{
		$this->curl_headers = $headers;
	}

	public function get_curl_headers()
	{
		return $this->curl_headers;
	}

	/**
	 * @brief Removes the base url from an url. This avoids some mixed content problems.
	 *
	 * @param string $orig_url
	 *
	 * @return string The cleaned url
	 */
	public function remove_baseurl($orig_url)
	{
		// Remove the hostname from the url if it is an internal link
		$nurl = normalise_link($orig_url);
		$base = normalise_link($this->get_baseurl());
		$url = str_replace($base . '/', '', $nurl);

		// if it is an external link return the orignal value
		if ($url == normalise_link($orig_url)) {
			return $orig_url;
		} else {
			return $url;
		}
	}

	/**
	 * @brief Register template engine class
	 *
	 * @param string $class
	 */
	private function register_template_engine($class)
	{
		$v = get_class_vars($class);
		if (x($v, 'name')) {
			$name = $v['name'];
			$this->template_engines[$name] = $class;
		} else {
			echo "template engine <tt>$class</tt> cannot be registered without a name.\n";
			die();
		}
	}

	/**
	 * @brief Return template engine instance.
	 *
	 * If $name is not defined, return engine defined by theme,
	 * or default
	 *
	 * @return object Template Engine instance
	 */
	public function template_engine()
	{
		$template_engine = 'smarty3';
		if (x($this->theme, 'template_engine')) {
			$template_engine = $this->theme['template_engine'];
		}

		if (isset($this->template_engines[$template_engine])) {
			if (isset($this->template_engine_instance[$template_engine])) {
				return $this->template_engine_instance[$template_engine];
			} else {
				$class = $this->template_engines[$template_engine];
				$obj = new $class;
				$this->template_engine_instance[$template_engine] = $obj;
				return $obj;
			}
		}

		echo "template engine <tt>$template_engine</tt> is not registered!\n";
		killme();
	}

	/**
	 * @brief Returns the active template engine.
	 *
	 * @return string
	 */
	public function get_template_engine()
	{
		return $this->theme['template_engine'];
	}

	public function set_template_engine($engine = 'smarty3')
	{
		$this->theme['template_engine'] = $engine;
	}

	public function get_template_ldelim($engine = 'smarty3')
	{
		return $this->ldelim[$engine];
	}

	public function get_template_rdelim($engine = 'smarty3')
	{
		return $this->rdelim[$engine];
	}

	public function save_timestamp($stamp, $value)
	{
		if (!isset($this->config['system']['profiler']) || !$this->config['system']['profiler']) {
			return;
		}

		$duration = (float) (microtime(true) - $stamp);

		if (!isset($this->performance[$value])) {
			// Prevent ugly E_NOTICE
			$this->performance[$value] = 0;
		}

		$this->performance[$value] += (float) $duration;
		$this->performance['marktime'] += (float) $duration;

		$callstack = System::callstack();

		if (!isset($this->callstack[$value][$callstack])) {
			// Prevent ugly E_NOTICE
			$this->callstack[$value][$callstack] = 0;
		}

		$this->callstack[$value][$callstack] += (float) $duration;
	}

	public function get_useragent()
	{
		return
			FRIENDICA_PLATFORM . " '" .
			FRIENDICA_CODENAME . "' " .
			FRIENDICA_VERSION . '-' .
			DB_UPDATE_VERSION . '; ' .
			$this->get_baseurl();
	}

	public function is_friendica_app()
	{
		return $this->is_friendica_app;
	}

	/**
	 * @brief Checks if the site is called via a backend process
	 *
	 * This isn't a perfect solution. But we need this check very early.
	 * So we cannot wait until the modules are loaded.
	 *
	 * @return bool Is it a known backend?
	 */
	public function is_backend()
	{
		static $backends = [
			'_well_known',
			'api',
			'dfrn_notify',
			'fetch',
			'hcard',
			'hostxrd',
			'nodeinfo',
			'noscrape',
			'p',
			'poco',
			'post',
			'proxy',
			'pubsub',
			'pubsubhubbub',
			'receive',
			'rsd_xml',
			'salmon',
			'statistics_json',
			'xrd',
		];

		// Check if current module is in backend or backend flag is set
		return (in_array($this->module, $backends) || $this->backend);
	}

	/**
	 * @brief Checks if the maximum number of database processes is reached
	 *
	 * @return bool Is the limit reached?
	 */
	public function max_processes_reached()
	{
		// Deactivated, needs more investigating if this check really makes sense
		return false;

		/*
		 * Commented out to suppress static analyzer issues
		 *
		if ($this->is_backend()) {
			$process = 'backend';
			$max_processes = Config::get('system', 'max_processes_backend');
			if (intval($max_processes) == 0) {
				$max_processes = 5;
			}
		} else {
			$process = 'frontend';
			$max_processes = Config::get('system', 'max_processes_frontend');
			if (intval($max_processes) == 0) {
				$max_processes = 20;
			}
		}

		$processlist = DBM::processlist();
		if ($processlist['list'] != '') {
			logger('Processcheck: Processes: ' . $processlist['amount'] . ' - Processlist: ' . $processlist['list'], LOGGER_DEBUG);

			if ($processlist['amount'] > $max_processes) {
				logger('Processcheck: Maximum number of processes for ' . $process . ' tasks (' . $max_processes . ') reached.', LOGGER_DEBUG);
				return true;
			}
		}
		return false;
		 */
	}

	/**
	 * @brief Checks if the minimal memory is reached
	 *
	 * @return bool Is the memory limit reached?
	 */
	public function min_memory_reached()
	{
		$min_memory = Config::get('system', 'min_memory', 0);
		if ($min_memory == 0) {
			return false;
		}

		if (!is_readable('/proc/meminfo')) {
			return false;
		}

		$memdata = explode("\n", file_get_contents('/proc/meminfo'));

		$meminfo = [];
		foreach ($memdata as $line) {
			list($key, $val) = explode(':', $line);
			$meminfo[$key] = (int) trim(str_replace('kB', '', $val));
			$meminfo[$key] = (int) ($meminfo[$key] / 1024);
		}

		if (!isset($meminfo['MemAvailable']) || !isset($meminfo['MemFree'])) {
			return false;
		}

		$free = $meminfo['MemAvailable'] + $meminfo['MemFree'];

		$reached = ($free < $min_memory);

		if ($reached) {
			logger('Minimal memory reached: ' . $free . '/' . $meminfo['MemTotal'] . ' - limit ' . $min_memory, LOGGER_DEBUG);
		}

		return $reached;
	}

	/**
	 * @brief Checks if the maximum load is reached
	 *
	 * @return bool Is the load reached?
	 */
	public function maxload_reached()
	{
		if ($this->is_backend()) {
			$process = 'backend';
			$maxsysload = intval(Config::get('system', 'maxloadavg'));
			if ($maxsysload < 1) {
				$maxsysload = 50;
			}
		} else {
			$process = 'frontend';
			$maxsysload = intval(Config::get('system', 'maxloadavg_frontend'));
			if ($maxsysload < 1) {
				$maxsysload = 50;
			}
		}

		$load = current_load();
		if ($load) {
			if (intval($load) > $maxsysload) {
				logger('system: load ' . $load . ' for ' . $process . ' tasks (' . $maxsysload . ') too high.');
				return true;
			}
		}
		return false;
	}

	public function proc_run($args)
	{
		if (!function_exists('proc_open')) {
			return;
		}

		array_unshift($args, ((x($this->config, 'php_path')) && (strlen($this->config['php_path'])) ? $this->config['php_path'] : 'php'));

		for ($x = 0; $x < count($args); $x ++) {
			$args[$x] = escapeshellarg($args[$x]);
		}

		$cmdline = implode(' ', $args);

		if ($this->min_memory_reached()) {
			return;
		}

		if (Config::get('system', 'proc_windows')) {
			$resource = proc_open('cmd /c start /b ' . $cmdline, [], $foo, $this->get_basepath());
		} else {
			$resource = proc_open($cmdline . ' &', [], $foo, $this->get_basepath());
		}
		if (!is_resource($resource)) {
			logger('We got no resource for command ' . $cmdline, LOGGER_DEBUG);
			return;
		}
		proc_close($resource);
	}

	/**
	 * @brief Returns the system user that is executing the script
	 *
	 * This mostly returns something like "www-data".
	 *
	 * @return string system username
	 */
	private static function systemuser()
	{
		if (!function_exists('posix_getpwuid') || !function_exists('posix_geteuid')) {
			return '';
		}

		$processUser = posix_getpwuid(posix_geteuid());
		return $processUser['name'];
	}

	/**
	 * @brief Checks if a given directory is usable for the system
	 *
	 * @return boolean the directory is usable
	 */
	public static function directory_usable($directory, $check_writable = true)
	{
		if ($directory == '') {
			logger('Directory is empty. This shouldn\'t happen.', LOGGER_DEBUG);
			return false;
		}

		if (!file_exists($directory)) {
			logger('Path "' . $directory . '" does not exist for user ' . self::systemuser(), LOGGER_DEBUG);
			return false;
		}

		if (is_file($directory)) {
			logger('Path "' . $directory . '" is a file for user ' . self::systemuser(), LOGGER_DEBUG);
			return false;
		}

		if (!is_dir($directory)) {
			logger('Path "' . $directory . '" is not a directory for user ' . self::systemuser(), LOGGER_DEBUG);
			return false;
		}

		if ($check_writable && !is_writable($directory)) {
			logger('Path "' . $directory . '" is not writable for user ' . self::systemuser(), LOGGER_DEBUG);
			return false;
		}

		return true;
	}

	/**
	 * @param string $cat     Config category
	 * @param string $k       Config key
	 * @param mixed  $default Default value if it isn't set
	 */
	public function getConfigValue($cat, $k, $default = null)
	{
		$return = $default;

		if ($cat === 'config') {
			if (isset($this->config[$k])) {
				$return = $this->config[$k];
			}
		} else {
			if (isset($this->config[$cat][$k])) {
				$return = $this->config[$cat][$k];
			}
		}

		return $return;
	}

	/**
	 * Sets a value in the config cache. Accepts raw output from the config table
	 *
	 * @param string $cat Config category
	 * @param string $k   Config key
	 * @param mixed  $v   Value to set
	 */
	public function setConfigValue($cat, $k, $v)
	{
		// Only arrays are serialized in database, so we have to unserialize sparingly
		$value = is_string($v) && preg_match("|^a:[0-9]+:{.*}$|s", $v) ? unserialize($v) : $v;

		if ($cat === 'config') {
			$this->config[$k] = $value;
		} else {
			$this->config[$cat][$k] = $value;
		}
	}

	/**
	 * Deletes a value from the config cache
	 *
	 * @param string $cat Config category
	 * @param string $k   Config key
	 */
	public function deleteConfigValue($cat, $k)
	{
		if ($cat === 'config') {
			if (isset($this->config[$k])) {
				unset($this->config[$k]);
			}
		} else {
			if (isset($this->config[$cat][$k])) {
				unset($this->config[$cat][$k]);
			}
		}
	}


	/**
	 * Retrieves a value from the user config cache
	 *
	 * @param int    $uid     User Id
	 * @param string $cat     Config category
	 * @param string $k       Config key
	 * @param mixed  $default Default value if key isn't set
	 */
	public function getPConfigValue($uid, $cat, $k, $default = null)
	{
		$return = $default;

		if (isset($this->config[$uid][$cat][$k])) {
			$return = $this->config[$uid][$cat][$k];
		}

		return $return;
	}

	/**
	 * Sets a value in the user config cache
	 *
	 * Accepts raw output from the pconfig table
	 *
	 * @param int    $uid User Id
	 * @param string $cat Config category
	 * @param string $k   Config key
	 * @param mixed  $v   Value to set
	 */
	public function setPConfigValue($uid, $cat, $k, $v)
	{
		// Only arrays are serialized in database, so we have to unserialize sparingly
		$value = is_string($v) && preg_match("|^a:[0-9]+:{.*}$|s", $v) ? unserialize($v) : $v;

		$this->config[$uid][$cat][$k] = $value;
	}

	/**
	 * Deletes a value from the user config cache
	 *
	 * @param int    $uid User Id
	 * @param string $cat Config category
	 * @param string $k   Config key
	 */
	public function deletePConfigValue($uid, $cat, $k)
	{
		if (isset($this->config[$uid][$cat][$k])) {
			unset($this->config[$uid][$cat][$k]);
		}
	}

	/**
	 * Generates the site's default sender email address
	 *
	 * @return string
	 */
	public function getSenderEmailAddress()
	{
		$sender_email = Config::get('config', 'sender_email');
		if (empty($sender_email)) {
			$hostname = $this->get_hostname();
			if (strpos($hostname, ':')) {
				$hostname = substr($hostname, 0, strpos($hostname, ':'));
			}

			$sender_email = 'noreply@' . $hostname;
		}

		return $sender_email;
	}

	/**
	 * @note Checks, if the App is in the Maintenance-Mode
	 *
	 * @return boolean
	 */
	public function checkMaintenanceMode()
	{
		if (Config::get('system', 'maintenance')) {
			$this->mode = App::MODE_MAINTENANCE;
			return true;
		}

		return false;
	}

	/**
	 * Returns the current theme name.
	 *
	 * @return string
	 */
	public function getCurrentTheme()
	{
		if ($this->mode == App::MODE_INSTALL) {
			return '';
		}

		if (!$this->current_theme) {
			$this->computeCurrentTheme();
		}

		return $this->current_theme;
	}

	/**
	 * Computes the current theme name based on the node settings, the user settings and the device type
	 *
	 * @throws Exception
	 */
	private function computeCurrentTheme()
	{
		$system_theme = Config::get('system', 'theme');
		if (!$system_theme) {
			throw new Exception(L10n::t('No system theme config value set.'));
		}

		// Sane default
		$this->current_theme = $system_theme;

		$allowed_themes = explode(',', Config::get('system', 'allowed_themes', $system_theme));

		$page_theme = null;
		// Find the theme that belongs to the user whose stuff we are looking at
		if ($this->profile_uid && ($this->profile_uid != local_user())) {
			// Allow folks to override user themes and always use their own on their own site.
			// This works only if the user is on the same server
			$user = dba::selectFirst('user', ['theme'], ['uid' => $this->profile_uid]);
			if (DBM::is_result($user) && !PConfig::get(local_user(), 'system', 'always_my_theme')) {
				$page_theme = $user['theme'];
			}
		}

		$user_theme = defaults($_SESSION, 'theme', $system_theme);
		// Specific mobile theme override
		if (($this->is_mobile || $this->is_tablet) && defaults($_SESSION, 'show-mobile', true)) {
			$system_mobile_theme = Config::get('system', 'mobile-theme');
			$user_mobile_theme = defaults($_SESSION, 'mobile-theme', $system_mobile_theme);

			// --- means same mobile theme as desktop
			if (!empty($user_mobile_theme) && $user_mobile_theme !== '---') {
				$user_theme = $user_mobile_theme;
			}
		}

		if ($page_theme) {
			$theme_name = $page_theme;
		} else {
			$theme_name = $user_theme;
		}

		if ($theme_name
			&& in_array($theme_name, $allowed_themes)
			&& (file_exists('view/theme/' . $theme_name . '/style.css')
			|| file_exists('view/theme/' . $theme_name . '/style.php'))
		) {
			$this->current_theme = $theme_name;
		}
	}

	/**
	 * @brief Return full URL to theme which is currently in effect.
	 *
	 * Provide a sane default if nothing is chosen or the specified theme does not exist.
	 *
	 * @return string
	 */
	public function getCurrentThemeStylesheetPath()
	{
		return Core\Theme::getStylesheetPath($this->getCurrentTheme());
	}
}
