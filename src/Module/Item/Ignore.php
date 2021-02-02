<?php
/**
 * @copyright Copyright (C) 2020, Friendica
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

namespace Friendica\Module\Item;

use Friendica\BaseModule;
use Friendica\Core\Session;
use Friendica\Core\System;
use Friendica\DI;
use Friendica\Model\Post;
use Friendica\Network\HTTPException;

/**
 * Module for ignoring threads or user items
 */
class Ignore extends BaseModule
{
	public static function rawContent(array $parameters = [])
	{
		$l10n = DI::l10n();

		if (!Session::isAuthenticated()) {
			throw new HttpException\ForbiddenException($l10n->t('Access denied.'));
		}

		if (empty($parameters['id'])) {
			throw new HTTPException\BadRequestException();
		}

		$itemId = intval($parameters['id']);

		$dba = DI::dba();

		$thread = Post::selectFirst(['uri-id', 'uid'], ['id' => $itemId, 'gravity' => GRAVITY_PARENT]);
		if (!$dba->isResult($thread)) {
			throw new HTTPException\NotFoundException();
		}

		$ignored = !Post\ThreadUser::getIgnored($thread['uri-id'], local_user());

		if (in_array($thread['uid'], [0, local_user()])) {
			Post\ThreadUser::setIgnored($thread['uri-id'], local_user(), $ignored);
		} else {
			throw new HTTPException\BadRequestException();
		}

		// See if we've been passed a return path to redirect to
		$return_path = $_REQUEST['return'] ?? '';
		if (!empty($return_path)) {
			$rand = '_=' . time();
			if (strpos($return_path, '?')) {
				$rand = "&$rand";
			} else {
				$rand = "?$rand";
			}

			DI::baseUrl()->redirect($return_path . $rand);
		}

		$return = [
			'status'  => 'ok',
			'item_id' => $itemId,
			'verb'    => 'ignore',
			'state'   => $ignored,
		];

		System::jsonExit($return);
	}
}
