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

namespace Friendica\Module\Api\Twitter\Statuses;

use Friendica\Database\DBA;
use Friendica\Module\BaseApi;
use Friendica\DI;
use Friendica\Model\Contact;
use Friendica\Model\Item;
use Friendica\Model\Post;

/**
 * Returns the most recent statuses from public users.
 */
class PublicTimeline extends BaseApi
{
	public function rawContent()
	{
		BaseApi::checkAllowedScope(BaseApi::SCOPE_READ);
		$uid = BaseApi::getCurrentUserID();

		// get last network messages

		// params
		$count = $_REQUEST['count'] ?? 20;
		$page = $_REQUEST['page'] ?? 1;
		$since_id = $_REQUEST['since_id'] ?? 0;
		$max_id = $_REQUEST['max_id'] ?? 0;
		$exclude_replies = (!empty($_REQUEST['exclude_replies']) ? 1 : 0);
		$conversation_id = $_REQUEST['conversation_id'] ?? 0;

		$start = max(0, ($page - 1) * $count);

		if ($exclude_replies && !$conversation_id) {
			$condition = ["`gravity` = ? AND `id` > ? AND `private` = ? AND `wall` AND NOT `author-hidden`",
				GRAVITY_PARENT, $since_id, Item::PUBLIC];

			if ($max_id > 0) {
				$condition[0] .= " AND `id` <= ?";
				$condition[] = $max_id;
			}

			$params = ['order' => ['id' => true], 'limit' => [$start, $count]];
			$statuses = Post::selectForUser($uid, [], $condition, $params);
		} else {
			$condition = ["`gravity` IN (?, ?) AND `id` > ? AND `private` = ? AND `wall` AND `origin` AND NOT `author-hidden`",
				GRAVITY_PARENT, GRAVITY_COMMENT, $since_id, Item::PUBLIC];

			if ($max_id > 0) {
				$condition[0] .= " AND `id` <= ?";
				$condition[] = $max_id;
			}
			if ($conversation_id > 0) {
				$condition[0] .= " AND `parent` = ?";
				$condition[] = $conversation_id;
			}

			$params = ['order' => ['id' => true], 'limit' => [$start, $count]];
			$statuses = Post::selectForUser($uid, [], $condition, $params);
		}

		$include_entities = strtolower(($_REQUEST['include_entities'] ?? 'false') == 'true');

		$ret = [];
		while ($status = DBA::fetch($statuses)) {
			$ret[] = DI::twitterStatus()->createFromUriId($status['uri-id'], $status['uid'], $include_entities)->toArray();
		}
		DBA::close($statuses);

		DI::apiResponse()->exit('statuses', ['status' => $ret], $this->parameters['extension'] ?? null, Contact::getPublicIdByUserId($uid));
	}
}
