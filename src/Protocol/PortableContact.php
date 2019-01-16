<?php
/**
 * @file src/Protocol/PortableContact.php
 *
 * @todo Move GNU Social URL schemata (http://server.tld/user/number) to http://server.tld/username
 * @todo Fetch profile data from profile page for Redmatrix users
 * @todo Detect if it is a forum
 */

namespace Friendica\Protocol;

use DOMDocument;
use DOMXPath;
use Exception;
use Friendica\Content\Text\HTML;
use Friendica\Core\Config;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\Model\GContact;
use Friendica\Model\Profile;
use Friendica\Network\Probe;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Network;
use Friendica\Util\Strings;
use Friendica\Util\XML;

class PortableContact
{
	/**
	 * @brief Fetch POCO data
	 *
	 * @param integer $cid  Contact ID
	 * @param integer $uid  User ID
	 * @param integer $zcid Global Contact ID
	 * @param integer $url  POCO address that should be polled
	 *
	 * Given a contact-id (minimum), load the PortableContacts friend list for that contact,
	 * and add the entries to the gcontact (Global Contact) table, or update existing entries
	 * if anything (name or photo) has changed.
	 * We use normalised urls for comparison which ignore http vs https and www.domain vs domain
	 *
	 * Once the global contact is stored add (if necessary) the contact linkage which associates
	 * the given uid, cid to the global contact entry. There can be many uid/cid combinations
	 * pointing to the same global contact id.
	 *
	 */
	public static function loadWorker($cid, $uid = 0, $zcid = 0, $url = null)
	{
		// Call the function "load" via the worker
		Worker::add(PRIORITY_LOW, "DiscoverPoCo", "load", (int)$cid, (int)$uid, (int)$zcid, $url);
	}

	/**
	 * @brief Fetch POCO data from the worker
	 *
	 * @param integer $cid  Contact ID
	 * @param integer $uid  User ID
	 * @param integer $zcid Global Contact ID
	 * @param integer $url  POCO address that should be polled
	 *
	 */
	public static function load($cid, $uid, $zcid, $url)
	{
		$a = \get_app();

		if ($cid) {
			if (!$url || !$uid) {
				$contact = DBA::selectFirst('contact', ['poco', 'uid'], ['id' => $cid]);
				if (DBA::isResult($contact)) {
					$url = $contact['poco'];
					$uid = $contact['uid'];
				}
			}
			if (!$uid) {
				return;
			}
		}

		if (!$url) {
			return;
		}

		$url = $url . (($uid) ? '/@me/@all?fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation' : '?fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation');

		Logger::log('load: ' . $url, Logger::DEBUG);

		$fetchresult = Network::fetchUrlFull($url);
		$s = $fetchresult->getBody();

		Logger::log('load: returns ' . $s, Logger::DATA);

		Logger::log('load: return code: ' . $fetchresult->getReturnCode(), Logger::DEBUG);

		if (($fetchresult->getReturnCode() > 299) || (! $s)) {
			return;
		}

		$j = json_decode($s, true);

		Logger::log('load: json: ' . print_r($j, true), Logger::DATA);

		if (!isset($j['entry'])) {
			return;
		}

		$total = 0;
		foreach ($j['entry'] as $entry) {
			$total ++;
			$profile_url = '';
			$profile_photo = '';
			$connect_url = '';
			$name = '';
			$network = '';
			$updated = DBA::NULL_DATETIME;
			$location = '';
			$about = '';
			$keywords = '';
			$gender = '';
			$contact_type = -1;
			$generation = 0;

			if (!empty($entry['displayName'])) {
				$name = $entry['displayName'];
			}

			if (isset($entry['urls'])) {
				foreach ($entry['urls'] as $url) {
					if ($url['type'] == 'profile') {
						$profile_url = $url['value'];
						continue;
					}
					if ($url['type'] == 'webfinger') {
						$connect_url = str_replace('acct:', '', $url['value']);
						continue;
					}
				}
			}
			if (isset($entry['photos'])) {
				foreach ($entry['photos'] as $photo) {
					if ($photo['type'] == 'profile') {
						$profile_photo = $photo['value'];
						continue;
					}
				}
			}

			if (isset($entry['updated'])) {
				$updated = date(DateTimeFormat::MYSQL, strtotime($entry['updated']));
			}

			if (isset($entry['network'])) {
				$network = $entry['network'];
			}

			if (isset($entry['currentLocation'])) {
				$location = $entry['currentLocation'];
			}

			if (isset($entry['aboutMe'])) {
				$about = HTML::toBBCode($entry['aboutMe']);
			}

			if (isset($entry['gender'])) {
				$gender = $entry['gender'];
			}

			if (isset($entry['generation']) && ($entry['generation'] > 0)) {
				$generation = ++$entry['generation'];
			}

			if (isset($entry['tags'])) {
				foreach ($entry['tags'] as $tag) {
					$keywords = implode(", ", $tag);
				}
			}

			if (isset($entry['contactType']) && ($entry['contactType'] >= 0)) {
				$contact_type = $entry['contactType'];
			}

			$gcontact = ["url" => $profile_url,
					"name" => $name,
					"network" => $network,
					"photo" => $profile_photo,
					"about" => $about,
					"location" => $location,
					"gender" => $gender,
					"keywords" => $keywords,
					"connect" => $connect_url,
					"updated" => $updated,
					"contact-type" => $contact_type,
					"generation" => $generation];

			try {
				$gcontact = GContact::sanitize($gcontact);
				$gcid = GContact::update($gcontact);

				GContact::link($gcid, $uid, $cid, $zcid);
			} catch (Exception $e) {
				Logger::log($e->getMessage(), Logger::DEBUG);
			}
		}
		Logger::log("load: loaded $total entries", Logger::DEBUG);

		$condition = ["`cid` = ? AND `uid` = ? AND `zcid` = ? AND `updated` < UTC_TIMESTAMP - INTERVAL 2 DAY", $cid, $uid, $zcid];
		DBA::delete('glink', $condition);
	}

	public static function reachable($profile, $server = "", $network = "", $force = false)
	{
		if ($server == "") {
			$server = self::detectServer($profile);
		}

		if ($server == "") {
			return true;
		}

		return self::checkServer($server, $network, $force);
	}

	public static function detectServer($profile)
	{
		// Try to detect the server path based upon some known standard paths
		$server_url = "";

		if ($server_url == "") {
			$friendica = preg_replace("=(https?://)(.*)/profile/(.*)=ism", "$1$2", $profile);
			if ($friendica != $profile) {
				$server_url = $friendica;
				$network = Protocol::DFRN;
			}
		}

		if ($server_url == "") {
			$diaspora = preg_replace("=(https?://)(.*)/u/(.*)=ism", "$1$2", $profile);
			if ($diaspora != $profile) {
				$server_url = $diaspora;
				$network = Protocol::DIASPORA;
			}
		}

		if ($server_url == "") {
			$red = preg_replace("=(https?://)(.*)/channel/(.*)=ism", "$1$2", $profile);
			if ($red != $profile) {
				$server_url = $red;
				$network = Protocol::DIASPORA;
			}
		}

		// Mastodon
		if ($server_url == "") {
			$mastodon = preg_replace("=(https?://)(.*)/users/(.*)=ism", "$1$2", $profile);
			if ($mastodon != $profile) {
				$server_url = $mastodon;
				$network = Protocol::OSTATUS;
			}
		}

		// Numeric OStatus variant
		if ($server_url == "") {
			$ostatus = preg_replace("=(https?://)(.*)/user/(.*)=ism", "$1$2", $profile);
			if ($ostatus != $profile) {
				$server_url = $ostatus;
				$network = Protocol::OSTATUS;
			}
		}

		// Wild guess
		if ($server_url == "") {
			$base = preg_replace("=(https?://)(.*?)/(.*)=ism", "$1$2", $profile);
			if ($base != $profile) {
				$server_url = $base;
				$network = Protocol::PHANTOM;
			}
		}

		if ($server_url == "") {
			return "";
		}

		$r = q(
			"SELECT `id` FROM `gserver` WHERE `nurl` = '%s' AND `last_contact` > `last_failure`",
			DBA::escape(Strings::normaliseLink($server_url))
		);

		if (DBA::isResult($r)) {
			return $server_url;
		}

		// Fetch the host-meta to check if this really is a server
		$curlResult = Network::curl($server_url."/.well-known/host-meta");
		if (!$curlResult->isSuccess()) {
			return "";
		}

		return $server_url;
	}

