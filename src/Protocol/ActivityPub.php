<?php
/**
 * @file src/Protocol/ActivityPub.php
 */
namespace Friendica\Protocol;

use Friendica\Database\DBA;
use Friendica\Core\System;
use Friendica\BaseObject;
use Friendica\Util\Network;
use Friendica\Util\HTTPSignature;
use Friendica\Core\Protocol;
use Friendica\Model\Conversation;
use Friendica\Model\Contact;
use Friendica\Model\APContact;
use Friendica\Model\Item;
use Friendica\Model\Profile;
use Friendica\Model\Term;
use Friendica\Model\User;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Crypto;
use Friendica\Content\Text\BBCode;
use Friendica\Content\Text\HTML;
use Friendica\Util\JsonLD;
use Friendica\Util\LDSignature;
use Friendica\Core\Config;

/**
 * @brief ActivityPub Protocol class
 * The ActivityPub Protocol is a message exchange protocol defined by the W3C.
 * https://www.w3.org/TR/activitypub/
 * https://www.w3.org/TR/activitystreams-core/
 * https://www.w3.org/TR/activitystreams-vocabulary/
 *
 * https://blog.joinmastodon.org/2018/06/how-to-implement-a-basic-activitypub-server/
 * https://blog.joinmastodon.org/2018/07/how-to-make-friends-and-verify-requests/
 *
 * Digest: https://tools.ietf.org/html/rfc5843
 * https://tools.ietf.org/html/draft-cavage-http-signatures-10#ref-15
 *
 * Mastodon implementation of supported activities:
 * https://github.com/tootsuite/mastodon/blob/master/app/lib/activitypub/activity.rb#L26
 *
 * To-do:
 * - Polling the outboxes for missing content?
 * - "about" needs HTML2BBCode parser
 */
class ActivityPub
{
	const PUBLIC_COLLECTION = 'https://www.w3.org/ns/activitystreams#Public';
	const CONTEXT = ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1',
		['vcard' => 'http://www.w3.org/2006/vcard/ns#',
		'diaspora' => 'https://diasporafoundation.org/ns/',
		'manuallyApprovesFollowers' => 'as:manuallyApprovesFollowers',
		'sensitive' => 'as:sensitive', 'Hashtag' => 'as:Hashtag']];
	const ACCOUNT_TYPES = ['Person', 'Organization', 'Service', 'Group', 'Application'];
	const CONTENT_TYPES = ['Note', 'Article', 'Video', 'Image'];
	const ACTIVITY_TYPES = ['Like', 'Dislike', 'Accept', 'Reject', 'TentativeAccept'];
	/**
	 * @brief Checks if the web request is done for the AP protocol
	 *
	 * @return is it AP?
	 */
	public static function isRequest()
	{
		return stristr(defaults($_SERVER, 'HTTP_ACCEPT', ''), 'application/activity+json') ||
			stristr(defaults($_SERVER, 'HTTP_ACCEPT', ''), 'application/ld+json');
	}

	/**
	 * Fetches ActivityPub content from the given url
	 *
	 * @param string $url content url
	 * @return array
	 */
	public static function fetchContent($url)
	{
		$ret = Network::curl($url, false, $redirects, ['accept_content' => 'application/activity+json, application/ld+json']);
		if (!$ret['success'] || empty($ret['body'])) {
			return false;
		}

		return json_decode($ret['body'], true);
	}

	/**
	 * Fetches a profile from the given url into an array that is compatible to Probe::uri
	 *
	 * @param string $url profile url
	 * @return array
	 */
	public static function probeProfile($url)
	{
		$apcontact = APContact::getByURL($url, true);
		if (empty($apcontact)) {
			return false;
		}

		$profile = ['network' => Protocol::ACTIVITYPUB];
		$profile['nick'] = $apcontact['nick'];
		$profile['name'] = $apcontact['name'];
		$profile['guid'] = $apcontact['uuid'];
		$profile['url'] = $apcontact['url'];
		$profile['addr'] = $apcontact['addr'];
		$profile['alias'] = $apcontact['alias'];
		$profile['photo'] = $apcontact['photo'];
		// $profile['community']
		// $profile['keywords']
		// $profile['location']
		$profile['about'] = $apcontact['about'];
		$profile['batch'] = $apcontact['sharedinbox'];
		$profile['notify'] = $apcontact['inbox'];
		$profile['poll'] = $apcontact['outbox'];
		$profile['pubkey'] = $apcontact['pubkey'];
		$profile['baseurl'] = $apcontact['baseurl'];

		// Remove all "null" fields
		foreach ($profile as $field => $content) {
			if (is_null($content)) {
				unset($profile[$field]);
			}
		}

		return $profile;
	}

	/**
	 * @brief 
	 *
	 * @param $url
	 * @param integer $uid User ID
	 */
	public static function fetchOutbox($url, $uid)
	{
		$data = self::fetchContent($url);
		if (empty($data)) {
			return;
		}

		if (!empty($data['orderedItems'])) {
			$items = $data['orderedItems'];
		} elseif (!empty($data['first']['orderedItems'])) {
			$items = $data['first']['orderedItems'];
		} elseif (!empty($data['first'])) {
			self::fetchOutbox($data['first'], $uid);
			return;
		} else {
			$items = [];
		}

		foreach ($items as $activity) {
			ActivityPub\Receiver::processActivity($activity, '', $uid, true);
		}
	}
}
