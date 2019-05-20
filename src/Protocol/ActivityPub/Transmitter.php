<?php
/**
 * @file src/Protocol/ActivityPub/Transmitter.php
 */
namespace Friendica\Protocol\ActivityPub;

use Friendica\BaseObject;
use Friendica\Content\Feature;
use Friendica\Database\DBA;
use Friendica\Core\Config;
use Friendica\Core\Logger;
use Friendica\Core\System;
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
use Friendica\Content\Text\Plaintext;
use Friendica\Util\JsonLD;
use Friendica\Util\LDSignature;
use Friendica\Model\Profile;
use Friendica\Object\Image;
use Friendica\Protocol\ActivityPub;
use Friendica\Core\Cache;
use Friendica\Util\Map;
use Friendica\Util\Network;

require_once 'include/api.php';
require_once 'mod/share.php';

/**
 * @brief ActivityPub Transmitter Protocol class
 *
 * To-Do:
 * - Undo Announce
 */
class Transmitter
{
	/**
	 * collects the lost of followers of the given owner
	 *
	 * @param array   $owner Owner array
	 * @param integer $page  Page number
	 *
	 * @return array of owners
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function getFollowers($owner, $page = null)
	{
		$condition = ['rel' => [Contact::FOLLOWER, Contact::FRIEND], 'network' => Protocol::NATIVE_SUPPORT, 'uid' => $owner['uid'],
			'self' => false, 'deleted' => false, 'hidden' => false, 'archive' => false, 'pending' => false];
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
	 * Create list of following contacts
	 *
	 * @param array   $owner Owner array
	 * @param integer $page  Page numbe
	 *
	 * @return array of following contacts
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function getFollowing($owner, $page = null)
	{
		$condition = ['rel' => [Contact::SHARING, Contact::FRIEND], 'network' => Protocol::NATIVE_SUPPORT, 'uid' => $owner['uid'],
			'self' => false, 'deleted' => false, 'hidden' => false, 'archive' => false, 'pending' => false];
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
	 * Public posts for the given owner
	 *
	 * @param array   $owner Owner array
	 * @param integer $page  Page numbe
	 *
	 * @return array of posts
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function getOutbox($owner, $page = null)
	{
		$public_contact = Contact::getIdForURL($owner['url'], 0, true);

		$condition = ['uid' => 0, 'contact-id' => $public_contact, 'author-id' => $public_contact,
			'private' => false, 'gravity' => [GRAVITY_PARENT, GRAVITY_COMMENT],
			'deleted' => false, 'visible' => true, 'moderated' => false];
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
	 * Return the service array containing information the used software and it's url
	 *
	 * @return array with service data
	 */
	private static function getService()
	{
		return ['type' => 'Service',
			'name' =>  FRIENDICA_PLATFORM . " '" . FRIENDICA_CODENAME . "' " . FRIENDICA_VERSION . '-' . DB_UPDATE_VERSION,
			'url' => BaseObject::getApp()->getBaseURL()];
	}

