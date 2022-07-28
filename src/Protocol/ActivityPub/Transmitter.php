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

namespace Friendica\Protocol\ActivityPub;

use Friendica\Content\Feature;
use Friendica\Content\Text\BBCode;
use Friendica\Core\Cache\Enum\Duration;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\APContact;
use Friendica\Model\Contact;
use Friendica\Model\GServer;
use Friendica\Model\Item;
use Friendica\Model\Photo;
use Friendica\Model\Post;
use Friendica\Model\Tag;
use Friendica\Model\User;
use Friendica\Network\HTTPException;
use Friendica\Protocol\Activity;
use Friendica\Protocol\ActivityPub;
use Friendica\Protocol\Relay;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\HTTPSignature;
use Friendica\Util\LDSignature;
use Friendica\Util\Map;
use Friendica\Util\Network;
use Friendica\Util\Strings;
use Friendica\Util\XML;

/**
 * ActivityPub Transmitter Protocol class
 *
 * To-Do:
 * @todo Undo Announce
 */
class Transmitter
{
	const CACHEKEY_FEATURED = 'transmitter:getFeatured:';
	const CACHEKEY_CONTACTS = 'transmitter:getContacts:';
	const CACHEKEY_OUTBOX   = 'transmitter:getOutbox:';

	/**
	 * Add relay servers to the list of inboxes
	 *
	 * @param array $inboxes
	 * @return array inboxes with added relay servers
	 */
	public static function addRelayServerInboxes(array $inboxes = []): array
	{
		foreach (Relay::getList(['inbox']) as $contact) {
			$inboxes[$contact['inbox']] = $contact['inbox'];
		}

		return $inboxes;
	}

	/**
	 * Add relay servers to the list of inboxes
	 *
	 * @param array $inboxes
	 * @return array inboxes with added relay servers
	 */
	public static function addRelayServerInboxesForItem(int $item_id, array $inboxes = []): array
	{
		$item = Post::selectFirst(['uid'], ['id' => $item_id]);
		if (empty($item)) {
			return $inboxes;
		}

		$relays = Relay::getDirectRelayList($item_id);
		if (empty($relays)) {
			return $inboxes;
		}

		foreach ($relays as $relay) {
			$contact = Contact::getByURLForUser($relay['url'], $item['uid'], false, ['id']);
			$inboxes[$relay['batch']][] = $contact['id'] ?? 0;
		}
		return $inboxes;
	}

	/**
	 * Subscribe to a relay and updates contact on success
	 *
	 * @param string $url Subscribe actor url
	 * @return bool success
	 */
	public static function sendRelayFollow(string $url): bool
	{
		$contact = Contact::getByURL($url);
		if (empty($contact)) {
			return false;
		}

		$activity_id = ActivityPub\Transmitter::activityIDFromContact($contact['id']);
		$success = ActivityPub\Transmitter::sendActivity('Follow', $url, 0, $activity_id);
		if ($success) {
			Contact::update(['rel' => Contact::FRIEND], ['id' => $contact['id']]);
		}

		return $success;
	}

	/**
	 * Unsubscribe from a relay and updates contact on success or forced
	 *
	 * @param string $url   Subscribe actor url
	 * @param bool   $force Set the relay status as non follower even if unsubscribe hadn't worked
	 * @return bool success
	 */
	public static function sendRelayUndoFollow(string $url, bool $force = false): bool
	{
		$contact = Contact::getByURL($url);
		if (empty($contact)) {
			return false;
		}

		$success = self::sendContactUndo($url, $contact['id'], 0);

		if ($success || $force) {
			Contact::update(['rel' => Contact::NOTHING], ['id' => $contact['id']]);
		}

		return $success;
	}

	/**
	 * Collects a list of contacts of the given owner
	 *
	 * @param array   $owner     Owner array
	 * @param array   $rel       The relevant value(s) contact.rel should match
	 * @param string  $module    The name of the relevant AP endpoint module (followers|following)
	 * @param integer $page      Page number
	 * @param string  $requester URL of the requester
	 * @param boolean $nocache   Wether to bypass caching
	 * @return array of owners
	 * @throws \Exception
	 */
	public static function getContacts(array $owner, array $rel, string $module, int $page = null, string $requester = null, bool $nocache = false): array
	{
		if (empty($page)) {
			$cachekey = self::CACHEKEY_CONTACTS . $module . ':'. $owner['uid'];
			$result = DI::cache()->get($cachekey);
			if (!$nocache && !is_null($result)) {
				return $result;
			}
		}

		$parameters = [
			'rel' => $rel,
			'uid' => $owner['uid'],
			'self' => false,
			'deleted' => false,
			'hidden' => false,
			'archive' => false,
			'pending' => false,
			'blocked' => false,
		];

		$condition = DBA::mergeConditions($parameters, ["`url` IN (SELECT `url` FROM `apcontact`)"]);

		$total = DBA::count('contact', $condition);

		$modulePath = '/' . $module . '/';

		$data = ['@context' => ActivityPub::CONTEXT];
		$data['id'] = DI::baseUrl() . $modulePath . $owner['nickname'];
		$data['type'] = 'OrderedCollection';
		$data['totalItems'] = $total;

		if (!empty($page)) {
			$data['id'] .= '?' . http_build_query(['page' => $page]);
		}

		// When we hide our friends we will only show the pure number but don't allow more.
		$show_contacts = empty($owner['hide-friends']);

		// Allow fetching the contact list when the requester is part of the list.
		if (($owner['page-flags'] == User::PAGE_FLAGS_PRVGROUP) && !empty($requester)) {
			$show_contacts = DBA::exists('contact', ['nurl' => Strings::normaliseLink($requester), 'uid' => $owner['uid'], 'blocked' => false]);
		}

		if (!$show_contacts) {
			if (!empty($cachekey)) {
				DI::cache()->set($cachekey, $data, Duration::DAY);
			}

			return $data;
		}

		if (empty($page)) {
			$data['first'] = DI::baseUrl() . $modulePath . $owner['nickname'] . '?page=1';
		} else {
			$data['type'] = 'OrderedCollectionPage';
			$list = [];

			$contacts = DBA::select('contact', ['url'], $condition, ['limit' => [($page - 1) * 100, 100]]);
			while ($contact = DBA::fetch($contacts)) {
				$list[] = $contact['url'];
			}
			DBA::close($contacts);

			if (count($list) == 100) {
				$data['next'] = DI::baseUrl() . $modulePath . $owner['nickname'] . '?page=' . ($page + 1);
			}

			$data['partOf'] = DI::baseUrl() . $modulePath . $owner['nickname'];

			$data['orderedItems'] = $list;
		}

		if (!empty($cachekey)) {
			DI::cache()->set($cachekey, $data, Duration::DAY);
		}

		return $data;
	}

