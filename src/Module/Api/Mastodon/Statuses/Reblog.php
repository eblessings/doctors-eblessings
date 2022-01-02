<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
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

use Friendica\Content\ContactSelector;
use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Item;
use Friendica\Model\Post;
use Friendica\Module\BaseApi;

/**
 * @see https://docs.joinmastodon.org/methods/statuses/
 */
class Reblog extends BaseApi
{
	protected function post(array $request = [])
	{
		self::checkAllowedScope(self::SCOPE_WRITE);
		$uid = self::getCurrentUserID();

		if (empty($this->parameters['id'])) {
			DI::mstdnError()->UnprocessableEntity();
		}

		$item = Post::selectFirstForUser($uid, ['id', 'network'], ['uri-id' => $this->parameters['id'], 'uid' => [$uid, 0]]);
		if (!DBA::isResult($item)) {
			DI::mstdnError()->RecordNotFound();
		}

		if (!in_array($item['network'], [Protocol::DFRN, Protocol::ACTIVITYPUB, Protocol::TWITTER])) {
			DI::mstdnError()->UnprocessableEntity(DI::l10n()->t("Posts from %s can't be shared", ContactSelector::networkToName($item['network'])));
		}

		Item::performActivity($item['id'], 'announce', $uid);

		System::jsonExit(DI::mstdnStatus()->createFromUriId($this->parameters['id'], $uid)->toArray());
	}
}
