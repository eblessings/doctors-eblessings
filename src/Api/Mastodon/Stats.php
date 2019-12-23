<?php

namespace Friendica\Api\Mastodon;

use Friendica\Core\Config;
use Friendica\Core\Protocol;
use Friendica\Database\DBA;

/**
 * Class Stats
 *
 * @see https://docs.joinmastodon.org/api/entities/#stats
 */
class Stats
{
	/** @var int */
	var $user_count;
	/** @var int */
	var $status_count;
	/** @var int */
	var $domain_count;

	/**
	 * Creates a stats record
	 *
	 * @return Stats
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function get() {
		$stats = new Stats();
		if (!empty(Config::get('system', 'nodeinfo'))) {
			$stats->user_count = intval(Config::get('nodeinfo', 'total_users'));
			$stats->status_count = Config::get('nodeinfo', 'local_posts') + Config::get('nodeinfo', 'local_comments');
			$stats->domain_count = DBA::count('gserver', ["`network` in (?, ?) AND `last_contact` >= `last_failure`", Protocol::DFRN, Protocol::ACTIVITYPUB]);
		}
		return $stats;
	}
}
