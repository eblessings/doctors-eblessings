<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
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
 * Returns the most recent statuses posted by the user and the users they follow.
 *
 * @see https://developer.twitter.com/en/docs/tweets/timelines/api-reference/get-statuses-home_timeline
 */
class HomeTimeline extends BaseApi
{
	protected function rawContent(array $request = [])
	{
		BaseApi::checkAllowedScope(BaseApi::SCOPE_READ);
		$uid = BaseApi::getCurrentUserID();

		// get last network messages

		// params
		$count           = $_REQUEST['count']    ?? 20;
		$page            = $_REQUEST['page']     ?? 0;
		$since_id        = $_REQUEST['since_id'] ?? 0;
		$max_id          = $_REQUEST['max_id']   ?? 0;
		$exclude_replies = !empty($_REQUEST['exclude_replies']);
		$conversation_id = $_REQUEST['conversation_id'] ?? 0;

		$start = max(0, ($page - 1) * $count);

		$condition = ["`uid` = ? AND `gravity` IN (?, ?) AND `id` > ?",
			$uid, GRAVITY_PARENT, GRAVITY_COMMENT, $since_id];

		if ($max_id > 0) {
			$condition[0] .= " AND `id` <= ?";
			$condition[] = $max_id;
		}
		if ($exclude_replies) {
			$condition[0] .= ' AND `gravity` = ?';
			$condition[] = GRAVITY_PARENT;
		}
		if ($conversation_id > 0) {
			$condition[0] .= " AND `parent` = ?";
			$condition[] = $conversation_id;
		}

		$params   = ['order' => ['id' => true], 'limit' => [$start, $count]];
		$statuses = Post::selectForUser($uid, [], $condition, $params);

		$include_entities = filter_var($request['include_entities'] ?? false, FILTER_VALIDATE_BOOLEAN);

		$ret     = [];
		$idarray = [];
		while ($status = DBA::fetch($statuses)) {
			$ret[]     = DI::twitterStatus()->createFromUriId($status['uri-id'], $status['uid'], $include_entities)->toArray();
			$idarray[] = intval($status['id']);
		}
		DBA::close($statuses);

		if (!empty($idarray)) {
			$unseen = Post::exists(['unseen' => true, 'id' => $idarray]);
			if ($unseen) {
				Item::update(['unseen' => false], ['unseen' => true, 'id' => $idarray]);
			}
		}

		$this->response->exit('statuses', ['status' => $ret], $this->parameters['extension'] ?? null, Contact::getPublicIdByUserId($uid));
	}
}
