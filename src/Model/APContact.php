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

use Friendica\Content\Text\HTML;
use Friendica\Core\Cache\Enum\Duration;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Database\DBStructure;
use Friendica\DI;
use Friendica\Network\HTTPClient\Client\HttpClientAccept;
use Friendica\Network\HTTPException;
use Friendica\Network\Probe;
use Friendica\Protocol\ActivityNamespace;
use Friendica\Protocol\ActivityPub;
use Friendica\Protocol\ActivityPub\Transmitter;
use Friendica\Util\Crypto;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\HTTPSignature;
use Friendica\Util\JsonLD;
use Friendica\Util\Network;

class APContact
{
	/**
	 * Fetch webfinger data
	 *
	 * @param string $addr Address
	 * @return array webfinger data
	 */
	private static function fetchWebfingerData(string $addr)
	{
		$addr_parts = explode('@', $addr);
		if (count($addr_parts) != 2) {
			return [];
		}

		if (Contact::isLocal($addr) && ($local_uid = User::getIdForURL($addr)) && ($local_owner = User::getOwnerDataById($local_uid))) {
			$data = [
				'addr'      => $local_owner['addr'],
				'baseurl'   => $local_owner['baseurl'],
				'url'       => $local_owner['url'],
				'subscribe' => $local_owner['baseurl'] . '/follow?url={uri}'];

			if (!empty($local_owner['alias']) && ($local_owner['url'] != $local_owner['alias'])) {
				$data['alias'] = $local_owner['alias'];
			}

			return $data;
		}

		$data = ['addr' => $addr];
		$template = 'https://' . $addr_parts[1] . '/.well-known/webfinger?resource=acct:' . urlencode($addr);
		$webfinger = Probe::webfinger(str_replace('{uri}', urlencode($addr), $template), HttpClientAccept::JRD_JSON);
		if (empty($webfinger['links'])) {
			$template = 'http://' . $addr_parts[1] . '/.well-known/webfinger?resource=acct:' . urlencode($addr);
			$webfinger = Probe::webfinger(str_replace('{uri}', urlencode($addr), $template), HttpClientAccept::JRD_JSON);
			if (empty($webfinger['links'])) {
				return [];
			}
			$data['baseurl'] = 'http://' . $addr_parts[1];
		} else {
			$data['baseurl'] = 'https://' . $addr_parts[1];
		}

		foreach ($webfinger['links'] as $link) {
			if (empty($link['rel'])) {
				continue;
			}

			if (!empty($link['template']) && ($link['rel'] == ActivityNamespace::OSTATUSSUB)) {
				$data['subscribe'] = $link['template'];
			}

			if (!empty($link['href']) && !empty($link['type']) && ($link['rel'] == 'self') && ($link['type'] == 'application/activity+json')) {
				$data['url'] = $link['href'];
			}

			if (!empty($link['href']) && !empty($link['type']) && ($link['rel'] == 'http://webfinger.net/rel/profile-page') && ($link['type'] == 'text/html')) {
				$data['alias'] = $link['href'];
			}
		}

		if (!empty($data['url']) && !empty($data['alias']) && ($data['url'] == $data['alias'])) {
			unset($data['alias']);
		}

		return $data;
	}

