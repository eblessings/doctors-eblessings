<?php
/**
 * @file src/Protocol/ActivityPub/Processor.php
 */
namespace Friendica\Protocol\ActivityPub;

use Friendica\Database\DBA;
use Friendica\Core\Protocol;
use Friendica\Model\Conversation;
use Friendica\Model\Contact;
use Friendica\Model\APContact;
use Friendica\Model\Item;
use Friendica\Model\User;
use Friendica\Content\Text\HTML;
use Friendica\Util\JsonLD;
use Friendica\Core\Config;
use Friendica\Protocol\ActivityPub;

/**
 * ActivityPub Protocol class
 *
 * To-Do:
 * - Store Diaspora signature
 */
class Processor
{
	/**
	 * Converts mentions from Pleroma into the Friendica format
	 *
	 * @param string $body
	 *
	 * @return converted body
	 */
	private static function convertMentions($body)
	{
		$URLSearchString = "^\[\]";
		$body = preg_replace("/\[url\=([$URLSearchString]*)\]([#@!])(.*?)\[\/url\]/ism", '$2[url=$1]$3[/url]', $body);

		return $body;
	}

	/**
	 * Constructs a string with tags for a given tag array
	 *
	 * @param array $tags
	 * @param boolean $sensitive
	 *
	 * @return string with tags
	 */
	private static function constructTagList($tags, $sensitive)
	{
		if (empty($tags)) {
			return '';
		}

		$tag_text = '';
		foreach ($tags as $tag) {
			if (in_array(defaults($tag, 'type', ''), ['Mention', 'Hashtag'])) {
				if (!empty($tag_text)) {
					$tag_text .= ',';
				}

				$tag_text .= substr($tag['name'], 0, 1) . '[url=' . $tag['href'] . ']' . substr($tag['name'], 1) . '[/url]';
			}
		}

		/// @todo add nsfw for $sensitive

		return $tag_text;
	}

	/**
	 * Add attachment data to the item array
	 *
	 * @param array $attachments
	 * @param array $item
	 *
	 * @return item array
	 */
	private static function constructAttachList($attachments, $item)
	{
		if (empty($attachments)) {
			return $item;
		}

		foreach ($attachments as $attach) {
			$filetype = strtolower(substr($attach['mediaType'], 0, strpos($attach['mediaType'], '/')));
			if ($filetype == 'image') {
				$item['body'] .= "\n[img]" . $attach['url'] . '[/img]';
			} else {
				if (!empty($item["attach"])) {
					$item["attach"] .= ',';
				} else {
					$item["attach"] = '';
				}
				if (!isset($attach['length'])) {
					$attach['length'] = "0";
				}
				$item["attach"] .= '[attach]href="'.$attach['url'].'" length="'.$attach['length'].'" type="'.$attach['mediaType'].'" title="'.defaults($attach, 'name', '').'"[/attach]';
			}
		}

		return $item;
	}

	/**
	 * Prepares data for a message
	 *
	 * @param array  $activity Activity array
	 * @param string $body     original source
	 */
	public static function createItem($activity, $body)
	{
		$item = [];
		$item['verb'] = ACTIVITY_POST;
		$item['parent-uri'] = $activity['reply-to-id'];

		if ($activity['reply-to-id'] == $activity['id']) {
			$item['gravity'] = GRAVITY_PARENT;
			$item['object-type'] = ACTIVITY_OBJ_NOTE;
		} else {
			$item['gravity'] = GRAVITY_COMMENT;
			$item['object-type'] = ACTIVITY_OBJ_COMMENT;
		}

		if (($activity['id'] != $activity['reply-to-id']) && !Item::exists(['uri' => $activity['reply-to-id']])) {
			logger('Parent ' . $activity['reply-to-id'] . ' not found. Try to refetch it.');
			self::fetchMissingActivity($activity['reply-to-id'], $activity);
		}

		self::postItem($activity, $item, $body);
	}

	/**
	 * Prepare the item array for a "like"
	 *
	 * @param array  $activity Activity array
	 * @param string $body     original source
	 */
	public static function likeItem($activity, $body)
	{
		$item = [];
		$item['verb'] = ACTIVITY_LIKE;
		$item['parent-uri'] = $activity['object_id'];
		$item['gravity'] = GRAVITY_ACTIVITY;
		$item['object-type'] = ACTIVITY_OBJ_NOTE;

		self::postItem($activity, $item, $body);
	}

	/**
	 * Delete items
	 *
	 * @param array $activity
	 */
	public static function deleteItem($activity)
	{
		$owner = Contact::getIdForURL($activity['actor']);

		logger('Deleting item ' . $activity['object_id'] . ' from ' . $owner, LOGGER_DEBUG);
		Item::delete(['uri' => $activity['object_id'], 'owner-id' => $owner]);
	}

