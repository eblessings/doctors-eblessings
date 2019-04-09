<?php

namespace Friendica\Util;

use Friendica\Core\Config\Configuration;

/**
 * A class which checks and contains the basic
 * environment for the BaseURL (url, urlpath, ssl_policy, hostname, scheme)
 */
class BaseURL
{
	/**
	 * No SSL necessary
	 */
	const SSL_POLICY_NONE = 0;

	/**
	 * SSL is necessary
	 */
	const SSL_POLICY_FULL = 1;

	/**
	 * SSL is optional, but preferred
	 */
	const SSL_POLICY_SELFSIGN = 2;

	/**
	 * Define the Default SSL scheme
	 */
	const DEFAULT_SSL_SCHEME = self::SSL_POLICY_SELFSIGN;

	/**
	 * The Friendica Config
	 * @var Configuration
	 */
	private $config;

	/**
	 * The server side variables
	 * @var array
	 */
	private $server;

	/**
	 * The hostname of the Base URL
	 * @var string
	 */
	private $hostname;

	/**
	 * The SSL_POLICY of the Base URL
	 * @var int
	 */
	private $sslPolicy;

	/**
	 * The URL sub-path of the Base URL
	 * @var string
	 */
	private $urlPath;

	/**
	 * The full URL
	 * @var string
	 */
	private $url;

	/**
	 * The current scheme of this call
	 * @var string
	 */
	private $scheme;

	/**
	 * Returns the hostname of this node
	 * @return string
	 */
	public function getHostname()
	{
		return $this->hostname;
	}

	/**
	 * Returns the current scheme of this call
	 * @return string
	 */
	public function getScheme()
	{
		return $this->scheme;
	}

	/**
	 * Returns the SSL policy of this node
	 * @return int
	 */
	public function getSSLPolicy()
	{
		return $this->sslPolicy;
	}

	/**
	 * Returns the sub-path of this URL
	 * @return string
	 */
	public function getUrlPath()
	{
		return $this->urlPath;
	}

	/**
	 * Returns the full URL of this call
	 *
	 * Note: $ssl parameter value doesn't directly correlate with the resulting protocol
	 *
	 * @param bool $ssl True, if ssl should get used
	 *
	 * @return string
	 */
	public function get($ssl = false)
	{
		if ($this->sslPolicy === self::SSL_POLICY_SELFSIGN && $ssl) {
			return Network::switchScheme($this->url);
		}

		return $this->url;
	}

	/**
	 * Save current parts of the base Url
	 *
	 * @param string? $hostname
	 * @param int?    $sslPolicy
	 * @param string? $urlPath
	 *
	 * @return bool true, if successful
	 */
	public function save($hostname = null, $sslPolicy = null, $urlPath = null)
	{
		$success = true;

		if (!empty($hostname)) {
			$this->hostname  = $hostname;
			if (!$this->config->set('config', 'hostname', $this->hostname)) {
				$success = false;
			}
		}

		if (isset($sslPolicy)) {
			$this->sslPolicy = $sslPolicy;
			if (!$this->config->set('system', 'ssl_policy', $this->sslPolicy)) {
				$success = false;
			}
		}

		if (isset($urlPath)) {
			$this->urlPath   = $urlPath;
			if (!$this->config->set('system', 'urlpath', $this->urlPath)) {
				$success = false;
			}
		}

		$this->determineBaseUrl();
		if (!$this->config->set('system', 'url', $this->url)) {
			$success = false;
		}

		return $success;
	}

	/**
	 * Save the current url as base URL
	 *
	 * @param $url
	 *
	 * @return bool true, if the save was successful
	 */
	public function saveByURL($url)
	{
		$parsed = @parse_url($url);

		if (empty($parsed)) {
			return false;
		}

		$hostname = $parsed['host'];
		if (!empty($hostname) && !empty($parsed['port'])) {
			$hostname .= ':' . $parsed['port'];
		}

		$urlPath = null;
		if (!empty($parsed['path'])) {
			$urlPath = trim($parsed['path'], '\\/');
		}

		$sslPolicy = null;
		if (!empty($parsed['scheme'])) {
			if ($parsed['scheme'] == 'https') {
				$sslPolicy = BaseURL::SSL_POLICY_FULL;
			}
		}

		return $this->save($hostname, $sslPolicy, $urlPath);
	}

	/**
	 * Checks, if a redirect to the HTTPS site would be necessary
	 *
	 * @return bool
	 */
	public function checkRedirectHttps()
	{
		return $this->config->get('system', 'force_ssl')
			&& ($this->getScheme() == "http")
			&& intval($this->getSSLPolicy()) == BaseURL::SSL_POLICY_FULL
			&& strpos($this->get(), 'https://') === 0
			&& !empty($this->server['REQUEST_METHOD'])
			&& $this->server['REQUEST_METHOD'] === 'GET';
	}