	/**
	 * Fetches a profile from a given url
	 *
	 * @param string  $url    profile url
	 * @param boolean $update true = always update, false = never update, null = update when not found or outdated
	 * @return array profile array
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function getByURL($url, $update = null)
	{
		if (empty($url) || Network::isUrlBlocked($url)) {
			Logger::info('Domain is blocked', ['url' => $url]);
			return [];
		}

		$fetched_contact = false;

		if (empty($update)) {
			if (is_null($update)) {
				$ref_update = DateTimeFormat::utc('now - 1 month');
			} else {
				$ref_update = DBA::NULL_DATETIME;
			}

			$apcontact = DBA::selectFirst('apcontact', [], ['url' => $url]);
			if (!DBA::isResult($apcontact)) {
				$apcontact = DBA::selectFirst('apcontact', [], ['alias' => $url]);
			}

			if (!DBA::isResult($apcontact)) {
				$apcontact = DBA::selectFirst('apcontact', [], ['addr' => $url]);
			}

			if (DBA::isResult($apcontact) && ($apcontact['updated'] > $ref_update) && !empty($apcontact['pubkey']) && !empty($apcontact['uri-id'])) {
				return $apcontact;
			}

			if (!is_null($update)) {
				return DBA::isResult($apcontact) ? $apcontact : [];
			}

			if (DBA::isResult($apcontact)) {
				$fetched_contact = $apcontact;
			}
		}

		$apcontact = [];

		$webfinger = empty(parse_url($url, PHP_URL_SCHEME));
		if ($webfinger) {
			$apcontact = self::fetchWebfingerData($url);
			if (empty($apcontact['url'])) {
				return $fetched_contact;
			}
			$url = $apcontact['url'];
		}

		// Detect multiple fast repeating request to the same address
		// See https://github.com/friendica/friendica/issues/9303
		$cachekey = 'apcontact:' . ItemURI::getIdByURI($url);
		$result = DI::cache()->get($cachekey);
		if (!is_null($result)) {
			Logger::notice('Multiple requests for the address', ['url' => $url, 'update' => $update, 'callstack' => System::callstack(20), 'result' => $result]);
			if (!empty($fetched_contact)) {
				return $fetched_contact;
			}
		} else {
			DI::cache()->set($cachekey, System::callstack(20), Duration::FIVE_MINUTES);
		}

		if (Network::isLocalLink($url) && ($local_uid = User::getIdForURL($url))) {
			try {
				$data = Transmitter::getProfile($local_uid);
				$local_owner = User::getOwnerDataById($local_uid);
			} catch(HTTPException\NotFoundException $e) {
				$data = null;
			}
		}

		if (empty($data)) {
			$local_owner = [];

			$curlResult = HTTPSignature::fetchRaw($url);
			$failed = empty($curlResult) || empty($curlResult->getBody()) ||
				(!$curlResult->isSuccess() && ($curlResult->getReturnCode() != 410));

			if (!$failed) {
				$data = json_decode($curlResult->getBody(), true);
				$failed = empty($data) || !is_array($data);
			}

			if (!$failed && ($curlResult->getReturnCode() == 410)) {
				$data = ['@context' => ActivityPub::CONTEXT, 'id' => $url, 'type' => 'Tombstone'];
			}

			if ($failed) {
				self::markForArchival($fetched_contact ?: []);
				return $fetched_contact;
			}
		}

		$compacted = JsonLD::compact($data);
		if (empty($compacted['@id'])) {
			return $fetched_contact;
		}

		$apcontact['url'] = $compacted['@id'];
		$apcontact['uuid'] = JsonLD::fetchElement($compacted, 'diaspora:guid', '@value');
		$apcontact['type'] = str_replace('as:', '', JsonLD::fetchElement($compacted, '@type'));
		$apcontact['following'] = JsonLD::fetchElement($compacted, 'as:following', '@id');
		$apcontact['followers'] = JsonLD::fetchElement($compacted, 'as:followers', '@id');
		$apcontact['inbox'] = JsonLD::fetchElement($compacted, 'ldp:inbox', '@id');
		self::unarchiveInbox($apcontact['inbox'], false);

		$apcontact['outbox'] = JsonLD::fetchElement($compacted, 'as:outbox', '@id');

		$apcontact['sharedinbox'] = '';
		if (!empty($compacted['as:endpoints'])) {
			$apcontact['sharedinbox'] = JsonLD::fetchElement($compacted['as:endpoints'], 'as:sharedInbox', '@id');
			self::unarchiveInbox($apcontact['sharedinbox'], true);
		}

		$apcontact['featured']      = JsonLD::fetchElement($compacted, 'toot:featured', '@id');
		$apcontact['featured-tags'] = JsonLD::fetchElement($compacted, 'toot:featuredTags', '@id');

		$apcontact['nick'] = JsonLD::fetchElement($compacted, 'as:preferredUsername', '@value') ?? '';
		$apcontact['name'] = JsonLD::fetchElement($compacted, 'as:name', '@value');

		if (empty($apcontact['name'])) {
			$apcontact['name'] = $apcontact['nick'];
		}

		$apcontact['about'] = HTML::toBBCode(JsonLD::fetchElement($compacted, 'as:summary', '@value'));

		$ims = JsonLD::fetchElementArray($compacted, 'vcard:hasInstantMessage');
		if (!empty($ims)) {
			foreach ($ims as $link) {
				if (substr($link, 0, 5) == 'xmpp:') {
					$apcontact['xmpp'] = substr($link, 5);
				}
				if (substr($link, 0, 7) == 'matrix:') {
					$apcontact['matrix'] = substr($link, 7);
				}
			}
		}

		$apcontact['photo'] = JsonLD::fetchElement($compacted, 'as:icon', '@id');
		if (is_array($apcontact['photo']) || !empty($compacted['as:icon']['as:url']['@id'])) {
			$apcontact['photo'] = JsonLD::fetchElement($compacted['as:icon'], 'as:url', '@id');
		}

		$apcontact['header'] = JsonLD::fetchElement($compacted, 'as:image', '@id');
		if (is_array($apcontact['header']) || !empty($compacted['as:image']['as:url']['@id'])) {
			$apcontact['header'] = JsonLD::fetchElement($compacted['as:image'], 'as:url', '@id');
		}

		if (empty($apcontact['alias'])) {
			$apcontact['alias'] = JsonLD::fetchElement($compacted, 'as:url', '@id');
			if (is_array($apcontact['alias'])) {
				$apcontact['alias'] = JsonLD::fetchElement($compacted['as:url'], 'as:href', '@id');
			}
		}

		// Quit if none of the basic values are set
		if (empty($apcontact['url']) || empty($apcontact['type']) || (($apcontact['type'] != 'Tombstone') && empty($apcontact['inbox']))) {
			return $fetched_contact;
		} elseif ($apcontact['type'] == 'Tombstone') {
			// The "inbox" field must have a content
			$apcontact['inbox'] = '';
		}

		// Quit if this doesn't seem to be an account at all
		if (!in_array($apcontact['type'], ActivityPub::ACCOUNT_TYPES)) {
			return $fetched_contact;
		}

		$parts = parse_url($apcontact['url']);
		unset($parts['scheme']);
		unset($parts['path']);

		if (empty($apcontact['addr'])) {
			if (!empty($apcontact['nick']) && is_array($parts)) {
				$apcontact['addr'] = $apcontact['nick'] . '@' . str_replace('//', '', Network::unparseURL($parts));
			} else {
				$apcontact['addr'] = '';
			}
		}

		$apcontact['pubkey'] = null;
		if (!empty($compacted['w3id:publicKey'])) {
			$apcontact['pubkey'] = trim(JsonLD::fetchElement($compacted['w3id:publicKey'], 'w3id:publicKeyPem', '@value'));
			if (strstr($apcontact['pubkey'], 'RSA ')) {
				$apcontact['pubkey'] = Crypto::rsaToPem($apcontact['pubkey']);
			}
		}

		$apcontact['manually-approve'] = (int)JsonLD::fetchElement($compacted, 'as:manuallyApprovesFollowers');

		if (!empty($compacted['as:generator'])) {
			$apcontact['baseurl'] = JsonLD::fetchElement($compacted['as:generator'], 'as:url', '@id');
			$apcontact['generator'] = JsonLD::fetchElement($compacted['as:generator'], 'as:name', '@value');
		}

		if (!empty($apcontact['following'])) {
			if (!empty($local_owner)) {
				$following = ActivityPub\Transmitter::getContacts($local_owner, [Contact::SHARING, Contact::FRIEND], 'following');
			} else {
				$following = ActivityPub::fetchContent($apcontact['following']);
			}
			if (!empty($following['totalItems'])) {
				// Mastodon seriously allows for this condition?
				// Jul 14 2021 - See https://mastodon.social/@BLUW for a negative following count
				if ($following['totalItems'] < 0) {
					$following['totalItems'] = 0;
				}
				$apcontact['following_count'] = $following['totalItems'];
			}
		}

		if (!empty($apcontact['followers'])) {
			if (!empty($local_owner)) {
				$followers = ActivityPub\Transmitter::getContacts($local_owner, [Contact::FOLLOWER, Contact::FRIEND], 'followers');
			} else {
				$followers = ActivityPub::fetchContent($apcontact['followers']);
			}
			if (!empty($followers['totalItems'])) {
				// Mastodon seriously allows for this condition?
				// Jul 14 2021 - See https://mastodon.online/@goes11 for a negative followers count
				if ($followers['totalItems'] < 0) {
					$followers['totalItems'] = 0;
				}
				$apcontact['followers_count'] = $followers['totalItems'];
			}
		}

		if (!empty($apcontact['outbox'])) {
			if (!empty($local_owner)) {
				$outbox = ActivityPub\Transmitter::getOutbox($local_owner);
			} else {
				$outbox = ActivityPub::fetchContent($apcontact['outbox']);
			}
			if (!empty($outbox['totalItems'])) {
				// Mastodon seriously allows for this condition?
				// Jul 20 2021 - See https://chaos.social/@m11 for a negative posts count
				if ($outbox['totalItems'] < 0) {
					$outbox['totalItems'] = 0;
				}
				$apcontact['statuses_count'] = $outbox['totalItems'];
			}
		}

		$apcontact['discoverable'] = JsonLD::fetchElement($compacted, 'toot:discoverable', '@value');

		// To-Do

		// Unhandled
		// tag, attachment, image, nomadicLocations, signature, featured, movedTo, liked

		// Unhandled from Misskey
		// sharedInbox, isCat

		// Unhandled from Kroeg
		// kroeg:blocks, updated

		// When the photo is too large, try to shorten it by removing parts
		if (strlen($apcontact['photo']) > 255) {
			$parts = parse_url($apcontact['photo']);
			unset($parts['fragment']);
			$apcontact['photo'] = Network::unparseURL($parts);

			if (strlen($apcontact['photo']) > 255) {
				unset($parts['query']);
				$apcontact['photo'] = Network::unparseURL($parts);
			}

			if (strlen($apcontact['photo']) > 255) {
				$apcontact['photo'] = substr($apcontact['photo'], 0, 255);
			}
		}

		if (!$webfinger && !empty($apcontact['addr'])) {
			$data = self::fetchWebfingerData($apcontact['addr']);
			if (!empty($data)) {
				$apcontact['baseurl'] = $data['baseurl'];

				if (empty($apcontact['alias']) && !empty($data['alias'])) {
					$apcontact['alias'] = $data['alias'];
				}
				if (!empty($data['subscribe'])) {
					$apcontact['subscribe'] = $data['subscribe'];
				}
			} else {
				$apcontact['addr'] = null;
			}
		}

		if (empty($apcontact['baseurl'])) {
			$apcontact['baseurl'] = null;
		}

		if (empty($apcontact['subscribe'])) {
			$apcontact['subscribe'] = null;
		}

		if (!empty($apcontact['baseurl']) && empty($fetched_contact['gsid'])) {
			$apcontact['gsid'] = GServer::getID($apcontact['baseurl']);
		} elseif (!empty($fetched_contact['gsid'])) {
			$apcontact['gsid'] = $fetched_contact['gsid'];
		} else {
			$apcontact['gsid'] = null;
		}

		if ($apcontact['url'] == $apcontact['alias']) {
			$apcontact['alias'] = null;
		}

		if (empty($apcontact['uuid'])) {
			$apcontact['uri-id'] = ItemURI::getIdByURI($apcontact['url']);
		} else {
			$apcontact['uri-id'] = ItemURI::insert(['uri' => $apcontact['url'], 'guid' => $apcontact['uuid']]);
		}

		$apcontact['updated'] = DateTimeFormat::utcNow();

		// We delete the old entry when the URL is changed
		if ($url != $apcontact['url']) {
			Logger::info('Delete changed profile url', ['old' => $url, 'new' => $apcontact['url']]);
			DBA::delete('apcontact', ['url' => $url]);
		}

		// Limit the length on incoming fields
		$apcontact = DBStructure::getFieldsForTable('apcontact', $apcontact);

		if (DBA::exists('apcontact', ['url' => $apcontact['url']])) {
			DBA::update('apcontact', $apcontact, ['url' => $apcontact['url']]);
		} else {
			DBA::replace('apcontact', $apcontact);
		}

		Logger::info('Updated profile', ['url' => $url]);

		return DBA::selectFirst('apcontact', [], ['url' => $apcontact['url']]) ?: [];
	}

	/**
	 * Mark the given AP Contact as "to archive"
	 *
	 * @param array $apcontact
	 * @return void
	 */
	public static function markForArchival(array $apcontact)
	{
		if (!empty($apcontact['inbox'])) {
			Logger::info('Set inbox status to failure', ['inbox' => $apcontact['inbox']]);
			HTTPSignature::setInboxStatus($apcontact['inbox'], false);
		}

		if (!empty($apcontact['sharedinbox'])) {
			// Check if there are any available inboxes
			$available = DBA::exists('apcontact', ["`sharedinbox` = ? AnD `inbox` IN (SELECT `url` FROM `inbox-status` WHERE `success` > `failure`)",
				$apcontact['sharedinbox']]);
			if (!$available) {
				// If all known personal inboxes are failing then set their shared inbox to failure as well
				Logger::info('Set shared inbox status to failure', ['sharedinbox' => $apcontact['sharedinbox']]);
				HTTPSignature::setInboxStatus($apcontact['sharedinbox'], false, true);
			}
		}
	}

	/**
	 * Unmark the given AP Contact as "to archive"
	 *
	 * @param array $apcontact
	 * @return void
	 */
	public static function unmarkForArchival(array $apcontact)
	{
		if (!empty($apcontact['inbox'])) {
			Logger::info('Set inbox status to success', ['inbox' => $apcontact['inbox']]);
			HTTPSignature::setInboxStatus($apcontact['inbox'], true);
		}
		if (!empty($apcontact['sharedinbox'])) {
			Logger::info('Set shared inbox status to success', ['sharedinbox' => $apcontact['sharedinbox']]);
			HTTPSignature::setInboxStatus($apcontact['sharedinbox'], true, true);
		}
	}

	/**
	 * Unarchive inboxes
	 *
	 * @param string  $url    inbox url
	 * @param boolean $shared Shared Inbox
	 */
	private static function unarchiveInbox($url, $shared)
	{
		if (empty($url)) {
			return;
		}

		HTTPSignature::setInboxStatus($url, true, $shared);
	}
}
