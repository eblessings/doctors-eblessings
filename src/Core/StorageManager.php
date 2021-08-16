<?php
/**
 * @copyright Copyright (C) 2010-2021, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Core;

use Exception;
use Friendica\Core\Config\IConfig;
use Friendica\Database\Database;
use Friendica\Model\Storage;
use Friendica\Network\HTTPException\InternalServerErrorException;
use Psr\Log\LoggerInterface;

/**
 * Manage storage backends
 *
 * Core code uses this class to get and set current storage backend class.
 * Addons use this class to register and unregister additional backends.
 */
class StorageManager
{
	// Default tables to look for data
	const TABLES = ['photo', 'attach'];

	// Default storage backends
	const DEFAULT_BACKENDS = [
		Storage\Filesystem::NAME => Storage\Filesystem::class,
		Storage\Database::NAME   => Storage\Database::class,
	];

	private $backends;

	/**
	 * @var Storage\IStorage[] A local cache for storage instances
	 */
	private $backendInstances = [];

	/** @var Database */
	private $dba;
	/** @var IConfig */
	private $config;
	/** @var LoggerInterface */
	private $logger;
	/** @var L10n */
	private $l10n;

	/** @var Storage\IWritableStorage */
	private $currentBackend;

	/**
	 * @param Database        $dba
	 * @param IConfig         $config
	 * @param LoggerInterface $logger
	 * @param L10n            $l10n
	 *
	 * @throws Storage\InvalidClassStorageException in case the active backend class is invalid
	 * @throws Storage\StorageException in case of unexpected errors during the active backend class loading
	 */
	public function __construct(Database $dba, IConfig $config, LoggerInterface $logger, L10n $l10n)
	{
		$this->dba      = $dba;
		$this->config   = $config;
		$this->logger   = $logger;
		$this->l10n     = $l10n;
		$this->backends = $config->get('storage', 'backends', self::DEFAULT_BACKENDS);

		$currentName = $this->config->get('storage', 'name');

		// you can only use user backends as a "default" backend, so the second parameter is true
		$this->currentBackend = $this->getWritableStorageByName($currentName);
	}

	/**
	 * Return current storage backend class
	 *
	 * @return Storage\IWritableStorage
	 */
	public function getBackend()
	{
		return $this->currentBackend;
	}

	/**
	 * Returns a writable storage backend class by registered name
	 *
	 * @param string $name Backend name
	 *
	 * @return Storage\IWritableStorage
	 *
	 * @throws Storage\InvalidClassStorageException in case there's no backend class for the name
	 * @throws Storage\StorageException in case of an unexpected failure during the hook call
	 */
	public function getWritableStorageByName(string $name): Storage\IWritableStorage
	{
		$storage = $this->getByName($name, $this->backends);
		if ($storage instanceof Storage\IWritableStorage) {
			return $storage;
		} else {
			throw new Storage\InvalidClassStorageException(sprintf('Backend %s is not writable', $name));
		}
	}

	/**
	 * Return storage backend class by registered name
	 *
	 * @param string     $name Backend name
	 * @param array|null $validBackends possible, manual override of the valid backends
	 *
	 * @return Storage\IStorage
	 *
	 * @throws Storage\InvalidClassStorageException in case there's no backend class for the name
	 * @throws Storage\StorageException in case of an unexpected failure during the hook call
	 */
	public function getByName(string $name, array $validBackends = null): Storage\IStorage
	{
		// If there's no cached instance create a new instance
		if (!isset($this->backendInstances[$name])) {
			// If the current name isn't a valid backend (or the SystemResource instance) create it
			if ($this->isValidBackend($name, $validBackends)) {
				switch ($name) {
					// Try the filesystem backend
					case Storage\Filesystem::getName():
						$this->backendInstances[$name] = new Storage\Filesystem($this->config, $this->l10n);
						break;
					// try the database backend
					case Storage\Database::getName():
						$this->backendInstances[$name] = new Storage\Database($this->dba);
						break;
					// at least, try if there's an addon for the backend
					case Storage\SystemResource::getName():
						$this->backendInstances[$name] = new Storage\SystemResource();
						break;
					case Storage\ExternalResource::getName():
						$this->backendInstances[$name] = new Storage\ExternalResource();
						break;
					default:
						$data = [
							'name'    => $name,
							'storage' => null,
						];
						try {
							Hook::callAll('storage_instance', $data);
							if (($data['storage'] ?? null) instanceof Storage\IStorage) {
								$this->backendInstances[$data['name'] ?? $name] = $data['storage'];
							} else {
								throw new Storage\InvalidClassStorageException(sprintf('Backend %s was not found', $name));
							}
						} catch (InternalServerErrorException $exception) {
							throw new Storage\StorageException(sprintf('Failed calling hook::storage_instance for backend %s', $name), $exception);
						}
						break;
				}
			} else {
				throw new Storage\InvalidClassStorageException(sprintf('Backend %s is not valid', $name));
			}
		}

		return $this->backendInstances[$name];
	}