	public static function alternateOStatusUrl($url)
	{
		return(preg_match("=https?://.+/user/\d+=ism", $url, $matches));
	}

	public static function lastUpdated($profile, $force = false)
	{
		$gcontacts = q(
			"SELECT * FROM `gcontact` WHERE `nurl` = '%s'",
			DBA::escape(Strings::normaliseLink($profile))
		);

		if (!DBA::isResult($gcontacts)) {
			return false;
		}

		$contact = ["url" => $profile];

		if ($gcontacts[0]["created"] <= DBA::NULL_DATETIME) {
			$contact['created'] = DateTimeFormat::utcNow();
		}

		$server_url = '';
		if ($force) {
			$server_url = Strings::normaliseLink(self::detectServer($profile));
		}

		if (($server_url == '') && ($gcontacts[0]["server_url"] != "")) {
			$server_url = $gcontacts[0]["server_url"];
		}

		if (!$force && (($server_url == '') || ($gcontacts[0]["server_url"] == $gcontacts[0]["nurl"]))) {
			$server_url = Strings::normaliseLink(self::detectServer($profile));
		}

		if (!in_array($gcontacts[0]["network"], [Protocol::DFRN, Protocol::DIASPORA, Protocol::FEED, Protocol::OSTATUS, ""])) {
			Logger::log("Profile ".$profile.": Network type ".$gcontacts[0]["network"]." can't be checked", Logger::DEBUG);
			return false;
		}

		if ($server_url != "") {
			if (!self::checkServer($server_url, $gcontacts[0]["network"], $force)) {
				if ($force) {
					$fields = ['last_failure' => DateTimeFormat::utcNow()];
					DBA::update('gcontact', $fields, ['nurl' => Strings::normaliseLink($profile)]);
				}

				Logger::log("Profile ".$profile.": Server ".$server_url." wasn't reachable.", Logger::DEBUG);
				return false;
			}
			$contact['server_url'] = $server_url;
		}

		if (in_array($gcontacts[0]["network"], ["", Protocol::FEED])) {
			$server = q(
				"SELECT `network` FROM `gserver` WHERE `nurl` = '%s' AND `network` != ''",
				DBA::escape(Strings::normaliseLink($server_url))
			);

			if ($server) {
				$contact['network'] = $server[0]["network"];
			} else {
				return false;
			}
		}

		// noscrape is really fast so we don't cache the call.
		if (($server_url != "") && ($gcontacts[0]["nick"] != "")) {
			//  Use noscrape if possible
			$server = q("SELECT `noscrape`, `network` FROM `gserver` WHERE `nurl` = '%s' AND `noscrape` != ''", DBA::escape(Strings::normaliseLink($server_url)));

			if ($server) {
				$curlResult = Network::curl($server[0]["noscrape"]."/".$gcontacts[0]["nick"]);

				if ($curlResult->isSuccess() && ($curlResult->getBody() != "")) {
					$noscrape = json_decode($curlResult->getBody(), true);

					if (is_array($noscrape)) {
						$contact["network"] = $server[0]["network"];

						if (isset($noscrape["fn"])) {
							$contact["name"] = $noscrape["fn"];
						}
						if (isset($noscrape["comm"])) {
							$contact["community"] = $noscrape["comm"];
						}
						if (isset($noscrape["tags"])) {
							$keywords = implode(" ", $noscrape["tags"]);
							if ($keywords != "") {
								$contact["keywords"] = $keywords;
							}
						}

						$location = Profile::formatLocation($noscrape);
						if ($location) {
							$contact["location"] = $location;
						}
						if (isset($noscrape["dfrn-notify"])) {
							$contact["notify"] = $noscrape["dfrn-notify"];
						}
						// Remove all fields that are not present in the gcontact table
						unset($noscrape["fn"]);
						unset($noscrape["key"]);
						unset($noscrape["homepage"]);
						unset($noscrape["comm"]);
						unset($noscrape["tags"]);
						unset($noscrape["locality"]);
						unset($noscrape["region"]);
						unset($noscrape["country-name"]);
						unset($noscrape["contacts"]);
						unset($noscrape["dfrn-request"]);
						unset($noscrape["dfrn-confirm"]);
						unset($noscrape["dfrn-notify"]);
						unset($noscrape["dfrn-poll"]);

						// Set the date of the last contact
						/// @todo By now the function "update_gcontact" doesn't work with this field
						//$contact["last_contact"] = DateTimeFormat::utcNow();

						$contact = array_merge($contact, $noscrape);

						GContact::update($contact);

						if (!empty($noscrape["updated"])) {
							$fields = ['last_contact' => DateTimeFormat::utcNow()];
							DBA::update('gcontact', $fields, ['nurl' => Strings::normaliseLink($profile)]);

							Logger::log("Profile ".$profile." was last updated at ".$noscrape["updated"]." (noscrape)", Logger::DEBUG);

							return $noscrape["updated"];
						}
					}
				}
			}
		}

		// If we only can poll the feed, then we only do this once a while
		if (!$force && !self::updateNeeded($gcontacts[0]["created"], $gcontacts[0]["updated"], $gcontacts[0]["last_failure"], $gcontacts[0]["last_contact"])) {
			Logger::log("Profile ".$profile." was last updated at ".$gcontacts[0]["updated"]." (cached)", Logger::DEBUG);

			GContact::update($contact);
			return $gcontacts[0]["updated"];
		}

		$data = Probe::uri($profile);

		// Is the profile link the alternate OStatus link notation? (http://domain.tld/user/4711)
		// Then check the other link and delete this one
		if (($data["network"] == Protocol::OSTATUS) && self::alternateOStatusUrl($profile)
			&& (Strings::normaliseLink($profile) == Strings::normaliseLink($data["alias"]))
			&& (Strings::normaliseLink($profile) != Strings::normaliseLink($data["url"]))
		) {
			// Delete the old entry
			DBA::delete('gcontact', ['nurl' => Strings::normaliseLink($profile)]);

			$gcontact = array_merge($gcontacts[0], $data);

			$gcontact["server_url"] = $data["baseurl"];

			try {
				$gcontact = GContact::sanitize($gcontact);
				GContact::update($gcontact);

				self::lastUpdated($data["url"], $force);
			} catch (Exception $e) {
				Logger::log($e->getMessage(), Logger::DEBUG);
			}

			Logger::log("Profile ".$profile." was deleted", Logger::DEBUG);
			return false;
		}

		if (($data["poll"] == "") || (in_array($data["network"], [Protocol::FEED, Protocol::PHANTOM]))) {
			$fields = ['last_failure' => DateTimeFormat::utcNow()];
			DBA::update('gcontact', $fields, ['nurl' => Strings::normaliseLink($profile)]);

			Logger::log("Profile ".$profile." wasn't reachable (profile)", Logger::DEBUG);
			return false;
		}

		$contact = array_merge($contact, $data);

		$contact["server_url"] = $data["baseurl"];

		GContact::update($contact);

		$curlResult = Network::curl($data["poll"]);

		if (!$curlResult->isSuccess()) {
			$fields = ['last_failure' => DateTimeFormat::utcNow()];
			DBA::update('gcontact', $fields, ['nurl' => Strings::normaliseLink($profile)]);

			Logger::log("Profile ".$profile." wasn't reachable (no feed)", Logger::DEBUG);
			return false;
		}

		$doc = new DOMDocument();
		/// @TODO Avoid error supression here
		@$doc->loadXML($curlResult->getBody());

		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('atom', "http://www.w3.org/2005/Atom");

		$entries = $xpath->query('/atom:feed/atom:entry');

		$last_updated = "";

		foreach ($entries as $entry) {
			$published = DateTimeFormat::utc($xpath->query('atom:published/text()', $entry)->item(0)->nodeValue);
			$updated   = DateTimeFormat::utc($xpath->query('atom:updated/text()'  , $entry)->item(0)->nodeValue);

			if ($last_updated < $published) {
				$last_updated = $published;
			}

			if ($last_updated < $updated) {
				$last_updated = $updated;
			}
		}

		// Maybe there aren't any entries. Then check if it is a valid feed
		if ($last_updated == "") {
			if ($xpath->query('/atom:feed')->length > 0) {
				$last_updated = DBA::NULL_DATETIME;
			}
		}

		$fields = ['last_contact' => DateTimeFormat::utcNow()];

		if (!empty($last_updated)) {
			$fields['updated'] = $last_updated;
		}

		DBA::update('gcontact', $fields, ['nurl' => Strings::normaliseLink($profile)]);

		if (($gcontacts[0]["generation"] == 0)) {
			$fields = ['generation' => 9];
			DBA::update('gcontact', $fields, ['nurl' => Strings::normaliseLink($profile)]);
		}

		Logger::log("Profile ".$profile." was last updated at ".$last_updated, Logger::DEBUG);

		return $last_updated;
	}

