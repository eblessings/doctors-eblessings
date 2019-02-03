<?php
namespace Friendica\Core\Config;

use Friendica\Core\Config;
use Friendica\Database\DBA;

/**
 * JustInTime Configuration Adapter
 *
 * Default Config Adapter. Provides the best performance for pages loading few configuration variables.
 *
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
class JITConfigAdapter implements IConfigAdapter
{
	private $cache;
	private $in_db;

	public function load($cat = "config")
	{
		// We don't preload "system" anymore.
		// This reduces the number of database reads a lot.
		if ($cat === 'system') {
			return;
		}

		$configs = DBA::select('config', ['v', 'k'], ['cat' => $cat]);
		while ($config = DBA::fetch($configs)) {
			$k = $config['k'];

			Config::setConfigValue($cat, $k, $config['v']);

			if ($cat !== 'config') {
				$this->cache[$cat][$k] = $config['v'];
				$this->in_db[$cat][$k] = true;
			}
		}
		DBA::close($configs);
	}

	public function get($cat, $k, $default_value = null, $refresh = false)
	{
		if (!$refresh) {
			// Do we have the cached value? Then return it
			if (isset($this->cache[$cat][$k])) {
				if ($this->cache[$cat][$k] === '!<unset>!') {
					return $default_value;
				} else {
					return $this->cache[$cat][$k];
				}
			}
		}

		$config = DBA::selectFirst('config', ['v'], ['cat' => $cat, 'k' => $k]);
		if (DBA::isResult($config)) {
			// manage array value
			$value = (preg_match("|^a:[0-9]+:{.*}$|s", $config['v']) ? unserialize($config['v']) : $config['v']);

			// Assign the value from the database to the cache
			$this->cache[$cat][$k] = $value;
			$this->in_db[$cat][$k] = true;
			return $value;
		} elseif (Config::getConfigValue($cat, $k) !== null) {
			// Assign the value (mostly) from config/local.config.php file to the cache
			$this->cache[$cat][$k] = Config::getConfigValue($cat, $k);
			$this->in_db[$cat][$k] = false;

			return Config::getConfigValue($cat, $k);
		} elseif (Config::getConfigValue('config', $k) !== null) {
			// Assign the value (mostly) from config/local.config.php file to the cache
			$this->cache[$k] = Config::getConfigValue('config', $k);
			$this->in_db[$k] = false;

			return Config::getConfigValue('config', $k);
		}

		$this->cache[$cat][$k] = '!<unset>!';
		$this->in_db[$cat][$k] = false;

		return $default_value;
	}

	public function set($cat, $k, $value)
	{
		// We store our setting values in a string variable.
		// So we have to do the conversion here so that the compare below works.
		// The exception are array values.
		$dbvalue = (!is_array($value) ? (string)$value : $value);

		$stored = $this->get($cat, $k, null, true);

		if (!isset($this->in_db[$cat])) {
			$this->in_db[$cat] = [];
		}
		if (!isset($this->in_db[$cat][$k])) {
			$this->in_db[$cat] = false;
		}

		if (($stored === $dbvalue) && $this->in_db[$cat][$k]) {
			return true;
		}

		Config::setConfigValue($cat, $k, $value);

		// Assign the just added value to the cache
		$this->cache[$cat][$k] = $dbvalue;

		// manage array value
		$dbvalue = (is_array($value) ? serialize($value) : $dbvalue);

		$result = DBA::update('config', ['v' => $dbvalue], ['cat' => $cat, 'k' => $k], true);

		if ($result) {
			$this->in_db[$cat][$k] = true;
		}

		return $result;
	}

	public function delete($cat, $k)
	{
		if (isset($this->cache[$cat][$k])) {
			unset($this->cache[$cat][$k]);
			unset($this->in_db[$cat][$k]);
		}

		$result = DBA::delete('config', ['cat' => $cat, 'k' => $k]);

		return $result;
	}
}
