<?php
/**
 * @file src/Protocol/ActivityPub/Transmitter.php
 */
namespace Friendica\Protocol\ActivityPub;

use Friendica\Database\DBA;
use Friendica\Core\System;
use Friendica\BaseObject;
use Friendica\Util\HTTPSignature;
use Friendica\Core\Protocol;
use Friendica\Model\Conversation;
use Friendica\Model\Contact;
use Friendica\Model\APContact;
use Friendica\Model\Item;
use Friendica\Model\Term;
use Friendica\Model\User;
use Friendica\Util\DateTimeFormat;
use Friendica\Content\Text\BBCode;
use Friendica\Util\JsonLD;
use Friendica\Util\LDSignature;
use Friendica\Protocol\ActivityPub;
use Friendica\Model\Profile;

/**
 * @brief ActivityPub Transmitter Protocol class
 *
 * To-Do:
 * - Event
 *
 * Complicated:
 * - Announce
 * - Undo Announce
 *
 * General:
 * - Attachments
 * - nsfw (sensitive)
 * - Queueing unsucessful deliveries
 */
class Transmitter
{
	/**
	 * @brief collects the lost of followers of the given owner
	 *
	 * @param array $owner Owner array
	 * @param integer $page Page number
	 *
	 * @return array of owners
	 */
	public static function getFollowers($owner, $page = null)
	{
		$condition = ['rel' => [Contact::FOLLOWER, Contact::FRIEND], 'network' => Protocol::NATIVE_SUPPORT, 'uid' => $owner['uid'],
			'self' => false, 'hidden' => false, 'archive' => false, 'pending' => false];
		$count = DBA::count('contact', $condition);

		$data = ['@context' => ActivityPub::CONTEXT];
		$data['id'] = System::baseUrl() . '/followers/' . $owner['nickname'];
		$data['type'] = 'OrderedCollection';
		$data['totalItems'] = $count;

		// When we hide our friends we will only show the pure number but don't allow more.
		$profile = Profile::getByUID($owner['uid']);
		if (!empty($profile['hide-friends'])) {
			return $data;
		}

		if (empty($page)) {
			$data['first'] = System::baseUrl() . '/followers/' . $owner['nickname'] . '?page=1';
		} else {
			$list = [];

			$contacts = DBA::select('contact', ['url'], $condition, ['limit' => [($page - 1) * 100, 100]]);
			while ($contact = DBA::fetch($contacts)) {
				$list[] = $contact['url'];
			}

			if (!empty($list)) {
				$data['next'] = System::baseUrl() . '/followers/' . $owner['nickname'] . '?page=' . ($page + 1);
			}

			$data['partOf'] = System::baseUrl() . '/followers/' . $owner['nickname'];

			$data['orderedItems'] = $list;
		}

		return $data;
	}

	/**
	 * @brief Create list of following contacts
	 *
	 * @param array $owner Owner array
	 * @param integer $page Page numbe
	 *
	 * @return array of following contacts
	 */
	public static function getFollowing($owner, $page = null)
	{
		$condition = ['rel' => [Contact::SHARING, Contact::FRIEND], 'network' => Protocol::NATIVE_SUPPORT, 'uid' => $owner['uid'],
			'self' => false, 'hidden' => false, 'archive' => false, 'pending' => false];
		$count = DBA::count('contact', $condition);

		$data = ['@context' => ActivityPub::CONTEXT];
		$data['id'] = System::baseUrl() . '/following/' . $owner['nickname'];
		$data['type'] = 'OrderedCollection';
		$data['totalItems'] = $count;

		// When we hide our friends we will only show the pure number but don't allow more.
		$profile = Profile::getByUID($owner['uid']);
		if (!empty($profile['hide-friends'])) {
			return $data;
		}

		if (empty($page)) {
			$data['first'] = System::baseUrl() . '/following/' . $owner['nickname'] . '?page=1';
		} else {
			$list = [];

			$contacts = DBA::select('contact', ['url'], $condition, ['limit' => [($page - 1) * 100, 100]]);
			while ($contact = DBA::fetch($contacts)) {
				$list[] = $contact['url'];
			}

			if (!empty($list)) {
				$data['next'] = System::baseUrl() . '/following/' . $owner['nickname'] . '?page=' . ($page + 1);
			}

			$data['partOf'] = System::baseUrl() . '/following/' . $owner['nickname'];

			$data['orderedItems'] = $list;
		}

		return $data;
	}

