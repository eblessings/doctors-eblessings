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

namespace Friendica\Model;

use Friendica\Core\Logger;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Navigation\Notifications\Entity;
use Friendica\Object\Api\Mastodon\Notification;
use Minishlink\WebPush\VAPID;

class Subscription
{
	/**
	 * Select a subscription record exists
	 *
	 * @param int   $applicationid
	 * @param int   $uid
	 * @param array $fields
	 *
	 * @return bool Does it exist?
	 */
	public static function select(int $applicationid, int $uid, array $fields = [])
	{
		return DBA::selectFirst('subscription', $fields, ['application-id' => $applicationid, 'uid' => $uid]);
	}

	/**
	 * Check if a subscription record exists
	 *
	 * @param int   $applicationid
	 * @param int   $uid
	 *
	 * @return bool Does it exist?
	 */
	public static function exists(int $applicationid, int $uid)
	{
		return DBA::exists('subscription', ['application-id' => $applicationid, 'uid' => $uid]);
	}

	/**
	 * Update a subscription record
	 *
	 * @param int   $applicationid
	 * @param int   $uid
	 * @param array $fields subscription fields
	 *
	 * @return bool result of update
	 */
	public static function update(int $applicationid, int $uid, array $fields)
	{
		return DBA::update('subscription', $fields, ['application-id' => $applicationid, 'uid' => $uid]);
	}

	/**
	 * Insert or replace a subscription record
	 *
	 * @param array $fields subscription fields
	 *
	 * @return bool result of replace
	 */
	public static function replace(array $fields)
	{
		return DBA::replace('subscription', $fields);
	}

	/**
	 * Delete a subscription record
	 *
	 * @param int $applicationid
	 * @param int $uid
	 * @return bool
	 */
	public static function delete(int $applicationid, int $uid)
	{
		return DBA::delete('subscription', ['application-id' => $applicationid, 'uid' => $uid]);
	}

	/**
	 * Fetch a VAPID keypair
	 *
	 * @return array
	 */
	private static function getKeyPair(): array
	{
		$keypair = DI::config()->get('system', 'ec_keypair');
		if (empty($keypair['publicKey']) || empty($keypair['privateKey'])) {
			$keypair = VAPID::createVapidKeys();
			DI::config()->set('system', 'ec_keypair', $keypair);
		}
		return $keypair;
	}

	/**
	 * Fetch the public VAPID key
	 *
	 * @return string
	 */
	public static function getPublicVapidKey(): string
	{
		$keypair = self::getKeyPair();
		return $keypair['publicKey'];
	}

	/**
	 * Fetch the public VAPID key
	 *
	 * @return string
	 */
	public static function getPrivateVapidKey(): string
	{
		$keypair = self::getKeyPair();
		return $keypair['privateKey'];
	}

	/**
	 * Prepare push notification
	 *
	 * @param int $nid
	 * @return void
	 */
	public static function pushByNotification(Entity\Notification $Notification)
	{
		$type = \Friendica\Factory\Api\Mastodon\Notification::getType($Notification);

		$desktop_notification = !in_array($type, [Notification::TYPE_RESHARE, Notification::TYPE_LIKE]);

		if (DI::pConfig()->get($Notification->uid, 'system', 'notify_like') && ($type == Notification::TYPE_LIKE)) {
			$desktop_notification = true;
		}

		if (DI::pConfig()->get($Notification->uid, 'system', 'notify_announce') && ($type == Notification::TYPE_RESHARE)) {
			$desktop_notification = true;
		}

		if ($desktop_notification) {
			DI::notify()->createFromNotification($Notification);
		}

		if (empty($type)) {
			return;
		}

		$subscriptions = DBA::select('subscription', [], ['uid' => $Notification->uid, $type => true]);
		while ($subscription = DBA::fetch($subscriptions)) {
			Logger::info('Push notification', ['id' => $subscription['id'], 'uid' => $subscription['uid'], 'type' => $type]);
			Worker::add(PRIORITY_HIGH, 'PushSubscription', $subscription['id'], $Notification->id);
		}
		DBA::close($subscriptions);
	}
}