	public static function updateNeeded($created, $updated, $last_failure, $last_contact)
	{
		$now = strtotime(DateTimeFormat::utcNow());

		if ($updated > $last_contact) {
			$contact_time = strtotime($updated);
		} else {
			$contact_time = strtotime($last_contact);
		}

		$failure_time = strtotime($last_failure);
		$created_time = strtotime($created);

		// If there is no "created" time then use the current time
		if ($created_time <= 0) {
			$created_time = $now;
		}

		// If the last contact was less than 24 hours then don't update
		if (($now - $contact_time) < (60 * 60 * 24)) {
			return false;
		}

		// If the last failure was less than 24 hours then don't update
		if (($now - $failure_time) < (60 * 60 * 24)) {
			return false;
		}

		// If the last contact was less than a week ago and the last failure is older than a week then don't update
		//if ((($now - $contact_time) < (60 * 60 * 24 * 7)) && ($contact_time > $failure_time))
		//	return false;

		// If the last contact time was more than a week ago and the contact was created more than a week ago, then only try once a week
		if ((($now - $contact_time) > (60 * 60 * 24 * 7)) && (($now - $created_time) > (60 * 60 * 24 * 7)) && (($now - $failure_time) < (60 * 60 * 24 * 7))) {
			return false;
		}

		// If the last contact time was more than a month ago and the contact was created more than a month ago, then only try once a month
		if ((($now - $contact_time) > (60 * 60 * 24 * 30)) && (($now - $created_time) > (60 * 60 * 24 * 30)) && (($now - $failure_time) < (60 * 60 * 24 * 30))) {
			return false;
		}

		return true;
	}

	/// @TODO Maybe move this out to an utilities class?
	private static function toBoolean($val)
	{
		if (($val == "true") || ($val == 1)) {
			return true;
		} elseif (($val == "false") || ($val == 0)) {
			return false;
		}

		return $val;
	}

	/**
	 * @brief Detect server type (Hubzilla or Friendica) via the poco data
	 *
	 * @param array $data POCO data
	 * @return array Server data
	 */
	private static function detectPocoData(array $data)
	{
		$server = false;

		if (!isset($data['entry'])) {
			return false;
		}

		if (count($data['entry']) == 0) {
			return false;
		}

		if (!isset($data['entry'][0]['urls'])) {
			return false;
		}

		if (count($data['entry'][0]['urls']) == 0) {
			return false;
		}

		foreach ($data['entry'][0]['urls'] as $url) {
			if ($url['type'] == 'zot') {
				$server = [];
				$server["platform"] = 'Hubzilla';
				$server["network"] = Protocol::DIASPORA;
				return $server;
			}
		}
		return false;
	}

	/**
	 * @brief Detect server type by using the nodeinfo data
	 *
	 * @param string $server_url address of the server
	 * @return array Server data
	 */
	private static function fetchNodeinfo($server_url)
	{
		$curlResult = Network::curl($server_url."/.well-known/nodeinfo");
		if (!$curlResult->isSuccess()) {
			return false;
		}

		$nodeinfo = json_decode($curlResult->getBody(), true);

		if (!is_array($nodeinfo) || !isset($nodeinfo['links'])) {
			return false;
		}

		$nodeinfo1_url = '';
		$nodeinfo2_url = '';

		foreach ($nodeinfo['links'] as $link) {
			if (!is_array($link) || empty($link['rel'])) {
				Logger::log('Invalid nodeinfo format for ' . $server_url, Logger::DEBUG);
				continue;
			}
			if ($link['rel'] == 'http://nodeinfo.diaspora.software/ns/schema/1.0') {
				$nodeinfo1_url = $link['href'];
			} elseif ($link['rel'] == 'http://nodeinfo.diaspora.software/ns/schema/2.0') {
				$nodeinfo2_url = $link['href'];
			}
		}

		if ($nodeinfo1_url . $nodeinfo2_url == '') {
			return false;
		}

		$server = [];

		// When the nodeinfo url isn't on the same host, then there is obviously something wrong
		if (!empty($nodeinfo2_url) && (parse_url($server_url, PHP_URL_HOST) == parse_url($nodeinfo2_url, PHP_URL_HOST))) {
			$server = self::parseNodeinfo2($nodeinfo2_url);
		}

		// When the nodeinfo url isn't on the same host, then there is obviously something wrong
		if (empty($server) && !empty($nodeinfo1_url) && (parse_url($server_url, PHP_URL_HOST) == parse_url($nodeinfo1_url, PHP_URL_HOST))) {
			$server = self::parseNodeinfo1($nodeinfo1_url);
		}

		return $server;
	}

