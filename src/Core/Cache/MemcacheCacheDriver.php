<?php

namespace Friendica\Core\Cache;

use Friendica\Core\Cache;

use Exception;
use Memcache;

/**
 * Memcache Cache Driver
 *
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
class MemcacheCacheDriver extends AbstractCacheDriver implements IMemoryCacheDriver
{
	use TraitCompareSet;
	use TraitCompareDelete;

	/**
	 * @var Memcache
	 */
	private $memcache;

	/**
	 * @param string $memcache_host
	 * @param int    $memcache_port
	 * @throws Exception
	 */
	public function __construct($memcache_host, $memcache_port)
	{
		if (!class_exists('Memcache', false)) {
			throw new Exception('Memcache class isn\'t available');
		}

		$this->memcache = new Memcache();

		if (!$this->memcache->connect($memcache_host, $memcache_port)) {
			throw new Exception('Expected Memcache server at ' . $memcache_host . ':' . $memcache_port . ' isn\'t available');
		}
	}

	/**
	 * (@inheritdoc)
	 */
	public function getAllKeys($prefix = null)
	{
		$list = [];
		$allSlabs = $this->memcache->getExtendedStats('slabs');
		foreach ($allSlabs as $slabs) {
			foreach (array_keys($slabs) as $slabId) {
				$cachedump = $this->memcache->getExtendedStats('cachedump', (int)$slabId);
				foreach ($cachedump as $keys => $arrVal) {
					if (!is_array($arrVal)) {
						continue;
					}
					$list = array_merge($list, array_keys($arrVal));
				}
			}
		}

		$list = $this->getOriginalKeys($list);

		return $this->filterPrefix($list, $prefix);
	}

	/**
	 * (@inheritdoc)
	 */
	public function get($key)
	{
		$return = null;
		$cachekey = $this->getCacheKey($key);

		// We fetch with the hostname as key to avoid problems with other applications
		$cached = $this->memcache->get($cachekey);

		// @see http://php.net/manual/en/memcache.get.php#84275
		if (is_bool($cached) || is_double($cached) || is_long($cached)) {
			return $return;
		}

		$value = @unserialize($cached);

		// Only return a value if the serialized value is valid.
		// We also check if the db entry is a serialized
		// boolean 'false' value (which we want to return).
		if ($cached === serialize(false) || $value !== false) {
			$return = $value;
		}

		return $return;
	}

	/**
	 * (@inheritdoc)
	 */
	public function set($key, $value, $ttl = Cache::FIVE_MINUTES)
	{
		$cachekey = $this->getCacheKey($key);

		// We store with the hostname as key to avoid problems with other applications
		if ($ttl > 0) {
			return $this->memcache->set(
				$cachekey,
				serialize($value),
				MEMCACHE_COMPRESSED,
				time() + $ttl
			);
		} else {
			return $this->memcache->set(
				$cachekey,
				serialize($value),
				MEMCACHE_COMPRESSED
			);
		}
	}

	/**
	 * (@inheritdoc)
	 */
	public function delete($key)
	{
		$cachekey = $this->getCacheKey($key);
		return $this->memcache->delete($cachekey);
	}

	/**
	 * (@inheritdoc)
	 */
	public function clear($outdated = true)
	{
		if ($outdated) {
			return true;
		} else {
			return $this->memcache->flush();
		}
	}

	/**
	 * (@inheritdoc)
	 */
	public function add($key, $value, $ttl = Cache::FIVE_MINUTES)
	{
		$cachekey = $this->getCacheKey($key);
		return $this->memcache->add($cachekey, serialize($value), MEMCACHE_COMPRESSED, $ttl);
	}
}
