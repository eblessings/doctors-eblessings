<?php
/**
 * @file src/Util/Network.php
 */
namespace Friendica\Util;

use Friendica\App;
use Friendica\Core\Addon;
use Friendica\Core\L10n;
use Friendica\Core\System;
use Friendica\Core\Config;
use Friendica\Network\Probe;
use Friendica\Object\Image;
use Friendica\Util\XML;
use DOMDocument;
use DomXPath;

class Network
{
	/**
	 * Curl wrapper
	 *
	 * If binary flag is true, return binary results.
	 * Set the cookiejar argument to a string (e.g. "/tmp/friendica-cookies.txt")
	 * to preserve cookies from one request to the next.
	 *
	 * @brief Curl wrapper
	 * @param string  $url            URL to fetch
	 * @param boolean $binary         default false
	 *                                TRUE if asked to return binary results (file download)
	 * @param integer $redirects      The recursion counter for internal use - default 0
	 * @param integer $timeout        Timeout in seconds, default system config value or 60 seconds
	 * @param string  $accept_content supply Accept: header with 'accept_content' as the value
	 * @param string  $cookiejar      Path to cookie jar file
	 *
	 * @return string The fetched content
	 */
	public static function fetchUrl($url, $binary = false, &$redirects = 0, $timeout = 0, $accept_content = null, $cookiejar = 0)
	{
		$ret = self::fetchUrlFull($url, $binary, $redirects, $timeout, $accept_content, $cookiejar);

		return $ret['body'];
	}

	/**
	 * Curl wrapper with array of return values.
	 *
	 * Inner workings and parameters are the same as @ref fetchUrl but returns an array with
	 * all the information collected during the fetch.
	 *
	 * @brief Curl wrapper with array of return values.
	 * @param string  $url            URL to fetch
	 * @param boolean $binary         default false
	 *                                TRUE if asked to return binary results (file download)
	 * @param integer $redirects      The recursion counter for internal use - default 0
	 * @param integer $timeout        Timeout in seconds, default system config value or 60 seconds
	 * @param string  $accept_content supply Accept: header with 'accept_content' as the value
	 * @param string  $cookiejar      Path to cookie jar file
	 *
	 * @return array With all relevant information, 'body' contains the actual fetched content.
	 */
	public static function fetchUrlFull($url, $binary = false, &$redirects = 0, $timeout = 0, $accept_content = null, $cookiejar = 0)
	{
		return self::curl(
			$url,
			$binary,
			$redirects,
			['timeout'=>$timeout,
			'accept_content'=>$accept_content,
			'cookiejar'=>$cookiejar
			]
		);
	}

