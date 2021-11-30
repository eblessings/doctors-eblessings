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
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	protected function rawContent(array $request = [])
	{
		$uid = self::getCurrentUserID();

		if (empty($this->parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		$id = $this->parameters['id'];
		if (!DBA::exists('contact', ['id' => $id, 'uid' => 0])) {
			DI::mstdnError()->RecordNotFound();
		}

		$request = $this->getRequest([
			'only_media'      => false, // Show only statuses with media attached? Defaults to false.
			'max_id'          => 0,     // Return results older than this id
			'since_id'        => 0,     // Return results newer than this id
			'min_id'          => 0,     // Return results immediately newer than this id
			'limit'           => 20,    // Maximum number of results to return. Defaults to 20.
			'pinned'          => false, // Only pinned posts
			'exclude_replies' => false, // Don't show comments
			'with_muted'      => false, // Pleroma extension: return activities by muted (not by blocked!) users.
			'exclude_reblogs' => false, // Undocumented parameter
			'tagged'          => false, // Undocumented parameter
		], $request);

		$params = ['order' => ['uri-id' => true], 'limit' => $request['limit']];

		if (!$uid) {
			$condition = ['author-id' => $id, 'private' => [Item::PUBLIC, Item::UNLISTED],
				'uid' => 0, 'network' => Protocol::FEDERATED];
		} else {
			$condition = ["`author-id` = ? AND (`uid` = 0 OR (`uid` = ? AND NOT `global`))", $id, $uid];
		}

		$condition = DBA::mergeConditions($condition, ["(`gravity` IN (?, ?) OR (`gravity` = ? AND `vid` = ?))",
			GRAVITY_PARENT, GRAVITY_COMMENT, GRAVITY_ACTIVITY, Verb::getID(Activity::ANNOUNCE)]);

		if ($request['only_media']) {
			$condition = DBA::mergeConditions($condition, ["`uri-id` IN (SELECT `uri-id` FROM `post-media` WHERE `type` IN (?, ?, ?))",
				Post\Media::AUDIO, Post\Media::IMAGE, Post\Media::VIDEO]);
		}
	
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

		if ($request['pinned']) {
			$condition = DBA::mergeConditions($condition, ['pinned' => true]);
		}

		if ($request['exclude_replies']) {
			$condition = DBA::mergeConditions($condition, ['gravity' => GRAVITY_PARENT]);
		}

		$items = Post::selectForUser($uid, ['uri-id'], $condition, $params);

		$statuses = [];
		while ($item = Post::fetch($items)) {
			self::setBoundaries($item['uri-id']);
			$statuses[] = DI::mstdnStatus()->createFromUriId($item['uri-id'], $uid);
		}
		DBA::close($items);

		if (!empty($request['min_id'])) {
			array_reverse($statuses);
		}

		self::setLinkHeader();
		System::jsonExit($statuses);
	}
}