	/**
	 * Return the ActivityPub profile of the given user
	 *
	 * @param integer $uid User ID
	 * @return array with profile data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function getProfile($uid)
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

		$fields = ['name', 'url', 'location', 'about', 'avatar', 'photo'];
		$contact = DBA::selectFirst('contact', $fields, ['uid' => $uid, 'self' => true]);
		if (!DBA::isResult($contact)) {
			return [];
		}

		// On old installations and never changed contacts this might not be filled
		if (empty($contact['avatar'])) {
			$contact['avatar'] = $contact['photo'];
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
		$data['manuallyApprovesFollowers'] = in_array($user['page-flags'], [User::PAGE_FLAGS_NORMAL, User::PAGE_FLAGS_PRVGROUP]);
		$data['publicKey'] = ['id' => $contact['url'] . '#main-key',
			'owner' => $contact['url'],
			'publicKeyPem' => $user['pubkey']];
		$data['endpoints'] = ['sharedInbox' => System::baseUrl() . '/inbox'];
		$data['icon'] = ['type' => 'Image',
			'url' => $contact['avatar']];

		$data['generator'] = self::getService();

		// tags: https://kitty.town/@inmysocks/100656097926961126.json
		return $data;
	}

	/**
	 * @param string $username
	 * @return array
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function getDeletedUser($username)
	{
		return [
			'@context' => ActivityPub::CONTEXT,
			'id' => System::baseUrl() . '/profile/' . $username,
			'type' => 'Tombstone',
			'published' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'updated' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'deleted' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
		];
	}

	/**
	 * Returns an array with permissions of a given item array
	 *
	 * @param array $item
	 *
	 * @return array with permissions
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
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
					$permissions[$element][] = $item_profile['followers'];
				} elseif (!in_array($receiver, $exclude)) {
					$permissions[$element][] = $receiver;
				}
			}
		}
		return $permissions;
	}

	/**
	 * Creates an array of permissions from an item thread
	 *
	 * @param array   $item       Item array
	 * @param boolean $blindcopy  addressing via "bcc" or "cc"?
	 * @param integer $last_id    Last item id for adding receivers
	 * @param boolean $forum_mode "true" means that we are sending content to a forum
	 *
	 * @return array with permission data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function createPermissionBlockForItem($item, $blindcopy, $last_id = 0, $forum_mode = false)
	{
		if ($last_id == 0) {
			$last_id = $item['id'];
		}

		$always_bcc = false;

		// Check if we should always deliver our stuff via BCC
		if (!empty($item['uid'])) {
			$profile = Profile::getByUID($item['uid']);
			if (!empty($profile)) {
				$always_bcc = $profile['hide-friends'];
			}
		}

		if (Config::get('debug', 'total_ap_delivery')) {
			// Will be activated in a later step
			$networks = [Protocol::ACTIVITYPUB, Protocol::DFRN, Protocol::DIASPORA, Protocol::OSTATUS];
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

		$terms = Term::tagArrayFromItemId($item['id'], [Term::MENTION, Term::IMPLICIT_MENTION]);

		if (!$item['private']) {
			$data = array_merge($data, self::fetchPermissionBlockFromConversation($item));

			$data['to'][] = ActivityPub::PUBLIC_COLLECTION;

			foreach ($terms as $term) {
				$profile = APContact::getByURL($term['url'], false);
				if (!empty($profile)) {
					$data['to'][] = $profile['url'];
				}
			}
		} else {
			$receiver_list = Item::enumeratePermissions($item);

			foreach ($terms as $term) {
				$cid = Contact::getIdForURL($term['url'], $item['uid']);
				if (!empty($cid) && in_array($cid, $receiver_list)) {
					$contact = DBA::selectFirst('contact', ['url', 'network', 'protocol'], ['id' => $cid]);
					if (!DBA::isResult($contact) || (!in_array($contact['network'], $networks) && ($contact['protocol'] != Protocol::ACTIVITYPUB))) {
						continue;
					}

					if (!empty($profile = APContact::getByURL($contact['url'], false))) {
						$data['to'][] = $profile['url'];
					}
				}
			}

			foreach ($receiver_list as $receiver) {
				$contact = DBA::selectFirst('contact', ['url', 'hidden', 'network', 'protocol'], ['id' => $receiver]);
				if (!DBA::isResult($contact) || (!in_array($contact['network'], $networks) && ($contact['protocol'] != Protocol::ACTIVITYPUB))) {
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

		if (!empty($item['parent'])) {
			$parents = Item::select(['id', 'author-link', 'owner-link', 'gravity', 'uri'], ['parent' => $item['parent']]);
			while ($parent = Item::fetch($parents)) {
				if ($parent['gravity'] == GRAVITY_PARENT) {
					$profile = APContact::getByURL($parent['owner-link'], false);
					if (!empty($profile)) {
						if ($item['gravity'] != GRAVITY_PARENT) {
							// Comments to forums are directed to the forum
							// But comments to forums aren't directed to the followers collection
							if ($profile['type'] == 'Group') {
								$data['to'][] = $profile['url'];
							} else {
								$data['cc'][] = $profile['url'];
								if (!$item['private']) {
									$data['cc'][] = $actor_profile['followers'];
								}
							}
						} else {
							// Public thread parent post always are directed to the followers
							if (!$item['private'] && !$forum_mode) {
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

		return $receivers;
	}

	/**
	 * Check if an inbox is archived
	 *
	 * @param string $url Inbox url
	 *
	 * @return boolean "true" if inbox is archived
	 */
	private static function archivedInbox($url)
	{
		return DBA::exists('inbox-status', ['url' => $url, 'archive' => true]);
	}

