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

namespace Friendica\Module\Api\Twitter\DirectMessages;

use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Contact;
use Friendica\Model\Mail;
use Friendica\Module\BaseApi;
use Friendica\Network\HTTPException\NotFoundException;

/**
 * Sends a new direct message.
 *
 * @see https://developer.twitter.com/en/docs/direct-messages/sending-and-receiving/api-reference/new-message
 */
class NewDM extends BaseApi
{
	protected function rawContent(array $request = [])
	{
		BaseApi::checkAllowedScope(BaseApi::SCOPE_WRITE);
		$uid = BaseApi::getCurrentUserID();

		if (empty($request['text']) || empty($request['screen_name']) && empty($request['user_id'])) {
			return;
		}

		$cid = BaseApi::getContactIDForSearchterm($request['screen_name'] ?? '', $request['profileurl'] ?? '', $request['user_id'] ?? 0, 0);
		if (empty($cid)) {
			throw new NotFoundException('Recipient not found');
		}

		$replyto = '';
		if (!empty($request['replyto'])) {
			$mail    = DBA::selectFirst('mail', ['parent-uri', 'title'], ['uid' => $uid, 'id' => $request['replyto']]);
			$replyto = $mail['parent-uri'];
			$sub     = $mail['title'];
		} else {
			if (!empty($request['title'])) {
				$sub = $request['title'];
			} else {
				$sub = ((strlen($request['text']) > 10) ? substr($request['text'], 0, 10) . '...' : $request['text']);
			}
		}

		$cdata = Contact::getPublicAndUserContactID($cid, $uid);

		$id = Mail::send($cdata['user'], $request['text'], $sub, $replyto);

		if ($id > -1) {
			$ret = DI::twitterDirectMessage()->createFromMailId($id, $uid, $request['getText'] ?? '');
		} else {
			$ret = ['error' => $id];
		}

		$this->response->exit('direct-messages', ['direct_message' => $ret], $this->parameters['extension'] ?? null, Contact::getPublicIdByUserId($uid));
	}
}