	/**
	 * @brief Parses Nodeinfo 1
	 *
	 * @param string $nodeinfo_url address of the nodeinfo path
	 * @return array Server data
	 */
	private static function parseNodeinfo1($nodeinfo_url)
	{
		$curlResult = Network::curl($nodeinfo_url);

		if (!$curlResult->isSuccess()) {
			return false;
		}

		$nodeinfo = json_decode($curlResult->getBody(), true);

		if (!is_array($nodeinfo)) {
			return false;
		}

		$server = [];

		$server['register_policy'] = REGISTER_CLOSED;

		if (is_bool($nodeinfo['openRegistrations']) && $nodeinfo['openRegistrations']) {
			$server['register_policy'] = REGISTER_OPEN;
		}

		if (is_array($nodeinfo['software'])) {
			if (isset($nodeinfo['software']['name'])) {
				$server['platform'] = $nodeinfo['software']['name'];
			}

			if (isset($nodeinfo['software']['version'])) {
				$server['version'] = $nodeinfo['software']['version'];
				// Version numbers on Nodeinfo are presented with additional info, e.g.:
				// 0.6.3.0-p1702cc1c, 0.6.99.0-p1b9ab160 or 3.4.3-2-1191.
				$server['version'] = preg_replace("=(.+)-(.{4,})=ism", "$1", $server['version']);
			}
		}

		if (isset($nodeinfo['metadata']['nodeName'])) {
			$server['site_name'] = $nodeinfo['metadata']['nodeName'];
		}

		if (!empty($nodeinfo['usage']['users']['total'])) {
			$server['registered-users'] = $nodeinfo['usage']['users']['total'];
		}

		$diaspora = false;
		$friendica = false;
		$gnusocial = false;

		if (is_array($nodeinfo['protocols']['inbound'])) {
			foreach ($nodeinfo['protocols']['inbound'] as $inbound) {
				if ($inbound == 'diaspora') {
					$diaspora = true;
				}
				if ($inbound == 'friendica') {
					$friendica = true;
				}
				if ($inbound == 'gnusocial') {
					$gnusocial = true;
				}
			}
		}

		if ($gnusocial) {
			$server['network'] = Protocol::OSTATUS;
		}
		if ($diaspora) {
			$server['network'] = Protocol::DIASPORA;
		}
		if ($friendica) {
			$server['network'] = Protocol::DFRN;
		}

		if (!$server) {
			return false;
		}

		return $server;
	}

	/**
	 * @brief Parses Nodeinfo 2
	 *
	 * @param string $nodeinfo_url address of the nodeinfo path
	 * @return array Server data
	 */
	private static function parseNodeinfo2($nodeinfo_url)
	{
		$curlResult = Network::curl($nodeinfo_url);
		if (!$curlResult->isSuccess()) {
			return false;
		}

		$nodeinfo = json_decode($curlResult->getBody(), true);

		if (!is_array($nodeinfo)) {
			return false;
		}

		$server = [];

		$server['register_policy'] = REGISTER_CLOSED;

		if (is_bool($nodeinfo['openRegistrations']) && $nodeinfo['openRegistrations']) {
			$server['register_policy'] = REGISTER_OPEN;
		}

		if (is_array($nodeinfo['software'])) {
			if (isset($nodeinfo['software']['name'])) {
				$server['platform'] = $nodeinfo['software']['name'];
			}

			if (isset($nodeinfo['software']['version'])) {
				$server['version'] = $nodeinfo['software']['version'];
				// Version numbers on Nodeinfo are presented with additional info, e.g.:
				// 0.6.3.0-p1702cc1c, 0.6.99.0-p1b9ab160 or 3.4.3-2-1191.
				$server['version'] = preg_replace("=(.+)-(.{4,})=ism", "$1", $server['version']);
			}
		}

		if (isset($nodeinfo['metadata']['nodeName'])) {
			$server['site_name'] = $nodeinfo['metadata']['nodeName'];
		}

		if (!empty($nodeinfo['usage']['users']['total'])) {
			$server['registered-users'] = $nodeinfo['usage']['users']['total'];
		}

		$diaspora = false;
		$friendica = false;
		$gnusocial = false;

		if (!empty($nodeinfo['protocols'])) {
			foreach ($nodeinfo['protocols'] as $protocol) {
				if ($protocol == 'diaspora') {
					$diaspora = true;
				} elseif ($protocol == 'friendica') {
					$friendica = true;
				} elseif ($protocol == 'gnusocial') {
					$gnusocial = true;
				}
			}
		}

		if ($gnusocial) {
			$server['network'] = Protocol::OSTATUS;
		} elseif ($diaspora) {
			$server['network'] = Protocol::DIASPORA;
		} elseif ($friendica) {
			$server['network'] = Protocol::DFRN;
		}

		if (empty($server)) {
			return false;
		}

		return $server;
	}

	/**
	 * @brief Detect server type (Hubzilla or Friendica) via the front page body
	 *
	 * @param string $body Front page of the server
	 * @return array Server data
	 */
	private static function detectServerType($body)
	{
		$server = false;

		$doc = new DOMDocument();
		/// @TODO Acoid supressing error
		@$doc->loadHTML($body);
		$xpath = new DOMXPath($doc);

		$list = $xpath->query("//meta[@name]");

		foreach ($list as $node) {
			$attr = [];
			if ($node->attributes->length) {
				foreach ($node->attributes as $attribute) {
					$attr[$attribute->name] = $attribute->value;
				}
			}
			if ($attr['name'] == 'generator') {
				$version_part = explode(" ", $attr['content']);
				if (count($version_part) == 2) {
					if (in_array($version_part[0], ["Friendika", "Friendica"])) {
						$server = [];
						$server["platform"] = $version_part[0];
						$server["version"] = $version_part[1];
						$server["network"] = Protocol::DFRN;
					}
				}
			}
		}

		if (!$server) {
			$list = $xpath->query("//meta[@property]");

			foreach ($list as $node) {
				$attr = [];
				if ($node->attributes->length) {
					foreach ($node->attributes as $attribute) {
						$attr[$attribute->name] = $attribute->value;
					}
				}
				if ($attr['property'] == 'generator' && in_array($attr['content'], ["hubzilla", "BlaBlaNet"])) {
					$server = [];
					$server["platform"] = $attr['content'];
					$server["version"] = "";
					$server["network"] = Protocol::DIASPORA;
				}
			}
		}

		if (!$server) {
			return false;
		}

		$server["site_name"] = XML::getFirstNodeValue($xpath, '//head/title/text()');

		return $server;
	}