	/**
	 * @brief fetches an URL.
	 *
	 * @param string  $url       URL to fetch
	 * @param boolean $binary    default false
	 *                           TRUE if asked to return binary results (file download)
	 * @param int     $redirects The recursion counter for internal use - default 0
	 * @param array   $opts      (optional parameters) assoziative array with:
	 *                           'accept_content' => supply Accept: header with 'accept_content' as the value
	 *                           'timeout' => int Timeout in seconds, default system config value or 60 seconds
	 *                           'http_auth' => username:password
	 *                           'novalidate' => do not validate SSL certs, default is to validate using our CA list
	 *                           'nobody' => only return the header
	 *                           'cookiejar' => path to cookie jar file
	 *
	 * @return array an assoziative array with:
	 *    int 'return_code' => HTTP return code or 0 if timeout or failure
	 *    boolean 'success' => boolean true (if HTTP 2xx result) or false
	 *    string 'redirect_url' => in case of redirect, content was finally retrieved from this URL
	 *    string 'header' => HTTP headers
	 *    string 'body' => fetched content
	 */
	public static function curl($url, $binary = false, &$redirects = 0, $opts = [])
	{
		$ret = ['return_code' => 0, 'success' => false, 'header' => '', 'info' => '', 'body' => ''];

		$stamp1 = microtime(true);

		$a = get_app();

		$parts = parse_url($url);
		$path_parts = explode('/', defaults($parts, 'path', ''));
		foreach ($path_parts as $part) {
		        if (strlen($part) <> mb_strlen($part)) {
				$parts2[] = rawurlencode($part);
		        } else {
		                $parts2[] = $part;
		        }
		}
		$parts['path'] =  implode('/', $parts2);
		$url = self::unparseURL($parts);

		if (self::isUrlBlocked($url)) {
			logger('domain of ' . $url . ' is blocked', LOGGER_DATA);
			return $ret;
		}

		$ch = @curl_init($url);

		if (($redirects > 8) || (!$ch)) {
			return $ret;
		}

		@curl_setopt($ch, CURLOPT_HEADER, true);

		if (x($opts, "cookiejar")) {
			curl_setopt($ch, CURLOPT_COOKIEJAR, $opts["cookiejar"]);
			curl_setopt($ch, CURLOPT_COOKIEFILE, $opts["cookiejar"]);
		}

		// These settings aren't needed. We're following the location already.
		//	@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		//	@curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

		if (x($opts, 'accept_content')) {
			curl_setopt(
				$ch,
				CURLOPT_HTTPHEADER,
				['Accept: ' . $opts['accept_content']]
			);
		}

		@curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		@curl_setopt($ch, CURLOPT_USERAGENT, $a->get_useragent());

		$range = intval(Config::get('system', 'curl_range_bytes', 0));

		if ($range > 0) {
			@curl_setopt($ch, CURLOPT_RANGE, '0-' . $range);
		}

		// Without this setting it seems as if some webservers send compressed content
		// This seems to confuse curl so that it shows this uncompressed.
		/// @todo  We could possibly set this value to "gzip" or something similar
		curl_setopt($ch, CURLOPT_ENCODING, '');

		if (x($opts, 'headers')) {
			@curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
		}

		if (x($opts, 'nobody')) {
			@curl_setopt($ch, CURLOPT_NOBODY, $opts['nobody']);
		}

		if (x($opts, 'timeout')) {
			@curl_setopt($ch, CURLOPT_TIMEOUT, $opts['timeout']);
		} else {
			$curl_time = Config::get('system', 'curl_timeout', 60);
			@curl_setopt($ch, CURLOPT_TIMEOUT, intval($curl_time));
		}

		// by default we will allow self-signed certs
		// but you can override this

		$check_cert = Config::get('system', 'verifyssl');
		@curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (($check_cert) ? true : false));

		if ($check_cert) {
			@curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		}

		$proxy = Config::get('system', 'proxy');

		if (strlen($proxy)) {
			@curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, 1);
			@curl_setopt($ch, CURLOPT_PROXY, $proxy);
			$proxyuser = @Config::get('system', 'proxyuser');

