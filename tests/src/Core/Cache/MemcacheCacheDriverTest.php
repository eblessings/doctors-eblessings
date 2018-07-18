<?php


namespace Friendica\Test\src\Core\Cache;


use Friendica\Core\Cache\CacheDriverFactory;

class MemcacheCacheDriverTest extends MemoryCacheTest
{
	/**
	 * @var \Friendica\Core\Cache\IMemoryCacheDriver
	 */
	private $cache;

	protected function getInstance()
	{
		if (class_exists('Memcache')) {
			try {
				$this->cache = CacheDriverFactory::create('memcache');
			} catch (\Exception $exception) {
				throw new \Exception("Memcache - TestCase failed: " . $exception->getMessage(), $exception->getCode(), $exception);
			}
			return $this->cache;
		} else {
			$this->markTestSkipped('Memcache driver isn\'t available');
			return null;
		}
	}

	public function tearDown()
	{
		if (class_exists('Memcache')) {
			$this->cache->clear(false);
		}
		parent::tearDown();
	}
}
