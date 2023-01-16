<?php
/**
 * @copyright Copyright (C) 2010-2023, the Friendica project
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

namespace Friendica\Test\src\Core\Cache;

use Friendica\Core\Cache;
use Friendica\Core\Config\Factory\Config;
use Friendica\Database\Definition\DbaDefinition;
use Friendica\Database\Definition\ViewDefinition;
use Friendica\Test\DatabaseTestTrait;
use Friendica\Test\Util\Database\StaticDatabase;
use Friendica\Test\Util\VFSTrait;
use Friendica\Util\Profiler;
use Mockery;
use Psr\Log\NullLogger;

class DatabaseCacheTest extends CacheTest
{
	use DatabaseTestTrait;
	use VFSTrait;

	protected function setUp(): void
	{
		$this->setUpVfsDir();

		$this->setUpDb();

		parent::setUp();
	}

	protected function getInstance()
	{
		$profiler = Mockery::mock(Profiler::class);
		$profiler->shouldReceive('startRecording');
		$profiler->shouldReceive('stopRecording');
		$profiler->shouldReceive('saveTimestamp')->withAnyArgs()->andReturn(true);

		// load real config to avoid mocking every config-entry which is related to the Database class
		$configFactory     = new Config();
		$configFileManager = (new Config())->createConfigFileManager($this->root->url(), []);
		$configCache       = $configFactory->createCache($configFileManager);
		$config            = new \Friendica\Core\Config\Model\Config($configFileManager, $configCache);

		$dbaDefinition  = (new DbaDefinition($configCache->get('system', 'basepath')))->load();
		$viewDefinition = (new ViewDefinition($configCache->get('system', 'basepath')))->load();

		$dba = new StaticDatabase($config, $profiler, $dbaDefinition, $viewDefinition, new NullLogger());

		$this->cache = new Cache\Type\DatabaseCache('database', $dba);
		return $this->cache;
	}

	protected function tearDown(): void
	{
		$this->cache->clear(false);

		$this->tearDownDb();

		parent::tearDown();
	}
}
