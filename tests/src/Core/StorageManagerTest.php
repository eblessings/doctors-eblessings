<?php

namespace Friendica\Test\src\Core;

use Dice\Dice;
use Friendica\Core\Config\IConfiguration;
use Friendica\Core\Config\PreloadConfiguration;
use Friendica\Core\Session\ISession;
use Friendica\Core\StorageManager;
use Friendica\Database\Database;
use Friendica\Factory\ConfigFactory;
use Friendica\Model\Config\Config;
use Friendica\Model\Storage;
use Friendica\Core\Session;
use Friendica\Test\DatabaseTest;
use Friendica\Test\Util\Database\StaticDatabase;
use Friendica\Test\Util\VFSTrait;
use Friendica\Util\ConfigFileLoader;
use Friendica\Util\Profiler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Friendica\Test\Util\SampleStorageBackend;

class StorageManagerTest extends DatabaseTest
{
	/** @var Database */
	private $dba;
	/** @var IConfiguration */
	private $config;
	/** @var LoggerInterface */
	private $logger;
	/** @var Dice */
	private $dice;

	use VFSTrait;

	public function setUp()
	{
		parent::setUp();

		$this->setUpVfsDir();

		$this->logger = new NullLogger();
		$this->dice   = (new Dice())
			->addRules(include __DIR__ . '/../../../static/dependencies.config.php')
			->addRule(Database::class, ['instanceOf' => StaticDatabase::class, 'shared' => true])
			->addRule(ISession::class, ['instanceOf' => Session\Memory::class, 'shared' => true, 'call' => null]);

		$profiler = \Mockery::mock(Profiler::class);
		$profiler->shouldReceive('saveTimestamp')->withAnyArgs()->andReturn(true);

		// load real config to avoid mocking every config-entry which is related to the Database class
		$configFactory = new ConfigFactory();
		$loader        = new ConfigFileLoader($this->root->url());
		$configCache   = $configFactory->createCache($loader);

		$this->dba = new StaticDatabase($configCache, $profiler, $this->logger);

		$configModel  = new Config($this->dba);
		$this->config = new PreloadConfiguration($configCache, $configModel);
	}

	/**
	 * Test plain instancing first
	 */
	public function testInstance()
	{
		$storageManager = new StorageManager($this->dba, $this->config, $this->logger, $this->dice);

		$this->assertInstanceOf(StorageManager::class, $storageManager);
	}

	public function dataStorages()
	{
		return [
			'empty'          => [
				'name'        => '',
				'assert'      => null,
				'assertName'  => '',
				'userBackend' => false,
			],
			'database'       => [
				'name'        => Storage\Database::NAME,
				'assert'      => Storage\Database::class,
				'assertName'  => Storage\Database::NAME,
				'userBackend' => true,
			],
			'filesystem'     => [
				'name'        => Storage\Filesystem::NAME,
				'assert'      => Storage\Filesystem::class,
				'assertName'  => Storage\Filesystem::NAME,
				'userBackend' => true,
			],
			'systemresource' => [
				'name'        => Storage\SystemResource::NAME,
				'assert'      => Storage\SystemResource::class,
				'assertName'  => Storage\SystemResource::NAME,
				// false here, because SystemResource isn't meant to be a user backend,
				// it's for system resources only
				'userBackend' => false,
			],
			'invalid'        => [
				'name'        => 'invalid',
				'assert'      => null,
				'assertName'  => '',
				'userBackend' => false,
			],
		];
	}

	/**
	 * Test the getByName() method
	 *
	 * @dataProvider dataStorages
	 */
	public function testGetByName($name, $assert, $assertName)
	{
		$storageManager = new StorageManager($this->dba, $this->config, $this->logger, $this->dice);

		$storage = $storageManager->getByName($name);

		if (!empty($assert)) {
			$this->assertInstanceOf(Storage\IStorage::class, $storage);
			$this->assertInstanceOf($assert, $storage);
			$this->assertEquals($name, $storage::getName());
		} else {
			$this->assertNull($storage);
		}
		$this->assertEquals($assertName, $storage);
	}