	public static function checkServer($server_url, $network = "", $force = false)
	{
		// Unify the server address
		$server_url = trim($server_url, "/");
		$server_url = str_replace("/index.php", "", $server_url);

		if ($server_url == "") {
			return false;
		}

		$gserver = DBA::selectFirst('gserver', [], ['nurl' => Strings::normaliseLink($server_url)]);
		if (DBA::isResult($gserver)) {
			if ($gserver["created"] <= DBA::NULL_DATETIME) {
				$fields = ['created' => DateTimeFormat::utcNow()];
				$condition = ['nurl' => Strings::normaliseLink($server_url)];
				DBA::update('gserver', $fields, $condition);
			}
			$poco = $gserver["poco"];
			$noscrape = $gserver["noscrape"];

			if ($network == "") {
				$network = $gserver["network"];
			}

			$last_contact = $gserver["last_contact"];
			$last_failure = $gserver["last_failure"];
			$version = $gserver["version"];
			$platform = $gserver["platform"];
			$site_name = $gserver["site_name"];
			$info = $gserver["info"];
			$register_policy = $gserver["register_policy"];
			$registered_users = $gserver["registered-users"];

			// See discussion under https://forum.friendi.ca/display/0b6b25a8135aabc37a5a0f5684081633
			// It can happen that a zero date is in the database, but storing it again is forbidden.
			if ($last_contact < DBA::NULL_DATETIME) {
				$last_contact = DBA::NULL_DATETIME;
			}

			if ($last_failure < DBA::NULL_DATETIME) {
				$last_failure = DBA::NULL_DATETIME;
			}

			if (!$force && !self::updateNeeded($gserver["created"], "", $last_failure, $last_contact)) {
				Logger::log("Use cached data for server ".$server_url, Logger::DEBUG);
				return ($last_contact >= $last_failure);
			}
		} else {
			$poco = "";
			$noscrape = "";
			$version = "";
			$platform = "";
			$site_name = "";
			$info = "";
			$register_policy = -1;
			$registered_users = 0;

			$last_contact = DBA::NULL_DATETIME;
			$last_failure = DBA::NULL_DATETIME;
		}
		Logger::log("Server ".$server_url." is outdated or unknown. Start discovery. Force: ".$force." Created: ".$gserver["created"]." Failure: ".$last_failure." Contact: ".$last_contact, Logger::DEBUG);

		$failure = false;
		$possible_failure = false;
		$orig_last_failure = $last_failure;
		$orig_last_contact = $last_contact;

		// Mastodon uses the "@" for user profiles.
		// But this can be misunderstood.
		if (parse_url($server_url, PHP_URL_USER) != '') {
			DBA::update('gserver', ['last_failure' => DateTimeFormat::utcNow()], ['nurl' => Strings::normaliseLink($server_url)]);
			return false;
		}

		// Check if the page is accessible via SSL.
		$orig_server_url = $server_url;
		$server_url = str_replace("http://", "https://", $server_url);

		// We set the timeout to 20 seconds since this operation should be done in no time if the server was vital
		$curlResult = Network::curl($server_url."/.well-known/host-meta", false, $redirects, ['timeout' => 20]);

		// Quit if there is a timeout.
		// But we want to make sure to only quit if we are mostly sure that this server url fits.
		if (DBA::isResult($gserver) && ($orig_server_url == $server_url) &&
			($curlResult->isTimeout())) {
			Logger::log("Connection to server ".$server_url." timed out.", Logger::DEBUG);
			DBA::update('gserver', ['last_failure' => DateTimeFormat::utcNow()], ['nurl' => Strings::normaliseLink($server_url)]);
			return false;
		}

		// Maybe the page is unencrypted only?
		$xmlobj = @simplexml_load_string($curlResult->getBody(), 'SimpleXMLElement', 0, "http://docs.oasis-open.org/ns/xri/xrd-1.0");
		if (!$curlResult->isSuccess() || ($curlResult->getBody() == "") || empty($xmlobj) || !is_object($xmlobj)) {
			$server_url = str_replace("https://", "http://", $server_url);

			// We set the timeout to 20 seconds since this operation should be done in no time if the server was vital
			$curlResult = Network::curl($server_url."/.well-known/host-meta", false, $redirects, ['timeout' => 20]);

			// Quit if there is a timeout
			if ($curlResult->isTimeout()) {
				Logger::log("Connection to server " . $server_url . " timed out.", Logger::DEBUG);
				DBA::update('gserver', ['last_failure' => DateTimeFormat::utcNow()], ['nurl' => Strings::normaliseLink($server_url)]);
				return false;
			}

			$xmlobj = @simplexml_load_string($curlResult->getBody(), 'SimpleXMLElement', 0, "http://docs.oasis-open.org/ns/xri/xrd-1.0");
		}

		if (!$curlResult->isSuccess() || ($curlResult->getBody() == "") || empty($xmlobj) || !is_object($xmlobj)) {
			// Workaround for bad configured servers (known nginx problem)
			if (!empty($curlResult->getInfo()) && !in_array($curlResult->getInfo()["http_code"], ["403", "404"])) {
				$failure = true;
			}

			$possible_failure = true;
		}

		// If the server has no possible failure we reset the cached data
		if (!$possible_failure) {
			$version = "";
			$platform = "";
			$site_name = "";
			$info = "";
			$register_policy = -1;
		}

		if (!$failure) {
			// This will be too low, but better than no value at all.
			$registered_users = DBA::count('gcontact', ['server_url' => Strings::normaliseLink($server_url)]);
		}

		// Look for poco
		if (!$failure) {
			$curlResult = Network::curl($server_url."/poco");

			if ($curlResult->isSuccess()) {
				$data = json_decode($curlResult->getBody(), true);

				if (isset($data['totalResults'])) {
					$registered_users = $data['totalResults'];
					$poco = $server_url . "/poco";
					$server = self::detectPocoData($data);

					if (!empty($server)) {
						$platform = $server['platform'];
						$network = $server['network'];
						$version = '';
						$site_name = '';
					}
				}

				/*
				 * There are servers out there who don't return 404 on a failure
				 * We have to be sure that don't misunderstand this
				 */
				if (is_null($data)) {
					$poco = "";
					$noscrape = "";
					$network = "";
				}
			}
		}

		if (!$failure) {
			// Test for Diaspora, Hubzilla, Mastodon or older Friendica servers
			$curlResult = Network::curl($server_url);

			if (!$curlResult->isSuccess() || ($curlResult->getBody() == "")) {
				$failure = true;
			} else {
				$server = self::detectServerType($curlResult->getBody());

				if (!empty($server)) {
					$platform = $server['platform'];
					$network = $server['network'];
					$version = $server['version'];
					$site_name = $server['site_name'];
				}

				$lines = explode("\n", $curlResult->getHeader());

				if (count($lines)) {
					foreach ($lines as $line) {
						$line = trim($line);

						if (stristr($line, 'X-Diaspora-Version:')) {
							$platform = "Diaspora";
							$version = trim(str_replace("X-Diaspora-Version:", "", $line));
							$version = trim(str_replace("x-diaspora-version:", "", $version));
							$network = Protocol::DIASPORA;
							$versionparts = explode("-", $version);
							$version = $versionparts[0];
						}

						if (stristr($line, 'Server: Mastodon')) {
							$platform = "Mastodon";
							$network = Protocol::OSTATUS;
						}
					}
				}
			}
		}

		if (!$failure && ($poco == "")) {
			// Test for Statusnet
			// Will also return data for Friendica and GNU Social - but it will be overwritten later
			// The "not implemented" is a special treatment for really, really old Friendica versions
			$curlResult = Network::curl($server_url."/api/statusnet/version.json");

			if ($curlResult->isSuccess() && ($curlResult->getBody() != '{"error":"not implemented"}') &&
				($curlResult->getBody() != '') && (strlen($curlResult->getBody()) < 30)) {
				$platform = "StatusNet";
				// Remove junk that some GNU Social servers return
				$version = str_replace(chr(239).chr(187).chr(191), "", $curlResult->getBody());
				$version = trim($version, '"');
				$network = Protocol::OSTATUS;
			}

			// Test for GNU Social
			$curlResult = Network::curl($server_url."/api/gnusocial/version.json");

			if ($curlResult->isSuccess() && ($curlResult->getBody() != '{"error":"not implemented"}') &&
				($curlResult->getBody() != '') && (strlen($curlResult->getBody()) < 30)) {
				$platform = "GNU Social";
				// Remove junk that some GNU Social servers return
				$version = str_replace(chr(239) . chr(187) . chr(191), "", $curlResult->getBody());
				$version = trim($version, '"');
				$network = Protocol::OSTATUS;
			}

			// Test for Mastodon
			$orig_version = $version;
			$curlResult = Network::curl($server_url . "/api/v1/instance");

			if ($curlResult->isSuccess() && ($curlResult->getBody() != '')) {
				$data = json_decode($curlResult->getBody(), true);

				if (isset($data['version'])) {
					$platform = "Mastodon";
					$version = defaults($data, 'version', '');
					$site_name = defaults($data, 'title', '');
					$info = defaults($data, 'description', '');
					$network = Protocol::OSTATUS;
				}

				if (!empty($data['stats']['user_count'])) {
					$registered_users = $data['stats']['user_count'];
				}
			}

			if (strstr($orig_version . $version, 'Pleroma')) {
				$platform = 'Pleroma';
				$version = trim(str_replace('Pleroma', '', $version));
			}
		}

		if (!$failure) {
			// Test for Hubzilla and Red
			$curlResult = Network::curl($server_url . "/siteinfo.json");

			if ($curlResult->isSuccess()) {
				$data = json_decode($curlResult->getBody(), true);

				if (isset($data['url'])) {
					$platform = $data['platform'];
					$version = $data['version'];
					$network = Protocol::DIASPORA;
				}

				if (!empty($data['site_name'])) {
					$site_name = $data['site_name'];
				}

				if (!empty($data['channels_total'])) {
					$registered_users = $data['channels_total'];
				}

				if (!empty($data['register_policy'])) {
					switch ($data['register_policy']) {
						case "REGISTER_OPEN":
							$register_policy = REGISTER_OPEN;
							break;

						case "REGISTER_APPROVE":
							$register_policy = REGISTER_APPROVE;
							break;

						case "REGISTER_CLOSED":
						default:
							$register_policy = REGISTER_CLOSED;
							break;
					}
				}
			} else {
				// Test for Hubzilla, Redmatrix or Friendica
				$curlResult = Network::curl($server_url."/api/statusnet/config.json");

				if ($curlResult->isSuccess()) {
					$data = json_decode($curlResult->getBody(), true);

					if (isset($data['site']['server'])) {
						if (isset($data['site']['platform'])) {
							$platform = $data['site']['platform']['PLATFORM_NAME'];
							$version = $data['site']['platform']['STD_VERSION'];
							$network = Protocol::DIASPORA;
						}

						if (isset($data['site']['BlaBlaNet'])) {
							$platform = $data['site']['BlaBlaNet']['PLATFORM_NAME'];
							$version = $data['site']['BlaBlaNet']['STD_VERSION'];
							$network = Protocol::DIASPORA;
						}

						if (isset($data['site']['hubzilla'])) {
							$platform = $data['site']['hubzilla']['PLATFORM_NAME'];
							$version = $data['site']['hubzilla']['RED_VERSION'];
							$network = Protocol::DIASPORA;
						}

						if (isset($data['site']['redmatrix'])) {
							if (isset($data['site']['redmatrix']['PLATFORM_NAME'])) {
								$platform = $data['site']['redmatrix']['PLATFORM_NAME'];
							} elseif (isset($data['site']['redmatrix']['RED_PLATFORM'])) {
								$platform = $data['site']['redmatrix']['RED_PLATFORM'];
							}

							$version = $data['site']['redmatrix']['RED_VERSION'];
							$network = Protocol::DIASPORA;
						}

						if (isset($data['site']['friendica'])) {
							$platform = $data['site']['friendica']['FRIENDICA_PLATFORM'];
							$version = $data['site']['friendica']['FRIENDICA_VERSION'];
							$network = Protocol::DFRN;
						}

						$site_name = $data['site']['name'];

						$private = false;
						$inviteonly = false;
						$closed = false;

						if (!empty($data['site']['closed'])) {
							$closed = self::toBoolean($data['site']['closed']);
						}

						if (!empty($data['site']['private'])) {
							$private = self::toBoolean($data['site']['private']);
						}

						if (!empty($data['site']['inviteonly'])) {
							$inviteonly = self::toBoolean($data['site']['inviteonly']);
						}

						if (!$closed && !$private and $inviteonly) {
							$register_policy = REGISTER_APPROVE;
						} elseif (!$closed && !$private) {
							$register_policy = REGISTER_OPEN;
						} else {
							$register_policy = REGISTER_CLOSED;
						}
					}
				}
			}
		}

		// Query statistics.json. Optional package for Diaspora, Friendica and Redmatrix
		if (!$failure) {
			$curlResult = Network::curl($server_url . "/statistics.json");

			if ($curlResult->isSuccess()) {
				$data = json_decode($curlResult->getBody(), true);

				if (isset($data['version'])) {
					$version = $data['version'];
					// Version numbers on statistics.json are presented with additional info, e.g.:
					// 0.6.3.0-p1702cc1c, 0.6.99.0-p1b9ab160 or 3.4.3-2-1191.
					$version = preg_replace("=(.+)-(.{4,})=ism", "$1", $version);
				}

				if (!empty($data['name'])) {
					$site_name = $data['name'];
				}

				if (!empty($data['network'])) {
					$platform = $data['network'];
				}

				if ($platform == "Diaspora") {
					$network = Protocol::DIASPORA;
				}

				if (!empty($data['registrations_open']) && $data['registrations_open']) {
					$register_policy = REGISTER_OPEN;
				} else {
					$register_policy = REGISTER_CLOSED;
				}
			}
		}

		// Query nodeinfo. Working for (at least) Diaspora and Friendica.
		if (!$failure) {
			$server = self::fetchNodeinfo($server_url);

			if (!empty($server)) {
				$register_policy = $server['register_policy'];

				if (isset($server['platform'])) {
					$platform = $server['platform'];
				}

				if (isset($server['network'])) {
					$network = $server['network'];
				}

				if (isset($server['version'])) {
					$version = $server['version'];
				}

				if (isset($server['site_name'])) {
					$site_name = $server['site_name'];
				}

				if (isset($server['registered-users'])) {
					$registered_users = $server['registered-users'];
				}
			}
		}

		// Check for noscrape
		// Friendica servers could be detected as OStatus servers
		if (!$failure && in_array($network, [Protocol::DFRN, Protocol::OSTATUS])) {
			$curlResult = Network::curl($server_url . "/friendica/json");

			if (!$curlResult->isSuccess()) {
				$curlResult = Network::curl($server_url . "/friendika/json");
			}

			if ($curlResult->isSuccess()) {
				$data = json_decode($curlResult->getBody(), true);

				if (isset($data['version'])) {
					$network = Protocol::DFRN;

					if (!empty($data['no_scrape_url'])) {
						$noscrape = $data['no_scrape_url'];
					}

					$version = $data['version'];

					if (!empty($data['site_name'])) {
						$site_name = $data['site_name'];
					}

					$info = defaults($data, 'info', '');
					$register_policy = defaults($data, 'register_policy', REGISTER_CLOSED);
					if (in_array($register_policy, ['REGISTER_CLOSED', 'REGISTER_APPROVE', 'REGISTER_OPEN'])) {
						$register_policy = constant($register_policy);
					} else {
						Logger::log("Register policy '$register_policy' from $server_url is invalid.");
						$register_policy = REGISTER_CLOSED; // set a default value
					}
					$platform = defaults($data, 'platform', '');
				}
			}
		}

		// Every server has got at least an admin account
		if (!$failure && ($registered_users == 0)) {
			$registered_users = 1;
		}

		if ($possible_failure && !$failure) {
			$failure = true;
		}

		if ($failure) {
			$last_contact = $orig_last_contact;
			$last_failure = DateTimeFormat::utcNow();
		} else {
			$last_contact = DateTimeFormat::utcNow();
			$last_failure = $orig_last_failure;
		}

		if (($last_contact <= $last_failure) && !$failure) {
			Logger::log("Server ".$server_url." seems to be alive, but last contact wasn't set - could be a bug", Logger::DEBUG);
		} elseif (($last_contact >= $last_failure) && $failure) {
			Logger::log("Server ".$server_url." seems to be dead, but last failure wasn't set - could be a bug", Logger::DEBUG);
		}

		// Check again if the server exists
		$found = DBA::exists('gserver', ['nurl' => Strings::normaliseLink($server_url)]);

		$version = strip_tags($version);
		$site_name = strip_tags($site_name);
		$info = strip_tags($info);
		$platform = strip_tags($platform);

		$fields = ['url' => $server_url, 'version' => $version,
				'site_name' => $site_name, 'info' => $info, 'register_policy' => $register_policy,
				'poco' => $poco, 'noscrape' => $noscrape, 'network' => $network,
				'platform' => $platform, 'registered-users' => $registered_users,
				'last_contact' => $last_contact, 'last_failure' => $last_failure];

		if ($found) {
			DBA::update('gserver', $fields, ['nurl' => Strings::normaliseLink($server_url)]);
		} elseif (!$failure) {
			$fields['nurl'] = Strings::normaliseLink($server_url);
			$fields['created'] = DateTimeFormat::utcNow();
			DBA::insert('gserver', $fields);
		}

		if (!$failure && in_array($fields['network'], [Protocol::DFRN, Protocol::DIASPORA])) {
			self::discoverRelay($server_url);
		}

		Logger::log("End discovery for server " . $server_url, Logger::DEBUG);

		return !$failure;
	}

