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

namespace Friendica\Module\Api\Mastodon\Accounts;

use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Item;
use Friendica\Model\Post;
use Friendica\Model\Verb;
use Friendica\Module\BaseApi;
use Friendica\Protocol\Activity;

/**
 * @see https://docs.joinmastodon.org/methods/accounts/
 */
class Statuses extends BaseApi
{
	/**
	 * @param array $parameters
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function rawContent(array $parameters = [])
	{
		if (empty($parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		$id = $parameters['id'];
		if (!DBA::exists('contact', ['id' => $id, 'uid' => 0])) {
			DI::mstdnError()->RecordNotFound();
		}

		// Show only statuses with media attached? Defaults to false.
		$only_media = (bool)!isset($_REQUEST['only_media']) ? false : ($_REQUEST['only_media'] == 'true');
		// Return results older than this id
		$max_id = (int)!isset($_REQUEST['max_id']) ? 0 : $_REQUEST['max_id'];
		// Return results newer than this id
		$since_id = (int)!isset($_REQUEST['since_id']) ? 0 : $_REQUEST['since_id'];
		// Return results immediately newer than this id
		$min_id = (int)!isset($_REQUEST['min_id']) ? 0 : $_REQUEST['min_id'];
		// Maximum number of results to return. Defaults to 20.
		$limit = (int)!isset($_REQUEST['limit']) ? 20 : $_REQUEST['limit'];

		$pinned = (bool)!isset($_REQUEST['pinned']) ? false : ($_REQUEST['pinned'] == 'true');
		$exclude_replies = (bool)!isset($_REQUEST['exclude_replies']) ? false : ($_REQUEST['exclude_replies'] == 'true');

		$params = ['order' => ['uri-id' => true], 'limit' => $limit];

		$uid = self::getCurrentUserID();

		if (!$uid) {
			$condition = ['author-id' => $id, 'private' => [Item::PUBLIC, Item::UNLISTED],
				'uid' => 0, 'network' => Protocol::FEDERATED];
		} else {
			$condition = ["`author-id` = ? AND (`uid` = 0 OR (`uid` = ? AND NOT `global`))", $id, $uid];
		}

		$condition = DBA::mergeConditions($condition, ["(`gravity` IN (?, ?) OR (`gravity` = ? AND `vid` = ?))",
			GRAVITY_PARENT, GRAVITY_COMMENT, GRAVITY_ACTIVITY, Verb::getID(Activity::ANNOUNCE)]);

		if ($only_media) {
			$condition = DBA::mergeConditions($condition, ["`uri-id` IN (SELECT `uri-id` FROM `post-media` WHERE `type` IN (?, ?, ?))",
				Post\Media::AUDIO, Post\Media::IMAGE, Post\Media::VIDEO]);
		}
	
		if (!empty($max_id)) {
			$condition = DBA::mergeConditions($condition, ["`uri-id` < ?", $max_id]);
		}

		if (!empty($since_id)) {
			$condition = DBA::mergeConditions($condition, ["`uri-id` > ?", $since_id]);
		}

		if (!empty($min_id)) {
			$condition = DBA::mergeConditions($condition, ["`uri-id` > ?", $min_id]);
			$params['order'] = ['uri-id'];
		}

		if ($pinned) {
			$condition = DBA::mergeConditions($condition, ['pinned' => true]);
		}

		if ($exclude_replies) {
			$condition = DBA::mergeConditions($condition, ['gravity' => GRAVITY_PARENT]);
		}

		$items = Post::selectForUser($uid, ['uri-id'], $condition, $params);

		$statuses = [];
		while ($item = Post::fetch($items)) {
			$statuses[] = DI::mstdnStatus()->createFromUriId($item['uri-id'], $uid);
		}
		DBA::close($items);

		if (!empty($min_id)) {
			array_reverse($statuses);
		}

		System::jsonExit($statuses);
	}
}
