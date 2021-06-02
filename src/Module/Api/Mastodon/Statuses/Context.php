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

namespace Friendica\Module\Api\Mastodon\Statuses;

use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Post;
use Friendica\Module\BaseApi;

/**
 * @see https://docs.joinmastodon.org/methods/statuses/
 */
class Context extends BaseApi
{
	/**
	 * @param array $parameters
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function rawContent(array $parameters = [])
	{
		$uid = self::getCurrentUserID();

		if (empty($parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		$request = self::getRequest([
			'limit'    => 40, // Maximum number of results to return. Defaults to 40.
		]);

		$id = $parameters['id'];

		$parent = Post::selectFirst(['parent-uri-id'], ['uri-id' => $id]);
		if (!DBA::isResult($parent)) {
			DI::mstdnError()->RecordNotFound();
		}

		$parents  = [];
		$children = [];

		$posts = Post::select(['uri-id', 'thr-parent-id'],
			['parent-uri-id' => $parent['parent-uri-id'], 'gravity' => [GRAVITY_PARENT, GRAVITY_COMMENT]], [], false);
		while ($post = Post::fetch($posts)) {
			if ($post['uri-id'] == $post['thr-parent-id']) {
				continue;
			}
			$parents[$post['uri-id']] = $post['thr-parent-id'];

			$children[$post['thr-parent-id']][] = $post['uri-id'];
		}
		DBA::close($posts);

		$statuses = ['ancestors' => [], 'descendants' => []];

		$ancestors = [];
		foreach (self::getParents($id, $parents) as $ancestor) {
			$ancestors[] = $ancestor;
		}

		asort($ancestors);
		foreach (array_slice($ancestors, 0, $request['limit']) as $ancestor) {
			$statuses['ancestors'][] = DI::mstdnStatus()->createFromUriId($ancestor, $uid);;
		}

		$descendants = [];
		foreach (self::getChildren($id, $children) as $descendant) {
			$descendants[] = $descendant; 
		}

		asort($descendants);
		foreach (array_slice($descendants, 0, $request['limit']) as $descendant) {
			$statuses['descendants'][] = DI::mstdnStatus()->createFromUriId($descendant, $uid);
		}

		System::jsonExit($statuses);
	}

	private static function getParents(int $id, array $parents, array $list = [])
	{
		if (!empty($parents[$id])) {
			$list[] = $parents[$id];

			$list = self::getParents($parents[$id], $parents, $list);
		}
		return $list;
	}

	private static function getChildren(int $id, array $children, array $list = [])
	{
		if (!empty($children[$id])) {
			foreach ($children[$id] as $child) {
				$list[] = $child;

				$list = self::getChildren($child, $children, $list);
			}
		}
		return $list;
	}
}