	/**
	 * Public posts for the given owner
	 *
	 * @param array   $owner     Owner array
	 * @param integer $page      Page number
	 * @param string  $requester URL of requesting account
	 * @param boolean $nocache   Wether to bypass caching
	 * @return array of posts
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function getOutbox(array $owner, int $page = null, string $requester = '', bool $nocache = false): array
	{
		if (empty($page)) {
			$cachekey = self::CACHEKEY_OUTBOX . $owner['uid'];
			$result = DI::cache()->get($cachekey);
			if (!$nocache && !is_null($result)) {
				return $result;
			}
		}

		$condition = ['private' => [Item::PUBLIC, Item::UNLISTED]];

		if (!empty($requester)) {
			$requester_id = Contact::getIdForURL($requester, $owner['uid']);
			if (!empty($requester_id)) {
				$permissionSets = DI::permissionSet()->selectByContactId($requester_id, $owner['uid']);
				if (!empty($permissionSets)) {
					$condition = ['psid' => array_merge($permissionSets->column('id'),
							[DI::permissionSet()->selectPublicForUser($owner['uid'])])];
				}
			}
		}

		$condition = array_merge($condition, [
			'uid'           => $owner['uid'],
			'author-id'      => Contact::getIdForURL($owner['url'], 0, false),
			'gravity'        => [GRAVITY_PARENT, GRAVITY_COMMENT],
			'network'        => Protocol::FEDERATED,
			'parent-network' => Protocol::FEDERATED,
			'origin'         => true,
			'deleted'        => false,
			'visible'        => true
		]);

		$count = Post::count($condition);

		$data = ['@context' => ActivityPub::CONTEXT];
		$data['id'] = DI::baseUrl() . '/outbox/' . $owner['nickname'];
		$data['type'] = 'OrderedCollection';
		$data['totalItems'] = $count;

		if (!empty($page)) {
			$data['id'] .= '?' . http_build_query(['page' => $page]);
		}

		if (empty($page)) {
			$data['first'] = DI::baseUrl() . '/outbox/' . $owner['nickname'] . '?page=1';
		} else {
			$data['type'] = 'OrderedCollectionPage';
			$list = [];

			$items = Post::select(['id'], $condition, ['limit' => [($page - 1) * 20, 20], 'order' => ['created' => true]]);
			while ($item = Post::fetch($items)) {
				$activity = self::createActivityFromItem($item['id'], true);
				$activity['type'] = $activity['type'] == 'Update' ? 'Create' : $activity['type'];

				// Only list "Create" activity objects here, no reshares
				if (!empty($activity['object']) && ($activity['type'] == 'Create')) {
					$list[] = $activity['object'];
				}
			}
			DBA::close($items);

			if (count($list) == 20) {
				$data['next'] = DI::baseUrl() . '/outbox/' . $owner['nickname'] . '?page=' . ($page + 1);
			}

			$data['partOf'] = DI::baseUrl() . '/outbox/' . $owner['nickname'];

			$data['orderedItems'] = $list;
		}

		if (!empty($cachekey)) {
			DI::cache()->set($cachekey, $data, Duration::DAY);
		}

		return $data;
	}

	/**
	 * Public posts for the given owner
	 *
	 * @param array   $owner   Owner array
	 * @param integer $page    Page number
	 * @param boolean $nocache Wether to bypass caching
	 *
	 * @return array of posts
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function getFeatured(array $owner, int $page = null, bool $nocache = false): array
	{
		if (empty($page)) {
			$cachekey = self::CACHEKEY_FEATURED . $owner['uid'];
			$result = DI::cache()->get($cachekey);
			if (!$nocache && !is_null($result)) {
				return $result;
			}
		}

		$owner_cid = Contact::getIdForURL($owner['url'], 0, false);

		$condition = ["`uri-id` IN (SELECT `uri-id` FROM `collection-view` WHERE `cid` = ? AND `type` = ?)",
			$owner_cid, Post\Collection::FEATURED];

		$condition = DBA::mergeConditions($condition, [
			'uid'           => $owner['uid'],
			'author-id'      => $owner_cid,
			'private'        => [Item::PUBLIC, Item::UNLISTED],
			'gravity'        => [GRAVITY_PARENT, GRAVITY_COMMENT],
			'network'        => Protocol::FEDERATED,
			'parent-network' => Protocol::FEDERATED,
			'origin'         => true,
			'deleted'        => false,
			'visible'        => true
		]);

		$count = Post::count($condition);

		$data = ['@context' => ActivityPub::CONTEXT];
		$data['id'] = DI::baseUrl() . '/featured/' . $owner['nickname'];
		$data['type'] = 'OrderedCollection';
		$data['totalItems'] = $count;

		if (!empty($page)) {
			$data['id'] .= '?' . http_build_query(['page' => $page]);
		}

		if (empty($page)) {
			$items = Post::select(['id'], $condition, ['limit' => 20, 'order' => ['created' => true]]);
		} else {
			$data['type'] = 'OrderedCollectionPage';
			$items = Post::select(['id'], $condition, ['limit' => [($page - 1) * 20, 20], 'order' => ['created' => true]]);
		}
		$list = [];

		while ($item = Post::fetch($items)) {
			$activity = self::createActivityFromItem($item['id'], true);
			$activity['type'] = $activity['type'] == 'Update' ? 'Create' : $activity['type'];

			// Only list "Create" activity objects here, no reshares
			if (!empty($activity['object']) && ($activity['type'] == 'Create')) {
				$list[] = $activity['object'];
			}
		}
		DBA::close($items);

		if (count($list) == 20) {
			$data['next'] = DI::baseUrl() . '/featured/' . $owner['nickname'] . '?page=' . ($page + 1);
		}

		if (!empty($page)) {
			$data['partOf'] = DI::baseUrl() . '/featured/' . $owner['nickname'];
		}

		$data['orderedItems'] = $list;

		if (!empty($cachekey)) {
			DI::cache()->set($cachekey, $data, Duration::DAY);
		}

		return $data;
	}

	/**
	 * Return the service array containing information the used software and it's url
	 *
	 * @return array with service data
	 */
	private static function getService(): array
	{
		return [
			'type' => 'Service',
			'name' =>  FRIENDICA_PLATFORM . " '" . FRIENDICA_CODENAME . "' " . FRIENDICA_VERSION . '-' . DB_UPDATE_VERSION,
			'url' => DI::baseUrl()->get()
		];
	}

	/**
	 * Return the ActivityPub profile of the given user
	 *
	 * @param int $uid User ID
	 * @return array with profile data
	 * @throws HTTPException\NotFoundException
	 * @throws HTTPException\InternalServerErrorException
	 */
	public static function getProfile(int $uid): array
	{
		$owner = User::getOwnerDataById($uid);
		if (!isset($owner['id'])) {
			DI::logger()->error('Unable to find owner data for uid', ['uid' => $uid, 'callstack' => System::callstack(20)]);
			throw new HTTPException\NotFoundException('User not found.');
		}

		$data = ['@context' => ActivityPub::CONTEXT];
		$data['id'] = $owner['url'];

		if (!empty($owner['guid'])) {
			$data['diaspora:guid'] = $owner['guid'];
		}

		$data['type'] = ActivityPub::ACCOUNT_TYPES[$owner['account-type']];

		if ($uid != 0) {
			$data['following'] = DI::baseUrl() . '/following/' . $owner['nick'];
			$data['followers'] = DI::baseUrl() . '/followers/' . $owner['nick'];
			$data['inbox']     = DI::baseUrl() . '/inbox/' . $owner['nick'];
			$data['outbox']    = DI::baseUrl() . '/outbox/' . $owner['nick'];
			$data['featured']  = DI::baseUrl() . '/featured/' . $owner['nick'];
		} else {
			$data['inbox'] = DI::baseUrl() . '/friendica/inbox';
		}

		$data['preferredUsername'] = $owner['nick'];
		$data['name'] = $owner['name'];

		if (!empty($owner['country-name'] . $owner['region'] . $owner['locality'])) {
			$data['vcard:hasAddress'] = ['@type' => 'vcard:Home', 'vcard:country-name' => $owner['country-name'],
				'vcard:region' => $owner['region'], 'vcard:locality' => $owner['locality']];
		}

		if (!empty($owner['about'])) {
			$data['summary'] = BBCode::convertForUriId($owner['uri-id'] ?? 0, $owner['about'], BBCode::EXTERNAL);
		}

		if (!empty($owner['xmpp']) || !empty($owner['matrix'])) {
			$data['vcard:hasInstantMessage'] = [];

			if (!empty($owner['xmpp'])) {
				$data['vcard:hasInstantMessage'][] = 'xmpp:' . $owner['xmpp'];
			}
			if (!empty($owner['matrix'])) {
				$data['vcard:hasInstantMessage'][] = 'matrix:' . $owner['matrix'];
			}
		}

		$data['url'] = $owner['url'];
		$data['manuallyApprovesFollowers'] = in_array($owner['page-flags'], [User::PAGE_FLAGS_NORMAL, User::PAGE_FLAGS_PRVGROUP]);
		$data['discoverable'] = (bool)$owner['net-publish'];
		$data['publicKey'] = ['id' => $owner['url'] . '#main-key',
			'owner' => $owner['url'],
			'publicKeyPem' => $owner['pubkey']];
		$data['endpoints'] = ['sharedInbox' => DI::baseUrl() . '/inbox'];
		$data['icon'] = ['type' => 'Image', 'url' => User::getAvatarUrl($owner)];

		$resourceid = Photo::ridFromURI($owner['photo']);
		if (!empty($resourceid)) {
			$photo = Photo::selectFirst(['type'], ["resource-id" => $resourceid]);
			if (!empty($photo['type'])) {
				$data['icon']['mediaType'] = $photo['type'];
			}
		}

		if (!empty($owner['header'])) {
			$data['image'] = ['type' => 'Image', 'url' => Contact::getHeaderUrlForId($owner['id'], '', $owner['updated'])];

			$resourceid = Photo::ridFromURI($owner['header']);
			if (!empty($resourceid)) {
				$photo = Photo::selectFirst(['type'], ["resource-id" => $resourceid]);
				if (!empty($photo['type'])) {
					$data['image']['mediaType'] = $photo['type'];
				}
			}
		}

		$custom_fields = [];

		foreach (DI::profileField()->selectByContactId(0, $uid) as $profile_field) {
			$custom_fields[] = [
				'type' => 'PropertyValue',
				'name' => $profile_field->label,
				'value' => BBCode::convertForUriId($owner['uri-id'], $profile_field->value)
			];
		};

		if (!empty($custom_fields)) {
			$data['attachment'] = $custom_fields;
		}

		$data['generator'] = self::getService();

		// tags: https://kitty.town/@inmysocks/100656097926961126.json
		return $data;
	}

