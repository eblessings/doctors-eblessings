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

namespace Friendica\Module\Api\Friendica\Notification;

use Exception;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Notification;
use Friendica\Model\Post;
use Friendica\Module\BaseApi;
use Friendica\Network\HTTPException\BadRequestException;
use Friendica\Network\HTTPException\InternalServerErrorException;
use Friendica\Network\HTTPException\NotFoundException;

/**
 * Set notification as seen and returns associated item (if possible)
 *
 * POST request with 'id' param as notification id
 */
class Seen extends BaseApi
{
	protected function rawContent(array $request = [])
	{
		BaseApi::checkAllowedScope(BaseApi::SCOPE_WRITE);
		$uid = BaseApi::getCurrentUserID();

		if (DI::args()->getArgc() !== 4) {
			throw new BadRequestException('Invalid argument count');
		}

		$id = intval($_REQUEST['id'] ?? 0);

		try {
			$Notify = DI::notify()->selectOneById($id);
			if ($Notify->uid !== $uid) {
				throw new NotFoundException();
			}

			if ($Notify->uriId) {
				DI::notification()->setAllSeenForUser($Notify->uid, ['target-uri-id' => $Notify->uriId]);
			}

			$Notify->setSeen();
			DI::notify()->save($Notify);

			if ($Notify->otype === Notification\ObjectType::ITEM) {
				$item = Post::selectFirstForUser($uid, [], ['id' => $Notify->iid, 'uid' => $uid]);
				if (DBA::isResult($item)) {
					$include_entities = strtolower(($_REQUEST['include_entities'] ?? 'false') == 'true');

					// we found the item, return it to the user
					$ret  = [DI::twitterStatus()->createFromUriId($item['uri-id'], $item['uid'], $include_entities)->toArray()];
					$data = ['status' => $ret];
					DI::apiResponse()->exit('statuses', $data, $this->parameters['extension'] ?? null);
				}
				// the item can't be found, but we set the notification as seen, so we count this as a success
			}

			DI::apiResponse()->exit('statuses', ['result' => 'success'], $this->parameters['extension'] ?? null);
		} catch (NotFoundException $e) {
			throw new BadRequestException('Invalid argument', $e);
		} catch (Exception $e) {
			throw new InternalServerErrorException('Internal Server exception', $e);
		}
	}
}
