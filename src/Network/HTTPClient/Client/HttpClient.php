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

namespace Friendica\Network\HTTPClient\Client;

use Friendica\Core\System;
use Friendica\Network\HTTPClient\Response\CurlResult;
use Friendica\Network\HTTPClient\Response\GuzzleResponse;
use Friendica\Network\HTTPClient\Capability\ICanSendHttpRequests;
use Friendica\Network\HTTPClient\Capability\ICanHandleHttpResponses;
use Friendica\Util\Network;
use Friendica\Util\Profiler;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use mattwright\URLResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Performs HTTP requests to a given URL
 */
class HttpClient implements ICanSendHttpRequests
{
	/** @var LoggerInterface */
	private $logger;
	/** @var Profiler */
	private $profiler;
	/** @var Client */
	private $client;
	/** @var URLResolver */
	private $resolver;

	public function __construct(LoggerInterface $logger, Profiler $profiler, Client $client, URLResolver $resolver)
	{
		$this->logger   = $logger;
		$this->profiler = $profiler;
		$this->client   = $client;
		$this->resolver = $resolver;
	}

	/**
	 * {@inheritDoc}
	 */
	public function request(string $method, string $url, array $opts = []): ICanHandleHttpResponses
	{
		$this->profiler->startRecording('network');
		$this->logger->debug('Request start.', ['url' => $url, 'method' => $method]);

		if (Network::isLocalLink($url)) {
			$this->logger->info('Local link', ['url' => $url, 'callstack' => System::callstack(20)]);
		}

		if (strlen($url) > 1000) {
			$this->logger->debug('URL is longer than 1000 characters.', ['url' => $url, 'callstack' => System::callstack(20)]);
			$this->profiler->stopRecording();
			return CurlResult::createErrorCurl(substr($url, 0, 200));
		}

		$parts2     = [];
		$parts      = parse_url($url);
		$path_parts = explode('/', $parts['path'] ?? '');
		foreach ($path_parts as $part) {
			if (strlen($part) <> mb_strlen($part)) {
				$parts2[] = rawurlencode($part);
			} else {
				$parts2[] = $part;
			}
		}
		$parts['path'] = implode('/', $parts2);
		$url           = Network::unparseURL($parts);

		if (Network::isUrlBlocked($url)) {
			$this->logger->info('Domain is blocked.', ['url' => $url]);
			$this->profiler->stopRecording();
			return CurlResult::createErrorCurl($url);
		}

		$conf = [];

		if (!empty($opts[HttpClientOptions::COOKIEJAR])) {
			$jar                           = new FileCookieJar($opts[HttpClientOptions::COOKIEJAR]);
			$conf[RequestOptions::COOKIES] = $jar;
		}

		$headers = [];

		if (!empty($opts[HttpClientOptions::ACCEPT_CONTENT])) {
			$headers['Accept'] = $opts[HttpClientOptions::ACCEPT_CONTENT];
		}

		if (!empty($opts[HttpClientOptions::LEGACY_HEADER])) {
			$this->logger->notice('Wrong option \'headers\' used.');
			$headers = array_merge($opts[HttpClientOptions::LEGACY_HEADER], $headers);
		}

		if (!empty($opts[HttpClientOptions::HEADERS])) {
			$headers = array_merge($opts[HttpClientOptions::HEADERS], $headers);
		}

		$conf[RequestOptions::HEADERS] = array_merge($this->client->getConfig(RequestOptions::HEADERS), $headers);

		if (!empty($opts[HttpClientOptions::TIMEOUT])) {
			$conf[RequestOptions::TIMEOUT] = $opts[HttpClientOptions::TIMEOUT];
		}

		if (!empty($opts[HttpClientOptions::BODY])) {
			$conf[RequestOptions::BODY] = $opts[HttpClientOptions::BODY];
		}

		if (!empty($opts[HttpClientOptions::AUTH])) {
			$conf[RequestOptions::AUTH] = $opts[HttpClientOptions::AUTH];
		}

		$conf[RequestOptions::ON_HEADERS] = function (ResponseInterface $response) use ($opts) {
			if (!empty($opts[HttpClientOptions::CONTENT_LENGTH]) &&
				(int)$response->getHeaderLine('Content-Length') > $opts[HttpClientOptions::CONTENT_LENGTH]) {
				throw new TransferException('The file is too big!');
			}
		};

		if (empty($conf[HttpClientOptions::HEADERS]['Accept'])) {
			$conf[HttpClientOptions::HEADERS]['Accept'] = HttpClientAccept::DEFAULT;
		}

		try {
			$this->logger->debug('http request config.', ['url' => $url, 'method' => $method, 'options' => $conf]);

			$response = $this->client->request($method, $url, $conf);
			return new GuzzleResponse($response, $url);
		} catch (TransferException $exception) {
			if ($exception instanceof RequestException &&
				$exception->hasResponse()) {
				return new GuzzleResponse($exception->getResponse(), $url, $exception->getCode(), '');
			} else {
				return new CurlResult($url, '', ['http_code' => 500], $exception->getCode(), '');
			}
		} catch (InvalidArgumentException | \InvalidArgumentException $argumentException) {
			$this->logger->info('Invalid Argument for HTTP call.', ['url' => $url, 'method' => $method, 'exception' => $argumentException]);
			return new CurlResult($url, '', ['http_code' => 500], $argumentException->getCode(), $argumentException->getMessage());
		} finally {
			$this->logger->debug('Request stop.', ['url' => $url, 'method' => $method]);
			$this->profiler->stopRecording();
		}
	}

