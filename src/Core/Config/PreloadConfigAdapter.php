<?php

namespace Friendica\Core\Config;

use Exception;
use Friendica\Database\DBA;

/**
 * Preload Configuration Adapter
 *
 * Minimizes the number of database queries to retrieve configuration values at the cost of memory.
 *
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
class PreloadConfigAdapter implements IConfigAdapter
{
	private $config_loaded = false;

	/**
	 * @var IConfigCache The config cache of this driver
	 */
	private $config;

	/**
	 * @param IConfigCache $config The config cache of this driver
	 */
	public function __construct($config)
	{
		$this->config = $config;
		$this->load();
	}

	public function load($family = 'config')
	{
		if ($this->config_loaded) {
			return;
		}

		$configs = DBA::select('config', ['cat', 'v', 'k']);
		while ($config = DBA::fetch($configs)) {
			$this->config->set($config['cat'], $config['k'], $config['v']);
		}
		DBA::close($configs);

		$this->config_loaded = true;
	}

	public function get($cat, $k, $default_value = null, $refresh = false)
	{
		if ($refresh) {
			$config = DBA::selectFirst('config', ['v'], ['cat' => $cat, 'k' => $k]);
			if (DBA::isResult($config)) {
				$this->config->set($cat, $k, $config['v']);
			}
		}

		$return = $this->config->get($cat, $k, $default_value);

		return $return;
	}

	public function set($cat, $k, $value)
	{
		// We store our setting values as strings.
		// So we have to do the conversion here so that the compare below works.
		// The exception are array values.
		$compare_value = !is_array($value) ? (string)$value : $value;

		if ($this->config->get($cat, $k) === $compare_value) {
			return true;
		}

		$this->config->set($cat, $k, $value);

		// manage array value
		$dbvalue = is_array($value) ? serialize($value) : $value;

		$result = DBA::update('config', ['v' => $dbvalue], ['cat' => $cat, 'k' => $k], true);
		if (!$result) {
			throw new Exception('Unable to store config value in [' . $cat . '][' . $k . ']');
		}

		return true;
	}

	public function delete($cat, $k)
	{
		$this->config->delete($cat, $k);

		$result = DBA::delete('config', ['cat' => $cat, 'k' => $k]);

		return $result;
	}
}