	/**
	 * @param Configuration $config The Friendica configuration
	 * @param array         $server The $_SERVER array
	 */
	public function __construct(Configuration $config, array $server)
	{
		$this->config = $config;
		$this->server = $server;

		$this->determineSchema();
		$this->checkConfig();
	}

	/**
	 * Check the current config during loading
	 */
	public function checkConfig()
	{
		$this->hostname  = $this->config->get('config', 'hostname');
		$this->urlPath   = $this->config->get('system', 'urlpath');
		$this->sslPolicy = $this->config->get('system', 'ssl_policy');
		$this->url       = $this->config->get('system', 'url');

		if (empty($this->hostname)) {
			$this->determineHostname();

			if (!empty($this->hostname)) {
				$this->config->set('config', 'hostname', $this->hostname);
			}
		}

		if (!isset($this->urlPath)) {
			$this->determineURLPath();
			$this->config->set('system', 'urlpath', $this->urlPath);
		}

		if (!isset($this->sslPolicy)) {
			if ($this->scheme == 'https') {
				$this->sslPolicy = self::SSL_POLICY_FULL;
			} else {
				$this->sslPolicy = self::DEFAULT_SSL_SCHEME;
			}
			$this->config->set('system', 'ssl_policy', $this->sslPolicy);
		}

		if (empty($this->url)) {
			$this->determineBaseUrl();

			if (!empty($url)) {
				$this->config->set('system', 'url', $this->url);
			}
		}
	}

	/**
	 * Determines the hostname of this node if not set already
	 */
	private function determineHostname()
	{
		$this->hostname = '';

		if (!empty($this->server['SERVER_NAME'])) {
			$this->hostname = $this->server['SERVER_NAME'];

			if (!empty($this->server['SERVER_PORT']) && $this->server['SERVER_PORT'] != 80 && $this->server['SERVER_PORT'] != 443) {
				$this->hostname .= ':' . $this->server['SERVER_PORT'];
			}
		}
	}

	/**
	 * Figure out if we are running at the top of a domain or in a sub-directory
	 */
	private function determineURLPath()
	{
		$this->urlPath = '';

		/*
		 * The automatic path detection in this function is currently deactivated,
		 * see issue https://github.com/friendica/friendica/issues/6679
		 *
		 * The problem is that the function seems to be confused with some url.
		 * These then confuses the detection which changes the url path.
		 */

		/* Relative script path to the web server root
		 * Not all of those $_SERVER properties can be present, so we do by inverse priority order
		 */
		$relative_script_path = '';
		$relative_script_path = defaults($this->server, 'REDIRECT_URL', $relative_script_path);
		$relative_script_path = defaults($this->server, 'REDIRECT_URI', $relative_script_path);
		$relative_script_path = defaults($this->server, 'REDIRECT_SCRIPT_URL', $relative_script_path);
		$relative_script_path = defaults($this->server, 'SCRIPT_URL', $relative_script_path);
		$relative_script_path = defaults($this->server, 'REQUEST_URI', $relative_script_path);

		/* $relative_script_path gives /relative/path/to/friendica/module/parameter
		 * QUERY_STRING gives pagename=module/parameter
		 *
		 * To get /relative/path/to/friendica we perform dirname() for as many levels as there are slashes in the QUERY_STRING
		 */
		if (!empty($relative_script_path)) {
			// Module
			if (!empty($this->server['QUERY_STRING'])) {
				$this->urlPath = trim(rdirname($relative_script_path, substr_count(trim($this->server['QUERY_STRING'], '/'), '/') + 1), '/');
			} else {
				// Root page
				$this->urlPath = trim($relative_script_path, '/');
			}
		}
	}

	/**
	 * Determine the full URL based on all parts
	 */
	private function determineBaseUrl()
	{
		$scheme = 'http';

		if ($this->sslPolicy == self::SSL_POLICY_FULL) {
			$scheme = 'https';
		}

		$this->url = $scheme . '://' . $this->hostname . (!empty($this->urlPath) ? '/' . $this->urlPath : '' );
	}

	/**
	 * Determine the scheme of the current used link
	 */
	private function determineSchema()
	{
		$this->scheme = 'http';

		if (!empty($this->server['HTTPS']) ||
			!empty($this->server['HTTP_FORWARDED']) && preg_match('/proto=https/', $this->server['HTTP_FORWARDED']) ||
			!empty($this->server['HTTP_X_FORWARDED_PROTO']) && $this->server['HTTP_X_FORWARDED_PROTO'] == 'https' ||
			!empty($this->server['HTTP_X_FORWARDED_SSL']) && $this->server['HTTP_X_FORWARDED_SSL'] == 'on' ||
			!empty($this->server['FRONT_END_HTTPS']) && $this->server['FRONT_END_HTTPS'] == 'on' ||
			!empty($this->server['SERVER_PORT']) && (intval($this->server['SERVER_PORT']) == 443) // XXX: reasonable assumption, but isn't this hardcoding too much?
		) {
			$this->scheme = 'https';
		}
	}
}
