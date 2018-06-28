<?php

namespace Friendica\Core\Lock;

/**
 * Class AbstractLockDriver
 *
 * @package Friendica\Core\Lock
 *
 * @brief Basic class for Locking with common functions (local acquired locks, releaseAll, ..)
 */
abstract class AbstractLockDriver implements ILockDriver
{
	/**
	 * @var array The local acquired locks
	 */
	protected $acquiredLocks = [];

	/**
	 * @brief Check if we've locally acquired a lock
	 *
	 * @param string key The Name of the lock
	 * @return bool      Returns true if the lock is set
	 */
	protected function hasAcquiredLock(string $key): bool {
		return isset($this->acquireLock[$key]);
	}

	/**
	 * @brief Mark a locally acquired lock
	 *
	 * @param string $key The Name of the lock
	 */
	protected function markAcquire(string $key) {
		$this->acquiredLocks[$key] = true;
	}

	/**
	 * @brief Mark a release of a locally acquired lock
	 *
	 * @param string $key The Name of the lock
	 */
	protected function markRelease(string $key) {
		unset($this->acquiredLocks[$key]);
	}

	/**
	 * @brief Releases all lock that were set by us
	 *
	 * @return void
	 */
	public function releaseAll() {
		foreach ($this->acquiredLocks as $acquiredLock) {
			$this->releaseLock($acquiredLock);
		}
	}
}