	/**
	 * Prepare the item array for a "dislike"
	 *
	 * @param array  $activity Activity array
	 * @param string $body     original source
	 */
	public static function dislikeItem($activity, $body)
	{
		$item = [];
		$item['verb'] = ACTIVITY_DISLIKE;
		$item['parent-uri'] = $activity['object_id'];
		$item['gravity'] = GRAVITY_ACTIVITY;
		$item['object-type'] = ACTIVITY_OBJ_NOTE;

		self::postItem($activity, $item, $body);
	}

	/**
	 * Creates an item post
	 *
	 * @param array  $activity Activity data
	 * @param array  $item     item array
	 * @param string $body     original source
	 */
	private static function postItem($activity, $item, $body)
	{
		/// @todo What to do with $activity['context']?

		if (($item['gravity'] != GRAVITY_PARENT) && !Item::exists(['uri' => $item['parent-uri']])) {
			logger('Parent ' . $item['parent-uri'] . ' not found, message will be discarded.', LOGGER_DEBUG);
			return;
		}

		$item['network'] = Protocol::ACTIVITYPUB;
		$item['private'] = !in_array(0, $activity['receiver']);
		$item['author-link'] = $activity['author'];
		$item['author-id'] = Contact::getIdForURL($activity['author'], 0, true);

		if (empty($activity['thread-completion'])) {
			$item['owner-link'] = $activity['actor'];
			$item['owner-id'] = Contact::getIdForURL($activity['actor'], 0, true);
		} else {
			logger('Ignoring actor because of thread completion.', LOGGER_DEBUG);
			$item['owner-link'] = $item['author-link'];
			$item['owner-id'] = $item['author-id'];
		}

		$item['uri'] = $activity['id'];
		$item['created'] = $activity['published'];
		$item['edited'] = $activity['updated'];
		$item['guid'] = $activity['diaspora:guid'];
		$item['title'] = HTML::toBBCode($activity['name']);
		$item['content-warning'] = HTML::toBBCode($activity['summary']);
		$item['body'] = self::convertMentions(HTML::toBBCode($activity['content']));
		$item['location'] = $activity['location'];
		$item['tag'] = self::constructTagList($activity['tags'], $activity['sensitive']);
		$item['app'] = $activity['service'];
		$item['plink'] = defaults($activity, 'alternate-url', $item['uri']);

		$item = self::constructAttachList($activity['attachments'], $item);

		if (!empty($activity['source'])) {
			$item['body'] = $activity['source'];
		}

		$item['protocol'] = Conversation::PARCEL_ACTIVITYPUB;
		$item['source'] = $body;
		$item['conversation-href'] = $activity['context'];
		$item['conversation-uri'] = $activity['conversation'];

		foreach ($activity['receiver'] as $receiver) {
			$item['uid'] = $receiver;
			$item['contact-id'] = Contact::getIdForURL($activity['author'], $receiver, true);

			if (($receiver != 0) && empty($item['contact-id'])) {
				$item['contact-id'] = Contact::getIdForURL($activity['author'], 0, true);
			}

			$item_id = Item::insert($item);
			logger('Storing for user ' . $item['uid'] . ': ' . $item_id);
		}
	}

	/**
	 * Fetches missing posts
	 *
	 * @param $url
	 * @param $child
	 */
	private static function fetchMissingActivity($url, $child)
	{
		if (Config::get('system', 'ostatus_full_threads')) {
			return;
		}

		$object = ActivityPub::fetchContent($url);
		if (empty($object)) {
			logger('Activity ' . $url . ' was not fetchable, aborting.');
			return;
		}

		$activity = [];
		$activity['@context'] = $object['@context'];
		unset($object['@context']);
		$activity['id'] = $object['id'];
		$activity['to'] = defaults($object, 'to', []);
		$activity['cc'] = defaults($object, 'cc', []);
		$activity['actor'] = $child['author'];
		$activity['object'] = $object;
		$activity['published'] = $object['published'];
		$activity['type'] = 'Create';

		$ldactivity = JsonLD::compact($activity);

		$ldactivity['thread-completion'] = true;

		ActivityPub\Receiver::processActivity($ldactivity);
		logger('Activity ' . $url . ' had been fetched and processed.');
	}

	/**
	 * perform a "follow" request
	 *
	 * @param array $activity
	 */
	public static function followUser($activity)
	{
		$uid = User::getIdForURL($activity['object_id']);
		if (empty($uid)) {
			return;
		}

		$owner = User::getOwnerDataById($uid);

		$cid = Contact::getIdForURL($activity['actor'], $uid);
		if (!empty($cid)) {
			self::switchContact($cid);
			$contact = DBA::selectFirst('contact', [], ['id' => $cid, 'network' => Protocol::NATIVE_SUPPORT]);
		} else {
			$contact = false;
		}

		$item = ['author-id' => Contact::getIdForURL($activity['actor']),
			'author-link' => $activity['actor']];

		Contact::addRelationship($owner, $contact, $item);
		$cid = Contact::getIdForURL($activity['actor'], $uid);
		if (empty($cid)) {
			return;
		}

		DBA::update('contact', ['hub-verify' => $activity['id']], ['id' => $cid]);
		logger('Follow user ' . $uid . ' from contact ' . $cid . ' with id ' . $activity['id']);
	}