	/**
	 * Test the isValidBackend() method
	 *
	 * @dataProvider dataStorages
	 */
	public function testIsValidBackend($name, $assert, $assertName, $userBackend)
	{
		$storageManager = new StorageManager($this->dba, $this->config, $this->logger, $this->dice);

		$this->assertEquals($userBackend, $storageManager->isValidBackend($name));
	}

	/**
	 * Test the method listBackends() with default setting
	 */
	public function testListBackends()
	{
		$storageManager = new StorageManager($this->dba, $this->config, $this->logger, $this->dice);

		$this->assertEquals(StorageManager::DEFAULT_BACKENDS, $storageManager->listBackends());
	}

	/**
	 * Test the method getBackend()
	 *
	 * @dataProvider dataStorages
	 */
	public function testGetBackend($name, $assert, $assertName, $userBackend)
	{
		$storageManager = new StorageManager($this->dba, $this->config, $this->logger, $this->dice);

		$this->assertNull($storageManager->getBackend());

		if ($userBackend) {
			$storageManager->setBackend($name);

			$this->assertInstanceOf($assert, $storageManager->getBackend());
		}
	}

	/**
	 * Test the method getBackend() with a pre-configured backend
	 *
	 * @dataProvider dataStorages
	 */
	public function testPresetBackend($name, $assert, $assertName, $userBackend)
	{
		$this->config->set('storage', 'name', $name);

		$storageManager = new StorageManager($this->dba, $this->config, $this->logger, $this->dice);

		if ($userBackend) {
			$this->assertInstanceOf($assert, $storageManager->getBackend());
		} else {
			$this->assertNull($storageManager->getBackend());
		}
	}

	/**
	 * Tests the register and unregister methods for a new backend storage class
	 *
	 * Uses a sample storage for testing
	 *
	 * @see SampleStorageBackend
	 */
	public function testRegisterUnregisterBackends()
	{
		$storageManager = new StorageManager($this->dba, $this->config, $this->logger, $this->dice);

		$this->assertTrue($storageManager->register(SampleStorageBackend::class));

		$this->assertEquals(array_merge(StorageManager::DEFAULT_BACKENDS, [
			'Sample Storage' => SampleStorageBackend::class,
		]), $storageManager->listBackends());
		$this->assertEquals(array_merge(StorageManager::DEFAULT_BACKENDS, [
			'Sample Storage' => SampleStorageBackend::class,
		]), $this->config->get('storage', 'backends'));

		$this->assertTrue($storageManager->setBackend(SampleStorageBackend::NAME));
		$this->assertEquals(SampleStorageBackend::NAME, $this->config->get('storage', 'name'));

		$this->assertInstanceOf(SampleStorageBackend::class, $storageManager->getBackend());

		$this->assertTrue($storageManager->unregister(SampleStorageBackend::class));
		$this->assertEquals(StorageManager::DEFAULT_BACKENDS, $this->config->get('storage', 'backends'));
		$this->assertEquals(StorageManager::DEFAULT_BACKENDS, $storageManager->listBackends());

		$this->assertNull($storageManager->getBackend());
		$this->assertNull($this->config->get('storage', 'name'));
	}

	/**
	 * Test moving data to a new storage (currently testing db & filesystem)
	 *
	 * @dataProvider dataStorages
	 */
	public function testMoveStorage($name, $assert, $assertName, $userBackend)
	{
		if (!$userBackend) {
			return;
		}

		$this->loadFixture(__DIR__ . '/../../datasets/storage/database.fixture.php', $this->dba);

		$storageManager = new StorageManager($this->dba, $this->config, $this->logger, $this->dice);
		$storage = $storageManager->getByName($name);
		$storageManager->move($storage);

		$photos = $this->dba->select('photo', ['backend-ref', 'backend-class', 'id', 'data']);

		while ($photo = $this->dba->fetch($photos)) {

			$this->assertEmpty($photo['data']);

			$storage = $storageManager->getByName($photo['backend-class']);
			$data = $storage->get($photo['backend-ref']);

			$this->assertNotEmpty($data);
		}
	}
}
