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

namespace Friendica\Factory\Api\Friendica;

use Friendica\App\BaseURL;
use Friendica\BaseFactory;
use Friendica\Database\DBA;
use Friendica\Model\Post;
use Friendica\Network\HTTPException;
use Friendica\Protocol\Activity;
use Psr\Log\LoggerInterface;
use Friendica\Factory\Api\Twitter\User as TwitterUser;

class Activities extends BaseFactory
{
	/** @var BaseURL */
	private $baseUrl;
	/** @var twitterUser entity */
	private $twitterUser;

	public function __construct(LoggerInterface $logger, BaseURL $baseURL, TwitterUser $twitteruser)
	{
		parent::__construct($logger);

		$this->twitterUser = $twitteruser;
		$this->baseUrl     = $baseURL;
	}

	/**
	 * @param int $uriId Uri-ID of the item
	 * @return Array
	 * @throws HTTPException\InternalServerErrorException
	 */
	public function createFromUriId(int $uriId, int $uid): array
	{
		$activities = [
			'like'        => [],
			'dislike'     => [],
			'attendyes'   => [],
			'attendno'    => [],
			'attendmaybe' => [],
			'announce'    => [],
		];

		$condition = ['uid' => $uid, 'thr-parent-id' => $uriId, 'gravity' => GRAVITY_ACTIVITY];

		$ret = Post::selectForUser($uid, ['author-id', 'verb'], $condition);

		while ($parent_item = Post::fetch($ret)) {
			// get user data and add it to the array of the activity
			$user = $this->twitterUser->createFromContactId($parent_item['author-id'], $uid)->toArray();
			switch ($parent_item['verb']) {
				case Activity::LIKE:
					$activities['like'][] = $user;
					break;
				case Activity::DISLIKE:
					$activities['dislike'][] = $user;
					break;
				case Activity::ATTEND:
					$activities['attendyes'][] = $user;
					break;
				case Activity::ATTENDNO:
					$activities['attendno'][] = $user;
					break;
				case Activity::ATTENDMAYBE:
					$activities['attendmaybe'][] = $user;
					break;
				case Activity::ANNOUNCE:
					$activities['announce'][] = $user;
					break;
				default:
					break;
			}
		}

		DBA::close($ret);

		return $activities;
	}
}
