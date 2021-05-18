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

namespace Friendica\Module\Api\Mastodon;

use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Contact;
use Friendica\Model\Notification;
use Friendica\Module\BaseApi;

/**
 * @see https://docs.joinmastodon.org/methods/notifications/
 */
class Notifications extends BaseApi
{
	/**
	 * @param array $parameters
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function rawContent(array $parameters = [])
	{
		self::login(self::SCOPE_READ);
		$uid = self::getCurrentUserID();

		if (!empty($parameters['id'])) {
			$id = $parameters['id'];
			if (!DBA::exists('notify', ['id' => $id, 'uid' => $uid])) {
				DI::mstdnError()->RecordNotFound();
			}
			System::jsonExit(DI::mstdnNotification()->createFromNotifyId($id));
		}

		$request = self::getRequest([
			'max_id'        => 0,     // Return results older than this ID
			'since_id'      => 0,     // Return results newer than this ID
			'min_id'        => 0,     // Return results immediately newer than this ID
			'limit'         => 20,    // Maximum number of results to return (default 20)
			'exclude_types' => [],    // Array of types to exclude (follow, favourite, reblog, mention, poll, follow_request)
			'account_id'    => 0,     // Return only notifications received from this account
			'with_muted'    => false, // Unknown parameter
			'count'         => 0,     // Unknown parameter
		]);

		$params = ['order' => ['id' => true], 'limit' => $request['limit']];

		$condition = ['uid' => $uid, 'seen' => false, 'type' => []];

		if (!empty($request['account_id'])) {
			$contact = Contact::getById($request['account_id'], ['url']);
			if (!empty($contact['url'])) {
				$condition['url'] = $contact['url'];
			}
		}

		if (!in_array('follow_request', $request['exclude_types'])) {
			$condition['type'] = array_merge($condition['type'], [Notification\Type::INTRO]);
		}

		if (!in_array('mention', $request['exclude_types'])) {
			$condition['type'] = array_merge($condition['type'],
				[Notification\Type::WALL, Notification\Type::COMMENT, Notification\Type::MAIL, Notification\Type::TAG_SELF, Notification\Type::POKE]);
		}

		if (!in_array('status', $request['exclude_types'])) {
			$condition['type'] = array_merge($condition['type'], [Notification\Type::SHARE]);
		}

		if (!empty($request['max_id'])) {
			$condition = DBA::mergeConditions($condition, ["`id` < ?", $request['max_id']]);
		}

		if (!empty($request['since_id'])) {
			$condition = DBA::mergeConditions($condition, ["`id` > ?", $request['since_id']]);
		}

		if (!empty($request['min_id'])) {
			$condition = DBA::mergeConditions($condition, ["`id` > ?", $request['min_id']]);

			$params['order'] = ['id'];
		}

		$notifications = [];

		$notify = DBA::select('notify', ['id'], $condition, $params);
		while ($notification = DBA::fetch($notify)) {
			$notifications[] = DI::mstdnNotification()->createFromNotifyId($notification['id']);
		}

		if (!empty($request['min_id'])) {
			array_reverse($notifications);
		}

		System::jsonExit($notifications);
	}
}