	/**
	 * @param string $username
	 * @return array
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function getDeletedUser(string $username): array
	{
		return [
			'@context' => ActivityPub::CONTEXT,
			'id' => DI::baseUrl() . '/profile/' . $username,
			'type' => 'Tombstone',
			'published' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'updated' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'deleted' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
		];
	}

	/**
	 * Returns an array with permissions of the thread parent of the given item array
	 *
	 * @param array $item
	 * @param bool  $is_forum_thread
	 *
	 * @return array with permissions
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function fetchPermissionBlockFromThreadParent(array $item, bool $is_forum_thread): array
	{
		if (empty($item['thr-parent-id'])) {
			return [];
		}

		$parent = Post::selectFirstPost(['author-link'], ['uri-id' => $item['thr-parent-id']]);
		if (empty($parent)) {
			return [];
		}

		$permissions = [
			'to' => [$parent['author-link']],
			'cc' => [],
			'bto' => [],
			'bcc' => [],
		];

		$parent_profile = APContact::getByURL($parent['author-link']);

		$item_profile = APContact::getByURL($item['author-link']);
		$exclude[] = $item['author-link'];

		if ($item['gravity'] == GRAVITY_PARENT) {
			$exclude[] = $item['owner-link'];
		}

		$type = [Tag::TO => 'to', Tag::CC => 'cc', Tag::BTO => 'bto', Tag::BCC => 'bcc'];
		foreach (Tag::getByURIId($item['thr-parent-id'], [Tag::TO, Tag::CC, Tag::BTO, Tag::BCC]) as $receiver) {
			if (!empty($parent_profile['followers']) && $receiver['url'] == $parent_profile['followers'] && !empty($item_profile['followers'])) {
				if (!$is_forum_thread) {
					$permissions[$type[$receiver['type']]][] = $item_profile['followers'];
				}
			} elseif (!in_array($receiver['url'], $exclude)) {
				$permissions[$type[$receiver['type']]][] = $receiver['url'];
			}
		}

		return $permissions;
	}

	/**
	 * Check if the given item id is from ActivityPub
	 *
	 * @param integer $item_id
	 * @return boolean "true" if the post is from ActivityPub
	 */
	private static function isAPPost(int $item_id): bool
	{
		if (empty($item_id)) {
			return false;
		}

		return Post::exists(['id' => $item_id, 'network' => Protocol::ACTIVITYPUB]);
	}

	/**
	 * Creates an array of permissions from an item thread
	 *
	 * @param array   $item      Item array
	 * @param boolean $blindcopy addressing via "bcc" or "cc"?
	 * @param integer $last_id   Last item id for adding receivers
	 *
	 * @return array with permission data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function createPermissionBlockForItem(array $item, bool $blindcopy, int $last_id = 0): array
	{
		if ($last_id == 0) {
			$last_id = $item['id'];
		}

		$always_bcc = false;
		$is_forum   = false;
		$follower   = '';

		// Check if we should always deliver our stuff via BCC
		if (!empty($item['uid'])) {
			$owner = User::getOwnerDataById($item['uid']);
			if (!empty($owner)) {
				$always_bcc = $owner['hide-friends'];
				$is_forum   = ($owner['account-type'] == User::ACCOUNT_TYPE_COMMUNITY) && $owner['manually-approve'];

				$profile  = APContact::getByURL($owner['url'], false);
				$follower = $profile['followers'] ?? '';
			}
		}

		if (DI::config()->get('system', 'ap_always_bcc')) {
			$always_bcc = true;
		}

		$parent = Post::selectFirst(['causer-link', 'post-reason'], ['id' => $item['parent']]);
		if (($parent['post-reason'] == Item::PR_ANNOUNCEMENT) && !empty($parent['causer-link'])) {
			$profile = APContact::getByURL($parent['causer-link'], false);
			$is_forum_thread = isset($profile['type']) && $profile['type'] == 'Group';
		} else {
			$is_forum_thread = false;
		}

		if (self::isAnnounce($item) || DI::config()->get('debug', 'total_ap_delivery') || self::isAPPost($last_id)) {
			// Will be activated in a later step
			$networks = Protocol::FEDERATED;
		} else {
			// For now only send to these contacts:
			$networks = [Protocol::ACTIVITYPUB, Protocol::OSTATUS];
		}

		$data = ['to' => [], 'cc' => [], 'bcc' => []];

		if ($item['gravity'] == GRAVITY_PARENT) {
			$actor_profile = APContact::getByURL($item['owner-link']);
		} else {
			$actor_profile = APContact::getByURL($item['author-link']);
		}

		$exclusive = false;

		$terms = Tag::getByURIId($item['uri-id'], [Tag::MENTION, Tag::IMPLICIT_MENTION, Tag::EXCLUSIVE_MENTION]);

		if ($item['private'] != Item::PRIVATE) {
			// Directly mention the original author upon a quoted reshare.
			// Else just ensure that the original author receives the reshare.
			$announce = self::getAnnounceArray($item);
			if (!empty($announce['comment'])) {
				$data['to'][] = $announce['actor']['url'];
			} elseif (!empty($announce)) {
				$data['cc'][] = $announce['actor']['url'];
			}

			$data = array_merge($data, self::fetchPermissionBlockFromThreadParent($item, $is_forum_thread));

			// Check if the item is completely public or unlisted
			if ($item['private'] == Item::PUBLIC) {
				$data['to'][] = ActivityPub::PUBLIC_COLLECTION;
			} else {
				$data['cc'][] = ActivityPub::PUBLIC_COLLECTION;
			}

			foreach ($terms as $term) {
				$profile = APContact::getByURL($term['url'], false);
				if (!empty($profile)) {
					if ($term['type'] == Tag::EXCLUSIVE_MENTION) {
						$exclusive = true;
						if (!empty($profile['followers']) && ($profile['type'] == 'Group')) {
							$data['cc'][] = $profile['followers'];
						}
					}
					$data['to'][] = $profile['url'];
				}
			}
		} else {
			$receiver_list = Item::enumeratePermissions($item, true);

			foreach ($terms as $term) {
				$cid = Contact::getIdForURL($term['url'], $item['uid']);
				if (!empty($cid) && in_array($cid, $receiver_list)) {
					$contact = DBA::selectFirst('contact', ['url', 'network', 'protocol', 'gsid'], ['id' => $cid, 'network' => Protocol::FEDERATED]);
					if (!DBA::isResult($contact) || !self::isAPContact($contact, $networks)) {
						continue;
					}

					$profile = APContact::getByURL($term['url'], false);
					if (!empty($profile)) {
						if ($term['type'] == Tag::EXCLUSIVE_MENTION) {
							$exclusive = true;
							if (!empty($profile['followers']) && ($profile['type'] == 'Group')) {
								$data['cc'][] = $profile['followers'];
							}
						}
						$data['to'][] = $profile['url'];
					}
				}
			}

			if ($is_forum && !$exclusive && !empty($follower)) {
				$data['cc'][] = $follower;
			} elseif (!$exclusive) {
				foreach ($receiver_list as $receiver) {
					$contact = DBA::selectFirst('contact', ['url', 'hidden', 'network', 'protocol', 'gsid'], ['id' => $receiver, 'network' => Protocol::FEDERATED]);
					if (!DBA::isResult($contact) || !self::isAPContact($contact, $networks)) {
						continue;
					}

					if (!empty($profile = APContact::getByURL($contact['url'], false))) {
						if ($contact['hidden'] || $always_bcc) {
							$data['bcc'][] = $profile['url'];
						} else {
							$data['cc'][] = $profile['url'];
						}
					}
				}
			}
		}

		if (!empty($item['parent'])) {
			$parents = Post::select(['id', 'author-link', 'owner-link', 'gravity', 'uri'], ['parent' => $item['parent']], ['order' => ['id']]);
			while ($parent = Post::fetch($parents)) {
				if ($parent['gravity'] == GRAVITY_PARENT) {
					$profile = APContact::getByURL($parent['owner-link'], false);
					if (!empty($profile)) {
						if ($item['gravity'] != GRAVITY_PARENT) {
							// Comments to forums are directed to the forum
							// But comments to forums aren't directed to the followers collection
							// This rule is only valid when the actor isn't the forum.
							// The forum needs to transmit their content to their followers.
							if (($profile['type'] == 'Group') && ($profile['url'] != ($actor_profile['url'] ?? ''))) {
								$data['to'][] = $profile['url'];
							} else {
								$data['cc'][] = $profile['url'];
								if (($item['private'] != Item::PRIVATE) && !empty($actor_profile['followers'])&& !$is_forum_thread) {
									$data['cc'][] = $actor_profile['followers'];
								}
							}
						} elseif (!$exclusive && !$is_forum_thread) {
							// Public thread parent post always are directed to the followers.
							if ($item['private'] != Item::PRIVATE) {
								$data['cc'][] = $actor_profile['followers'];
							}
						}
					}
				}

				// Don't include data from future posts
				if ($parent['id'] >= $last_id) {
					continue;
				}

				$profile = APContact::getByURL($parent['author-link'], false);
				if (!empty($profile)) {
					if (($profile['type'] == 'Group') || ($parent['uri'] == $item['thr-parent'])) {
						$data['to'][] = $profile['url'];
					} else {
						$data['cc'][] = $profile['url'];
					}
				}
			}
			DBA::close($parents);
		}

		$data['to'] = array_unique($data['to']);
		$data['cc'] = array_unique($data['cc']);
		$data['bcc'] = array_unique($data['bcc']);

		if (($key = array_search($item['author-link'], $data['to'])) !== false) {
			unset($data['to'][$key]);
		}

		if (($key = array_search($item['author-link'], $data['cc'])) !== false) {
			unset($data['cc'][$key]);
		}

		if (($key = array_search($item['author-link'], $data['bcc'])) !== false) {
			unset($data['bcc'][$key]);
		}

		foreach ($data['to'] as $to) {
			if (($key = array_search($to, $data['cc'])) !== false) {
				unset($data['cc'][$key]);
			}

			if (($key = array_search($to, $data['bcc'])) !== false) {
				unset($data['bcc'][$key]);
			}
		}

		foreach ($data['cc'] as $cc) {
			if (($key = array_search($cc, $data['bcc'])) !== false) {
				unset($data['bcc'][$key]);
			}
		}

		$receivers = ['to' => array_values($data['to']), 'cc' => array_values($data['cc']), 'bcc' => array_values($data['bcc'])];

		if (!$blindcopy) {
			unset($receivers['bcc']);
		}

		foreach (['to' => Tag::TO, 'cc' => Tag::CC, 'bcc' => Tag::BCC] as $element => $type) {
			if (!empty($receivers[$element])) {
				foreach ($receivers[$element] as $receiver) {
					if ($receiver == ActivityPub::PUBLIC_COLLECTION) {
						$name = Receiver::PUBLIC_COLLECTION;
					} else {
						$name = trim(parse_url($receiver, PHP_URL_PATH), '/');
					}
					Tag::store($item['uri-id'], $type, $name, $receiver);
				}
			}
		}

		return $receivers;
	}

	/**
	 * Check if an inbox is archived
	 *
	 * @param string $url Inbox url
	 * @return boolean "true" if inbox is archived
	 */
	public static function archivedInbox(string $url): bool
	{
		return DBA::exists('inbox-status', ['url' => $url, 'archive' => true]);
	}

