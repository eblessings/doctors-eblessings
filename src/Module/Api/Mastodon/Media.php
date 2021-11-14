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
	public static function post()
	{
		self::checkAllowedScope(self::SCOPE_WRITE);
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

	public static function put()
	{
		self::checkAllowedScope(self::SCOPE_WRITE);
		$uid = self::getCurrentUserID();

		$request = self::getRequest([
			'file'        => [], // The file to be attached, using multipart form data.
			'thumbnail'   => [], // The custom thumbnail of the media to be attached, using multipart form data.
			'description' => '', // A plain-text description of the media, for accessibility purposes.
			'focus'       => '', // Two floating points (x,y), comma-delimited ranging from -1.0 to 1.0
		]);

		if (empty(static::$parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		$photo = Photo::selectFirst(['resource-id'], ['id' => static::$parameters['id'], 'uid' => $uid]);
		if (empty($photo['resource-id'])) {
			DI::mstdnError()->RecordNotFound();
		}

		Photo::update(['desc' => $request['description']], ['resource-id' => $photo['resource-id']]);

		System::jsonExit(DI::mstdnAttachment()->createFromPhoto(static::$parameters['id']));
	}

	/**
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function rawContent()
	{
		self::checkAllowedScope(self::SCOPE_READ);
		$uid = self::getCurrentUserID();

		if (empty(static::$parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		$id = static::$parameters['id'];
		if (!Photo::exists(['id' => $id, 'uid' => $uid])) {
			DI::mstdnError()->RecordNotFound();
		}

		System::jsonExit(DI::mstdnAttachment()->createFromPhoto($id));
	}
}
