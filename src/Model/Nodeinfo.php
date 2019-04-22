<?php

namespace Friendica\Model;

use Friendica\BaseObject;
use Friendica\Core\Addon;
use Friendica\Database\DBA;
use Friendica\Util\Network;

/**
 * Model interaction for the nodeinfo
 */
class Nodeinfo extends BaseObject
{
	/**
	 * Updates the info about the current node
	 *
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function update()
	{
		$app = self::getApp();
		$config = $app->getConfig();
		$logger = $app->getLogger();

		// If the addon 'statistics_json' is enabled then disable it and activate nodeinfo.
		if (Addon::isEnabled('statistics_json')) {
			$config->set('system', 'nodeinfo', true);

			$addon = 'statistics_json';
			$addons = $config->get('system', 'addon');

			if ($addons) {
				$addons_arr = explode(',', str_replace(' ', '', $addons));

				$idx = array_search($addon, $addons_arr);
				if ($idx !== false) {
					unset($addons_arr[$idx]);
					Addon::uninstall($addon);
					$config->set('system', 'addon', implode(', ', $addons_arr));
				}
			}
		}

		if (empty($config->get('system', 'nodeinfo'))) {
			return;
		}

		$logger->info('cron_start');

		$userStats = User::getStatistics();

		$config->set('nodeinfo', 'total_users', $userStats['total_users']);
		$config->set('nodeinfo', 'active_users_halfyear', $userStats['active_users_halfyear']);
		$config->set('nodeinfo', 'active_users_monthly', $userStats['active_users_monthly']);

		$logger->debug('user statistics', $userStats);

		$local_posts = DBA::count('thread', ["`wall` AND NOT `deleted` AND `uid` != 0"]);
		$config->set('nodeinfo', 'local_posts', $local_posts);
		$logger->debug('thread statistics', ['local_posts' => $local_posts]);

		$local_comments = DBA::count('item', ["`origin` AND `id` != `parent` AND NOT `deleted` AND `uid` != 0"]);
		$config->set('nodeinfo', 'local_comments', $local_comments);
		$logger->debug('item statistics', ['local_comments' => $local_comments]);

		// Now trying to register
		$url = 'http://the-federation.info/register/' . $app->getHostName();
		$logger->debug('Check registering url', ['url' => $url]);
		$ret = Network::fetchUrl($url);
		$logger->debug('Check registering answer', ['answer' => $ret]);

		$logger->info('cron_end');
	}
}
