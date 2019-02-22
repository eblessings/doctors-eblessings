<?php
namespace Friendica\Core\Config\Adapter;

use Friendica\Database\DBA;

/**
 * JustInTime User Configuration Adapter
 *
 * Default PConfig Adapter. Provides the best performance for pages loading few configuration variables.
 *
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
class JITPConfigAdapter extends AbstractDbaConfigAdapter implements IPConfigAdapter
{
	private $in_db;

	/**
	 * {@inheritdoc}
	 */
	public function load($uid, $cat)
	{
		$return = [];

		if (!$this->isConnected()) {
			return $return;
		}

		$pconfigs = DBA::select('pconfig', ['v', 'k'], ['cat' => $cat, 'uid' => $uid]);
		if (DBA::isResult($pconfigs)) {
			while ($pconfig = DBA::fetch($pconfigs)) {
				$key = $pconfig['k'];
				$value = $this->toConfigValue($pconfig['v']);

				// The value was in the db, so don't check it again (unless you have to)
				$this->in_db[$uid][$cat][$key] = true;

				if (isset($value)) {
					$return[$key] = $value;
				}
			}
		} else if ($cat != 'config') {
			// Negative caching
			$return = null;
		}
		DBA::close($pconfigs);

		return [$cat => $return];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get($uid, $cat, $key)
	{
		if (!$this->isConnected()) {
			return null;
		}

		// The value was in the db, so don't check it again (unless you have to)
		$this->in_db[$uid][$cat][$key] = true;

		$pconfig = DBA::selectFirst('pconfig', ['v'], ['uid' => $uid, 'cat' => $cat, 'k' => $key]);
		if (DBA::isResult($pconfig)) {
			$value = $this->toConfigValue($pconfig['v']);

			if (isset($value)) {
				return $value;
			}
		}

		$this->in_db[$uid][$cat][$key] = false;
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function set($uid, $cat, $key, $value)
	{
		if (!$this->isConnected()) {
			return false;
		}

		// We store our setting values in a string variable.
		// So we have to do the conversion here so that the compare below works.
		// The exception are array values.
		$dbvalue = (!is_array($value) ? (string)$value : $value);

		$stored = $this->get($uid, $cat, $key);

		if (!isset($this->in_db[$uid])) {
			$this->in_db[$uid] = [];
		}
		if (!isset($this->in_db[$uid][$cat])) {
			$this->in_db[$uid][$cat] = [];
		}
		if (!isset($this->in_db[$uid][$cat][$key])) {
			$this->in_db[$uid][$cat][$key] = false;
		}

		if (($stored === $dbvalue) && $this->in_db[$uid][$cat][$key]) {
			return true;
		}

		// manage array value
		$dbvalue = (is_array($value) ? serialize($value) : $dbvalue);

		$result = DBA::update('pconfig', ['v' => $dbvalue], ['uid' => $uid, 'cat' => $cat, 'k' => $key], true);

		$this->in_db[$uid][$cat][$key] = $result;

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($uid, $cat, $key)
	{
		if (!$this->isConnected()) {
			return false;
		}

		if (isset($this->in_db[$uid][$cat][$key])) {
			unset($this->in_db[$uid][$cat][$key]);
		}

		return DBA::delete('pconfig', ['uid' => $uid, 'cat' => $cat, 'k' => $key]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function isLoaded($uid, $cat, $key)
	{
		if (!$this->isConnected()) {
			return false;
		}

		return (isset($this->in_db[$uid][$cat][$key])) && $this->in_db[$uid][$cat][$key];
	}
}