	/**
	 * Check if a given contact should be delivered via AP
	 *
	 * @param array $contact Contact array
	 * @param array $networks Array with networks
	 * @return bool Whether the used protocol matches ACTIVITYPUB
	 * @throws Exception
	 */
	private static function isAPContact(array $contact, array $networks): bool
	{
		if (in_array($contact['network'], $networks) || ($contact['protocol'] == Protocol::ACTIVITYPUB)) {
			return true;
		}

		return GServer::getProtocol($contact['gsid'] ?? 0) == Post\DeliveryData::ACTIVITYPUB;
	}

	/**
	 * Fetches a list of inboxes of followers of a given user
	 *
	 * @param integer $uid      User ID
	 * @param boolean $personal fetch personal inboxes
	 * @param boolean $all_ap   Retrieve all AP enabled inboxes
	 * @return array of follower inboxes
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function fetchTargetInboxesforUser(int $uid, bool $personal = false, bool $all_ap = false): array
	{
		$inboxes = [];

		$isforum = false;

		if (!empty($item['uid'])) {
			$profile = User::getOwnerDataById($item['uid']);
			if (!empty($profile)) {
				$isforum = $profile['account-type'] == User::ACCOUNT_TYPE_COMMUNITY;
			}
		}

		if (DI::config()->get('debug', 'total_ap_delivery') || $all_ap) {
			// Will be activated in a later step
			$networks = Protocol::FEDERATED;
		} else {
			// For now only send to these contacts:
			$networks = [Protocol::ACTIVITYPUB, Protocol::OSTATUS];
		}

		$condition = [
			'uid' => $uid,
			'archive' => false,
			'pending' => false,
			'blocked' => false,
			'network' => Protocol::FEDERATED,
		];

		if (!empty($uid)) {
			$condition['rel'] = [Contact::FOLLOWER, Contact::FRIEND];
		}

		$contacts = DBA::select('contact', ['id', 'url', 'network', 'protocol', 'gsid'], $condition);
		while ($contact = DBA::fetch($contacts)) {
			if (!self::isAPContact($contact, $networks)) {
				continue;
			}

			if ($isforum && ($contact['network'] == Protocol::DFRN)) {
				continue;
			}

			if (Network::isUrlBlocked($contact['url'])) {
				continue;
			}

			$profile = APContact::getByURL($contact['url'], false);
			if (!empty($profile)) {
				if (empty($profile['sharedinbox']) || $personal || Contact::isLocal($contact['url'])) {
					$target = $profile['inbox'];
				} else {
					$target = $profile['sharedinbox'];
				}
				if (!self::archivedInbox($target)) {
					$inboxes[$target][] = $contact['id'];
				}
			}
		}
		DBA::close($contacts);

		return $inboxes;
	}

	/**
	 * Fetches an array of inboxes for the given item and user
	 *
	 * @param array   $item     Item array
	 * @param integer $uid      User ID
	 * @param boolean $personal fetch personal inboxes
	 * @param integer $last_id  Last item id for adding receivers
	 * @return array with inboxes
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function fetchTargetInboxes(array $item, int $uid, bool $personal = false, int $last_id = 0): array
	{
		$permissions = self::createPermissionBlockForItem($item, true, $last_id);
		if (empty($permissions)) {
			return [];
		}

		$inboxes = [];

		if ($item['gravity'] == GRAVITY_ACTIVITY) {
			$item_profile = APContact::getByURL($item['author-link'], false);
		} else {
			$item_profile = APContact::getByURL($item['owner-link'], false);
		}

		if (empty($item_profile)) {
			return [];
		}

		$profile_uid = User::getIdForURL($item_profile['url']);

		foreach (['to', 'cc', 'bto', 'bcc'] as $element) {
			if (empty($permissions[$element])) {
				continue;
			}

			$blindcopy = in_array($element, ['bto', 'bcc']);

			foreach ($permissions[$element] as $receiver) {
				if (empty($receiver) || Network::isUrlBlocked($receiver)) {
					continue;
				}

				if ($item_profile && ($receiver == $item_profile['followers']) && ($uid == $profile_uid)) {
					$inboxes = array_merge($inboxes, self::fetchTargetInboxesforUser($uid, $personal, self::isAPPost($last_id)));
				} else {
					$profile = APContact::getByURL($receiver, false);
					if (!empty($profile)) {
						$contact = Contact::getByURLForUser($receiver, $uid, false, ['id']);

						if (empty($profile['sharedinbox']) || $personal || $blindcopy || Contact::isLocal($receiver)) {
							$target = $profile['inbox'];
						} else {
							$target = $profile['sharedinbox'];
						}
						if (!self::archivedInbox($target)) {
							$inboxes[$target][] = $contact['id'] ?? 0;
						}
					}
				}
			}
		}

		return $inboxes;
	}

	/**
	 * Creates an array in the structure of the item table for a given mail id
	 *
	 * @param integer $mail_id Mail id
	 * @return array
	 * @throws \Exception
	 */
	public static function getItemArrayFromMail(int $mail_id, bool $use_title = false): array
	{
		$mail = DBA::selectFirst('mail', [], ['id' => $mail_id]);
		if (!DBA::isResult($mail)) {
			return [];
		}

		$reply = DBA::selectFirst('mail', ['uri', 'uri-id', 'from-url'], ['parent-uri' => $mail['parent-uri'], 'reply' => false]);
		if (!DBA::isResult($reply)) {
			$reply = $mail;
		}

		// Making the post more compatible for Mastodon by:
		// - Making it a note and not an article (no title)
		// - Moving the title into the "summary" field that is used as a "content warning"

		if (!$use_title) {
			$mail['body']         = '[abstract]' . $mail['title'] . "[/abstract]\n" . $mail['body'];
			$mail['title']        = '';
		}

		$mail['content-warning']  = '';
		$mail['author-link']      = $mail['owner-link'] = $mail['from-url'];
		$mail['owner-id']         = $mail['author-id'];
		$mail['allow_cid']        = '<'.$mail['contact-id'].'>';
		$mail['allow_gid']        = '';
		$mail['deny_cid']         = '';
		$mail['deny_gid']         = '';
		$mail['private']          = Item::PRIVATE;
		$mail['deleted']          = false;
		$mail['edited']           = $mail['created'];
		$mail['plink']            = DI::baseUrl() . '/message/' . $mail['id'];
		$mail['parent-uri']       = $reply['uri'];
		$mail['parent-uri-id']    = $reply['uri-id'];
		$mail['parent-author-id'] = Contact::getIdForURL($reply['from-url'], 0, false);
		$mail['gravity']          = ($mail['reply'] ? GRAVITY_COMMENT: GRAVITY_PARENT);
		$mail['event-type']       = '';
		$mail['language']         = '';
		$mail['parent']           = 0;

		return $mail;
	}