	/**
	 * Update the given profile
	 *
	 * @param array $activity
	 */
	public static function updatePerson($activity)
	{
		if (empty($activity['object_id'])) {
			return;
		}

		logger('Updating profile for ' . $activity['object_id'], LOGGER_DEBUG);
		APContact::getByURL($activity['object_id'], true);
	}

	/**
	 * Delete the given profile
	 *
	 * @param array $activity
	 */
	public static function deletePerson($activity)
	{
		if (empty($activity['object_id']) || empty($activity['actor'])) {
			logger('Empty object id or actor.', LOGGER_DEBUG);
			return;
		}

		if ($activity['object_id'] != $activity['actor']) {
			logger('Object id does not match actor.', LOGGER_DEBUG);
			return;
		}

		$contacts = DBA::select('contact', ['id'], ['nurl' => normalise_link($activity['object_id'])]);
		while ($contact = DBA::fetch($contacts)) {
			Contact::remove($contact['id']);
		}
		DBA::close($contacts);

		logger('Deleted contact ' . $activity['object_id'], LOGGER_DEBUG);
	}

	/**
	 * Accept a follow request
	 *
	 * @param array $activity
	 */
	public static function acceptFollowUser($activity)
	{
		$uid = User::getIdForURL($activity['object_actor']);
		if (empty($uid)) {
			return;
		}

		$owner = User::getOwnerDataById($uid);

		$cid = Contact::getIdForURL($activity['actor'], $uid);
		if (empty($cid)) {
			logger('No contact found for ' . $activity['actor'], LOGGER_DEBUG);
			return;
		}

		self::switchContact($cid);

		$fields = ['pending' => false];

		$contact = DBA::selectFirst('contact', ['rel'], ['id' => $cid]);
		if ($contact['rel'] == Contact::FOLLOWER) {
			$fields['rel'] = Contact::FRIEND;
		}

		$condition = ['id' => $cid];
		DBA::update('contact', $fields, $condition);
		logger('Accept contact request from contact ' . $cid . ' for user ' . $uid, LOGGER_DEBUG);
	}

	/**
	 * Reject a follow request
	 *
	 * @param array $activity
	 */
	public static function rejectFollowUser($activity)
	{
		$uid = User::getIdForURL($activity['object_actor']);
		if (empty($uid)) {
			return;
		}

		$owner = User::getOwnerDataById($uid);

		$cid = Contact::getIdForURL($activity['actor'], $uid);
		if (empty($cid)) {
			logger('No contact found for ' . $activity['actor'], LOGGER_DEBUG);
			return;
		}

		self::switchContact($cid);

		if (DBA::exists('contact', ['id' => $cid, 'rel' => Contact::SHARING, 'pending' => true])) {
			Contact::remove($cid);
			logger('Rejected contact request from contact ' . $cid . ' for user ' . $uid . ' - contact had been removed.', LOGGER_DEBUG);
		} else {
			logger('Rejected contact request from contact ' . $cid . ' for user ' . $uid . '.', LOGGER_DEBUG);
		}
	}

	/**
	 * Undo activity like "like" or "dislike"
	 *
	 * @param array $activity
	 */
	public static function undoActivity($activity)
	{
		if (empty($activity['object_id'])) {
			return;
		}

		if (empty($activity['object_actor'])) {
			return;
		}

		$author_id = Contact::getIdForURL($activity['object_actor']);
		if (empty($author_id)) {
			return;
		}

		Item::delete(['uri' => $activity['object_id'], 'author-id' => $author_id, 'gravity' => GRAVITY_ACTIVITY]);
	}

	/**
	 * Activity to remove a follower
	 *
	 * @param array $activity
	 */
	public static function undoFollowUser($activity)
	{
		$uid = User::getIdForURL($activity['object_object']);
		if (empty($uid)) {
			return;
		}

		$owner = User::getOwnerDataById($uid);

		$cid = Contact::getIdForURL($activity['actor'], $uid);
		if (empty($cid)) {
			logger('No contact found for ' . $activity['actor'], LOGGER_DEBUG);
			return;
		}

		self::switchContact($cid);

		$contact = DBA::selectFirst('contact', [], ['id' => $cid]);
		if (!DBA::isResult($contact)) {
			return;
		}

		Contact::removeFollower($owner, $contact);
		logger('Undo following request from contact ' . $cid . ' for user ' . $uid, LOGGER_DEBUG);
	}

	/**
	 * Switches a contact to AP if needed
	 *
	 * @param integer $cid Contact ID
	 */
	private static function switchContact($cid)
	{
		$contact = DBA::selectFirst('contact', ['network'], ['id' => $cid, 'network' => Protocol::NATIVE_SUPPORT]);
		if (!DBA::isResult($contact) || ($contact['network'] == Protocol::ACTIVITYPUB)) {
			return;
		}

		logger('Change existing contact ' . $cid . ' from ' . $contact['network'] . ' to ActivityPub.');
		Contact::updateFromProbe($cid, Protocol::ACTIVITYPUB);
	}
}
