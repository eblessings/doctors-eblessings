<?php


namespace Friendica\Test\src\Core\Lock;

use Friendica\Core\Cache\CacheDriverFactory;
use Friendica\Core\Lock\CacheLockDriver;

/**
 * @requires extension redis
 */
class RedisCacheLockDriverTest extends LockTest
{
	protected function getInstance()
	{
		$this->configCache
			->shouldReceive('get')
			->with('system', 'redis_host', NULL)
			->andReturn('localhost');

		$this->configCache
			->shouldReceive('get')
			->with('system', 'redis_port', NULL)
			->andReturn(null);

		return new CacheLockDriver(CacheDriverFactory::create('redis'));
	}
}