	/**
	 * Creates an activity array for a given mail id
	 *
	 * @param integer $mail_id
	 * @param boolean $object_mode Is the activity item is used inside another object?
	 *
	 * @return array of activity
	 * @throws \Exception
	 */
	public static function createActivityFromMail(int $mail_id, bool $object_mode = false): array
	{
		$mail = self::getItemArrayFromMail($mail_id);
		if (empty($mail)) {
			return [];
		}
		$object = self::createNote($mail);

		if (!$object_mode) {
			$data = ['@context' => ActivityPub::CONTEXT];
		} else {
			$data = [];
		}

		$data['id'] = $mail['uri'] . '/Create';
		$data['type'] = 'Create';
		$data['actor'] = $mail['author-link'];
		$data['published'] = DateTimeFormat::utc($mail['created'] . '+00:00', DateTimeFormat::ATOM);
		$data['instrument'] = self::getService();
		$data = array_merge($data, self::createPermissionBlockForItem($mail, true));

		if (empty($data['to']) && !empty($data['cc'])) {
			$data['to'] = $data['cc'];
		}

		if (empty($data['to']) && !empty($data['bcc'])) {
			$data['to'] = $data['bcc'];
		}

		unset($data['cc']);
		unset($data['bcc']);

		$object['to'] = $data['to'];
		$object['tag'] = [['type' => 'Mention', 'href' => $object['to'][0], 'name' => '']];

		unset($object['cc']);
		unset($object['bcc']);

		$data['directMessage'] = true;

		$data['object'] = $object;

		$owner = User::getOwnerDataById($mail['uid']);

		if (!$object_mode && !empty($owner)) {
			return LDSignature::sign($data, $owner);
		} else {
			return $data;
		}
	}

	/**
	 * Returns the activity type of a given item
	 *
	 * @param array $item Item array
	 * @return string with activity type
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function getTypeOfItem(array $item): string
	{
		$reshared = false;

		// Only check for a reshare, if it is a real reshare and no quoted reshare
		if (strpos($item['body'], '[share') === 0) {
			$announce = self::getAnnounceArray($item);
			$reshared = !empty($announce);
		}

		if ($reshared) {
			$type = 'Announce';
		} elseif ($item['verb'] == Activity::POST) {
			if ($item['created'] == $item['edited']) {
				$type = 'Create';
			} else {
				$type = 'Update';
			}
		} elseif ($item['verb'] == Activity::LIKE) {
			$type = 'Like';
		} elseif ($item['verb'] == Activity::DISLIKE) {
			$type = 'Dislike';
		} elseif ($item['verb'] == Activity::ATTEND) {
			$type = 'Accept';
		} elseif ($item['verb'] == Activity::ATTENDNO) {
			$type = 'Reject';
		} elseif ($item['verb'] == Activity::ATTENDMAYBE) {
			$type = 'TentativeAccept';
		} elseif ($item['verb'] == Activity::FOLLOW) {
			$type = 'Follow';
		} elseif ($item['verb'] == Activity::TAG) {
			$type = 'Add';
		} elseif ($item['verb'] == Activity::ANNOUNCE) {
			$type = 'Announce';
		} else {
			$type = '';
		}

		return $type;
	}

	/**
	 * Creates the activity or fetches it from the cache
	 *
	 * @param integer $item_id Item id
	 * @param boolean $force Force new cache entry
	 * @return array|false activity or false on failure
	 * @throws \Exception
	 */
	public static function createCachedActivityFromItem(int $item_id, bool $force = false)
	{
		$cachekey = 'APDelivery:createActivity:' . $item_id;

		if (!$force) {
			$data = DI::cache()->get($cachekey);
			if (!is_null($data)) {
				return $data;
			}
		}

		$data = self::createActivityFromItem($item_id);

		DI::cache()->set($cachekey, $data, Duration::QUARTER_HOUR);
		return $data;
	}

	/**
	 * Creates an activity array for a given item id
	 *
	 * @param integer $item_id
	 * @param boolean $object_mode Is the activity item is used inside another object?
	 * @return false|array
	 * @throws \Exception
	 */
	public static function createActivityFromItem(int $item_id, bool $object_mode = false)
	{
		Logger::info('Fetching activity', ['item' => $item_id]);
		$item = Post::selectFirst(Item::DELIVER_FIELDLIST, ['id' => $item_id, 'parent-network' => Protocol::NATIVE_SUPPORT]);
		if (!DBA::isResult($item)) {
			return false;
		}

		if (empty($item['uri-id'])) {
			Logger::warning('Item without uri-id', ['item' => $item]);
			return false;
		}

		if (!$item['deleted']) {
			$data = Post\Activity::getByURIId($item['uri-id']);
			if (!$item['origin'] && !empty($data)) {
				if (!empty($data['type'])) {
					if (in_array($data['type'], ['Create', 'Update'])) {
						if ($object_mode) {
							unset($data['@context']);
							unset($data['signature']);
						}
						Logger::info('Return stored conversation', ['item' => $item_id]);
						return $data;
					} elseif (in_array('as:' . $data['type'], Receiver::CONTENT_TYPES)) {
						if (!empty($data['@context'])) {
							$context = $data['@context'];
							unset($data['@context']);
						}
						unset($data['actor']);
						$object = $data;
					}
				}
			}
		}

		$type = self::getTypeOfItem($item);

		if (!$object_mode) {
			$data = ['@context' => $context ?? ActivityPub::CONTEXT];

			if ($item['deleted'] && ($item['gravity'] == GRAVITY_ACTIVITY)) {
				$type = 'Undo';
			} elseif ($item['deleted']) {
				$type = 'Delete';
			}
		} else {
			$data = [];
		}

		if ($type == 'Delete') {
			$data['id'] = Item::newURI($item['guid']) . '/' . $type;;
		} elseif (($item['gravity'] == GRAVITY_ACTIVITY) && ($type != 'Undo')) {
			$data['id'] = $item['uri'];
		} else {
			$data['id'] = $item['uri'] . '/' . $type;
		}

		$data['type'] = $type;

		if (($type != 'Announce') || ($item['gravity'] != GRAVITY_PARENT)) {
			$data['actor'] = $item['author-link'];
		} else {
			$data['actor'] = $item['owner-link'];
		}

		$data['published'] = DateTimeFormat::utc($item['created'] . '+00:00', DateTimeFormat::ATOM);

		$data['instrument'] = self::getService();

		$data = array_merge($data, self::createPermissionBlockForItem($item, false));

		if (in_array($data['type'], ['Create', 'Update', 'Delete'])) {
			$data['object'] = $object ?? self::createNote($item);
			$data['published'] = DateTimeFormat::utcNow(DateTimeFormat::ATOM);
		} elseif ($data['type'] == 'Add') {
			$data = self::createAddTag($item, $data);
		} elseif ($data['type'] == 'Announce') {
			if ($item['verb'] == ACTIVITY::ANNOUNCE) {
				$data['object'] = $item['thr-parent'];
			} else {
				$data = self::createAnnounce($item, $data);
			}
		} elseif ($data['type'] == 'Follow') {
			$data['object'] = $item['parent-uri'];
		} elseif ($data['type'] == 'Undo') {
			$data['object'] = self::createActivityFromItem($item_id, true);
		} else {
			$data['diaspora:guid'] = $item['guid'];
			if (!empty($item['signed_text'])) {
				$data['diaspora:like'] = $item['signed_text'];
			}
			$data['object'] = $item['thr-parent'];
		}

		if (!empty($item['contact-uid'])) {
			$uid = $item['contact-uid'];
		} else {
			$uid = $item['uid'];
		}

		$owner = User::getOwnerDataById($uid);

		Logger::info('Fetched activity', ['item' => $item_id, 'uid' => $uid]);

		// We don't sign if we aren't the actor. This is important for relaying content especially for forums
		if (!$object_mode && !empty($owner) && ($data['actor'] == $owner['url'])) {
			return LDSignature::sign($data, $owner);
		} else {
			return $data;
		}

		/// @todo Create "conversation" entry
	}

	/**
	 * Creates a location entry for a given item array
	 *
	 * @param array $item Item array
	 * @return array with location array
	 */
	private static function createLocation(array $item): array
	{
		$location = ['type' => 'Place'];

		if (!empty($item['location'])) {
			$location['name'] = $item['location'];
		}

		$coord = [];

		if (empty($item['coord'])) {
			$coord = Map::getCoordinates($item['location']);
		} else {
			$coords = explode(' ', $item['coord']);
			if (count($coords) == 2) {
				$coord = ['lat' => $coords[0], 'lon' => $coords[1]];
			}
		}

		if (!empty($coord['lat']) && !empty($coord['lon'])) {
			$location['latitude'] = $coord['lat'];
			$location['longitude'] = $coord['lon'];
		}

		return $location;
	}

