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

use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\DI;
use Friendica\Model\Item;
use Friendica\Model\Post;
use Friendica\Module\BaseApi;

/**
 * @see https://docs.joinmastodon.org/methods/statuses/
 */
class Statuses extends BaseApi
{
	public static function post(array $parameters = [])
	{
		$data = self::getJsonPostData();
		self::unsupported('post');
	}

	public static function delete(array $parameters = [])
	{
		self::login();
		$uid = self::getCurrentUserID();

		if (empty($parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		$item = Post::selectFirstForUser($uid, ['id'], ['uri-id' => $parameters['id'], 'uid' => $uid]);
		if (empty($item['id'])) {
			DI::mstdnError()->RecordNotFound();
		}

		if (!Item::markForDeletionById($item['id'])) {
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
		if (empty($parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		System::jsonExit(DI::mstdnStatus()->createFromUriId($parameters['id'], self::getCurrentUserID()));
	}
}