	/**
	 * @brief Fetch relay data from a given server url
	 *
	 * @param string $server_url address of the server
	 */
	private static function discoverRelay($server_url)
	{
		Logger::log("Discover relay data for server " . $server_url, Logger::DEBUG);

		$curlResult = Network::curl($server_url . "/.well-known/x-social-relay");

		if (!$curlResult->isSuccess()) {
			return;
		}

		$data = json_decode($curlResult->getBody(), true);

		if (!is_array($data)) {
			return;
		}

		$gserver = DBA::selectFirst('gserver', ['id', 'relay-subscribe', 'relay-scope'], ['nurl' => Strings::normaliseLink($server_url)]);

		if (!DBA::isResult($gserver)) {
			return;
		}

		if (($gserver['relay-subscribe'] != $data['subscribe']) || ($gserver['relay-scope'] != $data['scope'])) {
			$fields = ['relay-subscribe' => $data['subscribe'], 'relay-scope' => $data['scope']];
			DBA::update('gserver', $fields, ['id' => $gserver['id']]);
		}

		DBA::delete('gserver-tag', ['gserver-id' => $gserver['id']]);

		if ($data['scope'] == 'tags') {
			// Avoid duplicates
			$tags = [];
			foreach ($data['tags'] as $tag) {
				$tag = mb_strtolower($tag);
				if (strlen($tag) < 100) {
					$tags[$tag] = $tag;
				}
			}

			foreach ($tags as $tag) {
				DBA::insert('gserver-tag', ['gserver-id' => $gserver['id'], 'tag' => $tag], true);
			}
		}

		// Create or update the relay contact
		$fields = [];
		if (isset($data['protocols'])) {
			if (isset($data['protocols']['diaspora'])) {
				$fields['network'] = Protocol::DIASPORA;

				if (isset($data['protocols']['diaspora']['receive'])) {
					$fields['batch'] = $data['protocols']['diaspora']['receive'];
				} elseif (is_string($data['protocols']['diaspora'])) {
					$fields['batch'] = $data['protocols']['diaspora'];
				}
			}

			if (isset($data['protocols']['dfrn'])) {
				$fields['network'] = Protocol::DFRN;

				if (isset($data['protocols']['dfrn']['receive'])) {
					$fields['batch'] = $data['protocols']['dfrn']['receive'];
				} elseif (is_string($data['protocols']['dfrn'])) {
					$fields['batch'] = $data['protocols']['dfrn'];
				}
			}
		}
		Diaspora::setRelayContact($server_url, $fields);
	}

