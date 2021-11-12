<?php

namespace Friendica\Module\Api;

use Friendica\App\Arguments;
use Friendica\Core\L10n;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\DI;
use Friendica\Object\Api\Mastodon\Error;
use Friendica\Util\Arrays;
use Friendica\Util\HTTPInputData;
use Friendica\Util\XML;

/**
 * This class is used to format and return API responses
 */
class ApiResponse
{
	/** @var L10n */
	protected $l10n;
	/** @var Arguments */
	protected $args;

	/**
	 * @param L10n      $l10n
	 * @param Arguments $args
	 */
	public function __construct(L10n $l10n, Arguments $args)
	{
		$this->l10n = $l10n;
		$this->args = $args;
	}

	/**
	 * Creates the XML from a JSON style array
	 *
	 * @param array  $data         JSON style array
	 * @param string $root_element Name of the root element
	 *
	 * @return string The XML data
	 */
	public function createXML(array $data, string $root_element): string
	{
		$childname = key($data);
		$data2     = array_pop($data);

		$namespaces = [
			''          => 'http://api.twitter.com',
			'statusnet' => 'http://status.net/schema/api/1/',
			'friendica' => 'http://friendi.ca/schema/api/1/',
			'georss'    => 'http://www.georss.org/georss'
		];

		/// @todo Auto detection of needed namespaces
		if (in_array($root_element, ['ok', 'hash', 'config', 'version', 'ids', 'notes', 'photos'])) {
			$namespaces = [];
		}

		if (is_array($data2)) {
			$key = key($data2);
			Arrays::walkRecursive($data2, ['Friendica\Module\Api\ApiResponse', 'reformatXML']);

			if ($key == '0') {
				$data4 = [];
				$i     = 1;

				foreach ($data2 as $item) {
					$data4[$i++ . ':' . $childname] = $item;
				}

				$data2 = $data4;
			}
		}

		$data3 = [$root_element => $data2];

		return XML::fromArray($data3, $xml, false, $namespaces);
	}

	/**
	 * Formats the data according to the data type
	 *
	 * @param string $root_element Name of the root element
	 * @param string $type         Return type (atom, rss, xml, json)
	 * @param array  $data         JSON style array
	 *
	 * @return array|string (string|array) XML data or JSON data
	 */
	public static function formatData(string $root_element, string $type, array $data)
	{
		switch ($type) {
			case 'atom':
			case 'rss':
			case 'xml':
				$ret = DI::apiResponse()->createXML($data, $root_element);
				break;
			case 'json':
			default:
				$ret = $data;
				break;
		}
		return $ret;
	}

	/**
	 * Callback function to transform the array in an array that can be transformed in a XML file
	 *
	 * @param mixed  $item Array item value
	 * @param string $key  Array key
	 *
	 * @return boolean
	 */
	public static function reformatXML(&$item, string &$key): bool
	{
		if (is_bool($item)) {
			$item = ($item ? 'true' : 'false');
		}

		if (substr($key, 0, 10) == 'statusnet_') {
			$key = 'statusnet:' . substr($key, 10);
		} elseif (substr($key, 0, 10) == 'friendica_') {
			$key = 'friendica:' . substr($key, 10);
		}
		return true;
	}

	/**
	 * Exit with error code
	 *
	 * @param int         $code
	 * @param string      $description
	 * @param string      $message
	 * @param string|null $format
	 *
	 * @return void
	 */
	public static function error(int $code, string $description, string $message, string $format = null)
	{
		$error = [
			'error'   => $message ?: $description,
			'code'    => $code . ' ' . $description,
			'request' => DI::args()->getQueryString()
		];

		header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $code . ' ' . $description);

		self::exit('status', ['status' => $error], $format);
	}

	/**
	 * Outputs formatted data according to the data type and then exits the execution.
	 *
	 * @param string      $root_element
	 * @param array       $data   An array with a single element containing the returned result
	 * @param string|null $format Output format (xml, json, rss, atom)
	 *
	 * @return void
	 */
	public static function exit(string $root_element, array $data, string $format = null)
	{
		$format = $format ?? 'json';

		$return = static::formatData($root_element, $format, $data);

		switch ($format) {
			case 'xml':
				header('Content-Type: text/xml');
				break;
			case 'json':
				header('Content-Type: application/json');
				if (!empty($return)) {
					$json = json_encode(end($return));
					if (!empty($_GET['callback'])) {
						$json = $_GET['callback'] . '(' . $json . ')';
					}
					$return = $json;
				}
				break;
			case 'rss':
				header('Content-Type: application/rss+xml');
				$return = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $return;
				break;
			case 'atom':
				header('Content-Type: application/atom+xml');
				$return = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $return;
				break;
		}

		echo $return;
		exit;
	}

	/**
	 * Quit execution with the message that the endpoint isn't implemented
	 *
	 * @param string $method
	 *
	 * @return void
	 * @throws \Exception
	 */
	public static function unsupported(string $method = 'all')
	{
		$path = DI::args()->getQueryString();
		Logger::info('Unimplemented API call',
			[
				'method'  => $method,
				'path'    => $path,
				'agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
				'request' => HTTPInputData::process()
			]);
		$error             = DI::l10n()->t('API endpoint %s %s is not implemented', strtoupper($method), $path);
		$error_description = DI::l10n()->t('The API endpoint is currently not implemented but might be in the future.');
		$errorobj          = new Error($error, $error_description);
		System::jsonError(501, $errorobj->toArray());
	}
}
