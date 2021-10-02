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
use Friendica\Model\Post;
use Friendica\Model\Verb;
use Friendica\Module\BaseApi;
use Friendica\Protocol\Activity;

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
		self::checkAllowedScope(self::SCOPE_READ);
		$uid = self::getCurrentUserID();

		if (!empty($parameters['id'])) {
			$id = $parameters['id'];
			if (!DBA::exists('notification', ['id' => $id, 'uid' => $uid])) {
				DI::mstdnError()->RecordNotFound();
			}
			System::jsonExit(DI::mstdnNotification()->createFromNotificationId($id));
		}

		$request = self::getRequest([
			'max_id'        => 0,     // Return results older than this ID
			'since_id'      => 0,     // Return results newer than this ID
			'min_id'        => 0,     // Return results immediately newer than this ID
			'limit'         => 20,    // Maximum number of results to return (default 20)
			'exclude_types' => [],    // Array of types to exclude (follow, favourite, reblog, mention, poll, follow_request)
			'account_id'    => 0,     // Return only notifications received from this account
			'with_muted'    => false, // Pleroma extension: return activities by muted (not by blocked!) users.
			'count'         => 0,     // Unknown parameter
		]);

		$params = ['order' => ['id' => true], 'limit' => $request['limit']];

		$condition = ['uid' => $uid, 'seen' => false];

		if (!empty($request['account_id'])) {
			$contact = Contact::getById($request['account_id'], ['url']);
			if (!empty($contact['url'])) {
				$condition['url'] = $contact['url'];
			}
		}

		if (in_array(Notification::TYPE_INTRODUCTION, $request['exclude_types'])) {
			$condition = DBA::mergeConditions($condition,
				["(`vid` != ? OR `type` != ? OR NOT EXISTS (SELECT `id` FROM `contact` WHERE `id` = `actor-id` AND `pending`))",
				Verb::getID(Activity::FOLLOW), Post\UserNotification::TYPE_NONE]);
		}

		if (in_array(Notification::TYPE_FOLLOW, $request['exclude_types'])) {
			$condition = DBA::mergeConditions($condition,
				["(`vid` != ? OR `type` != ? OR NOT EXISTS (SELECT `id` FROM `contact` WHERE `id` = `actor-id` AND NOT `pending`))",
				Verb::getID(Activity::FOLLOW), Post\UserNotification::TYPE_NONE]);
		}

		if (in_array(Notification::TYPE_LIKE, $request['exclude_types'])) {
			$condition = DBA::mergeConditions($condition, [
				"(NOT `vid` IN (?, ?) OR NOT `type` IN (?, ?))",
				Verb::getID(Activity::LIKE), Verb::getID(Activity::DISLIKE),
				Post\UserNotification::TYPE_DIRECT_COMMENT, Post\UserNotification::TYPE_THREAD_COMMENT
			]);
		}

		if (in_array(Notification::TYPE_RESHARE, $request['exclude_types'])) {
			$condition = DBA::mergeConditions($condition, [
				"(NOT `vid` IN (?) OR NOT `type` IN (?, ?))",
				Verb::getID(Activity::ANNOUNCE),
				Post\UserNotification::TYPE_DIRECT_COMMENT, Post\UserNotification::TYPE_THREAD_COMMENT
			]);
		}

		if (in_array(Notification::TYPE_MENTION, $request['exclude_types'])) {
			$condition = DBA::mergeConditions($condition, [
				"(NOT `vid` IN (?) OR NOT `type` IN (?, ?, ?, ?, ?))",
				Verb::getID(Activity::POST), Post\UserNotification::TYPE_EXPLICIT_TAGGED,
				Post\UserNotification::TYPE_IMPLICIT_TAGGED, Post\UserNotification::TYPE_DIRECT_COMMENT,
				Post\UserNotification::TYPE_DIRECT_THREAD_COMMENT, Post\UserNotification::TYPE_THREAD_COMMENT]);
		}

		if (in_array(Notification::TYPE_POST, $request['exclude_types'])) {
			$condition = DBA::mergeConditions($condition, ["(NOT `vid` IN (?) OR NOT `type` IN (?))",
				Verb::getID(Activity::POST), Post\UserNotification::TYPE_SHARED]);
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

		$notify = DBA::select('notification', ['id'], $condition, $params);
		while ($notification = DBA::fetch($notify)) {
			self::setBoundaries($notification['id']);
			$entry = DI::mstdnNotification()->createFromNotificationId($notification['id']);
			if (!empty($entry)) {
				$notifications[] = $entry;
			}
		}

		if (!empty($request['min_id'])) {
			array_reverse($notifications);
		}

		self::setLinkHeader();
		System::jsonExit($notifications);
	}
}