	/**
	 * @brief Returns a list of all known servers
	 * @return array List of server urls
	 */
	public static function serverlist()
	{
		$r = q(
			"SELECT `url`, `site_name` AS `displayName`, `network`, `platform`, `version` FROM `gserver`
			WHERE `network` IN ('%s', '%s', '%s') AND `last_contact` > `last_failure`
			ORDER BY `last_contact`
			LIMIT 1000",
			DBA::escape(Protocol::DFRN),
			DBA::escape(Protocol::DIASPORA),
			DBA::escape(Protocol::OSTATUS)
		);

		if (!DBA::isResult($r)) {
			return false;
		}

		return $r;
	}

	/**
	 * @brief Fetch server list from remote servers and adds them when they are new.
	 *
	 * @param string $poco URL to the POCO endpoint
	 */
	private static function fetchServerlist($poco)
	{
		$curlResult = Network::curl($poco . "/@server");

		if (!$curlResult->isSuccess()) {
			return;
		}

		$serverlist = json_decode($curlResult->getBody(), true);

		if (!is_array($serverlist)) {
			return;
		}

		foreach ($serverlist as $server) {
			$server_url = str_replace("/index.php", "", $server['url']);

			$r = q("SELECT `nurl` FROM `gserver` WHERE `nurl` = '%s'", DBA::escape(Strings::normaliseLink($server_url)));

			if (!DBA::isResult($r)) {
				Logger::log("Call server check for server ".$server_url, Logger::DEBUG);
				Worker::add(PRIORITY_LOW, "DiscoverPoCo", "server", $server_url);
			}
		}
	}

	private static function discoverFederation()
	{
		$last = Config::get('poco', 'last_federation_discovery');

		if ($last) {
			$next = $last + (24 * 60 * 60);

			if ($next > time()) {
				return;
			}
		}

		// Discover Friendica, Hubzilla and Diaspora servers
		$curlResult = Network::fetchUrl("http://the-federation.info/pods.json");

		if (!empty($curlResult)) {
			$servers = json_decode($curlResult, true);

			if (!empty($servers['pods'])) {
				foreach ($servers['pods'] as $server) {
					Worker::add(PRIORITY_LOW, "DiscoverPoCo", "server", "https://" . $server['host']);
				}
			}
		}

		// Disvover Mastodon servers
		if (!Config::get('system', 'ostatus_disabled')) {
			$accesstoken = Config::get('system', 'instances_social_key');

			if (!empty($accesstoken)) {
				$api = 'https://instances.social/api/1.0/instances/list?count=0';
				$header = ['Authorization: Bearer '.$accesstoken];
				$curlResult = Network::curl($api, false, $redirects, ['headers' => $header]);

				if ($curlResult->isSuccess()) {
					$servers = json_decode($curlResult->getBody(), true);

					foreach ($servers['instances'] as $server) {
						$url = (is_null($server['https_score']) ? 'http' : 'https') . '://' . $server['name'];
						Worker::add(PRIORITY_LOW, "DiscoverPoCo", "server", $url);
					}
				}
			}
		}

		// Currently disabled, since the service isn't available anymore.
		// It is not removed since I hope that there will be a successor.
		// Discover GNU Social Servers.
		//if (!Config::get('system','ostatus_disabled')) {
		//	$serverdata = "http://gstools.org/api/get_open_instances/";

		//	$curlResult = Network::curl($serverdata);
		//	if ($curlResult->isSuccess()) {
		//		$servers = json_decode($result->getBody(), true);

		//		foreach($servers['data'] as $server)
		//			self::checkServer($server['instance_address']);
		//	}
		//}

		Config::set('poco', 'last_federation_discovery', time());
	}