	/**
	 * Returns a tag array for a given item array
	 *
	 * @param array $item Item array
	 * @return array of tags
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function createTagList(array $item): array
	{
		$tags = [];

		$terms = Tag::getByURIId($item['uri-id'], [Tag::HASHTAG, Tag::MENTION, Tag::IMPLICIT_MENTION, Tag::EXCLUSIVE_MENTION]);
		foreach ($terms as $term) {
			if ($term['type'] == Tag::HASHTAG) {
				$url = DI::baseUrl() . '/search?tag=' . urlencode($term['name']);
				$tags[] = ['type' => 'Hashtag', 'href' => $url, 'name' => '#' . $term['name']];
			} else {
				$contact = Contact::getByURL($term['url'], false, ['addr']);
				if (empty($contact)) {
					continue;
				}
				if (!empty($contact['addr'])) {
					$mention = '@' . $contact['addr'];
				} else {
					$mention = '@' . $term['url'];
				}

				$tags[] = ['type' => 'Mention', 'href' => $term['url'], 'name' => $mention];
			}
		}

		$announce = self::getAnnounceArray($item);
		// Mention the original author upon commented reshares
		if (!empty($announce['comment'])) {
			$tags[] = ['type' => 'Mention', 'href' => $announce['actor']['url'], 'name' => '@' . $announce['actor']['addr']];
		}

		return $tags;
	}

	/**
	 * Adds attachment data to the JSON document
	 *
	 * @param array  $item Data of the item that is to be posted
	 * @param string $type Object type
	 *
	 * @return array with attachment data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function createAttachmentList(array $item, string $type): array
	{
		$attachments = [];

		$uriids = [$item['uri-id']];
		$shared = BBCode::fetchShareAttributes($item['body']);
		if (!empty($shared['guid'])) {
			$shared_item = Post::selectFirst(['uri-id'], ['guid' => $shared['guid']]);
			if (!empty($shared_item['uri-id'])) {
				$uriids[] = $shared_item['uri-id'];
			}
		}

		$urls = [];
		foreach ($uriids as $uriid) {
			foreach (Post\Media::getByURIId($uriid, [Post\Media::AUDIO, Post\Media::IMAGE, Post\Media::VIDEO, Post\Media::DOCUMENT, Post\Media::TORRENT]) as $attachment) {
				if (in_array($attachment['url'], $urls)) {
					continue;
				}
				$urls[] = $attachment['url'];

				$attach = ['type' => 'Document',
					'mediaType' => $attachment['mimetype'],
					'url' => $attachment['url'],
					'name' => $attachment['description']];

				if (!empty($attachment['height'])) {
					$attach['height'] = $attachment['height'];
				}

				if (!empty($attachment['width'])) {
					$attach['width'] = $attachment['width'];
				}

				if (!empty($attachment['preview'])) {
					$attach['image'] = $attachment['preview'];
				}

				$attachments[] = $attach;
			}
		}

		return $attachments;
	}

	/**
	 * Callback function to replace a Friendica style mention in a mention for a summary
	 *
	 * @param array $match Matching values for the callback
	 * @return string Replaced mention
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function mentionAddrCallback(array $match): string
	{
		if (empty($match[1])) {
			return '';
		}

		$data = Contact::getByURL($match[1], false, ['addr']);
		if (empty($data['addr'])) {
			return $match[0];
		}

		return '@' . $data['addr'];
	}

	/**
	 * Remove image elements since they are added as attachment
	 *
	 * @param string $body HTML code
	 * @return string with removed images
	 */
	private static function removePictures(string $body): string
	{
		// Simplify image codes
		$body = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $body);
		$body = preg_replace("/\[img\=(.*?)\](.*?)\[\/img\]/ism", '[img]$1[/img]', $body);

		// Now remove local links
		$body = preg_replace_callback(
			'/\[url=([^\[\]]*)\]\[img\](.*)\[\/img\]\[\/url\]/Usi',
			function ($match) {
				// We remove the link when it is a link to a local photo page
				if (Photo::isLocalPage($match[1])) {
					return '';
				}
				// otherwise we just return the link
				return '[url]' . $match[1] . '[/url]';
			},
			$body
		);

		// Remove all pictures
		$body = preg_replace("/\[img\]([^\[\]]*)\[\/img\]/Usi", '', $body);