	/**
	 * @brief Public posts for the given owner
	 *
	 * @param array $owner Owner array
	 * @param integer $page Page numbe
	 *
	 * @return array of posts
	 */
	public static function getOutbox($owner, $page = null)
	{
		$public_contact = Contact::getIdForURL($owner['url'], 0, true);

		$condition = ['uid' => $owner['uid'], 'contact-id' => $owner['id'], 'author-id' => $public_contact,
			'wall' => true, 'private' => false, 'gravity' => [GRAVITY_PARENT, GRAVITY_COMMENT],
			'deleted' => false, 'visible' => true];
		$count = DBA::count('item', $condition);

		$data = ['@context' => ActivityPub::CONTEXT];
		$data['id'] = System::baseUrl() . '/outbox/' . $owner['nickname'];
		$data['type'] = 'OrderedCollection';
		$data['totalItems'] = $count;

		if (empty($page)) {
			$data['first'] = System::baseUrl() . '/outbox/' . $owner['nickname'] . '?page=1';
		} else {
			$list = [];

			$condition['parent-network'] = Protocol::NATIVE_SUPPORT;

			$items = Item::select(['id'], $condition, ['limit' => [($page - 1) * 20, 20], 'order' => ['created' => true]]);
			while ($item = Item::fetch($items)) {
				$object = self::createObjectFromItemID($item['id']);
				unset($object['@context']);
				$list[] = $object;
			}

			if (!empty($list)) {
				$data['next'] = System::baseUrl() . '/outbox/' . $owner['nickname'] . '?page=' . ($page + 1);
			}

			$data['partOf'] = System::baseUrl() . '/outbox/' . $owner['nickname'];

			$data['orderedItems'] = $list;
		}

		return $data;
	}

	/**
	 * Return the ActivityPub profile of the given user
	 *
	 * @param integer $uid User ID
	 * @return profile array
	 */
	public static function profile($uid)
	{
		$condition = ['uid' => $uid, 'blocked' => false, 'account_expired' => false,
			'account_removed' => false, 'verified' => true];
		$fields = ['guid', 'nickname', 'pubkey', 'account-type', 'page-flags'];
		$user = DBA::selectFirst('user', $fields, $condition);
		if (!DBA::isResult($user)) {
			return [];
		}

		$fields = ['locality', 'region', 'country-name'];
		$profile = DBA::selectFirst('profile', $fields, ['uid' => $uid, 'is-default' => true]);
		if (!DBA::isResult($profile)) {
			return [];
		}

		$fields = ['name', 'url', 'location', 'about', 'avatar'];
		$contact = DBA::selectFirst('contact', $fields, ['uid' => $uid, 'self' => true]);
		if (!DBA::isResult($contact)) {
			return [];
		}

		$data = ['@context' => ActivityPub::CONTEXT];
		$data['id'] = $contact['url'];
		$data['diaspora:guid'] = $user['guid'];
		$data['type'] = ActivityPub::ACCOUNT_TYPES[$user['account-type']];
		$data['following'] = System::baseUrl() . '/following/' . $user['nickname'];
		$data['followers'] = System::baseUrl() . '/followers/' . $user['nickname'];
		$data['inbox'] = System::baseUrl() . '/inbox/' . $user['nickname'];
		$data['outbox'] = System::baseUrl() . '/outbox/' . $user['nickname'];
		$data['preferredUsername'] = $user['nickname'];
		$data['name'] = $contact['name'];
		$data['vcard:hasAddress'] = ['@type' => 'vcard:Home', 'vcard:country-name' => $profile['country-name'],
			'vcard:region' => $profile['region'], 'vcard:locality' => $profile['locality']];
		$data['summary'] = $contact['about'];
		$data['url'] = $contact['url'];
		$data['manuallyApprovesFollowers'] = in_array($user['page-flags'], [Contact::PAGE_NORMAL, Contact::PAGE_PRVGROUP]);
		$data['publicKey'] = ['id' => $contact['url'] . '#main-key',
			'owner' => $contact['url'],
			'publicKeyPem' => $user['pubkey']];
		$data['endpoints'] = ['sharedInbox' => System::baseUrl() . '/inbox'];
		$data['icon'] = ['type' => 'Image',
			'url' => $contact['avatar']];

		// tags: https://kitty.town/@inmysocks/100656097926961126.json
		return $data;
	}

