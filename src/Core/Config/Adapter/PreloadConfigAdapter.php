<?php

namespace Friendica\Core\Config\Adapter;

use Friendica\Database\DBA;

/**
 * Preload Configuration Adapter
 *
 * Minimizes the number of database queries to retrieve configuration values at the cost of memory.
 *
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
class PreloadConfigAdapter extends AbstractDbaConfigAdapter implements IConfigAdapter
{
	private $config_loaded = false;

	/**
	 * {@inheritdoc}
	 */
	public function load($cat = 'config')
	{
		$return = [];

		if (!$this->isConnected()) {
			return $return;
		}

		if ($this->config_loaded) {
			return $return;
		}

		$configs = DBA::select('config', ['cat', 'v', 'k']);
		while ($config = DBA::fetch($configs)) {
			$value = $this->toConfigValue($config['v']);
			if (isset($value)) {
				$return[$config['cat']][$config['k']] = $value;
			}
		}
		DBA::close($configs);

		$this->config_loaded = true;

		return $return;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get($cat, $key)
	{
		if (!$this->isConnected()) {
			return null;
		}

		$config = DBA::selectFirst('config', ['v'], ['cat' => $cat, 'k' => $key]);
		if (DBA::isResult($config)) {
			$value = $this->toConfigValue($config['v']);

			if (isset($value)) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function set($cat, $key, $value)
	{
		if (!$this->isConnected()) {
			return false;
		}

		// We store our setting values as strings.
		// So we have to do the conversion here so that the compare below works.
		// The exception are array values.
		$compare_value = !is_array($value) ? (string)$value : $value;
		$stored_value = $this->get($cat, $key);

		if (isset($stored_value) && $stored_value === $compare_value) {
			return true;
		}

		$dbvalue = $this->toDbValue($value);

		return DBA::update('config', ['v' => $dbvalue], ['cat' => $cat, 'k' => $key], true);
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($cat, $key)
	{
		if (!$this->isConnected()) {
			return false;
		}

		return DBA::delete('config', ['cat' => $cat, 'k' => $key]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function isLoaded($cat, $key)
	{
		if (!$this->isConnected()) {
			return false;
		}

		return $this->config_loaded;
	}
}