	/**
	 * Checks, if the storage is a valid backend
	 *
	 * @param string|null $name          The name or class of the backend
	 * @param array|null  $validBackends Possible, valid backends to check
	 *
	 * @return boolean True, if the backend is a valid backend
	 */
	public function isValidBackend(string $name = null, array $validBackends = null): bool
	{
		$validBackends = $validBackends ?? array_merge($this->backends,
				[
					Storage\SystemResource::getName()   => '',
					Storage\ExternalResource::getName() => ''
				]);
		return array_key_exists($name, $validBackends);
	}

	/**
	 * Set current storage backend class
	 *
	 * @param Storage\IWritableStorage $storage The storage class
	 *
	 * @return boolean True, if the set was successful
	 */
	public function setBackend(Storage\IWritableStorage $storage): bool
	{
		if ($this->config->set('storage', 'name', $storage::getName())) {
			$this->currentBackend = $storage;
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Get registered backends
	 *
	 * @return array
	 */
	public function listBackends(): array
	{
		return $this->backends;
	}

	/**
	 * Register a storage backend class
	 *
	 * You have to register the hook "storage_instance" as well to make this class work!
	 *
	 * @param string $class Backend class name
	 *
	 * @return boolean True, if the registration was successful
	 */
	public function register(string $class): bool
	{
		if (is_subclass_of($class, Storage\IStorage::class)) {
			/** @var Storage\IStorage $class */

			$backends                    = $this->backends;
			$backends[$class::getName()] = $class;

			if ($this->config->set('storage', 'backends', $backends)) {
				$this->backends = $backends;
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	/**
	 * Unregister a storage backend class
	 *
	 * @param string $class Backend class name
	 *
	 * @return boolean True, if unregistering was successful
	 */
	public function unregister(string $class): bool
	{
		if (is_subclass_of($class, Storage\IStorage::class)) {
			/** @var Storage\IStorage $class */

			unset($this->backends[$class::getName()]);

			if ($this->currentBackend instanceof $class) {
				$this->config->set('storage', 'name', null);
				$this->currentBackend = null;
			}

			return $this->config->set('storage', 'backends', $this->backends);
		} else {
			return false;
		}
	}

	/**
	 * Move up to 5000 resources to storage $dest
	 *
	 * Copy existing data to destination storage and delete from source.
	 * This method cannot move to legacy in-table `data` field.
	 *
	 * @param Storage\IWritableStorage $destination Destination storage class name
	 * @param array                    $tables      Tables to look in for resources. Optional, defaults to ['photo', 'attach']
	 * @param int                      $limit       Limit of the process batch size, defaults to 5000
	 *
	 * @return int Number of moved resources
	 * @throws Storage\StorageException
	 * @throws Exception
	 */
	public function move(Storage\IWritableStorage $destination, array $tables = self::TABLES, int $limit = 5000): int
	{
		if (!$this->isValidBackend($destination, $this->backends)) {
			throw new Storage\StorageException(sprintf("Can't move to storage backend '%s'", $destination::getName()));
		}

		$moved = 0;
		foreach ($tables as $table) {
			// Get the rows where backend class is not the destination backend class
			$resources = $this->dba->select(
				$table,
				['id', 'data', 'backend-class', 'backend-ref'],
				['`backend-class` IS NULL or `backend-class` != ?', $destination::getName()],
				['limit' => $limit]
			);

			while ($resource = $this->dba->fetch($resources)) {
				$id        = $resource['id'];
				$sourceRef = $resource['backend-ref'];
				$source    = null;

				try {
					$source = $this->getWritableStorageByName($resource['backend-class'] ?? '');
					$this->logger->info('Get data from old backend.', ['oldBackend' => $source, 'oldReference' => $sourceRef]);
					$data = $source->get($sourceRef);
				} catch (Storage\InvalidClassStorageException $exception) {
					$this->logger->info('Get data from DB resource field.', ['oldReference' => $sourceRef]);
					$data = $resource['data'];
				} catch (Storage\ReferenceStorageException $exception) {
					$this->logger->info('Invalid source reference.', ['oldBackend' => $source, 'oldReference' => $sourceRef]);
					continue;
				}

				$this->logger->info('Save data to new backend.', ['newBackend' => $destination::getName()]);
				$destinationRef = $destination->put($data);
				$this->logger->info('Saved data.', ['newReference' => $destinationRef]);

				if ($destinationRef !== '') {
					$this->logger->info('update row');
					if ($this->dba->update($table, ['backend-class' => $destination::getName(), 'backend-ref' => $destinationRef, 'data' => ''], ['id' => $id])) {
						if (!empty($source)) {
							$this->logger->info('Delete data from old backend.', ['oldBackend' => $source, 'oldReference' => $sourceRef]);
							$source->delete($sourceRef);
						}
						$moved++;
					}
				}
			}

			$this->dba->close($resources);
		}

		return $moved;
	}
}