	/**
	 * @brief Returns an array with permissions of a given item array
	 *
	 * @param array $item
	 *
	 * @return array with permissions
	 */
	private static function fetchPermissionBlockFromConversation($item)
	{
		if (empty($item['thr-parent'])) {
			return [];
		}

		$condition = ['item-uri' => $item['thr-parent'], 'protocol' => Conversation::PARCEL_ACTIVITYPUB];
		$conversation = DBA::selectFirst('conversation', ['source'], $condition);
		if (!DBA::isResult($conversation)) {
			return [];
		}

		$activity = json_decode($conversation['source'], true);

		$actor = JsonLD::fetchElement($activity, 'actor', 'id');
		$profile = APContact::getByURL($actor);

		$item_profile = APContact::getByURL($item['author-link']);
		$exclude[] = $item['author-link'];

		if ($item['gravity'] == GRAVITY_PARENT) {
			$exclude[] = $item['owner-link'];
		}

		$permissions['to'][] = $actor;

		foreach (['to', 'cc', 'bto', 'bcc'] as $element) {
			if (empty($activity[$element])) {
				continue;
			}
			if (is_string($activity[$element])) {
				$activity[$element] = [$activity[$element]];
			}

			foreach ($activity[$element] as $receiver) {
				if ($receiver == $profile['followers'] && !empty($item_profile['followers'])) {
					$receiver = $item_profile['followers'];
				}
				if (!in_array($receiver, $exclude)) {
					$permissions[$element][] = $receiver;
				}
			}
		}
		return $permissions;
	}

	/**
	 * @brief Creates an array of permissions from an item thread
	 *
	 * @param array $item
	 *
	 * @return permission array
	 */
	private static function createPermissionBlockForItem($item)
	{
		$data = ['to' => [], 'cc' => []];

		$data = array_merge($data, self::fetchPermissionBlockFromConversation($item));

		$actor_profile = APContact::getByURL($item['author-link']);

		$terms = Term::tagArrayFromItemId($item['id'], TERM_MENTION);

		$contacts[$item['author-link']] = $item['author-link'];

		if (!$item['private']) {
			$data['to'][] = ActivityPub::PUBLIC_COLLECTION;
			if (!empty($actor_profile['followers'])) {
				$data['cc'][] = $actor_profile['followers'];
			}

			foreach ($terms as $term) {
				$profile = APContact::getByURL($term['url'], false);
				if (!empty($profile) && empty($contacts[$profile['url']])) {
					$data['cc'][] = $profile['url'];
					$contacts[$profile['url']] = $profile['url'];
				}
			}
		} else {
			$receiver_list = Item::enumeratePermissions($item);

			$mentioned = [];

			foreach ($terms as $term) {
				$cid = Contact::getIdForURL($term['url'], $item['uid']);
				if (!empty($cid) && in_array($cid, $receiver_list)) {
					$contact = DBA::selectFirst('contact', ['url'], ['id' => $cid, 'network' => Protocol::ACTIVITYPUB]);
					$data['to'][] = $contact['url'];
					$contacts[$contact['url']] = $contact['url'];
				}
			}

			foreach ($receiver_list as $receiver) {
				$contact = DBA::selectFirst('contact', ['url'], ['id' => $receiver, 'network' => Protocol::ACTIVITYPUB]);
				if (empty($contacts[$contact['url']])) {
					$data['cc'][] = $contact['url'];
					$contacts[$contact['url']] = $contact['url'];
				}
			}
		}

		$parents = Item::select(['id', 'author-link', 'owner-link', 'gravity'], ['parent' => $item['parent']]);
		while ($parent = Item::fetch($parents)) {
			// Don't include data from future posts
			if ($parent['id'] >= $item['id']) {
				continue;
			}

			$profile = APContact::getByURL($parent['author-link'], false);
			if (!empty($profile) && empty($contacts[$profile['url']])) {
				$data['cc'][] = $profile['url'];
				$contacts[$profile['url']] = $profile['url'];
			}

			if ($item['gravity'] != GRAVITY_PARENT) {
				continue;
			}

			$profile = APContact::getByURL($parent['owner-link'], false);
			if (!empty($profile) && empty($contacts[$profile['url']])) {
				$data['cc'][] = $profile['url'];
				$contacts[$profile['url']] = $profile['url'];
			}
		}
		DBA::close($parents);

		if (empty($data['to'])) {
			$data['to'] = $data['cc'];
			$data['cc'] = [];
		}

		return $data;
	}