	public static function discoverSingleServer($id)
	{
		$r = q("SELECT `poco`, `nurl`, `url`, `network` FROM `gserver` WHERE `id` = %d", intval($id));

		if (!DBA::isResult($r)) {
			return false;
		}

		$server = $r[0];

		// Discover new servers out there (Works from Friendica version 3.5.2)
		self::fetchServerlist($server["poco"]);

		// Fetch all users from the other server
		$url = $server["poco"] . "/?fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation";

		Logger::log("Fetch all users from the server " . $server["url"], Logger::DEBUG);

		$curlResult = Network::curl($url);

		if ($curlResult->isSuccess() && !empty($curlResult->getBody())) {
			$data = json_decode($curlResult->getBody(), true);

			if (!empty($data)) {
				self::discoverServer($data, 2);
			}

			if (Config::get('system', 'poco_discovery') > 1) {
				$timeframe = Config::get('system', 'poco_discovery_since');

				if ($timeframe == 0) {
					$timeframe = 30;
				}

				$updatedSince = date(DateTimeFormat::MYSQL, time() - $timeframe * 86400);

				// Fetch all global contacts from the other server (Not working with Redmatrix and Friendica versions before 3.3)
				$url = $server["poco"]."/@global?updatedSince=".$updatedSince."&fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation";

				$success = false;

				$curlResult = Network::curl($url);

				if ($curlResult->isSuccess() && !empty($curlResult->getBody())) {
					Logger::log("Fetch all global contacts from the server " . $server["nurl"], Logger::DEBUG);
					$data = json_decode($curlResult->getBody(), true);

					if (!empty($data)) {
						$success = self::discoverServer($data);
					}
				}

				if (!$success && (Config::get('system', 'poco_discovery') > 2)) {
					Logger::log("Fetch contacts from users of the server " . $server["nurl"], Logger::DEBUG);
					self::discoverServerUsers($data, $server);
				}
			}

			$fields = ['last_poco_query' => DateTimeFormat::utcNow()];
			DBA::update('gserver', $fields, ['nurl' => $server["nurl"]]);

			return true;
		} else {
			// If the server hadn't replied correctly, then force a sanity check
			self::checkServer($server["url"], $server["network"], true);

			// If we couldn't reach the server, we will try it some time later
			$fields = ['last_poco_query' => DateTimeFormat::utcNow()];
			DBA::update('gserver', $fields, ['nurl' => $server["nurl"]]);

			return false;
		}
	}

	public static function discover($complete = false)
	{
		// Update the server list
		self::discoverFederation();

		$no_of_queries = 5;

		$requery_days = intval(Config::get('system', 'poco_requery_days'));

		if ($requery_days == 0) {
			$requery_days = 7;
		}

		$last_update = date('c', time() - (60 * 60 * 24 * $requery_days));

		$gservers = q("SELECT `id`, `url`, `nurl`, `network`
			FROM `gserver`
			WHERE `last_contact` >= `last_failure`
			AND `poco` != ''
			AND `last_poco_query` < '%s'
			ORDER BY RAND()", DBA::escape($last_update)
		);

		if (DBA::isResult($gservers)) {
			foreach ($gservers as $gserver) {
				if (!self::checkServer($gserver['url'], $gserver['network'])) {
					// The server is not reachable? Okay, then we will try it later
					$fields = ['last_poco_query' => DateTimeFormat::utcNow()];
					DBA::update('gserver', $fields, ['nurl' => $gserver['nurl']]);
					continue;
				}

				Logger::log('Update directory from server ' . $gserver['url'] . ' with ID ' . $gserver['id'], Logger::DEBUG);
				Worker::add(PRIORITY_LOW, 'DiscoverPoCo', 'update_server_directory', (int) $gserver['id']);

				if (!$complete && ( --$no_of_queries == 0)) {
					break;
				}
			}
		}
	}

	private static function discoverServerUsers(array $data, array $server)
	{
		if (!isset($data['entry'])) {
			return;
		}

		foreach ($data['entry'] as $entry) {
			$username = '';

			if (isset($entry['urls'])) {
				foreach ($entry['urls'] as $url) {
					if ($url['type'] == 'profile') {
						$profile_url = $url['value'];
						$path_array = explode('/', parse_url($profile_url, PHP_URL_PATH));
						$username = end($path_array);
					}
				}
			}

			if ($username != '') {
				Logger::log('Fetch contacts for the user ' . $username . ' from the server ' . $server['nurl'], Logger::DEBUG);

				// Fetch all contacts from a given user from the other server
				$url = $server['poco'] . '/' . $username . '/?fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation';

				$curlResult = Network::curl($url);

				if ($curlResult->isSuccess()) {
					$data = json_decode($curlResult->getBody(), true);

					if (!empty($data)) {
						self::discoverServer($data, 3);
					}
				}
			}
		}
	}

	private static function discoverServer(array $data, $default_generation = 0)
	{
		if (empty($data['entry'])) {
			return false;
		}

		$success = false;

		foreach ($data['entry'] as $entry) {
			$profile_url = '';
			$profile_photo = '';
			$connect_url = '';
			$name = '';
			$network = '';
			$updated = DBA::NULL_DATETIME;
			$location = '';
			$about = '';
			$keywords = '';
			$gender = '';
			$contact_type = -1;
			$generation = $default_generation;

			if (!empty($entry['displayName'])) {
				$name = $entry['displayName'];
			}

			if (isset($entry['urls'])) {
				foreach ($entry['urls'] as $url) {
					if ($url['type'] == 'profile') {
						$profile_url = $url['value'];
						continue;
					}
					if ($url['type'] == 'webfinger') {
						$connect_url = str_replace('acct:' , '', $url['value']);
						continue;
					}
				}
			}

			if (isset($entry['photos'])) {
				foreach ($entry['photos'] as $photo) {
					if ($photo['type'] == 'profile') {
						$profile_photo = $photo['value'];
						continue;
					}
				}
			}

			if (isset($entry['updated'])) {
				$updated = date(DateTimeFormat::MYSQL, strtotime($entry['updated']));
			}

			if (isset($entry['network'])) {
				$network = $entry['network'];
			}

			if (isset($entry['currentLocation'])) {
				$location = $entry['currentLocation'];
			}

			if (isset($entry['aboutMe'])) {
				$about = HTML::toBBCode($entry['aboutMe']);
			}

			if (isset($entry['gender'])) {
				$gender = $entry['gender'];
			}

			if (isset($entry['generation']) && ($entry['generation'] > 0)) {
				$generation = ++$entry['generation'];
			}

			if (isset($entry['contactType']) && ($entry['contactType'] >= 0)) {
				$contact_type = $entry['contactType'];
			}

			if (isset($entry['tags'])) {
				foreach ($entry['tags'] as $tag) {
					$keywords = implode(", ", $tag);
				}
			}

			if ($generation > 0) {
				$success = true;

				Logger::log("Store profile ".$profile_url, Logger::DEBUG);

				$gcontact = ["url" => $profile_url,
						"name" => $name,
						"network" => $network,
						"photo" => $profile_photo,
						"about" => $about,
						"location" => $location,
						"gender" => $gender,
						"keywords" => $keywords,
						"connect" => $connect_url,
						"updated" => $updated,
						"contact-type" => $contact_type,
						"generation" => $generation];

				try {
					$gcontact = GContact::sanitize($gcontact);
					GContact::update($gcontact);
				} catch (Exception $e) {
					Logger::log($e->getMessage(), Logger::DEBUG);
				}

				Logger::log("Done for profile ".$profile_url, Logger::DEBUG);
			}
		}
		return $success;
	}

}
