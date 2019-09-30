<?php


namespace Friendica\Test\src\Core\Lock;

use Friendica\Core\Cache\MemcacheCache;
use Friendica\Core\Config\Configuration;
use Friendica\Core\Lock\CacheLock;

/**
 * @requires extension Memcache
 */
class MemcacheCacheLockTest extends LockTest
{
	protected function getInstance()
	{
		$configMock = \Mockery::mock(Configuration::class);

		$host = $_SERVER['MEMCACHE_HOST'] ?? 'localhost';

		$configMock
			->shouldReceive('get')
			->with('system', 'memcache_host')
			->andReturn($host);
		$configMock
			->shouldReceive('get')
			->with('system', 'memcache_port')
			->andReturn(11211);

		$lock = null;

		try {
			$cache = new MemcacheCache($host, $configMock);
			$lock = new CacheLock($cache);
		} catch (\Exception $e) {
			$this->markTestSkipped('Memcache is not available');
		}

		return $lock;
	}
}
