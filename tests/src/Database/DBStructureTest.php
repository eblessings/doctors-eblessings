<?php

namespace Friendica\Test\src\Database;

use Friendica\App;
use Friendica\Core\Config\Cache;
use Friendica\Database\DBStructure;
use Friendica\Factory;
use Friendica\Test\DatabaseTest;
use Friendica\Util\BasePath;

class DBStructureTest extends DatabaseTest
{
	public function setUp()
	{
		$basePath = BasePath::create(dirname(__DIR__) . '/../../');
		$configLoader = new Cache\ConfigCacheLoader($basePath);
		$configCache = Factory\ConfigFactory::createCache($configLoader);
		$profiler = Factory\ProfilerFactory::create($configCache);
		Factory\DBFactory::init($basePath, $configCache, $profiler, $_SERVER);
		$config = Factory\ConfigFactory::createConfig($configCache);
		Factory\ConfigFactory::createPConfig($configCache);
		$logger = Factory\LoggerFactory::create('test', $config);
		$this->app = new App($basePath, $config, $logger, $profiler, false);

		parent::setUp();
	}

	/**
	 * @small
	 */
	public function testExists() {
		$this->assertTrue(DBStructure::existsTable('config'));

		$this->assertFalse(DBStructure::existsTable('notatable'));

		$this->assertTrue(DBStructure::existsColumn('config', ['k']));
		$this->assertFalse(DBStructure::existsColumn('config', ['nonsense']));
		$this->assertFalse(DBStructure::existsColumn('config', ['k', 'nonsense']));
	}

	/**
	 * @small
	 */
	public function testRename() {
		$fromColumn = 'k';
		$toColumn = 'key';
		$fromType = 'varbinary(255) not null';
		$toType = 'varbinary(255) not null comment \'Test To Type\'';

		$this->assertTrue(DBStructure::rename('config', [ $fromColumn => [ $toColumn, $toType ]]));
		$this->assertTrue(DBStructure::existsColumn('config', [ $toColumn ]));
		$this->assertFalse(DBStructure::existsColumn('config', [ $fromColumn ]));

		$this->assertTrue(DBStructure::rename('config', [ $toColumn => [ $fromColumn, $fromType ]]));
		$this->assertTrue(DBStructure::existsColumn('config', [ $fromColumn ]));
		$this->assertFalse(DBStructure::existsColumn('config', [ $toColumn ]));
	}

	/**
	 * @small
	 */
	public function testChangePrimaryKey() {
		$oldID = 'client_id';
		$newID = 'pw';

		$this->assertTrue(DBStructure::rename('clients', [ $newID ], DBStructure::RENAME_PRIMARY_KEY));
		$this->assertTrue(DBStructure::rename('clients', [ $oldID ], DBStructure::RENAME_PRIMARY_KEY));
	}
}
