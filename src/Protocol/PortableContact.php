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
use Friendica\Model\Contact;
use Friendica\Model\GContact;
use Friendica\Model\GServer;
use Friendica\Model\Profile;
use Friendica\Module\Register;
use Friendica\Network\Probe;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Network;
use Friendica\Util\Strings;
use Friendica\Util\XML;

class PortableContact
{
	const DISABLED = 0;
	const USERS = 1;
	const USERS_GCONTACTS = 2;
	const USERS_GCONTACTS_FALLBACK = 3;

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
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
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
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function load($cid, $uid, $zcid, $url)
	{
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
			$server = Contact::getBasepath($profile);
		}

		if ($server == "") {
			return true;
		}

		return GServer::check($server, $network, $force);
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
			$server_url = Strings::normaliseLink(Contact::getBasepath($profile));
		}

		if (($server_url == '') && ($gcontacts[0]["server_url"] != "")) {
			$server_url = $gcontacts[0]["server_url"];
		}

		if (!$force && (($server_url == '') || ($gcontacts[0]["server_url"] == $gcontacts[0]["nurl"]))) {
			$server_url = Strings::normaliseLink(Contact::getBasepath($profile));
		}

		if (!in_array($gcontacts[0]["network"], [Protocol::DFRN, Protocol::DIASPORA, Protocol::FEED, Protocol::OSTATUS, ""])) {
			Logger::log("Profile ".$profile.": Network type ".$gcontacts[0]["network"]." can't be checked", Logger::DEBUG);
			return false;
		}

		if ($server_url != "") {
			if (!GServer::check($server_url, $gcontacts[0]["network"], $force)) {
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
			$published_item = $xpath->query('atom:published/text()', $entry)->item(0);
			$updated_item   = $xpath->query('atom:updated/text()'  , $entry)->item(0);
			$published      = isset($published_item->nodeValue) ? DateTimeFormat::utc($published_item->nodeValue) : null;
			$updated        = isset($updated_item->nodeValue) ? DateTimeFormat::utc($updated_item->nodeValue) : null;

			if (!isset($published) || !isset($updated)) {
				Logger::notice('Invalid entry for XPath.', ['entry' => $entry, 'profile' => $profile]);
				continue;
			}

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

	/**
	 * @brief Returns a list of all known servers
	 * @return array List of server urls
	 * @throws Exception
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
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
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
				$curlResult = Network::curl($api, false, ['headers' => $header]);

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
		//			GServer::check($server['instance_address']);
		//	}
		//}

		Config::set('poco', 'last_federation_discovery', time());
	}

	public static function discoverSingleServer($id)
	{
		$server = DBA::selectFirst('gserver', ['poco', 'nurl', 'url', 'network'], ['id' => $id]);

		if (!DBA::isResult($server)) {
			return false;
		}

		// Discover new servers out there (Works from Friendica version 3.5.2)
		self::fetchServerlist($server["poco"]);

		// Fetch all users from the other server
		$url = $server["poco"] . "/?fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation";

		Logger::info("Fetch all users from the server " . $server["url"]);

		$curlResult = Network::curl($url);

		if ($curlResult->isSuccess() && !empty($curlResult->getBody())) {
			$data = json_decode($curlResult->getBody(), true);

			if (!empty($data)) {
				self::discoverServer($data, 2);
			}

			if (Config::get('system', 'poco_discovery') >= self::USERS_GCONTACTS) {
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
					Logger::info("Fetch all global contacts from the server " . $server["nurl"]);
					$data = json_decode($curlResult->getBody(), true);

					if (!empty($data)) {
						$success = self::discoverServer($data);
					}
				}

				if (!$success && !empty($data) && Config::get('system', 'poco_discovery') >= self::USERS_GCONTACTS_FALLBACK) {
					Logger::info("Fetch contacts from users of the server " . $server["nurl"]);
					self::discoverServerUsers($data, $server);
				}
			}

			$fields = ['last_poco_query' => DateTimeFormat::utcNow()];
			DBA::update('gserver', $fields, ['nurl' => $server["nurl"]]);

			return true;
		} else {
			// If the server hadn't replied correctly, then force a sanity check
			GServer::check($server["url"], $server["network"], true);

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
				if (!GServer::check($gserver['url'], $gserver['network'])) {
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
