<?php

namespace Friendica\Factory;

use Friendica\Core\Config\Cache;
use Friendica\Database;
use Friendica\Util\Profiler;

class DBFactory
{
	/**
	 * Initialize the DBA connection
	 *
	 * @param string             $basePath    The basepath of the application
	 * @param Cache\IConfigCache $configCache The configuration cache
	 * @param Profiler           $profiler    The profiler
	 * @param array              $server      The $_SERVER variables
	 *
	 * @throws \Exception if connection went bad
	 *
	 * @todo refactor basedir during https://github.com/friendica/friendica/issues/6720
	 */
	public static function init($basePath, Cache\IConfigCache $configCache, Profiler $profiler, array $server)
	{
		if (Database\DBA::connected()) {
			return;
		}

		$db_host = $configCache->get('database', 'hostname');
		$db_user = $configCache->get('database', 'username');
		$db_pass = $configCache->get('database', 'password');
		$db_data = $configCache->get('database', 'database');
		$charset = $configCache->get('database', 'charset');

		// Use environment variables for mysql if they are set beforehand
		if (!empty($server['MYSQL_HOST'])
			&& !empty($server['MYSQL_USERNAME'] || !empty($server['MYSQL_USER']))
			&& $server['MYSQL_PASSWORD'] !== false
			&& !empty($server['MYSQL_DATABASE']))
		{
			$db_host = $server['MYSQL_HOST'];
			if (!empty($server['MYSQL_PORT'])) {
				$db_host .= ':' . $server['MYSQL_PORT'];
			}
			if (!empty($server['MYSQL_USERNAME'])) {
				$db_user = $server['MYSQL_USERNAME'];
			} else {
				$db_user = $server['MYSQL_USER'];
			}
			$db_pass = (string) $server['MYSQL_PASSWORD'];
			$db_data = $server['MYSQL_DATABASE'];
		}

		if (Database\DBA::connect($basePath, $configCache, $profiler, $db_host, $db_user, $db_pass, $db_data, $charset)) {
			// Loads DB_UPDATE_VERSION constant
			Database\DBStructure::definition($basePath, false);
		}

		unset($db_host, $db_user, $db_pass, $db_data, $charset);
	}
}
