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

namespace Friendica\Module\Api\Twitter\Statuses;

use Friendica\Core\Logger;
use Friendica\Module\BaseApi;
use Friendica\DI;
use Friendica\Model\Contact;
use Friendica\Model\Item;

/**
 * Destroys a specific status.
 *
 * @see https://developer.twitter.com/en/docs/tweets/post-and-engage/api-reference/post-statuses-destroy-id
 */
class Destroy extends BaseApi
{
	protected function post(array $request = [], array $post = [])
	{
		BaseApi::checkAllowedScope(BaseApi::SCOPE_READ);
		$uid = BaseApi::getCurrentUserID();

		if (empty($this->parameters['id'])) {
			$id = intval($request['id'] ?? 0);
		} else {
			$id = (int)$this->parameters['id'];
		}

		$this->logger->notice('API: api_statuses_destroy: ' . $id);

		$include_entities = strtolower(($request['include_entities'] ?? 'false') == 'true');

		$ret = DI::twitterStatus()->createFromItemId($id, $uid, $include_entities)->toArray();

		Item::deleteForUser(['id' => $id], $uid);

		$this->response->exit('status', ['status' => $ret], $this->parameters['extension'] ?? null, Contact::getPublicIdByUserId($uid));
	}
}