	/**
	 * @brief Fetches a list of inboxes of followers of a given user
	 *
	 * @param integer $uid User ID
	 *
	 * @return array of follower inboxes
	 */
	public static function fetchTargetInboxesforUser($uid)
	{
		$inboxes = [];

		$condition = ['uid' => $uid, 'network' => Protocol::ACTIVITYPUB, 'archive' => false, 'pending' => false];

		if (!empty($uid)) {
			$condition['rel'] = [Contact::FOLLOWER, Contact::FRIEND];
		}

		$contacts = DBA::select('contact', ['notify', 'batch'], $condition);
		while ($contact = DBA::fetch($contacts)) {
			$contact = defaults($contact, 'batch', $contact['notify']);
			$inboxes[$contact] = $contact;
		}
		DBA::close($contacts);

		return $inboxes;
	}

	/**
	 * @brief Fetches an array of inboxes for the given item and user
	 *
	 * @param array $item
	 * @param integer $uid User ID
	 *
	 * @return array with inboxes
	 */
	public static function fetchTargetInboxes($item, $uid)
	{
		$permissions = self::createPermissionBlockForItem($item);
		if (empty($permissions)) {
			return [];
		}

		$inboxes = [];

		if ($item['gravity'] == GRAVITY_ACTIVITY) {
			$item_profile = APContact::getByURL($item['author-link']);
		} else {
			$item_profile = APContact::getByURL($item['owner-link']);
		}

		foreach (['to', 'cc', 'bto', 'bcc'] as $element) {
			if (empty($permissions[$element])) {
				continue;
			}

			foreach ($permissions[$element] as $receiver) {
				if ($receiver == $item_profile['followers']) {
					$inboxes = self::fetchTargetInboxesforUser($uid);
				} else {
					$profile = APContact::getByURL($receiver);
					if (!empty($profile)) {
						$target = defaults($profile, 'sharedinbox', $profile['inbox']);
						$inboxes[$target] = $target;
					}
				}
			}
		}

		return $inboxes;
	}

	/**
	 * @brief Returns the activity type of a given item
	 *
	 * @param array $item
	 *
	 * @return activity type
	 */
	private static function getTypeOfItem($item)
	{
		if ($item['verb'] == ACTIVITY_POST) {
			if ($item['created'] == $item['edited']) {
				$type = 'Create';
			} else {
				$type = 'Update';
			}
		} elseif ($item['verb'] == ACTIVITY_LIKE) {
			$type = 'Like';
		} elseif ($item['verb'] == ACTIVITY_DISLIKE) {
			$type = 'Dislike';
		} elseif ($item['verb'] == ACTIVITY_ATTEND) {
			$type = 'Accept';
		} elseif ($item['verb'] == ACTIVITY_ATTENDNO) {
			$type = 'Reject';
		} elseif ($item['verb'] == ACTIVITY_ATTENDMAYBE) {
			$type = 'TentativeAccept';
		} else {
			$type = '';
		}

		return $type;
	}

	/**
	 * @brief Creates an activity array for a given item id
	 *
	 * @param integer $item_id
	 * @param boolean $object_mode Is the activity item is used inside another object?
	 *
	 * @return array of activity
	 */
	public static function createActivityFromItem($item_id, $object_mode = false)
	{
		$item = Item::selectFirst([], ['id' => $item_id, 'parent-network' => Protocol::NATIVE_SUPPORT]);

		if (!DBA::isResult($item)) {
			return false;
		}

		$condition = ['item-uri' => $item['uri'], 'protocol' => Conversation::PARCEL_ACTIVITYPUB];
		$conversation = DBA::selectFirst('conversation', ['source'], $condition);
		if (DBA::isResult($conversation)) {
			$data = json_decode($conversation['source']);
			if (!empty($data)) {
				return $data;
			}
		}

		$type = self::getTypeOfItem($item);

		if (!$object_mode) {
			$data = ['@context' => ActivityPub::CONTEXT];

			if ($item['deleted'] && ($item['gravity'] == GRAVITY_ACTIVITY)) {
				$type = 'Undo';
			} elseif ($item['deleted']) {
				$type = 'Delete';
			}
		} else {
			$data = [];
		}

		$data['id'] = $item['uri'] . '#' . $type;
		$data['type'] = $type;
		$data['actor'] = $item['author-link'];

		$data['published'] = DateTimeFormat::utc($item["created"]."+00:00", DateTimeFormat::ATOM);

		if ($item["created"] != $item["edited"]) {
			$data['updated'] = DateTimeFormat::utc($item["edited"]."+00:00", DateTimeFormat::ATOM);
		}

		$data['context'] = self::fetchContextURLForItem($item);

		$data = array_merge($data, self::createPermissionBlockForItem($item));

		if (in_array($data['type'], ['Create', 'Update', 'Announce', 'Delete'])) {
			$data['object'] = self::createNote($item);
		} elseif ($data['type'] == 'Undo') {
			$data['object'] = self::createActivityFromItem($item_id, true);
		} else {
			$data['object'] = $item['thr-parent'];
		}

		$owner = User::getOwnerDataById($item['uid']);

		if (!$object_mode) {
			return LDSignature::sign($data, $owner);
		} else {
			return $data;
		}
	}

