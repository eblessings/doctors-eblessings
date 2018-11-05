<?php


namespace Friendica\Test\src\Core\Lock;

use Friendica\Core\Cache\CacheDriverFactory;
use Friendica\Core\Lock\CacheLockDriver;

/**
 * @requires extension Memcache
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MemcacheCacheLockDriverTest extends LockTest
{
	protected function getInstance()
	{
		return new CacheLockDriver(CacheDriverFactory::create('memcache'));
	}
}
