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

use Friendica\App\Router;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Module\BaseApi;

/**
 * @see https://docs.joinmastodon.org/methods/statuses/scheduled_statuses/
 */
class ScheduledStatuses extends BaseApi
{
	public static function put(array $parameters = [])
	{
		self::checkAllowedScope(self::SCOPE_WRITE);
		$uid = self::getCurrentUserID();

		self::unsupported(Router::PUT);
	}

	public static function delete(array $parameters = [])
	{
		self::checkAllowedScope(self::SCOPE_WRITE);
		$uid = self::getCurrentUserID();

		if (empty($parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		$condtion = ['id' => $parameters['id'], 'uid' => $uid];
		$post = DBA::selectFirst('delayed-post', ['id', 'wid'], $condtion);
		if (empty($post['id'])) {
			DI::mstdnError()->RecordNotFound();
		}

		if (!DBA::delete('delayed-post', $condtion)) {
			DI::mstdnError()->RecordNotFound();
		}

		if (!DBA::delete('workerqueue', ['id' => $post['wid']])) {
			DI::mstdnError()->RecordNotFound();
		}

		System::jsonExit([]);
	}

	/**
	 * @param array $parameters
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function rawContent(array $parameters = [])
	{
		self::checkAllowedScope(self::SCOPE_READ);
		$uid = self::getCurrentUserID();

		if (isset($parameters['id'])) {
			System::jsonExit(DI::mstdnScheduledStatus()->createFromDelayedPostId($parameters['id'], $uid)->toArray());
		}

		$request = self::getRequest([
			'limit'           => 20, // Max number of results to return. Defaults to 20.
			'max_id'          => 0,  // Return results older than ID
			'since_id'        => 0,  // Return results newer than ID
			'min_id'          => 0,  // Return results immediately newer than ID
		]);

		$params = ['order' => ['id' => true], 'limit' => $request['limit']];

		$condition = ["`uid` = ? AND NOT `wid` IS NULL", $uid];

		if (!empty($request['max_id'])) {
			$condition = DBA::mergeConditions($condition, ["`uri-id` < ?", $request['max_id']]);
		}

		if (!empty($request['since_id'])) {
			$condition = DBA::mergeConditions($condition, ["`uri-id` > ?", $request['since_id']]);
		}

		if (!empty($request['min_id'])) {
			$condition = DBA::mergeConditions($condition, ["`uri-id` > ?", $request['min_id']]);
			$params['order'] = ['uri-id'];
		}

		$posts = DBA::select('delayed-post', ['id'], $condition, $params);

		$statuses = [];
		while ($post = DBA::fetch($posts)) {
			self::setBoundaries($post['id']);
			$statuses[] = DI::mstdnScheduledStatus()->createFromDelayedPostId($post['id'], $uid);
		}
		DBA::close($posts);

		if (!empty($request['min_id'])) {
			array_reverse($statuses);
		}

		self::setLinkHeader();
		System::jsonExit($statuses);
	}
}