			if (strlen($proxyuser)) {
				@curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyuser);
			}
		}

		if (Config::get('system', 'ipv4_resolve', false)) {
			curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		}

		if ($binary) {
			@curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		}

		$a->set_curl_code(0);

		// don't let curl abort the entire application
		// if it throws any errors.

		$s = @curl_exec($ch);
		$curl_info = @curl_getinfo($ch);

		// Special treatment for HTTP Code 416
		// See https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/416
		if (($curl_info['http_code'] == 416) && ($range > 0)) {
			@curl_setopt($ch, CURLOPT_RANGE, '');
			$s = @curl_exec($ch);
			$curl_info = @curl_getinfo($ch);
		}

		if (curl_errno($ch) !== CURLE_OK) {
			logger('error fetching ' . $url . ': ' . curl_error($ch), LOGGER_NORMAL);
		}

		$ret['errno'] = curl_errno($ch);

		$base = $s;
		$ret['info'] = $curl_info;

		$http_code = $curl_info['http_code'];

		logger($url . ': ' . $http_code . " " . $s, LOGGER_DATA);
		$header = '';

		// Pull out multiple headers, e.g. proxy and continuation headers
		// allow for HTTP/2.x without fixing code

		while (preg_match('/^HTTP\/[1-2].+? [1-5][0-9][0-9]/', $base)) {
			$chunk = substr($base, 0, strpos($base, "\r\n\r\n") + 4);
			$header .= $chunk;
			$base = substr($base, strlen($chunk));
		}

		$a->set_curl_code($http_code);
		$a->set_curl_content_type($curl_info['content_type']);
		$a->set_curl_headers($header);

		if ($http_code == 301 || $http_code == 302 || $http_code == 303 || $http_code == 307) {
			$new_location_info = @parse_url($curl_info['redirect_url']);
			$old_location_info = @parse_url($curl_info['url']);

			$newurl = $curl_info['redirect_url'];

			if (empty($new_location_info['path']) && !empty($new_location_info['host'])) {
				$newurl = $new_location_info['scheme'] . '://' . $new_location_info['host'] . $old_location_info['path'];
			}

			$matches = [];

			if (preg_match('/(Location:|URI:)(.*?)\n/i', $header, $matches)) {
				$newurl = trim(array_pop($matches));
			}
			if (strpos($newurl, '/') === 0) {
				$newurl = $old_location_info["scheme"]."://".$old_location_info["host"].$newurl;
			}
			$old_location_query = @parse_url($url, PHP_URL_QUERY);

			if ($old_location_query != '') {
				$newurl .= '?' . $old_location_query;
			}

			if (filter_var($newurl, FILTER_VALIDATE_URL)) {
				$redirects++;
				@curl_close($ch);
				return self::curl($newurl, $binary, $redirects, $opts);
			}
		}

		$a->set_curl_code($http_code);
		$a->set_curl_content_type($curl_info['content_type']);

		$rc = intval($http_code);
		$ret['return_code'] = $rc;
		$ret['success'] = (($rc >= 200 && $rc <= 299) ? true : false);
		$ret['redirect_url'] = $url;

		if (!$ret['success']) {
			$ret['error'] = curl_error($ch);
			$ret['debug'] = $curl_info;
			logger('error: '.$url.': '.$ret['return_code'].' - '.$ret['error'], LOGGER_DEBUG);
			logger('debug: '.print_r($curl_info, true), LOGGER_DATA);
		}

		$ret['body'] = substr($s, strlen($header));
		$ret['header'] = $header;

		if (x($opts, 'debug')) {
			$ret['debug'] = $curl_info;
		}

		@curl_close($ch);

		$a->save_timestamp($stamp1, 'network');

		return($ret);
	}

	/**
	 * @brief Send POST request to $url
	 *
	 * @param string  $url       URL to post
	 * @param mixed   $params    array of POST variables
	 * @param string  $headers   HTTP headers
	 * @param integer $redirects Recursion counter for internal use - default = 0
	 * @param integer $timeout   The timeout in seconds, default system config value or 60 seconds
	 *
	 * @return string The content
	 */
	public static function post($url, $params, $headers = null, &$redirects = 0, $timeout = 0)
	{
		$stamp1 = microtime(true);

		if (self::isUrlBlocked($url)) {
			logger('post_url: domain of ' . $url . ' is blocked', LOGGER_DATA);
			return false;
		}

		$a = get_app();
		$ch = curl_init($url);

		if (($redirects > 8) || (!$ch)) {
			return false;
		}

		logger('post_url: start ' . $url, LOGGER_DATA);

		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		curl_setopt($ch, CURLOPT_USERAGENT, $a->get_useragent());

		if (Config::get('system', 'ipv4_resolve', false)) {
			curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		}

		if (intval($timeout)) {
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		} else {
			$curl_time = Config::get('system', 'curl_timeout', 60);
			curl_setopt($ch, CURLOPT_TIMEOUT, intval($curl_time));
		}

		if (defined('LIGHTTPD')) {
			if (!is_array($headers)) {
				$headers = ['Expect:'];
			} else {
				if (!in_array('Expect:', $headers)) {
					array_push($headers, 'Expect:');
				}
			}
		}

		if ($headers) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}

		$check_cert = Config::get('system', 'verifyssl');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (($check_cert) ? true : false));

		if ($check_cert) {
			@curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		}

		$proxy = Config::get('system', 'proxy');

		if (strlen($proxy)) {
			curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, 1);
			curl_setopt($ch, CURLOPT_PROXY, $proxy);
			$proxyuser = Config::get('system', 'proxyuser');
			if (strlen($proxyuser)) {
				curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyuser);
			}
		}

		$a->set_curl_code(0);

		// don't let curl abort the entire application
		// if it throws any errors.

		$s = @curl_exec($ch);

		$base = $s;
		$curl_info = curl_getinfo($ch);
		$http_code = $curl_info['http_code'];

		logger('post_url: result ' . $http_code . ' - ' . $url, LOGGER_DATA);

		$header = '';

		// Pull out multiple headers, e.g. proxy and continuation headers
		// allow for HTTP/2.x without fixing code

		while (preg_match('/^HTTP\/[1-2].+? [1-5][0-9][0-9]/', $base)) {
			$chunk = substr($base, 0, strpos($base, "\r\n\r\n") + 4);
			$header .= $chunk;
			$base = substr($base, strlen($chunk));
		}

		if ($http_code == 301 || $http_code == 302 || $http_code == 303 || $http_code == 307) {
			$matches = [];
			$new_location_info = @parse_url($curl_info['redirect_url']);
			$old_location_info = @parse_url($curl_info['url']);

			preg_match('/(Location:|URI:)(.*?)\n/', $header, $matches);
			$newurl = trim(array_pop($matches));

			if (strpos($newurl, '/') === 0) {
				$newurl = $old_location_info["scheme"] . "://" . $old_location_info["host"] . $newurl;
			}

			if (filter_var($newurl, FILTER_VALIDATE_URL)) {
				$redirects++;
				logger('post_url: redirect ' . $url . ' to ' . $newurl);
				return self::post($newurl, $params, $headers, $redirects, $timeout);
			}
		}

		$a->set_curl_code($http_code);

		$body = substr($s, strlen($header));

		$a->set_curl_headers($header);

		curl_close($ch);

		$a->save_timestamp($stamp1, 'network');

		logger('post_url: end ' . $url, LOGGER_DATA);

		return $body;
	}

	/**
	 * @brief Check URL to see if it's real
	 *
	 * Take a URL from the wild, prepend http:// if necessary
	 * and check DNS to see if it's real (or check if is a valid IP address)
	 *
	 * @param string $url The URL to be validated
	 * @return string|boolean The actual working URL, false else
	 */
	public static function isUrlValid($url)
	{
		if (Config::get('system', 'disable_url_validation')) {
			return $url;
		}

		// no naked subdomains (allow localhost for tests)
		if (strpos($url, '.') === false && strpos($url, '/localhost/') === false) {
			return false;
		}

		if (substr($url, 0, 4) != 'http') {
			$url = 'http://' . $url;
		}

		/// @TODO Really suppress function outcomes? Why not find them + debug them?
		$h = @parse_url($url);

		if ((is_array($h)) && (@dns_get_record($h['host'], DNS_A + DNS_CNAME) || filter_var($h['host'], FILTER_VALIDATE_IP) )) {
			return $url;
		}

		return false;
	}

	/**
	 * @brief Checks that email is an actual resolvable internet address
	 *
	 * @param string $addr The email address
	 * @return boolean True if it's a valid email address, false if it's not
	 */
	public static function isEmailDomainValid($addr)
	{
		if (Config::get('system', 'disable_email_validation')) {
			return true;
		}

		if (! strpos($addr, '@')) {
			return false;
		}

		$h = substr($addr, strpos($addr, '@') + 1);

		if (($h) && (dns_get_record($h, DNS_A + DNS_MX) || filter_var($h, FILTER_VALIDATE_IP) )) {
			return true;
		}
		if (($h) && dns_get_record($h, DNS_CNAME + DNS_MX)) {
			return true;
		}
		return false;
	}

	/**
	 * @brief Check if URL is allowed
	 *
	 * Check $url against our list of allowed sites,
	 * wildcards allowed. If allowed_sites is unset return true;
	 *
	 * @param string $url URL which get tested
	 * @return boolean True if url is allowed otherwise return false
	 */
	public static function isUrlAllowed($url)
	{
		$h = @parse_url($url);

		if (! $h) {
			return false;
		}

		$str_allowed = Config::get('system', 'allowed_sites');
		if (! $str_allowed) {
			return true;
		}

		$found = false;

		$host = strtolower($h['host']);

		// always allow our own site
		if ($host == strtolower($_SERVER['SERVER_NAME'])) {
			return true;
		}

		$fnmatch = function_exists('fnmatch');
		$allowed = explode(',', $str_allowed);

		if (count($allowed)) {
			foreach ($allowed as $a) {
				$pat = strtolower(trim($a));
				if (($fnmatch && fnmatch($pat, $host)) || ($pat == $host)) {
					$found = true;
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * Checks if the provided url domain is on the domain blocklist.
	 * Returns true if it is or malformed URL, false if not.
	 *
	 * @param string $url The url to check the domain from
	 *
	 * @return boolean
	 */
	public static function isUrlBlocked($url)
	{
		$host = @parse_url($url, PHP_URL_HOST);
		if (! $host) {
			return true;
		}

		$domain_blocklist = Config::get('system', 'blocklist', []);
		if (! $domain_blocklist) {
			return false;
		}

		foreach ($domain_blocklist as $domain_block) {
			if (strcasecmp($domain_block['domain'], $host) === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @brief Check if email address is allowed to register here.
	 *
	 * Compare against our list (wildcards allowed).
	 *
	 * @param  string $email email address
	 * @return boolean False if not allowed, true if allowed
	 *    or if allowed list is not configured
	 */
	public static function isEmailDomainAllowed($email)
	{
		$domain = strtolower(substr($email, strpos($email, '@') + 1));
		if (!$domain) {
			return false;
		}

		$str_allowed = Config::get('system', 'allowed_email', '');
		if (!x($str_allowed)) {
			return true;
		}

		$allowed = explode(',', $str_allowed);

		return self::isDomainAllowed($domain, $allowed);
	}

	/**
	 * Checks for the existence of a domain in a domain list
	 *
	 * @brief Checks for the existence of a domain in a domain list
	 * @param string $domain
	 * @param array  $domain_list
	 * @return boolean
	 */
	public static function isDomainAllowed($domain, array $domain_list)
	{
		$found = false;

		foreach ($domain_list as $item) {
			$pat = strtolower(trim($item));
			if (fnmatch($pat, $domain) || ($pat == $domain)) {
				$found = true;
				break;
			}
		}

		return $found;
	}

	public static function lookupAvatarByEmail($email)
	{
		$avatar['size'] = 175;
		$avatar['email'] = $email;
		$avatar['url'] = '';
		$avatar['success'] = false;

		Addon::callHooks('avatar_lookup', $avatar);

		if (! $avatar['success']) {
			$avatar['url'] = System::baseUrl() . '/images/person-175.jpg';
		}

		logger('Avatar: ' . $avatar['email'] . ' ' . $avatar['url'], LOGGER_DEBUG);
		return $avatar['url'];
	}

	/**
	 * @brief Remove Google Analytics and other tracking platforms params from URL
	 *
	 * @param string $url Any user-submitted URL that may contain tracking params
	 * @return string The same URL stripped of tracking parameters
	 */
	public static function stripTrackingQueryParams($url)
	{
		$urldata = parse_url($url);
		if (!empty($urldata["query"])) {
			$query = $urldata["query"];
			parse_str($query, $querydata);

			if (is_array($querydata)) {
				foreach ($querydata as $param => $value) {
					if (in_array(
						$param,
						[
							"utm_source", "utm_medium", "utm_term", "utm_content", "utm_campaign",
							"wt_mc", "pk_campaign", "pk_kwd", "mc_cid", "mc_eid",
							"fb_action_ids", "fb_action_types", "fb_ref",
							"awesm", "wtrid",
							"woo_campaign", "woo_source", "woo_medium", "woo_content", "woo_term"]
						)
					) {
						$pair = $param . "=" . urlencode($value);
						$url = str_replace($pair, "", $url);

						// Second try: if the url isn't encoded completely
						$pair = $param . "=" . str_replace(" ", "+", $value);
						$url = str_replace($pair, "", $url);

						// Third try: Maybey the url isn't encoded at all
						$pair = $param . "=" . $value;
						$url = str_replace($pair, "", $url);

						$url = str_replace(["?&", "&&"], ["?", ""], $url);
					}
				}
			}

			if (substr($url, -1, 1) == "?") {
				$url = substr($url, 0, -1);
			}
		}

		return $url;
	}

	/**
	 * @brief Returns the original URL of the provided URL
	 *
	 * This function strips tracking query params and follows redirections, either
	 * through HTTP code or meta refresh tags. Stops after 10 redirections.
	 *
	 * @todo Remove the $fetchbody parameter that generates an extraneous HEAD request
	 *
	 * @see ParseUrl::getSiteinfo
	 *
	 * @param string $url       A user-submitted URL
	 * @param int    $depth     The current redirection recursion level (internal)
	 * @param bool   $fetchbody Wether to fetch the body or not after the HEAD requests
	 * @return string A canonical URL
	 */
	public static function finalUrl($url, $depth = 1, $fetchbody = false)
	{
		$a = get_app();

		$url = self::stripTrackingQueryParams($url);

		if ($depth > 10) {
			return($url);
		}

		$url = trim($url, "'");

		$stamp1 = microtime(true);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_NOBODY, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, $a->get_useragent());

		curl_exec($ch);
		$curl_info = @curl_getinfo($ch);
		$http_code = $curl_info['http_code'];
		curl_close($ch);

		$a->save_timestamp($stamp1, "network");

		if ($http_code == 0) {
			return($url);
		}

		if ((($curl_info['http_code'] == "301") || ($curl_info['http_code'] == "302"))
			&& (($curl_info['redirect_url'] != "") || ($curl_info['location'] != ""))
		) {
			if ($curl_info['redirect_url'] != "") {
				return(self::finalUrl($curl_info['redirect_url'], ++$depth, $fetchbody));
			} else {
				return(self::finalUrl($curl_info['location'], ++$depth, $fetchbody));
			}
		}

		// Check for redirects in the meta elements of the body if there are no redirects in the header.
		if (!$fetchbody) {
			return(self::finalUrl($url, ++$depth, true));
		}

		// if the file is too large then exit
		if ($curl_info["download_content_length"] > 1000000) {
			return($url);
		}

		// if it isn't a HTML file then exit
		if (($curl_info["content_type"] != "") && !strstr(strtolower($curl_info["content_type"]), "html")) {
			return($url);
		}

		$stamp1 = microtime(true);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_NOBODY, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, $a->get_useragent());

		$body = curl_exec($ch);
		curl_close($ch);

		$a->save_timestamp($stamp1, "network");

		if (trim($body) == "") {
			return($url);
		}

		// Check for redirect in meta elements
		$doc = new DOMDocument();
		@$doc->loadHTML($body);

		$xpath = new DomXPath($doc);

		$list = $xpath->query("//meta[@content]");
		foreach ($list as $node) {
			$attr = [];
			if ($node->attributes->length) {
				foreach ($node->attributes as $attribute) {
					$attr[$attribute->name] = $attribute->value;
				}
			}

			if (@$attr["http-equiv"] == 'refresh') {
				$path = $attr["content"];
				$pathinfo = explode(";", $path);
				foreach ($pathinfo as $value) {
					if (substr(strtolower($value), 0, 4) == "url=") {
						return(self::finalUrl(substr($value, 4), ++$depth));
					}
				}
			}
		}

		return $url;
	}

	/**
	 * @brief Find the matching part between two url
	 *
	 * @param string $url1
	 * @param string $url2
	 * @return string The matching part
	 */
	public static function getUrlMatch($url1, $url2)
	{
		if (($url1 == "") || ($url2 == "")) {
			return "";
		}

		$url1 = normalise_link($url1);
		$url2 = normalise_link($url2);

		$parts1 = parse_url($url1);
		$parts2 = parse_url($url2);

		if (!isset($parts1["host"]) || !isset($parts2["host"])) {
			return "";
		}

		if (empty($parts1["scheme"])) {
			$parts1["scheme"] = '';
		}
		if (empty($parts2["scheme"])) {
			$parts2["scheme"] = '';
		}

		if ($parts1["scheme"] != $parts2["scheme"]) {
			return "";
		}

		if (empty($parts1["host"])) {
			$parts1["host"] = '';
		}
		if (empty($parts2["host"])) {
			$parts2["host"] = '';
		}

		if ($parts1["host"] != $parts2["host"]) {
			return "";
		}

		if (empty($parts1["port"])) {
			$parts1["port"] = '';
		}
		if (empty($parts2["port"])) {
			$parts2["port"] = '';
		}

		if ($parts1["port"] != $parts2["port"]) {
			return "";
		}

		$match = $parts1["scheme"]."://".$parts1["host"];

		if ($parts1["port"]) {
			$match .= ":".$parts1["port"];
		}

		if (empty($parts1["path"])) {
			$parts1["path"] = '';
		}
		if (empty($parts2["path"])) {
			$parts2["path"] = '';
		}

		$pathparts1 = explode("/", $parts1["path"]);
		$pathparts2 = explode("/", $parts2["path"]);

		$i = 0;
		$path = "";
		do {
			$path1 = defaults($pathparts1, $i, '');
			$path2 = defaults($pathparts2, $i, '');

			if ($path1 == $path2) {
				$path .= $path1."/";
			}
		} while (($path1 == $path2) && ($i++ <= count($pathparts1)));

		$match .= $path;

		return normalise_link($match);
	}

	/**
	 * @brief Glue url parts together
	 *
	 * @param array $parsed URL parts
	 *
	 * @return string The glued URL
	 */
	public static function unparseURL($parsed)
	{
		$get = function ($key) use ($parsed) {
			return isset($parsed[$key]) ? $parsed[$key] : null;
		};

		$pass      = $get('pass');
		$user      = $get('user');
		$userinfo  = $pass !== null ? "$user:$pass" : $user;
		$port      = $get('port');
		$scheme    = $get('scheme');
		$query     = $get('query');
		$fragment  = $get('fragment');
		$authority = ($userinfo !== null ? $userinfo."@" : '') .
						$get('host') .
						($port ? ":$port" : '');

		return	(strlen($scheme) ? $scheme.":" : '') .
			(strlen($authority) ? "//".$authority : '') .
			$get('path') .
			(strlen($query) ? "?".$query : '') .
			(strlen($fragment) ? "#".$fragment : '');
	}
}