		return $body;
	}

	/**
	 * Returns if the post contains sensitive content ("nsfw")
	 *
	 * @param integer $uri_id URI id
	 * @return boolean Whether URI id was found
	 * @throws \Exception
	 */
	private static function isSensitive(int $uri_id): bool
	{
		return DBA::exists('tag-view', ['uri-id' => $uri_id, 'name' => 'nsfw', 'type' => Tag::HASHTAG]);
	}

	/**
	 * Creates event data
	 *
	 * @param array $item Item array
	 * @return array with the event data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function createEvent(array $item): array
	{
		$event = [];
		$event['name'] = $item['event-summary'];
		$event['content'] = BBCode::convertForUriId($item['uri-id'], $item['event-desc'], BBCode::ACTIVITYPUB);
		$event['startTime'] = DateTimeFormat::utc($item['event-start'], 'c');

		if (!$item['event-nofinish']) {
			$event['endTime'] = DateTimeFormat::utc($item['event-finish'], 'c');
		}

		if (!empty($item['event-location'])) {
			$item['location'] = $item['event-location'];
			$event['location'] = self::createLocation($item);
		}

		// 2021.12: Backward compatibility value, all the events now "adjust" to the viewer timezone
		$event['dfrn:adjust'] = true;

		return $event;
	}

	/**
	 * Creates a note/article object array
	 *
	 * @param array $item
	 * @return array with the object data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function createNote(array $item): array
	{
		if (empty($item)) {
			return [];
		}

		// We are treating posts differently when they are directed to a community.
		// This is done to better support Lemmy. Most of the changes should work with other systems as well.
		// But to not risk compatibility issues we currently perform the changes only for communities.
		if ($item['gravity'] == GRAVITY_PARENT) {
			$isCommunityPost = !empty(Tag::getByURIId($item['uri-id'], [Tag::EXCLUSIVE_MENTION]));
			$links = Post\Media::getByURIId($item['uri-id'], [Post\Media::HTML]);
			if ($isCommunityPost && (count($links) == 1)) {
				$link = $links[0]['url'];
			}
		} else {
			$isCommunityPost = false;
		}

		if ($item['event-type'] == 'event') {
			$type = 'Event';
		} elseif (!empty($item['title'])) {
			if (!$isCommunityPost || empty($link)) {
				$type = 'Article';
			} else {
				// "Page" is used by Lemmy for posts that contain an external link
				$type = 'Page';
			}
		} else {
			$type = 'Note';
		}

		if ($item['deleted']) {
			$type = 'Tombstone';
		}

		$data = [];
		$data['id'] = $item['uri'];
		$data['type'] = $type;

		if ($item['deleted']) {
			return $data;
		}

		$data['summary'] = BBCode::toPlaintext(BBCode::getAbstract($item['body'], Protocol::ACTIVITYPUB));

		if ($item['uri'] != $item['thr-parent']) {
			$data['inReplyTo'] = $item['thr-parent'];
		} else {
			$data['inReplyTo'] = null;
		}

		$data['diaspora:guid'] = $item['guid'];
		$data['published'] = DateTimeFormat::utc($item['created'] . '+00:00', DateTimeFormat::ATOM);

		if ($item['created'] != $item['edited']) {
			$data['updated'] = DateTimeFormat::utc($item['edited'] . '+00:00', DateTimeFormat::ATOM);
		}

		$data['url'] = $link ?? $item['plink'];
		$data['attributedTo'] = $item['author-link'];
		$data['sensitive'] = self::isSensitive($item['uri-id']);
		$data['conversation'] = $data['context'] = $item['conversation'];

		if (!empty($item['title'])) {
			$data['name'] = BBCode::toPlaintext($item['title'], false);
		}

		$permission_block = self::createPermissionBlockForItem($item, false);

		$body = $item['body'];

		if ($type == 'Note') {
			$body = $item['raw-body'] ?? self::removePictures($body);
		}

		/**
		 * @todo Improve the automated summary
		 * This part is currently deactivated. The automated summary seems to be more
		 * confusing than helping. But possibly we will find a better way.
		 * So the code is left here for now as a reminder
		 *
		 * } elseif (($type == 'Article') && empty($data['summary'])) {
		 * 		$regexp = "/[@!]\[url\=([^\[\]]*)\].*?\[\/url\]/ism";
		 * 		$summary = preg_replace_callback($regexp, ['self', 'mentionAddrCallback'], $body);
		 * 		$data['summary'] = BBCode::toPlaintext(Plaintext::shorten(self::removePictures($summary), 1000));
		 * }
		 */

		if (empty($item['uid']) || !Feature::isEnabled($item['uid'], 'explicit_mentions')) {
			$body = self::prependMentions($body, $item['uri-id'], $item['author-link']);
		}

		if ($type == 'Event') {
			$data = array_merge($data, self::createEvent($item));
		} else {
			if ($isCommunityPost) {
				// For community posts we remove the visible "!user@domain.tld".
				// This improves the look at systems like Lemmy.
				// Also in the future we should control the community delivery via other methods.
				$body = preg_replace("/!\[url\=[^\[\]]*\][^\[\]]*\[\/url\]/ism", '', $body);
			}

			if ($type == 'Page') {
				// When we transmit "Page" posts we have to remove the attachment.
				// The attachment contains the link that we already transmit in the "url" field.
				$body = preg_replace("/\s*\[attachment .*?\].*?\[\/attachment\]\s*/ism", '', $body);
			}

			$body = BBCode::setMentionsToNicknames($body);

			$data['content'] = BBCode::convertForUriId($item['uri-id'], $body, BBCode::ACTIVITYPUB);
		}

		// The regular "content" field does contain a minimized HTML. This is done since systems like
		// Mastodon has got problems with - for example - embedded pictures.
		// The contentMap does contain the unmodified HTML.
		$language = self::getLanguage($item);
		if (!empty($language)) {
			$richbody = BBCode::setMentionsToNicknames($item['body'] ?? '');
			$richbody = BBCode::removeAttachment($richbody);

			$data['contentMap'][$language] = BBCode::convertForUriId($item['uri-id'], $richbody, BBCode::EXTERNAL);
		}

		$data['source'] = ['content' => $item['body'], 'mediaType' => "text/bbcode"];

		if (!empty($item['signed_text']) && ($item['uri'] != $item['thr-parent'])) {
			$data['diaspora:comment'] = $item['signed_text'];
		}

		$data['attachment'] = self::createAttachmentList($item, $type);
		$data['tag'] = self::createTagList($item);

		if (empty($data['location']) && (!empty($item['coord']) || !empty($item['location']))) {
			$data['location'] = self::createLocation($item);
		}

		if (!empty($item['app'])) {
			$data['generator'] = ['type' => 'Application', 'name' => $item['app']];
		}

		$data = array_merge($data, $permission_block);

		return $data;
	}

	/**
	 * Fetches the language from the post, the user or the system.
	 *
	 * @param array $item
	 * @return string language string
	 */
	private static function getLanguage(array $item): string
	{
		// Try to fetch the language from the post itself
		if (!empty($item['language'])) {
			$languages = array_keys(json_decode($item['language'], true));
			if (!empty($languages[0])) {
				return $languages[0];
			}
		}

		// Otherwise use the user's language
		if (!empty($item['uid'])) {
			$user = DBA::selectFirst('user', ['language'], ['uid' => $item['uid']]);
			if (!empty($user['language'])) {
				return $user['language'];
			}
		}

		// And finally just use the system language
		return DI::config()->get('system', 'language');
	}

	/**
	 * Creates an an "add tag" entry
	 *
	 * @param array $item Item array
	 * @param array $activity activity data
	 * @return array with activity data for adding tags
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function createAddTag(array $item, array $activity): array
	{
		$object = XML::parseString($item['object']);
		$target = XML::parseString($item['target']);

		$activity['diaspora:guid'] = $item['guid'];
		$activity['actor'] = $item['author-link'];
		$activity['target'] = (string)$target->id;
		$activity['summary'] = BBCode::toPlaintext($item['body']);
		$activity['object'] = ['id' => (string)$object->id, 'type' => 'tag', 'name' => (string)$object->title, 'content' => (string)$object->content];

		return $activity;
	}

	/**
	 * Creates an announce object entry
	 *
	 * @param array $item Item array
	 * @param array $activity activity data
	 * @return array with activity data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function createAnnounce(array $item, array $activity): array
	{
		$orig_body = $item['body'];
		$announce = self::getAnnounceArray($item);
		if (empty($announce)) {
			$activity['type'] = 'Create';
			$activity['object'] = self::createNote($item);
			return $activity;
		}

		if (empty($announce['comment'])) {
			// Pure announce, without a quote
			$activity['type'] = 'Announce';
			$activity['object'] = $announce['object']['uri'];
			return $activity;
		}

		// Quote
		$activity['type'] = 'Create';
		$item['body'] = $announce['comment'] . "\n" . $announce['object']['plink'];
		$activity['object'] = self::createNote($item);

		/// @todo Finally descide how to implement this in AP. This is a possible way:
		$activity['object']['attachment'][] = self::createNote($announce['object']);

		$activity['object']['source']['content'] = $orig_body;
		return $activity;
	}

	/**
	 * Return announce related data if the item is an annunce
	 *
	 * @param array $item
	 * @return array Announcement array
	 */
	public static function getAnnounceArray(array $item): array
	{
		$reshared = Item::getShareArray($item);
		if (empty($reshared['guid'])) {
			return [];
		}

		$reshared_item = Post::selectFirst(Item::DELIVER_FIELDLIST, ['guid' => $reshared['guid']]);
		if (!DBA::isResult($reshared_item)) {
			return [];
		}

		if (!in_array($reshared_item['network'], [Protocol::ACTIVITYPUB, Protocol::DFRN])) {
			return [];
		}

		$profile = APContact::getByURL($reshared_item['author-link'], false);
		if (empty($profile)) {
			return [];
		}

		return ['object' => $reshared_item, 'actor' => $profile, 'comment' => $reshared['comment']];
	}

	/**
	 * Checks if the provided item array is an announce
	 *
	 * @param array $item Item array
	 * @return boolean Whether item is an announcement
	 */
	public static function isAnnounce(array $item): bool
	{
		if (!empty($item['verb']) && ($item['verb'] == Activity::ANNOUNCE)) {
			return true;
		}

		$announce = self::getAnnounceArray($item);
		if (empty($announce)) {
			return false;
		}

		return empty($announce['comment']);
	}

	/**
	 * Creates an activity id for a given contact id
	 *
	 * @param integer $cid Contact ID of target
	 *
	 * @return bool|string activity id
	 */
	public static function activityIDFromContact(int $cid)
	{
		$contact = DBA::selectFirst('contact', ['uid', 'id', 'created'], ['id' => $cid]);
		if (!DBA::isResult($contact)) {
			return false;
		}

		$hash = hash('ripemd128', $contact['uid'].'-'.$contact['id'].'-'.$contact['created']);
		$uuid = substr($hash, 0, 8). '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
		return DI::baseUrl() . '/activity/' . $uuid;
	}

	/**
	 * Transmits a contact suggestion to a given inbox
	 *
	 * @param integer $uid           User ID
	 * @param string  $inbox         Target inbox
	 * @param integer $suggestion_id Suggestion ID
	 * @return boolean was the transmission successful?
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function sendContactSuggestion(int $uid, string $inbox, int $suggestion_id): bool
	{
		$owner = User::getOwnerDataById($uid);

		$suggestion = DI::fsuggest()->selectOneById($suggestion_id);

		$data = [
			'@context' => ActivityPub::CONTEXT,
			'id' => DI::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Announce',
			'actor' => $owner['url'],
			'object' => $suggestion->url,
			'content' => $suggestion->note,
			'instrument' => self::getService(),
			'to' => [ActivityPub::PUBLIC_COLLECTION],
			'cc' => []
		];

		$signed = LDSignature::sign($data, $owner);

		Logger::info('Deliver profile deletion for user ' . $uid . ' to ' . $inbox . ' via ActivityPub');
		return HTTPSignature::transmit($signed, $inbox, $uid);
	}

	/**
	 * Transmits a profile relocation to a given inbox
	 *
	 * @param integer $uid   User ID
	 * @param string  $inbox Target inbox
	 * @return boolean was the transmission successful?
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function sendProfileRelocation(int $uid, string $inbox): bool
	{
		$owner = User::getOwnerDataById($uid);

		$data = [
			'@context' => ActivityPub::CONTEXT,
			'id' => DI::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'dfrn:relocate',
			'actor' => $owner['url'],
			'object' => $owner['url'],
			'published' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'instrument' => self::getService(),
			'to' => [ActivityPub::PUBLIC_COLLECTION],
			'cc' => []
		];

		$signed = LDSignature::sign($data, $owner);

		Logger::info('Deliver profile relocation for user ' . $uid . ' to ' . $inbox . ' via ActivityPub');
		return HTTPSignature::transmit($signed, $inbox, $uid);
	}

	/**
	 * Transmits a profile deletion to a given inbox
	 *
	 * @param integer $uid   User ID
	 * @param string  $inbox Target inbox
	 * @return boolean was the transmission successful?
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function sendProfileDeletion(int $uid, string $inbox): bool
	{
		$owner = User::getOwnerDataById($uid);

		if (empty($owner)) {
			Logger::error('No owner data found, the deletion message cannot be processed.', ['user' => $uid]);
			return false;
		}

		if (empty($owner['uprvkey'])) {
			Logger::error('No private key for owner found, the deletion message cannot be processed.', ['user' => $uid]);
			return false;
		}

		$data = ['@context' => ActivityPub::CONTEXT,
			'id' => DI::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Delete',
			'actor' => $owner['url'],
			'object' => $owner['url'],
			'published' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'instrument' => self::getService(),
			'to' => [ActivityPub::PUBLIC_COLLECTION],
			'cc' => []];

		$signed = LDSignature::sign($data, $owner);

		Logger::info('Deliver profile deletion for user ' . $uid . ' to ' . $inbox . ' via ActivityPub');
		return HTTPSignature::transmit($signed, $inbox, $uid);
	}

	/**
	 * Transmits a profile change to a given inbox
	 *
	 * @param integer $uid   User ID
	 * @param string  $inbox Target inbox
	 * @return boolean was the transmission successful?
	 * @throws HTTPException\InternalServerErrorException
	 * @throws HTTPException\NotFoundException
	 * @throws \ImagickException
	 */
	public static function sendProfileUpdate(int $uid, string $inbox): bool
	{
		$owner = User::getOwnerDataById($uid);
		$profile = APContact::getByURL($owner['url']);

		$data = ['@context' => ActivityPub::CONTEXT,
			'id' => DI::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Update',
			'actor' => $owner['url'],
			'object' => self::getProfile($uid),
			'published' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'instrument' => self::getService(),
			'to' => [$profile['followers']],
			'cc' => []];

		$signed = LDSignature::sign($data, $owner);

		Logger::info('Deliver profile update for user ' . $uid . ' to ' . $inbox . ' via ActivityPub');
		return HTTPSignature::transmit($signed, $inbox, $uid);
	}

	/**
	 * Transmits a given activity to a target
	 *
	 * @param string  $activity Type name
	 * @param string  $target   Target profile
	 * @param integer $uid      User ID
	 * @param string  $id Activity-identifier
	 * @return bool
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 * @throws \Exception
	 */
	public static function sendActivity(string $activity, string $target, int $uid, string $id = ''): bool
	{
		$profile = APContact::getByURL($target);
		if (empty($profile['inbox'])) {
			Logger::warning('No inbox found for target', ['target' => $target, 'profile' => $profile]);
			return false;
		}

		$owner = User::getOwnerDataById($uid);

		if (empty($id)) {
			$id = DI::baseUrl() . '/activity/' . System::createGUID();
		}

		$data = [
			'@context' => ActivityPub::CONTEXT,
			'id' => $id,
			'type' => $activity,
			'actor' => $owner['url'],
			'object' => $profile['url'],
			'instrument' => self::getService(),
			'to' => [$profile['url']],
		];

		Logger::info('Sending activity ' . $activity . ' to ' . $target . ' for user ' . $uid);

		$signed = LDSignature::sign($data, $owner);
		return HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * Transmits a "follow object" activity to a target
	 * This is a preparation for sending automated "follow" requests when receiving "Announce" messages
	 *
	 * @param string  $object Object URL
	 * @param string  $target Target profile
	 * @param integer $uid    User ID
	 * @return bool
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 * @throws \Exception
	 */
	public static function sendFollowObject(string $object, string $target, int $uid = 0): bool
	{
		$profile = APContact::getByURL($target);
		if (empty($profile['inbox'])) {
			Logger::warning('No inbox found for target', ['target' => $target, 'profile' => $profile]);
			return false;
		}

		if (empty($uid)) {
			// Fetch the list of administrators
			$admin_mail = explode(',', str_replace(' ', '', DI::config()->get('config', 'admin_email')));

			// We need to use some user as a sender. It doesn't care who it will send. We will use an administrator account.
			$condition = ['verified' => true, 'blocked' => false, 'account_removed' => false, 'account_expired' => false, 'email' => $admin_mail];
			$first_user = DBA::selectFirst('user', ['uid'], $condition);
			$uid = $first_user['uid'];
		}

		$condition = ['verb' => Activity::FOLLOW, 'uid' => 0, 'parent-uri' => $object,
			'author-id' => Contact::getPublicIdByUserId($uid)];
		if (Post::exists($condition)) {
			Logger::info('Follow for ' . $object . ' for user ' . $uid . ' does already exist.');
			return false;
		}

		$owner = User::getOwnerDataById($uid);

		$data = [
			'@context' => ActivityPub::CONTEXT,
			'id' => DI::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Follow',
			'actor' => $owner['url'],
			'object' => $object,
			'instrument' => self::getService(),
			'to' => [$profile['url']],
		];

		Logger::info('Sending follow ' . $object . ' to ' . $target . ' for user ' . $uid);

		$signed = LDSignature::sign($data, $owner);
		return HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * Transmit a message that the contact request had been accepted
	 *
	 * @param string  $target Target profile
	 * @param string  $id Object id
	 * @param integer $uid    User ID
	 * @return void
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function sendContactAccept(string $target, string $id, int $uid)
	{
		$profile = APContact::getByURL($target);
		if (empty($profile['inbox'])) {
			Logger::warning('No inbox found for target', ['target' => $target, 'profile' => $profile]);
			return;
		}

		$owner = User::getOwnerDataById($uid);
		$data = [
			'@context' => ActivityPub::CONTEXT,
			'id' => DI::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Accept',
			'actor' => $owner['url'],
			'object' => [
				'id' => $id,
				'type' => 'Follow',
				'actor' => $profile['url'],
				'object' => $owner['url']
			],
			'instrument' => self::getService(),
			'to' => [$profile['url']],
		];

		Logger::debug('Sending accept to ' . $target . ' for user ' . $uid . ' with id ' . $id);

		$signed = LDSignature::sign($data, $owner);
		HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * Reject a contact request or terminates the contact relation
	 *
	 * @param string $target   Target profile
	 * @param string $objectId Object id
	 * @param int    $uid      User ID
	 * @return bool Operation success
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function sendContactReject(string $target, string $objectId, int $uid): bool
	{
		$profile = APContact::getByURL($target);
		if (empty($profile['inbox'])) {
			Logger::warning('No inbox found for target', ['target' => $target, 'profile' => $profile]);
			return false;
		}

		$owner = User::getOwnerDataById($uid);
		$data = [
			'@context' => ActivityPub::CONTEXT,
			'id' => DI::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Reject',
			'actor'  => $owner['url'],
			'object' => [
				'id' => $objectId,
				'type' => 'Follow',
				'actor' => $profile['url'],
				'object' => $owner['url']
			],
			'instrument' => self::getService(),
			'to' => [$profile['url']],
		];

		Logger::debug('Sending reject to ' . $target . ' for user ' . $uid . ' with id ' . $objectId);

		$signed = LDSignature::sign($data, $owner);
		return HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * Transmits a message that we don't want to follow this contact anymore
	 *
	 * @param string  $target Target profile
	 * @param integer $cid    Contact id
	 * @param integer $uid    User ID
	 * @return bool success
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 * @throws \Exception
	 */
	public static function sendContactUndo(string $target, int $cid, int $uid): bool
	{
		$profile = APContact::getByURL($target);
		if (empty($profile['inbox'])) {
			Logger::warning('No inbox found for target', ['target' => $target, 'profile' => $profile]);
			return false;
		}

		$object_id = self::activityIDFromContact($cid);
		if (empty($object_id)) {
			return false;
		}

		$objectId = DI::baseUrl() . '/activity/' . System::createGUID();

		$owner = User::getOwnerDataById($uid);
		$data = [
			'@context' => ActivityPub::CONTEXT,
			'id' => $objectId,
			'type' => 'Undo',
			'actor' => $owner['url'],
			'object' => [
				'id' => $object_id,
				'type' => 'Follow',
				'actor' => $owner['url'],
				'object' => $profile['url']
			],
			'instrument' => self::getService(),
			'to' => [$profile['url']],
		];

		Logger::info('Sending undo to ' . $target . ' for user ' . $uid . ' with id ' . $objectId);

		$signed = LDSignature::sign($data, $owner);
		return HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * Prepends mentions (@) to $body variable
	 *
	 * @param string $body HTML code
	 * @param int    $uriId
	 * @param string $authorLink Author link
	 * @return string HTML code with prepended mentions
	 */
	private static function prependMentions(string $body, int $uriid, string $authorLink): string
	{
		$mentions = [];

		foreach (Tag::getByURIId($uriid, [Tag::IMPLICIT_MENTION]) as $tag) {
			$profile = Contact::getByURL($tag['url'], false, ['addr', 'contact-type', 'nick']);
			if (!empty($profile['addr'])
				&& $profile['contact-type'] != Contact::TYPE_COMMUNITY
				&& !strstr($body, $profile['addr'])
				&& !strstr($body, $tag['url'])
				&& $tag['url'] !== $authorLink
			) {
				$mentions[] = '@[url=' . $tag['url'] . ']' . $profile['nick'] . '[/url]';
			}
		}

		$mentions[] = $body;

		return implode(' ', $mentions);
	}
}