	/**
	 * Fetches a list of inboxes of followers of a given user
	 *
	 * @param integer $uid      User ID
	 * @param boolean $personal fetch personal inboxes
	 *
	 * @return array of follower inboxes
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function fetchTargetInboxesforUser($uid, $personal = false)
	{
		$inboxes = [];

		if (Config::get('debug', 'total_ap_delivery')) {
			// Will be activated in a later step
			$networks = [Protocol::ACTIVITYPUB, Protocol::DFRN, Protocol::DIASPORA, Protocol::OSTATUS];
		} else {
			// For now only send to these contacts:
			$networks = [Protocol::ACTIVITYPUB, Protocol::OSTATUS];
		}

		$condition = ['uid' => $uid, 'archive' => false, 'pending' => false];

		if (!empty($uid)) {
			$condition['rel'] = [Contact::FOLLOWER, Contact::FRIEND];
		}

		$contacts = DBA::select('contact', ['url', 'network', 'protocol'], $condition);
		while ($contact = DBA::fetch($contacts)) {
			if (!in_array($contact['network'], $networks) && ($contact['protocol'] != Protocol::ACTIVITYPUB)) {
				continue;
			}

			if (Network::isUrlBlocked($contact['url'])) {
				continue;
			}

			$profile = APContact::getByURL($contact['url'], false);
			if (!empty($profile)) {
				if (empty($profile['sharedinbox']) || $personal) {
					$target = $profile['inbox'];
				} else {
					$target = $profile['sharedinbox'];
				}
				if (!self::archivedInbox($target)) {
					$inboxes[$target] = $target;
				}
			}
		}
		DBA::close($contacts);

		return $inboxes;
	}

	/**
	 * Fetches an array of inboxes for the given item and user
	 *
	 * @param array   $item       Item array
	 * @param integer $uid        User ID
	 * @param boolean $personal   fetch personal inboxes
	 * @param integer $last_id    Last item id for adding receivers
	 * @param boolean $forum_mode "true" means that we are sending content to a forum
	 * @return array with inboxes
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function fetchTargetInboxes($item, $uid, $personal = false, $last_id = 0, $forum_mode = false)
	{
		$permissions = self::createPermissionBlockForItem($item, true, $last_id, $forum_mode);
		if (empty($permissions)) {
			return [];
		}

		$inboxes = [];

		if ($item['gravity'] == GRAVITY_ACTIVITY) {
			$item_profile = APContact::getByURL($item['author-link'], false);
		} else {
			$item_profile = APContact::getByURL($item['owner-link'], false);
		}

		foreach (['to', 'cc', 'bto', 'bcc'] as $element) {
			if (empty($permissions[$element])) {
				continue;
			}

			$blindcopy = in_array($element, ['bto', 'bcc']);

			foreach ($permissions[$element] as $receiver) {
				if (Network::isUrlBlocked($receiver)) {
					continue;
				}

				if ($receiver == $item_profile['followers']) {
					$inboxes = array_merge($inboxes, self::fetchTargetInboxesforUser($uid, $personal));
				} else {
					$profile = APContact::getByURL($receiver, false);
					if (!empty($profile)) {
						if (empty($profile['sharedinbox']) || $personal || $blindcopy) {
							$target = $profile['inbox'];
						} else {
							$target = $profile['sharedinbox'];
						}
						if (!self::archivedInbox($target)) {
							$inboxes[$target] = $target;
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
	 * @param integer $mail_id
	 *
	 * @return array
	 * @throws \Exception
	 */
	public static function ItemArrayFromMail($mail_id)
	{
		$mail = DBA::selectFirst('mail', [], ['id' => $mail_id]);

		$reply = DBA::selectFirst('mail', ['uri'], ['parent-uri' => $mail['parent-uri'], 'reply' => false]);

		// Making the post more compatible for Mastodon by:
		// - Making it a note and not an article (no title)
		// - Moving the title into the "summary" field that is used as a "content warning"
		$mail['body'] = '[abstract]' . $mail['title'] . "[/abstract]\n" . $mail['body'];
		$mail['title'] = '';

		$mail['author-link'] = $mail['owner-link'] = $mail['from-url'];
		$mail['allow_cid'] = '<'.$mail['contact-id'].'>';
		$mail['allow_gid'] = '';
		$mail['deny_cid'] = '';
		$mail['deny_gid'] = '';
		$mail['private'] = true;
		$mail['deleted'] = false;
		$mail['edited'] = $mail['created'];
		$mail['plink'] = $mail['uri'];
		$mail['thr-parent'] = $reply['uri'];
		$mail['gravity'] = ($mail['reply'] ? GRAVITY_COMMENT: GRAVITY_PARENT);

		$mail['event-type'] = '';
		$mail['attach'] = '';

		$mail['parent'] = 0;

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
	public static function createActivityFromMail($mail_id, $object_mode = false)
	{
		$mail = self::ItemArrayFromMail($mail_id);
		$object = self::createNote($mail);

		$object['to'] = $object['cc'];
		unset($object['cc']);

		$object['tag'] = [['type' => 'Mention', 'href' => $object['to'][0], 'name' => 'test']];

		if (!$object_mode) {
			$data = ['@context' => ActivityPub::CONTEXT];
		} else {
			$data = [];
		}

		$data['id'] = $mail['uri'] . '#Create';
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
	 * @param array $item
	 *
	 * @return string with activity type
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function getTypeOfItem($item)
	{
		$reshared = false;

		// Only check for a reshare, if it is a real reshare and no quoted reshare
		if (strpos($item['body'], "[share") === 0) {
			$announce = api_share_as_retweet($item);
			$reshared = !empty($announce['plink']);
		}

		if ($reshared) {
			$type = 'Announce';
		} elseif ($item['verb'] == ACTIVITY_POST) {
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
		} elseif ($item['verb'] == ACTIVITY_FOLLOW) {
			$type = 'Follow';
		} else {
			$type = '';
		}

		return $type;
	}

	/**
	 * Creates the activity or fetches it from the cache
	 *
	 * @param integer $item_id
	 * @param boolean $force Force new cache entry
	 *
	 * @return array with the activity
	 * @throws \Exception
	 */
	public static function createCachedActivityFromItem($item_id, $force = false)
	{
		$cachekey = 'APDelivery:createActivity:' . $item_id;

		if (!$force) {
			$data = Cache::get($cachekey);
			if (!is_null($data)) {
				return $data;
			}
		}

		$data = ActivityPub\Transmitter::createActivityFromItem($item_id);

		Cache::set($cachekey, $data, Cache::QUARTER_HOUR);
		return $data;
	}

	/**
	 * Creates an activity array for a given item id
	 *
	 * @param integer $item_id
	 * @param boolean $object_mode Is the activity item is used inside another object?
	 *
	 * @return array of activity
	 * @throws \Exception
	 */
	public static function createActivityFromItem($item_id, $object_mode = false)
	{
		$item = Item::selectFirst([], ['id' => $item_id, 'parent-network' => Protocol::NATIVE_SUPPORT]);

		if (!DBA::isResult($item)) {
			return false;
		}

		if ($item['wall'] && ($item['uri'] == $item['parent-uri'])) {
			$owner = User::getOwnerDataById($item['uid']);
			if (($owner['account-type'] == User::ACCOUNT_TYPE_COMMUNITY) && ($item['author-link'] != $owner['url'])) {
				$type = 'Announce';

				// Disguise forum posts as reshares. Will later be converted to a real announce
				$item['body'] = share_header($item['author-name'], $item['author-link'], $item['author-avatar'],
					$item['guid'], $item['created'], $item['plink']) . $item['body'] . '[/share]';
			}
		}

		if (empty($type)) {
			$condition = ['item-uri' => $item['uri'], 'protocol' => Conversation::PARCEL_ACTIVITYPUB];
			$conversation = DBA::selectFirst('conversation', ['source'], $condition);
			if (DBA::isResult($conversation)) {
				$data = json_decode($conversation['source'], true);
				if (!empty($data)) {
					return $data;
				}
			}

			$type = self::getTypeOfItem($item);
		}

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

		if (Item::isForumPost($item) && ($type != 'Announce')) {
			$data['actor'] = $item['author-link'];
		} else {
			$data['actor'] = $item['owner-link'];
		}

		$data['published'] = DateTimeFormat::utc($item['created'] . '+00:00', DateTimeFormat::ATOM);

		$data['instrument'] = self::getService();

		$data = array_merge($data, self::createPermissionBlockForItem($item, false));

		if (in_array($data['type'], ['Create', 'Update', 'Delete'])) {
			$data['object'] = self::createNote($item);
		} elseif ($data['type'] == 'Announce') {
			$data = self::createAnnounce($item, $data);
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

		if (!$object_mode && !empty($owner)) {
			return LDSignature::sign($data, $owner);
		} else {
			return $data;
		}

		/// @todo Create "conversation" entry
	}

	/**
	 * Creates an object array for a given item id
	 *
	 * @param integer $item_id
	 *
	 * @return array with the object data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
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
	 * Creates a location entry for a given item array
	 *
	 * @param array $item
	 *
	 * @return array with location array
	 */
	private static function createLocation($item)
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
	 * @param array $item
	 *
	 * @return array of tags
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function createTagList($item)
	{
		$tags = [];

		$terms = Term::tagArrayFromItemId($item['id'], [Term::HASHTAG, Term::MENTION, Term::IMPLICIT_MENTION]);
		foreach ($terms as $term) {
			if ($term['type'] == Term::HASHTAG) {
				$url = System::baseUrl() . '/search?tag=' . urlencode($term['term']);
				$tags[] = ['type' => 'Hashtag', 'href' => $url, 'name' => '#' . $term['term']];
			} elseif ($term['type'] == Term::MENTION || $term['type'] == Term::IMPLICIT_MENTION) {
				$contact = Contact::getDetailsByURL($term['url']);
				if (!empty($contact['addr'])) {
					$mention = '@' . $contact['addr'];
				} else {
					$mention = '@' . $term['url'];
				}

				$tags[] = ['type' => 'Mention', 'href' => $term['url'], 'name' => $mention];
			}
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
	private static function createAttachmentList($item, $type)
	{
		$attachments = [];

		$arr = explode('[/attach],', $item['attach']);
		if (count($arr)) {
			foreach ($arr as $r) {
				$matches = false;
				$cnt = preg_match('|\[attach\]href=\"(.*?)\" length=\"(.*?)\" type=\"(.*?)\" title=\"(.*?)\"|', $r, $matches);
				if ($cnt) {
					$attributes = ['type' => 'Document',
							'mediaType' => $matches[3],
							'url' => $matches[1],
							'name' => null];

					if (trim($matches[4]) != '') {
						$attributes['name'] = trim($matches[4]);
					}

					$attachments[] = $attributes;
				}
			}
		}

		if ($type != 'Note') {
			return $attachments;
		}

		// Simplify image codes
		$body = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $item['body']);

		// Grab all pictures and create attachments out of them
		if (preg_match_all("/\[img\]([^\[\]]*)\[\/img\]/Usi", $body, $pictures)) {
			foreach ($pictures[1] as $picture) {
				$imgdata = Image::getInfoFromURL($picture);
				if ($imgdata) {
					$attachments[] = ['type' => 'Document',
						'mediaType' => $imgdata['mime'],
						'url' => $picture,
						'name' => null];
				}
			}
		}

		return $attachments;
	}

	/**
	 * @brief Callback function to replace a Friendica style mention in a mention that is used on AP
	 *
	 * @param array $match Matching values for the callback
	 * @return string Replaced mention
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function mentionCallback($match)
	{
		if (empty($match[1])) {
			return '';
		}

		$data = Contact::getDetailsByURL($match[1]);
		if (empty($data['nick'])) {
			return $match[0];
		}

		return '@[url=' . $data['url'] . ']' . $data['nick'] . '[/url]';
	}

	/**
	 * Remove image elements and replaces them with links to the image
	 *
	 * @param string $body
	 *
	 * @return string with replaced elements
	 */
	private static function removePictures($body)
	{
		// Simplify image codes
		$body = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $body);

		$body = preg_replace("/\[url=([^\[\]]*)\]\[img\](.*)\[\/img\]\[\/url\]/Usi", '[url]$1[/url]', $body);
		$body = preg_replace("/\[img\]([^\[\]]*)\[\/img\]/Usi", '[url]$1[/url]', $body);

		return $body;
	}

	/**
	 * Fetches the "context" value for a givem item array from the "conversation" table
	 *
	 * @param array $item
	 *
	 * @return string with context url
	 * @throws \Exception
	 */
	private static function fetchContextURLForItem($item)
	{
		$conversation = DBA::selectFirst('conversation', ['conversation-href', 'conversation-uri'], ['item-uri' => $item['parent-uri']]);
		if (DBA::isResult($conversation) && !empty($conversation['conversation-href'])) {
			$context_uri = $conversation['conversation-href'];
		} elseif (DBA::isResult($conversation) && !empty($conversation['conversation-uri'])) {
			$context_uri = $conversation['conversation-uri'];
		} else {
			$context_uri = $item['parent-uri'] . '#context';
		}
		return $context_uri;
	}

	/**
	 * Returns if the post contains sensitive content ("nsfw")
	 *
	 * @param integer $item_id
	 *
	 * @return boolean
	 * @throws \Exception
	 */
	private static function isSensitive($item_id)
	{
		$condition = ['otype' => TERM_OBJ_POST, 'oid' => $item_id, 'type' => TERM_HASHTAG, 'term' => 'nsfw'];
		return DBA::exists('term', $condition);
	}

	/**
	 * Creates event data
	 *
	 * @param array $item
	 *
	 * @return array with the event data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function createEvent($item)
	{
		$event = [];
		$event['name'] = $item['event-summary'];
		$event['content'] = BBCode::convert($item['event-desc'], false, 7);
		$event['startTime'] = DateTimeFormat::utc($item['event-start'] . '+00:00', DateTimeFormat::ATOM);

		if (!$item['event-nofinish']) {
			$event['endTime'] = DateTimeFormat::utc($item['event-finish'] . '+00:00', DateTimeFormat::ATOM);
		}

		if (!empty($item['event-location'])) {
			$item['location'] = $item['event-location'];
			$event['location'] = self::createLocation($item);
		}

		return $event;
	}

	/**
	 * Creates a note/article object array
	 *
	 * @param array $item
	 *
	 * @return array with the object data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function createNote($item)
	{
		if ($item['event-type'] == 'event') {
			$type = 'Event';
		} elseif (!empty($item['title'])) {
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

		$data['url'] = $item['plink'];
		$data['attributedTo'] = $item['author-link'];
		$data['sensitive'] = self::isSensitive($item['id']);
		$data['context'] = self::fetchContextURLForItem($item);

		if (!empty($item['title'])) {
			$data['name'] = BBCode::toPlaintext($item['title'], false);
		}

		$permission_block = self::createPermissionBlockForItem($item, false);

		$body = $item['body'];

		if (empty($item['uid']) || !Feature::isEnabled($item['uid'], 'explicit_mentions')) {
			$body = self::prependMentions($body, $permission_block);
		}

		if ($type == 'Note') {
			$body = self::removePictures($body);
		} elseif (($type == 'Article') && empty($data['summary'])) {
			$data['summary'] = BBCode::toPlaintext(Plaintext::shorten(self::removePictures($body), 1000));
		}

		if ($type == 'Event') {
			$data = array_merge($data, self::createEvent($item));
		} else {
			$regexp = "/[@!]\[url\=([^\[\]]*)\].*?\[\/url\]/ism";
			$body = preg_replace_callback($regexp, ['self', 'mentionCallback'], $body);

			$data['content'] = BBCode::convert($body, false, 7);
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
	 * Creates an announce object entry
	 *
	 * @param array $item
	 * @param array $data activity data
	 *
	 * @return array with activity data
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function createAnnounce($item, $data)
	{
		$announce = api_share_as_retweet($item);
		if (empty($announce['plink'])) {
			$data['type'] = 'Create';
			$data['object'] = self::createNote($item);
			return $data;
		}

		// Fetch the original id of the object
		$activity = ActivityPub::fetchContent($announce['plink'], $item['uid']);
		if (!empty($activity)) {
			$ldactivity = JsonLD::compact($activity);
			$id = JsonLD::fetchElement($ldactivity, '@id');
			if (!empty($id)) {
				$data['object'] = $id;
				return $data;
			}
		}

		$data['type'] = 'Create';
		$data['object'] = self::createNote($item);
		return $data;
	}

	/**
	 * Creates an activity id for a given contact id
	 *
	 * @param integer $cid Contact ID of target
	 *
	 * @return bool|string activity id
	 */
	public static function activityIDFromContact($cid)
	{
		$contact = DBA::selectFirst('contact', ['uid', 'id', 'created'], ['id' => $cid]);
		if (!DBA::isResult($contact)) {
			return false;
		}

		$hash = hash('ripemd128', $contact['uid'].'-'.$contact['id'].'-'.$contact['created']);
		$uuid = substr($hash, 0, 8). '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
		return System::baseUrl() . '/activity/' . $uuid;
	}

	/**
	 * Transmits a contact suggestion to a given inbox
	 *
	 * @param integer $uid           User ID
	 * @param string  $inbox         Target inbox
	 * @param integer $suggestion_id Suggestion ID
	 *
	 * @return boolean was the transmission successful?
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function sendContactSuggestion($uid, $inbox, $suggestion_id)
	{
		$owner = User::getOwnerDataById($uid);

		$suggestion = DBA::selectFirst('fsuggest', ['url', 'note', 'created'], ['id' => $suggestion_id]);

		$data = ['@context' => ActivityPub::CONTEXT,
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Announce',
			'actor' => $owner['url'],
			'object' => $suggestion['url'],
			'content' => $suggestion['note'],
			'instrument' => self::getService(),
			'to' => [ActivityPub::PUBLIC_COLLECTION],
			'cc' => []];

		$signed = LDSignature::sign($data, $owner);

		Logger::log('Deliver profile deletion for user ' . $uid . ' to ' . $inbox . ' via ActivityPub', Logger::DEBUG);
		return HTTPSignature::transmit($signed, $inbox, $uid);
	}

	/**
	 * Transmits a profile relocation to a given inbox
	 *
	 * @param integer $uid   User ID
	 * @param string  $inbox Target inbox
	 *
	 * @return boolean was the transmission successful?
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function sendProfileRelocation($uid, $inbox)
	{
		$owner = User::getOwnerDataById($uid);

		$data = ['@context' => ActivityPub::CONTEXT,
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'dfrn:relocate',
			'actor' => $owner['url'],
			'object' => $owner['url'],
			'published' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'instrument' => self::getService(),
			'to' => [ActivityPub::PUBLIC_COLLECTION],
			'cc' => []];

		$signed = LDSignature::sign($data, $owner);

		Logger::log('Deliver profile relocation for user ' . $uid . ' to ' . $inbox . ' via ActivityPub', Logger::DEBUG);
		return HTTPSignature::transmit($signed, $inbox, $uid);
	}

	/**
	 * Transmits a profile deletion to a given inbox
	 *
	 * @param integer $uid   User ID
	 * @param string  $inbox Target inbox
	 *
	 * @return boolean was the transmission successful?
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function sendProfileDeletion($uid, $inbox)
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
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Delete',
			'actor' => $owner['url'],
			'object' => $owner['url'],
			'published' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'instrument' => self::getService(),
			'to' => [ActivityPub::PUBLIC_COLLECTION],
			'cc' => []];

		$signed = LDSignature::sign($data, $owner);

		Logger::log('Deliver profile deletion for user ' . $uid . ' to ' . $inbox . ' via ActivityPub', Logger::DEBUG);
		return HTTPSignature::transmit($signed, $inbox, $uid);
	}

	/**
	 * Transmits a profile change to a given inbox
	 *
	 * @param integer $uid   User ID
	 * @param string  $inbox Target inbox
	 *
	 * @return boolean was the transmission successful?
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function sendProfileUpdate($uid, $inbox)
	{
		$owner = User::getOwnerDataById($uid);
		$profile = APContact::getByURL($owner['url']);

		$data = ['@context' => ActivityPub::CONTEXT,
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Update',
			'actor' => $owner['url'],
			'object' => self::getProfile($uid),
			'published' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'instrument' => self::getService(),
			'to' => [$profile['followers']],
			'cc' => []];

		$signed = LDSignature::sign($data, $owner);

		Logger::log('Deliver profile update for user ' . $uid . ' to ' . $inbox . ' via ActivityPub', Logger::DEBUG);
		return HTTPSignature::transmit($signed, $inbox, $uid);
	}

	/**
	 * Transmits a given activity to a target
	 *
	 * @param string  $activity Type name
	 * @param string  $target   Target profile
	 * @param integer $uid      User ID
	 * @return bool
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 * @throws \Exception
	 */
	public static function sendActivity($activity, $target, $uid, $id = '')
	{
		$profile = APContact::getByURL($target);

		$owner = User::getOwnerDataById($uid);

		if (empty($id)) {
			$id = System::baseUrl() . '/activity/' . System::createGUID();
		}

		$data = ['@context' => ActivityPub::CONTEXT,
			'id' => $id,
			'type' => $activity,
			'actor' => $owner['url'],
			'object' => $profile['url'],
			'instrument' => self::getService(),
			'to' => [$profile['url']]];

		Logger::log('Sending activity ' . $activity . ' to ' . $target . ' for user ' . $uid, Logger::DEBUG);

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
	public static function sendFollowObject($object, $target, $uid = 0)
	{
		$profile = APContact::getByURL($target);

		if (empty($uid)) {
			// Fetch the list of administrators
			$admin_mail = explode(',', str_replace(' ', '', Config::get('config', 'admin_email')));

			// We need to use some user as a sender. It doesn't care who it will send. We will use an administrator account.
			$condition = ['verified' => true, 'blocked' => false, 'account_removed' => false, 'account_expired' => false, 'email' => $admin_mail];
			$first_user = DBA::selectFirst('user', ['uid'], $condition);
			$uid = $first_user['uid'];
		}

		$condition = ['verb' => ACTIVITY_FOLLOW, 'uid' => 0, 'parent-uri' => $object,
			'author-id' => Contact::getPublicIdByUserId($uid)];
		if (Item::exists($condition)) {
			Logger::log('Follow for ' . $object . ' for user ' . $uid . ' does already exist.', Logger::DEBUG);
			return false;
		}

		$owner = User::getOwnerDataById($uid);

		$data = ['@context' => ActivityPub::CONTEXT,
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Follow',
			'actor' => $owner['url'],
			'object' => $object,
			'instrument' => self::getService(),
			'to' => [$profile['url']]];

		Logger::log('Sending follow ' . $object . ' to ' . $target . ' for user ' . $uid, Logger::DEBUG);

		$signed = LDSignature::sign($data, $owner);
		return HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * Transmit a message that the contact request had been accepted
	 *
	 * @param string  $target Target profile
	 * @param         $id
	 * @param integer $uid    User ID
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function sendContactAccept($target, $id, $uid)
	{
		$profile = APContact::getByURL($target);

		$owner = User::getOwnerDataById($uid);
		$data = ['@context' => ActivityPub::CONTEXT,
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Accept',
			'actor' => $owner['url'],
			'object' => [
				'id' => (string)$id,
				'type' => 'Follow',
				'actor' => $profile['url'],
				'object' => $owner['url']
			],
			'instrument' => self::getService(),
			'to' => [$profile['url']]];

		Logger::debug('Sending accept to ' . $target . ' for user ' . $uid . ' with id ' . $id);

		$signed = LDSignature::sign($data, $owner);
		HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * Reject a contact request or terminates the contact relation
	 *
	 * @param string  $target Target profile
	 * @param         $id
	 * @param integer $uid    User ID
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function sendContactReject($target, $id, $uid)
	{
		$profile = APContact::getByURL($target);

		$owner = User::getOwnerDataById($uid);
		$data = ['@context' => ActivityPub::CONTEXT,
			'id' => System::baseUrl() . '/activity/' . System::createGUID(),
			'type' => 'Reject',
			'actor' => $owner['url'],
			'object' => [
				'id' => (string)$id,
				'type' => 'Follow',
				'actor' => $profile['url'],
				'object' => $owner['url']
			],
			'instrument' => self::getService(),
			'to' => [$profile['url']]];

		Logger::debug('Sending reject to ' . $target . ' for user ' . $uid . ' with id ' . $id);

		$signed = LDSignature::sign($data, $owner);
		HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	/**
	 * Transmits a message that we don't want to follow this contact anymore
	 *
	 * @param string  $target Target profile
	 * @param integer $uid    User ID
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 * @throws \Exception
	 */
	public static function sendContactUndo($target, $cid, $uid)
	{
		$profile = APContact::getByURL($target);

		$object_id = self::activityIDFromContact($cid);
		if (empty($object_id)) {
			return;
		}

		$id = System::baseUrl() . '/activity/' . System::createGUID();

		$owner = User::getOwnerDataById($uid);
		$data = ['@context' => ActivityPub::CONTEXT,
			'id' => $id,
			'type' => 'Undo',
			'actor' => $owner['url'],
			'object' => ['id' => $object_id, 'type' => 'Follow',
				'actor' => $owner['url'],
				'object' => $profile['url']],
			'instrument' => self::getService(),
			'to' => [$profile['url']]];

		Logger::log('Sending undo to ' . $target . ' for user ' . $uid . ' with id ' . $id, Logger::DEBUG);

		$signed = LDSignature::sign($data, $owner);
		HTTPSignature::transmit($signed, $profile['inbox'], $uid);
	}

	private static function prependMentions($body, array $permission_block)
	{
		if (Config::get('system', 'disable_implicit_mentions')) {
			return $body;
		}

		$mentions = [];

		foreach ($permission_block['to'] as $profile_url) {
			$profile = Contact::getDetailsByURL($profile_url);
			if (!empty($profile['addr'])
				&& $profile['contact-type'] != Contact::TYPE_COMMUNITY
				&& !strstr($body, $profile['addr'])
				&& !strstr($body, $profile_url)
			) {
				$mentions[] = '@[url=' . $profile_url . ']' . $profile['nick'] . '[/url]';
			}
		}

		$mentions[] = $body;

		return implode(' ', $mentions);
	}
}
