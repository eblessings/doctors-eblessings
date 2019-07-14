<?php
namespace Friendica\Test\src\Database;

use Friendica\App;
use Friendica\Core\Config;
use Friendica\Database\DBA;
use Friendica\Factory;
use Friendica\Test\DatabaseTest;
use Friendica\Util\BaseURL;

class DBATest extends DatabaseTest
{
	public function setUp()
	{
		$configModel = new \Friendica\Model\Config\Config(self::$dba);
		$config = Factory\ConfigFactory::createConfig(self::$configCache, $configModel);
		Factory\ConfigFactory::createPConfig(self::$configCache, new Config\Cache\PConfigCache());
		$logger = Factory\LoggerFactory::create('test', self::$dba, $config, self::$profiler);
		$baseUrl = new BaseURL($config, $_SERVER);
		$router = new App\Router();
		$this->app = new App(self::$dba, $config, self::$mode, $router, $baseUrl, $logger, self::$profiler, false);

		parent::setUp();

		// Default config
		Config::set('config', 'hostname', 'localhost');
		Config::set('system', 'throttle_limit_day', 100);
		Config::set('system', 'throttle_limit_week', 100);
		Config::set('system', 'throttle_limit_month', 100);
		Config::set('system', 'theme', 'system_theme');
	}

	/**
	 * @small
	 */
	public function testExists() {

		$this->assertTrue(DBA::exists('config', []));
		$this->assertFalse(DBA::exists('notable', []));

		$this->assertTrue(DBA::exists('config', null));
		$this->assertFalse(DBA::exists('notable', null));

		$this->assertTrue(DBA::exists('config', ['k' => 'hostname']));
		$this->assertFalse(DBA::exists('config', ['k' => 'nonsense']));
	}
}
