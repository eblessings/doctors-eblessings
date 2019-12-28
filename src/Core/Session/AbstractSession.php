<?php


namespace Friendica\Core\Session;

/**
 * Contains the base methods for $_SESSION interaction
 */
class AbstractSession
{
	/**
	 * {@inheritDoc}
	 */
	public function start()
	{
		return $this;
	}

	/**
	 * {@inheritDoc}}
	 */
	public function exists(string $name)
	{
		return isset($_SESSION[$name]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get(string $name, $defaults = null)
	{
		return $_SESSION[$name] ?? $defaults;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set(string $name, $value)
	{
		$_SESSION[$name] = $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function setMultiple(array $values)
	{
		$_SESSION = $values + $_SESSION;
	}

	/**
	 * {@inheritDoc}
	 */
	public function remove(string $name)
	{
		unset($_SESSION[$name]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear()
	{
		$_SESSION = [];
	}
}