	/**
	 * @brief Creates an object array for a given item id
	 *
	 * @param integer $item_id
	 *
	 * @return object array
	 */
	public static function createObjectFromItemID($item_id)
	{
		$item = Item::selectFirst([], ['id' => $item_id, 'parent-network' => Protocol::NATIVE_SUPPORT]);

		if (!DBA::isResult($item)) {
			return false;
		}

		$data = ['@context' => ActivityPub::CONTEXT];
		$data = array_merge($data, self::createNote($item));

		return $data;
	}

	/**
	 * @brief Returns a tag array for a given item array
	 *
	 * @param array $item
	 *
	 * @return array of tags
	 */
	private static function createTagList($item)
	{
		$tags = [];

		$terms = Term::tagArrayFromItemId($item['id'], TERM_MENTION);
		foreach ($terms as $term) {
			$contact = Contact::getDetailsByURL($term['url']);
			if (!empty($contact['addr'])) {
				$mention = '@' . $contact['addr'];
			} else {
				$mention = '@' . $term['url'];
			}

			$tags[] = ['type' => 'Mention', 'href' => $term['url'], 'name' => $mention];
		}
		return $tags;
	}

	/**
	 * @brief Fetches the "context" value for a givem item array from the "conversation" table
	 *
	 * @param array $item
	 *
	 * @return string with context url
	 */
	private static function fetchContextURLForItem($item)
	{
		$conversation = DBA::selectFirst('conversation', ['conversation-href', 'conversation-uri'], ['item-uri' => $item['parent-uri']]);
		if (DBA::isResult($conversation) && !empty($conversation['conversation-href'])) {
			$context_uri = $conversation['conversation-href'];
		} elseif (DBA::isResult($conversation) && !empty($conversation['conversation-uri'])) {
			$context_uri = $conversation['conversation-uri'];
		} else {
			$context_uri = str_replace('/objects/', '/context/', $item['parent-uri']);
		}
		return $context_uri;
	}

