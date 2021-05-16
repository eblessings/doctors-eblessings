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
use Friendica\Model\Photo;
use Friendica\Module\BaseApi;

/**
 * @see https://docs.joinmastodon.org/methods/statuses/media/
 */
class Media extends BaseApi
{
	public static function post(array $parameters = [])
	{
		self::login(self::SCOPE_WRITE);
		$uid = self::getCurrentUserID();

		Logger::info('Photo post', ['request' => $_REQUEST, 'files' => $_FILES]);

		if (empty($_FILES['file'])) {
			DI::mstdnError()->UnprocessableEntity();
		}
	
		$media = Photo::upload($uid, $_FILES['file']);
		if (empty($media)) {
			DI::mstdnError()->UnprocessableEntity();
		}

		Logger::info('Uploaded photo', ['media' => $media]);

		System::jsonExit(DI::mstdnAttachment()->createFromPhoto($media['id']));
	}

	public static function put(array $parameters = [])
	{
		self::login(self::SCOPE_WRITE);
		$uid = self::getCurrentUserID();

		$data = self::getPutData();

		if (empty($parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		$photo = Photo::selectFirst(['resource-id'], ['id' => $parameters['id'], 'uid' => $uid]);
		if (empty($photo['resource-id'])) {
			DI::mstdnError()->RecordNotFound();
		}

		Photo::update(['desc' => $data['description'] ?? ''], ['resource-id' => $photo['resource-id']]);

		System::jsonExit(DI::mstdnAttachment()->createFromPhoto($parameters['id']));
	}

	/**
	 * @param array $parameters
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function rawContent(array $parameters = [])
	{
		self::login(self::SCOPE_READ);
		$uid = self::getCurrentUserID();

		if (empty($parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		$id = $parameters['id'];
		if (!Photo::exists(['id' => $id, 'uid' => $uid])) {
			DI::mstdnError()->RecordNotFound();
		}

		System::jsonExit(DI::mstdnAttachment()->createFromPhoto($id));
	}
}
