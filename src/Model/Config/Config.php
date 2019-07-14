<?php

namespace Friendica\Model\Config;


class Config extends DbaConfig
{
	/**
	 * Loads all configuration values and returns the loaded category as an array.
	 *
	 * @param string $cat The category of the configuration values to load
	 *
	 * @return array
	 */
	public function load(string $cat = null)
	{
		$return = [];

		if (empty($cat)) {
			$configs = $this->dba->select('config', ['cat', 'v', 'k']);
		} else {
			$configs = $this->dba->select('config', ['cat', 'v', 'k'], ['cat' => $cat]);
		}

		while ($config = $this->dba->fetch($configs)) {

			$key   = $config['k'];
			$value = $this->toConfigValue($config['v']);

			// just save it in case it is set
			if (isset($value)) {
				$return[$config['cat']][$key] = $value;
			}
		}
		$this->dba->close($configs);

		return $return;
	}

	/**
	 * Get a particular system-wide config variable given the category name
	 * ($cat) and a key ($key).
	 *
	 * Note: Boolean variables are defined as 0/1 in the database
	 *
	 * @param string $cat The category of the configuration value
	 * @param string $key The configuration key to query
	 *
	 * @return null|mixed Stored value or null if it does not exist
	 */
	public function get(string $cat, string $key)
	{
		if (!$this->isConnected()) {
			return null;
		}

		$config = $this->dba->selectFirst('config', ['v'], ['cat' => $cat, 'k' => $key]);
		if ($this->dba->isResult($config)) {
			$value = $this->toConfigValue($config['v']);

			// just return it in case it is set
			if (isset($value)) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Stores a config value ($value) in the category ($cat) under the key ($key).
	 *
	 * Note: Please do not store booleans - convert to 0/1 integer values!
	 *
	 * @param string $cat   The category of the configuration value
	 * @param string $key   The configuration key to set
	 * @param mixed  $value The value to store
	 *
	 * @return bool Operation success
	 */
	public function set(string $cat, string $key, $value)
	{
		if (!$this->isConnected()) {
			return false;
		}

		// We store our setting values in a string variable.
		// So we have to do the conversion here so that the compare below works.
		// The exception are array values.
		$compare_value = (!is_array($value) ? (string)$value : $value);
		$stored_value  = $this->get($cat, $key);

		if (isset($stored_value) && ($stored_value === $compare_value)) {
			return true;
		}

		$dbvalue = $this->toDbValue($value);

		$result = $this->dba->update('config', ['v' => $dbvalue], ['cat' => $cat, 'k' => $key], true);

		return $result;
	}

	/**
	 * Removes the configured value from the stored cache
	 * and removes it from the database.
	 *
	 * @param string $cat The category of the configuration value
	 * @param string $key The configuration key to delete
	 *
	 * @return bool Operation success
	 */
	public function delete(string $cat, string $key)
	{
		if (!$this->isConnected()) {
			return false;
		}

		return $this->dba->delete('config', ['cat' => $cat, 'k' => $key]);
	}
}