	/**
	 * @brief Creates a note/article object array
	 *
	 * @param array $item
	 *
	 * @return object array
	 */
	private static function createNote($item)
	{
		if (!empty($item['title'])) {
			$type = 'Article';
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

		$data['summary'] = null; // Ignore by now

		if ($item['uri'] != $item['thr-parent']) {
			$data['inReplyTo'] = $item['thr-parent'];
		} else {
			$data['inReplyTo'] = null;
		}

		$data['diaspora:guid'] = $item['guid'];
		$data['published'] = DateTimeFormat::utc($item["created"]."+00:00", DateTimeFormat::ATOM);

		if ($item["created"] != $item["edited"]) {
			$data['updated'] = DateTimeFormat::utc($item["edited"]."+00:00", DateTimeFormat::ATOM);
		}

		$data['url'] = $item['plink'];
		$data['attributedTo'] = $item['author-link'];
		$data['actor'] = $item['author-link'];
		$data['sensitive'] = false; // - Query NSFW
		$data['context'] = self::fetchContextURLForItem($item);

		if (!empty($item['title'])) {
			$data['name'] = BBCode::convert($item['title'], false, 7);
		}

		$data['content'] = BBCode::convert($item['body'], false, 7);
		$data['source'] = ['content' => $item['body'], 'mediaType' => "text/bbcode"];

		if (!empty($item['signed_text']) && ($item['uri'] != $item['thr-parent'])) {
			$data['diaspora:comment'] = $item['signed_text'];
		}

		$data['attachment'] = []; // @ToDo
		$data['tag'] = self::createTagList($item);
		$data = array_merge($data, self::createPermissionBlockForItem($item));

		return $data;
	}

	/**
	 * @brief Transmits a profile deletion to a given inbox
	 *
	 * @param integer $uid User ID
	 * @param string $inbox Target inbox
	 */
	public static function transmitProfileDeletion($uid, $inbox)
	{
		$owner = User::getOwnerDataById($uid);
		$profile = APContact::getByURL($owner['url']);

		$data = ['@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Delete',
			'actor' => $owner['url'],
			'object' => $owner['url'],
			'published' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'to' => [ActivityPub::PUBLIC_COLLECTION],
			'cc' => []];

		$signed = LDSignature::sign($data, $owner);

		logger('Deliver profile deletion for user ' . $uid . ' to ' . $inbox .' via ActivityPub', LOGGER_DEBUG);
		HTTPSignature::transmit($signed, $inbox, $uid);
	}

	/**
	 * @brief Transmits a profile change to a given inbox
	 *
	 * @param integer $uid User ID
	 * @param string $inbox Target inbox
	 */
	public static function transmitProfileUpdate($uid, $inbox)
	{
		$owner = User::getOwnerDataById($uid);
		$profile = APContact::getByURL($owner['url']);

		$data = ['@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Update',
			'actor' => $owner['url'],
			'object' => self::profile($uid),
			'published' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'to' => [$profile['followers']],
			'cc' => []];

		$signed = LDSignature::sign($data, $owner);

		logger('Deliver profile update for user ' . $uid . ' to ' . $inbox .' via ActivityPub', LOGGER_DEBUG);
		HTTPSignature::transmit($signed, $inbox, $uid);
	}

	/**
	 * @brief Transmits a given activity to a target
	 *
	 * @param array $activity
	 * @param string $target Target profile
	 * @param integer $uid User ID
	 */
	public static function transmitActivity($activity, $target, $uid)
	{
		$profile = APContact::getByURL($target);

		$owner = User::getOwnerDataById($uid);

		$data = ['@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => $activity,
			'actor' => $owner['url'],
			'object' => $profile['url'],
			'to' => $profile['url']];

		logger('Sending activity ' . $activity . ' to ' . $target . ' for user ' . $uid, LOGGER_DEBUG);

		$signed = LDSignature::sign($data, $owner);
		HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * @brief Transmit a message that the contact request had been accepted
	 *
	 * @param string $target Target profile
	 * @param $id
	 * @param integer $uid User ID
	 */
	public static function transmitContactAccept($target, $id, $uid)
	{
		$profile = APContact::getByURL($target);

		$owner = User::getOwnerDataById($uid);
		$data = ['@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Accept',
			'actor' => $owner['url'],
			'object' => ['id' => $id, 'type' => 'Follow',
				'actor' => $profile['url'],
				'object' => $owner['url']],
			'to' => $profile['url']];

		logger('Sending accept to ' . $target . ' for user ' . $uid . ' with id ' . $id, LOGGER_DEBUG);

		$signed = LDSignature::sign($data, $owner);
		HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * @brief 
	 *
	 * @param string $target Target profile
	 * @param $id
	 * @param integer $uid User ID
	 */
	public static function transmitContactReject($target, $id, $uid)
	{
		$profile = APContact::getByURL($target);

		$owner = User::getOwnerDataById($uid);
		$data = ['@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Reject',
			'actor' => $owner['url'],
			'object' => ['id' => $id, 'type' => 'Follow',
				'actor' => $profile['url'],
				'object' => $owner['url']],
			'to' => $profile['url']];

		logger('Sending reject to ' . $target . ' for user ' . $uid . ' with id ' . $id, LOGGER_DEBUG);

		$signed = LDSignature::sign($data, $owner);
		HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * @brief 
	 *
	 * @param string $target Target profile
	 * @param integer $uid User ID
	 */
	public static function transmitContactUndo($target, $uid)
	{
		$profile = APContact::getByURL($target);

		$id = System::baseUrl() . '/activity/' . System::createGUID();

		$owner = User::getOwnerDataById($uid);
		$data = ['@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $id,
			'type' => 'Undo',
			'actor' => $owner['url'],
			'object' => ['id' => $id, 'type' => 'Follow',
				'actor' => $owner['url'],
				'object' => $profile['url']],
			'to' => $profile['url']];

		logger('Sending undo to ' . $target . ' for user ' . $uid . ' with id ' . $id, LOGGER_DEBUG);

		$signed = LDSignature::sign($data, $owner);
		HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}
}
