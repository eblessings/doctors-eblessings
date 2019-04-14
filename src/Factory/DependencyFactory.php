<?php

namespace Friendica\Factory;

use Friendica\App;
use Friendica\Factory;
use Friendica\Util\BasePath;
use Friendica\Util\BaseURL;
use Friendica\Util\Config;

class DependencyFactory
{
	/**
	 * Setting all default-dependencies of a friendica execution
	 *
	 * @param string $channel   The channel of this execution
	 * @param string $directory The base directory
	 * @param bool   $isBackend True, if it's a backend execution, otherwise false (Default true)
	 *
	 * @return App The application
	 *
	 * @throws \Exception
	 */
	public static function setUp($channel, $directory, $isBackend = true)
	{
		$basePath = BasePath::create($directory, $_SERVER);
		$mode = new App\Mode($basePath);
		$router = new App\Router();
		$configLoader = new Config\ConfigFileLoader($basePath, $mode);
		$configCache = Factory\ConfigFactory::createCache($configLoader);
		$profiler = Factory\ProfilerFactory::create($configCache);
		Factory\DBFactory::init($configCache, $profiler, $_SERVER);
		$config = Factory\ConfigFactory::createConfig($configCache);
		// needed to call PConfig::init()
		Factory\ConfigFactory::createPConfig($configCache);
		$logger = Factory\LoggerFactory::create($channel, $config, $profiler);
		Factory\LoggerFactory::createDev($channel, $config, $profiler);
		$baseURL = new BaseURL($config, $_SERVER);

		return new App($config, $mode, $router, $baseURL, $logger, $profiler, $isBackend);
	}
}
