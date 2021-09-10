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

namespace Friendica\Model\Contact;

use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Database\Database;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\ItemURI;

/**
 * This class provides information about user related contacts based on the "user-contact" table.
 */
class User
{
	/**
	 * Insert a user-contact for a given contact array
	 *
	 * @param array $contact
	 * @return void
	 */
	public static function insertForContactArray(array $contact)
	{
		if (!isset($contact['uid']) || (empty($contact['uri-id']) && empty($contact['url']))) {
			Logger::info('Missing contact details', ['contact' => $contact, 'callstack' => System::callstack(20)]);
			return false;
		}

		if (empty($contact['uri-id'])) {
			$contact['uri-id'] = ItemURI::getIdByURI($contact['url']);
		}

		$pcontact = Contact::selectFirst(['id'], ['uri-id' => $contact['uri-id'], 'uid' => 0]);
		if (!DBA::isResult($pcontact)) {
			Logger::info('Public contact for user not found', ['uri-id' => $contact['uri-id'], 'uid' => $contact['uid'], 'cid' => $pcontact['id']]);
			return false;
		}

		$fields = [
			'cid'     => $pcontact['id'],
			'uid'     => $contact['uid'],
			'uri-id'  => $contact['uri-id'],
			'blocked' => $contact['blocked'] ?? false,
			'ignored' => $contact['readonly'] ?? false,
		];

		$ret = DBA::insert('user-contact', $fields, Database::INSERT_IGNORE);

		Logger::info('Inserted user contact', ['uid' => $contact['uid'], 'cid' => $pcontact['id'], 'uri-id' => $contact['uri-id'], 'ret' => $ret]);

		return $ret;
	}

	/**
	 * Block contact id for user id
	 *
	 * @param int     $cid     Either public contact id or user's contact id
	 * @param int     $uid     User ID
	 * @param boolean $blocked Is the contact blocked or unblocked?
	 * @throws \Exception
	 */
	public static function setBlocked($cid, $uid, $blocked)
	{
		$cdata = Contact::getPublicAndUserContactID($cid, $uid);
		if (empty($cdata)) {
			return;
		}

		if ($cdata['user'] != 0) {
			DBA::update('contact', ['blocked' => $blocked], ['id' => $cdata['user'], 'pending' => false]);
		}

		DBA::update('user-contact', ['blocked' => $blocked], ['cid' => $cdata['public'], 'uid' => $uid], true);
	}

	/**
	 * Returns "block" state for contact id and user id
	 *
	 * @param int $cid Either public contact id or user's contact id
	 * @param int $uid User ID
	 *
	 * @return boolean is the contact id blocked for the given user?
	 * @throws \Exception
	 */
	public static function isBlocked($cid, $uid)
	{
		$cdata = Contact::getPublicAndUserContactID($cid, $uid);
		if (empty($cdata)) {
			return false;
		}

		$public_blocked = false;

		if (!empty($cdata['public'])) {
			$public_contact = DBA::selectFirst('user-contact', ['blocked'], ['cid' => $cdata['public'], 'uid' => $uid]);
			if (DBA::isResult($public_contact)) {
				$public_blocked = $public_contact['blocked'];
			}
		}

		$user_blocked = $public_blocked;

		if (!empty($cdata['user'])) {
			$user_contact = DBA::selectFirst('contact', ['blocked'], ['id' => $cdata['user'], 'pending' => false]);
			if (DBA::isResult($user_contact)) {
				$user_blocked = $user_contact['blocked'];
			}
		}

		if ($user_blocked != $public_blocked) {
			DBA::update('user-contact', ['blocked' => $user_blocked], ['cid' => $cdata['public'], 'uid' => $uid], true);
		}

		return $user_blocked;
	}

	/**
	 * Ignore contact id for user id
	 *
	 * @param int     $cid     Either public contact id or user's contact id
	 * @param int     $uid     User ID
	 * @param boolean $ignored Is the contact ignored or unignored?
	 * @throws \Exception
	 */
	public static function setIgnored($cid, $uid, $ignored)
	{
		$cdata = Contact::getPublicAndUserContactID($cid, $uid);
		if (empty($cdata)) {
			return;
		}

		if ($cdata['user'] != 0) {
			DBA::update('contact', ['readonly' => $ignored], ['id' => $cdata['user'], 'pending' => false]);
		}

		DBA::update('user-contact', ['ignored' => $ignored], ['cid' => $cdata['public'], 'uid' => $uid], true);
	}

	/**
	 * Returns "ignore" state for contact id and user id
	 *
	 * @param int $cid Either public contact id or user's contact id
	 * @param int $uid User ID
	 *
	 * @return boolean is the contact id ignored for the given user?
	 * @throws \Exception
	 */
	public static function isIgnored($cid, $uid)
	{
		$cdata = Contact::getPublicAndUserContactID($cid, $uid);
		if (empty($cdata)) {
			return false;
		}

		$public_ignored = false;

		if (!empty($cdata['public'])) {
			$public_contact = DBA::selectFirst('user-contact', ['ignored'], ['cid' => $cdata['public'], 'uid' => $uid]);
			if (DBA::isResult($public_contact)) {
				$public_ignored = $public_contact['ignored'];
			}
		}

		$user_ignored = $public_ignored;

		if (!empty($cdata['user'])) {
			$user_contact = DBA::selectFirst('contact', ['readonly'], ['id' => $cdata['user'], 'pending' => false]);
			if (DBA::isResult($user_contact)) {
				$user_ignored = $user_contact['readonly'];
			}
		}

		if ($user_ignored != $public_ignored) {
			DBA::update('user-contact', ['ignored' => $user_ignored], ['cid' => $cdata['public'], 'uid' => $uid], true);
		}

		return $user_ignored;
	}

	/**
	 * Set "collapsed" for contact id and user id
	 *
	 * @param int     $cid       Either public contact id or user's contact id
	 * @param int     $uid       User ID
	 * @param boolean $collapsed are the contact's posts collapsed or uncollapsed?
	 * @throws \Exception
	 */
	public static function setCollapsed($cid, $uid, $collapsed)
	{
		$cdata = Contact::getPublicAndUserContactID($cid, $uid);
		if (empty($cdata)) {
			return;
		}

		DBA::update('user-contact', ['collapsed' => $collapsed], ['cid' => $cdata['public'], 'uid' => $uid], true);
	}

	/**
	 * Returns "collapsed" state for contact id and user id
	 *
	 * @param int $cid Either public contact id or user's contact id
	 * @param int $uid User ID
	 *
	 * @return boolean is the contact id blocked for the given user?
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function isCollapsed($cid, $uid)
	{
		$cdata = Contact::getPublicAndUserContactID($cid, $uid);
		if (empty($cdata)) {
			return;
		}

		$collapsed = false;

		if (!empty($cdata['public'])) {
			$public_contact = DBA::selectFirst('user-contact', ['collapsed'], ['cid' => $cdata['public'], 'uid' => $uid]);
			if (DBA::isResult($public_contact)) {
				$collapsed = $public_contact['collapsed'];
			}
		}

		return $collapsed;
	}
}
