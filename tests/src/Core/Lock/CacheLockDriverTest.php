<?php

namespace Friendica\Test\src\Core\Lock;


use Friendica\Core\Cache\ArrayCache;
use Friendica\Core\Lock\CacheLockDriver;

class CacheLockDriverTest extends LockTest
{
	/**
	 * @var \Friendica\Core\Cache\IMemoryCacheDriver
	 */
	private $cache;

	protected function getInstance()
	{
		$this->cache = new ArrayCache();
		return new CacheLockDriver($this->cache);
	}

	public function tearDown()
	{
		$this->cache->clear();
		parent::tearDown();
	}
}