<?php

namespace Friendica\App;

use Friendica\Core\Config\Capability\IManageConfigValues;

/**
 * Container for the whole request
 *
 * @see https://www.php-fig.org/psr/psr-7/#321-psrhttpmessageserverrequestinterface
 *
 * @todo future container class for whole requests, currently it's not :-)
 */
class Request
{
	/** @var string the default possible headers, which could contain the client IP */
	const ORDERED_FORWARD_FOR_HEADER = 'HTTP_X_FORWARDED_FOR';

	/** @var string The remote IP address of the current request */
	protected $remoteAddress;

	/**
	 * @return string The remote IP address of the current request
	 */
	public function getRemoteAddress(): string
	{
		return $this->remoteAddress;
	}

	public function __construct(IManageConfigValues $config, array $server = [])
	{
		$this->remoteAddress = $this->determineRemoteAddress($config, $server);
	}

	/**
	 * Checks if given $remoteAddress matches given $trustedProxy.
	 * If $trustedProxy is an IPv4 IP range given in CIDR notation, true will be returned if
	 * $remoteAddress is an IPv4 address within that IP range.
	 * Otherwise, $remoteAddress will be compared to $trustedProxy literally and the result
	 * will be returned.
	 *
	 * @return boolean true if $remoteAddress matches $trustedProxy, false otherwise
	 */
	protected function matchesTrustedProxy(string $trustedProxy, string $remoteAddress): bool
	{
		$cidrre = '/^([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\/([0-9]{1,2})$/';

		if (preg_match($cidrre, $trustedProxy, $match)) {
			$net       = $match[1];
			$shiftbits = min(32, max(0, 32 - intval($match[2])));
			$netnum    = ip2long($net) >> $shiftbits;
			$ipnum     = ip2long($remoteAddress) >> $shiftbits;

			return $ipnum === $netnum;
		}

		return $trustedProxy === $remoteAddress;
	}

	/**
	 * Checks if given $remoteAddress matches any entry in the given array $trustedProxies.
	 * For details regarding what "match" means, refer to `matchesTrustedProxy`.
	 *
	 * @return boolean true if $remoteAddress matches any entry in $trustedProxies, false otherwise
	 */
	protected function isTrustedProxy(array $trustedProxies, string $remoteAddress): bool
	{
		foreach ($trustedProxies as $tp) {
			if ($this->matchesTrustedProxy($tp, $remoteAddress)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param IManageConfigValues $config
	 * @param array               $server
	 *
	 * @return string
	 */
	protected function determineRemoteAddress(IManageConfigValues $config, array $server): string
	{
		$remoteAddress  = $server['REMOTE_ADDR'] ?? '0.0.0.0';
		$trustedProxies = preg_split('/(\s*,*\s*)*,+(\s*,*\s*)*/', $config->get('proxy', 'trusted_proxies', ''));

		if (\is_array($trustedProxies) && $this->isTrustedProxy($trustedProxies, $remoteAddress)) {
			$forwardedForHeaders = preg_split('/(\s*,*\s*)*,+(\s*,*\s*)*/', $config->get('proxy', 'forwarded_for_headers')) ?? static::ORDERED_FORWARD_FOR_HEADER;

			foreach ($forwardedForHeaders as $header) {
				if (isset($server[$header])) {
					foreach (explode(',', $server[$header]) as $IP) {
						$IP = trim($IP);

						// remove brackets from IPv6 addresses
						if (strpos($IP, '[') === 0 && substr($IP, -1) === ']') {
							$IP = substr($IP, 1, -1);
						}

						// skip trusted proxies in the list itself
						if ($this->isTrustedProxy($trustedProxies, $IP)) {
							continue;
						}

						if (filter_var($IP, FILTER_VALIDATE_IP) !== false) {
							return $IP;
						}
					}
				}
			}
		}

		return $remoteAddress;
	}
}