	/** {@inheritDoc}
	 */
	public function head(string $url, array $opts = []): ICanHandleHttpResponses
	{
		return $this->request('head', $url, $opts);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get(string $url, array $opts = []): ICanHandleHttpResponses
	{
		return $this->request('get', $url, $opts);
	}

	/**
	 * {@inheritDoc}
	 */
	public function post(string $url, $params, array $headers = [], int $timeout = 0): ICanHandleHttpResponses
	{
		$opts = [];

		$opts[HttpClientOptions::BODY] = $params;

		if (!empty($headers)) {
			$opts[HttpClientOptions::HEADERS] = $headers;
		}

		if (!empty($timeout)) {
			$opts[HttpClientOptions::TIMEOUT] = $timeout;
		}

		return $this->request('post', $url, $opts);
	}

	/**
	 * {@inheritDoc}
	 */
	public function finalUrl(string $url): string
	{
		$this->profiler->startRecording('network');

		if (Network::isLocalLink($url)) {
			$this->logger->debug('Local link', ['url' => $url, 'callstack' => System::callstack(20)]);
		}

		if (Network::isUrlBlocked($url)) {
			$this->logger->info('Domain is blocked.', ['url' => $url]);
			return $url;
		}

		if (Network::isRedirectBlocked($url)) {
			$this->logger->info('Domain should not be redirected.', ['url' => $url]);
			return $url;
		}

		$url = Network::stripTrackingQueryParams($url);

		$url = trim($url, "'");

		$urlResult = $this->resolver->resolveURL($url);

		if ($urlResult->didErrorOccur()) {
			throw new TransferException($urlResult->getErrorMessageString(), $urlResult->getHTTPStatusCode());
		}

		return $urlResult->getURL();
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch(string $url, int $timeout = 0, string $accept_content = '', string $cookiejar = ''): string
	{
		$ret = $this->fetchFull($url, $timeout, $accept_content, $cookiejar);

		return $ret->getBody();
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetchFull(string $url, int $timeout = 0, string $accept_content = '', string $cookiejar = ''): ICanHandleHttpResponses
	{
		return $this->get(
			$url,
			[
				HttpClientOptions::TIMEOUT        => $timeout,
				HttpClientOptions::ACCEPT_CONTENT => $accept_content,
				HttpClientOptions::COOKIEJAR      => $cookiejar
			]
		);
	}
}
