<?php

namespace Friendica\Api\Entity\Mastodon;

use Friendica\Api\BaseEntity;
use Friendica\Core\Config;
use Friendica\Core\Protocol;
use Friendica\Database\DBA;

/**
 * Class Stats
 *
 * @see https://docs.joinmastodon.org/api/entities/#stats
 */
class Stats extends BaseEntity
{
	/** @var int */
	protected $user_count = 0;
	/** @var int */
	protected $status_count = 0;
	/** @var int */
	protected $domain_count = 0;

	/**
	 * Creates a stats record
	 *
	 * @return Stats
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function get() {
		$stats = new Stats();
		if (!empty(DI::config()->get('system', 'nodeinfo'))) {
			$stats->user_count = intval(DI::config()->get('nodeinfo', 'total_users'));
			$stats->status_count = DI::config()->get('nodeinfo', 'local_posts') + DI::config()->get('nodeinfo', 'local_comments');
			$stats->domain_count = DBA::count('gserver', ["`network` in (?, ?) AND `last_contact` >= `last_failure`", Protocol::DFRN, Protocol::ACTIVITYPUB]);
		}
		return $stats;
	}
}
