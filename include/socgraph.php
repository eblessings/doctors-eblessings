<?php
/**
 * @file include/socgraph.php
 *
 * @todo Move GNU Social URL schemata (http://server.tld/user/number) to http://server.tld/username
 * @todo Fetch profile data from profile page for Redmatrix users
 * @todo Detect if it is a forum
 */

use Friendica\App;
use Friendica\Core\System;
use Friendica\Core\Cache;
use Friendica\Core\Config;
use Friendica\Core\Worker;
use Friendica\Database\DBM;
use Friendica\Network\Probe;

require_once 'include/datetime.php';
require_once 'include/network.php';
require_once 'include/html2bbcode.php';
require_once 'include/Contact.php';
require_once 'include/Photo.php';

/**
 * @brief Fetch POCO data
 *
 * @param integer $cid Contact ID
 * @param integer $uid User ID
 * @param integer $zcid Global Contact ID
 * @param integer $url POCO address that should be polled
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
function poco_load($cid, $uid = 0, $zcid = 0, $url = null) {
	// Call the function "poco_load_worker" via the worker
	Worker::add(PRIORITY_LOW, "discover_poco", "poco_load", (int)$cid, (int)$uid, (int)$zcid, $url);
}

/**
 * @brief Fetch POCO data from the worker
 *
 * @param integer $cid Contact ID
 * @param integer $uid User ID
 * @param integer $zcid Global Contact ID
 * @param integer $url POCO address that should be polled
 *
 */
function poco_load_worker($cid, $uid, $zcid, $url) {
	$a = get_app();

	if ($cid) {
		if ((! $url) || (! $uid)) {
			$r = q("select `poco`, `uid` from `contact` where `id` = %d limit 1",
				intval($cid)
			);
			if (DBM::is_result($r)) {
				$url = $r[0]['poco'];
				$uid = $r[0]['uid'];
			}
		}
		if (! $uid) {
			return;
		}
	}

	if (! $url) {
		return;
	}

	$url = $url . (($uid) ? '/@me/@all?fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation' : '?fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation') ;

	logger('poco_load: ' . $url, LOGGER_DEBUG);

	$s = fetch_url($url);

	logger('poco_load: returns ' . $s, LOGGER_DATA);

	logger('poco_load: return code: ' . $a->get_curl_code(), LOGGER_DEBUG);

	if (($a->get_curl_code() > 299) || (! $s)) {
		return;
	}

	$j = json_decode($s);

	logger('poco_load: json: ' . print_r($j,true),LOGGER_DATA);

	if (! isset($j->entry)) {
		return;
	}

	$total = 0;
	foreach ($j->entry as $entry) {

		$total ++;
		$profile_url = '';
		$profile_photo = '';
		$connect_url = '';
		$name = '';
		$network = '';
		$updated = NULL_DATE;
		$location = '';
		$about = '';
		$keywords = '';
		$gender = '';
		$contact_type = -1;
		$generation = 0;

		$name = $entry->displayName;

		if (isset($entry->urls)) {
			foreach ($entry->urls as $url) {
				if ($url->type == 'profile') {
					$profile_url = $url->value;
					continue;
				}
				if ($url->type == 'webfinger') {
					$connect_url = str_replace('acct:' , '', $url->value);
					continue;
				}
			}
		}
		if (isset($entry->photos)) {
			foreach ($entry->photos as $photo) {
				if ($photo->type == 'profile') {
					$profile_photo = $photo->value;
					continue;
				}
			}
		}

		if (isset($entry->updated)) {
			$updated = date("Y-m-d H:i:s", strtotime($entry->updated));
		}

		if (isset($entry->network)) {
			$network = $entry->network;
		}

		if (isset($entry->currentLocation)) {
			$location = $entry->currentLocation;
		}

		if (isset($entry->aboutMe)) {
			$about = html2bbcode($entry->aboutMe);
		}

		if (isset($entry->gender)) {
			$gender = $entry->gender;
		}

		if (isset($entry->generation) && ($entry->generation > 0)) {
			$generation = ++$entry->generation;
		}

		if (isset($entry->tags)) {
			foreach ($entry->tags as $tag) {
				$keywords = implode(", ", $tag);
			}
		}

		if (isset($entry->contactType) && ($entry->contactType >= 0)) {
			$contact_type = $entry->contactType;
		}

		$gcontact = array("url" => $profile_url,
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
				"generation" => $generation);

		try {
			$gcontact = sanitize_gcontact($gcontact);
			$gcid = update_gcontact($gcontact);

			link_gcontact($gcid, $uid, $cid, $zcid);
		} catch (Exception $e) {
			logger($e->getMessage(), LOGGER_DEBUG);
		}
	}
	logger("poco_load: loaded $total entries",LOGGER_DEBUG);

	q("DELETE FROM `glink` WHERE `cid` = %d AND `uid` = %d AND `zcid` = %d AND `updated` < UTC_TIMESTAMP - INTERVAL 2 DAY",
		intval($cid),
		intval($uid),
		intval($zcid)
	);

}
/**
 * @brief Sanitize the given gcontact data
 *
 * @param array $gcontact array with gcontact data
 * @throw Exception
 *
 * Generation:
 *  0: No definition
 *  1: Profiles on this server
 *  2: Contacts of profiles on this server
 *  3: Contacts of contacts of profiles on this server
 *  4: ...
 *
 */
function sanitize_gcontact($gcontact) {

	if ($gcontact['url'] == "") {
		throw new Exception('URL is empty');
	}

	$urlparts = parse_url($gcontact['url']);
	if (!isset($urlparts["scheme"])) {
		throw new Exception("This (".$gcontact['url'].") doesn't seem to be an url.");
	}

	if (in_array($urlparts["host"], array("www.facebook.com", "facebook.com", "twitter.com",
						"identi.ca", "alpha.app.net"))) {
		throw new Exception('Contact from a non federated network ignored. ('.$gcontact['url'].')');
	}

	// Don't store the statusnet connector as network
	// We can't simply set this to NETWORK_OSTATUS since the connector could have fetched posts from friendica as well
	if ($gcontact['network'] == NETWORK_STATUSNET) {
		$gcontact['network'] = "";
	}

	// Assure that there are no parameter fragments in the profile url
	if (in_array($gcontact['network'], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS, ""))) {
		$gcontact['url'] = clean_contact_url($gcontact['url']);
	}

	$alternate = poco_alternate_ostatus_url($gcontact['url']);

	// The global contacts should contain the original picture, not the cached one
	if (($gcontact['generation'] != 1) && stristr(normalise_link($gcontact['photo']), normalise_link(System::baseUrl()."/photo/"))) {
		$gcontact['photo'] = "";
	}

	if (!isset($gcontact['network'])) {
		$r = q("SELECT `network` FROM `contact` WHERE `uid` = 0 AND `nurl` = '%s' AND `network` != '' AND `network` != '%s' LIMIT 1",
			dbesc(normalise_link($gcontact['url'])), dbesc(NETWORK_STATUSNET)
		);
		if (DBM::is_result($r)) {
			$gcontact['network'] = $r[0]["network"];
		}

		if (($gcontact['network'] == "") || ($gcontact['network'] == NETWORK_OSTATUS)) {
			$r = q("SELECT `network`, `url` FROM `contact` WHERE `uid` = 0 AND `alias` IN ('%s', '%s') AND `network` != '' AND `network` != '%s' LIMIT 1",
				dbesc($gcontact['url']), dbesc(normalise_link($gcontact['url'])), dbesc(NETWORK_STATUSNET)
			);
			if (DBM::is_result($r)) {
				$gcontact['network'] = $r[0]["network"];
			}
		}
	}

	$gcontact['server_url'] = '';
	$gcontact['network'] = '';

	$x = q("SELECT * FROM `gcontact` WHERE `nurl` = '%s' LIMIT 1",
		dbesc(normalise_link($gcontact['url']))
	);

	if (DBM::is_result($x)) {
		if (!isset($gcontact['network']) && ($x[0]["network"] != NETWORK_STATUSNET)) {
			$gcontact['network'] = $x[0]["network"];
		}
		if ($gcontact['updated'] <= NULL_DATE) {
			$gcontact['updated'] = $x[0]["updated"];
		}
		if (!isset($gcontact['server_url']) && (normalise_link($x[0]["server_url"]) != normalise_link($x[0]["url"]))) {
			$gcontact['server_url'] = $x[0]["server_url"];
		}
		if (!isset($gcontact['addr'])) {
			$gcontact['addr'] = $x[0]["addr"];
		}
	}

	if ((!isset($gcontact['network']) || !isset($gcontact['name']) || !isset($gcontact['addr']) || !isset($gcontact['photo']) || !isset($gcontact['server_url']) || $alternate)
		&& poco_reachable($gcontact['url'], $gcontact['server_url'], $gcontact['network'], false)) {
		$data = Probe::uri($gcontact['url']);

		if ($data["network"] == NETWORK_PHANTOM) {
			throw new Exception('Probing for URL '.$gcontact['url'].' failed');
		}

		$orig_profile = $gcontact['url'];

		$gcontact["server_url"] = $data["baseurl"];

		$gcontact = array_merge($gcontact, $data);

		if ($alternate && ($gcontact['network'] == NETWORK_OSTATUS)) {
			// Delete the old entry - if it exists
			$r = q("SELECT `id` FROM `gcontact` WHERE `nurl` = '%s'", dbesc(normalise_link($orig_profile)));
			if (DBM::is_result($r)) {
				q("DELETE FROM `gcontact` WHERE `nurl` = '%s'", dbesc(normalise_link($orig_profile)));
				q("DELETE FROM `glink` WHERE `gcid` = %d", intval($r[0]["id"]));
			}
		}
	}

	if (!isset($gcontact['name']) || !isset($gcontact['photo'])) {
		throw new Exception('No name and photo for URL '.$gcontact['url']);
	}

	if (!in_array($gcontact['network'], array(NETWORK_DFRN, NETWORK_OSTATUS, NETWORK_DIASPORA))) {
		throw new Exception('No federated network ('.$gcontact['network'].') detected for URL '.$gcontact['url']);
	}

	if (!isset($gcontact['server_url'])) {
		// We check the server url to be sure that it is a real one
		$server_url = poco_detect_server($gcontact['url']);

		// We are now sure that it is a correct URL. So we use it in the future
		if ($server_url != "") {
			$gcontact['server_url'] = $server_url;
		}
	}

	// The server URL doesn't seem to be valid, so we don't store it.
	if (!poco_check_server($gcontact['server_url'], $gcontact['network'])) {
		$gcontact['server_url'] = "";
	}

	return $gcontact;
}

/**
 * @brief Link the gcontact entry with user, contact and global contact
 *
 * @param integer $gcid Global contact ID
 * @param integer $cid Contact ID
 * @param integer $uid User ID
 * @param integer $zcid Global Contact ID
 * *
 */
function link_gcontact($gcid, $uid = 0, $cid = 0, $zcid = 0) {

	if ($gcid <= 0) {
		return;
	}

	$r = q("SELECT * FROM `glink` WHERE `cid` = %d AND `uid` = %d AND `gcid` = %d AND `zcid` = %d LIMIT 1",
		intval($cid),
		intval($uid),
		intval($gcid),
		intval($zcid)
	);

	if (!DBM::is_result($r)) {
		q("INSERT INTO `glink` (`cid`, `uid`, `gcid`, `zcid`, `updated`) VALUES (%d, %d, %d, %d, '%s') ",
			intval($cid),
			intval($uid),
			intval($gcid),
			intval($zcid),
			dbesc(datetime_convert())
		);
	} else {
		q("UPDATE `glink` SET `updated` = '%s' WHERE `cid` = %d AND `uid` = %d AND `gcid` = %d AND `zcid` = %d",
			dbesc(datetime_convert()),
			intval($cid),
			intval($uid),
			intval($gcid),
			intval($zcid)
		);
	}
}

function poco_reachable($profile, $server = "", $network = "", $force = false) {

	if ($server == "") {
		$server = poco_detect_server($profile);
	}

	if ($server == "") {
		return true;
	}

	return poco_check_server($server, $network, $force);
}

function poco_detect_server($profile) {

	// Try to detect the server path based upon some known standard paths
	$server_url = "";

	if ($server_url == "") {
		$friendica = preg_replace("=(https?://)(.*)/profile/(.*)=ism", "$1$2", $profile);
		if ($friendica != $profile) {
			$server_url = $friendica;
			$network = NETWORK_DFRN;
		}
	}

	if ($server_url == "") {
		$diaspora = preg_replace("=(https?://)(.*)/u/(.*)=ism", "$1$2", $profile);
		if ($diaspora != $profile) {
			$server_url = $diaspora;
			$network = NETWORK_DIASPORA;
		}
	}

	if ($server_url == "") {
		$red = preg_replace("=(https?://)(.*)/channel/(.*)=ism", "$1$2", $profile);
		if ($red != $profile) {
			$server_url = $red;
			$network = NETWORK_DIASPORA;
		}
	}

	// Mastodon
	if ($server_url == "") {
		$mastodon = preg_replace("=(https?://)(.*)/users/(.*)=ism", "$1$2", $profile);
		if ($mastodon != $profile) {
			$server_url = $mastodon;
			$network = NETWORK_OSTATUS;
		}
	}

	// Numeric OStatus variant
	if ($server_url == "") {
		$ostatus = preg_replace("=(https?://)(.*)/user/(.*)=ism", "$1$2", $profile);
		if ($ostatus != $profile) {
			$server_url = $ostatus;
			$network = NETWORK_OSTATUS;
		}
	}

	// Wild guess
	if ($server_url == "") {
		$base = preg_replace("=(https?://)(.*?)/(.*)=ism", "$1$2", $profile);
		if ($base != $profile) {
			$server_url = $base;
			$network = NETWORK_PHANTOM;
		}
	}

	if ($server_url == "") {
		return "";
	}

	$r = q("SELECT `id` FROM `gserver` WHERE `nurl` = '%s' AND `last_contact` > `last_failure`",
		dbesc(normalise_link($server_url)));
	if (DBM::is_result($r)) {
		return $server_url;
	}

	// Fetch the host-meta to check if this really is a server
	$serverret = z_fetch_url($server_url."/.well-known/host-meta");
	if (!$serverret["success"]) {
		return "";
	}

	return $server_url;
}

function poco_alternate_ostatus_url($url) {
	return(preg_match("=https?://.+/user/\d+=ism", $url, $matches));
}

function poco_last_updated($profile, $force = false) {

	$gcontacts = q("SELECT * FROM `gcontact` WHERE `nurl` = '%s'",
			dbesc(normalise_link($profile)));

	if (!DBM::is_result($gcontacts)) {
		return false;
	}

	$contact = array("url" => $profile);

	if ($gcontacts[0]["created"] <= NULL_DATE) {
		$contact['created'] = datetime_convert();
	}

	if ($force) {
		$server_url = normalise_link(poco_detect_server($profile));
	}

	if (($server_url == '') && ($gcontacts[0]["server_url"] != "")) {
		$server_url = $gcontacts[0]["server_url"];
	}

	if (!$force && (($server_url == '') || ($gcontacts[0]["server_url"] == $gcontacts[0]["nurl"]))) {
		$server_url = normalise_link(poco_detect_server($profile));
	}

	if (!in_array($gcontacts[0]["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_FEED, NETWORK_OSTATUS, ""))) {
		logger("Profile ".$profile.": Network type ".$gcontacts[0]["network"]." can't be checked", LOGGER_DEBUG);
		return false;
	}

	if ($server_url != "") {
		if (!poco_check_server($server_url, $gcontacts[0]["network"], $force)) {
			if ($force) {
				q("UPDATE `gcontact` SET `last_failure` = '%s' WHERE `nurl` = '%s'",
					dbesc(datetime_convert()), dbesc(normalise_link($profile)));
			}

			logger("Profile ".$profile.": Server ".$server_url." wasn't reachable.", LOGGER_DEBUG);
			return false;
		}
		$contact['server_url'] = $server_url;
	}

	if (in_array($gcontacts[0]["network"], array("", NETWORK_FEED))) {
		$server = q("SELECT `network` FROM `gserver` WHERE `nurl` = '%s' AND `network` != ''",
			dbesc(normalise_link($server_url)));

		if ($server) {
			$contact['network'] = $server[0]["network"];
		} else {
			return false;
		}
	}

	// noscrape is really fast so we don't cache the call.
	if (($server_url != "") && ($gcontacts[0]["nick"] != "")) {

		//  Use noscrape if possible
		$server = q("SELECT `noscrape`, `network` FROM `gserver` WHERE `nurl` = '%s' AND `noscrape` != ''", dbesc(normalise_link($server_url)));

		if ($server) {
			$noscraperet = z_fetch_url($server[0]["noscrape"]."/".$gcontacts[0]["nick"]);

			if ($noscraperet["success"] && ($noscraperet["body"] != "")) {

				$noscrape = json_decode($noscraperet["body"], true);

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

					$location = formatted_location($noscrape);
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
					//$contact["last_contact"] = datetime_convert();

					$contact = array_merge($contact, $noscrape);

					update_gcontact($contact);

					if (trim($noscrape["updated"]) != "") {
						q("UPDATE `gcontact` SET `last_contact` = '%s' WHERE `nurl` = '%s'",
							dbesc(datetime_convert()), dbesc(normalise_link($profile)));

						logger("Profile ".$profile." was last updated at ".$noscrape["updated"]." (noscrape)", LOGGER_DEBUG);

						return $noscrape["updated"];
					}
				}
			}
		}
	}

	// If we only can poll the feed, then we only do this once a while
	if (!$force && !poco_do_update($gcontacts[0]["created"], $gcontacts[0]["updated"], $gcontacts[0]["last_failure"], $gcontacts[0]["last_contact"])) {
		logger("Profile ".$profile." was last updated at ".$gcontacts[0]["updated"]." (cached)", LOGGER_DEBUG);

		update_gcontact($contact);
		return $gcontacts[0]["updated"];
	}

	$data = Probe::uri($profile);

	// Is the profile link the alternate OStatus link notation? (http://domain.tld/user/4711)
	// Then check the other link and delete this one
	if (($data["network"] == NETWORK_OSTATUS) && poco_alternate_ostatus_url($profile) &&
		(normalise_link($profile) == normalise_link($data["alias"])) &&
		(normalise_link($profile) != normalise_link($data["url"]))) {

		// Delete the old entry
		q("DELETE FROM `gcontact` WHERE `nurl` = '%s'", dbesc(normalise_link($profile)));
		q("DELETE FROM `glink` WHERE `gcid` = %d", intval($gcontacts[0]["id"]));

		$gcontact = array_merge($gcontacts[0], $data);

		$gcontact["server_url"] = $data["baseurl"];

		try {
			$gcontact = sanitize_gcontact($gcontact);
			update_gcontact($gcontact);

			poco_last_updated($data["url"], $force);
		} catch (Exception $e) {
			logger($e->getMessage(), LOGGER_DEBUG);
		}

		logger("Profile ".$profile." was deleted", LOGGER_DEBUG);
		return false;
	}

	if (($data["poll"] == "") || (in_array($data["network"], array(NETWORK_FEED, NETWORK_PHANTOM)))) {
		q("UPDATE `gcontact` SET `last_failure` = '%s' WHERE `nurl` = '%s'",
			dbesc(datetime_convert()), dbesc(normalise_link($profile)));

		logger("Profile ".$profile." wasn't reachable (profile)", LOGGER_DEBUG);
		return false;
	}

	$contact = array_merge($contact, $data);

	$contact["server_url"] = $data["baseurl"];

	update_gcontact($contact);

	$feedret = z_fetch_url($data["poll"]);

	if (!$feedret["success"]) {
		q("UPDATE `gcontact` SET `last_failure` = '%s' WHERE `nurl` = '%s'",
			dbesc(datetime_convert()), dbesc(normalise_link($profile)));

		logger("Profile ".$profile." wasn't reachable (no feed)", LOGGER_DEBUG);
		return false;
	}

	$doc = new DOMDocument();
	@$doc->loadXML($feedret["body"]);

	$xpath = new DomXPath($doc);
	$xpath->registerNamespace('atom', "http://www.w3.org/2005/Atom");

	$entries = $xpath->query('/atom:feed/atom:entry');

	$last_updated = "";

	foreach ($entries as $entry) {
		$published = $xpath->query('atom:published/text()', $entry)->item(0)->nodeValue;
		$updated = $xpath->query('atom:updated/text()', $entry)->item(0)->nodeValue;

		if ($last_updated < $published)
			$last_updated = $published;

		if ($last_updated < $updated)
			$last_updated = $updated;
	}

	// Maybe there aren't any entries. Then check if it is a valid feed
	if ($last_updated == "") {
		if ($xpath->query('/atom:feed')->length > 0) {
			$last_updated = NULL_DATE;
		}
	}
	q("UPDATE `gcontact` SET `updated` = '%s', `last_contact` = '%s' WHERE `nurl` = '%s'",
		dbesc(DBM::date($last_updated)), dbesc(DBM::date()), dbesc(normalise_link($profile)));

	if (($gcontacts[0]["generation"] == 0)) {
		q("UPDATE `gcontact` SET `generation` = 9 WHERE `nurl` = '%s'",
			dbesc(normalise_link($profile)));
	}

	logger("Profile ".$profile." was last updated at ".$last_updated, LOGGER_DEBUG);

	return($last_updated);
}

function poco_do_update($created, $updated, $last_failure,  $last_contact) {
	$now = strtotime(datetime_convert());

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

function poco_to_boolean($val) {
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
 * @param object $data POCO data
 * @return array Server data
 */
function poco_detect_poco_data($data) {
	$server = false;

	if (!isset($data->entry)) {
		return false;
	}

	if (count($data->entry) == 0) {
		return false;
	}

	if (!isset($data->entry[0]->urls)) {
		return false;
	}

	if (count($data->entry[0]->urls) == 0) {
		return false;
	}

	foreach ($data->entry[0]->urls as $url) {
		if ($url->type == 'zot') {
			$server = array();
			$server["platform"] = 'Hubzilla';
			$server["network"] = NETWORK_DIASPORA;
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
function poco_fetch_nodeinfo($server_url) {
	$serverret = z_fetch_url($server_url."/.well-known/nodeinfo");
	if (!$serverret["success"]) {
		return false;
	}

	$nodeinfo = json_decode($serverret['body']);

	if (!is_object($nodeinfo)) {
		return false;
	}

	if (!is_array($nodeinfo->links)) {
		return false;
	}

	$nodeinfo_url = '';

	foreach ($nodeinfo->links as $link) {
		if ($link->rel == 'http://nodeinfo.diaspora.software/ns/schema/1.0') {
			$nodeinfo_url = $link->href;
		}
	}

	if ($nodeinfo_url == '') {
		return false;
	}

	$serverret = z_fetch_url($nodeinfo_url);
	if (!$serverret["success"]) {
		return false;
	}

	$nodeinfo = json_decode($serverret['body']);

	if (!is_object($nodeinfo)) {
		return false;
	}

	$server = array();

	$server['register_policy'] = REGISTER_CLOSED;

	if (is_bool($nodeinfo->openRegistrations) && $nodeinfo->openRegistrations) {
		$server['register_policy'] = REGISTER_OPEN;
	}

	if (is_object($nodeinfo->software)) {
		if (isset($nodeinfo->software->name)) {
			$server['platform'] = $nodeinfo->software->name;
		}

		if (isset($nodeinfo->software->version)) {
			$server['version'] = $nodeinfo->software->version;
			// Version numbers on Nodeinfo are presented with additional info, e.g.:
			// 0.6.3.0-p1702cc1c, 0.6.99.0-p1b9ab160 or 3.4.3-2-1191.
			$server['version'] = preg_replace("=(.+)-(.{4,})=ism", "$1", $server['version']);
		}
	}

	if (is_object($nodeinfo->metadata)) {
		if (isset($nodeinfo->metadata->nodeName)) {
			$server['site_name'] = $nodeinfo->metadata->nodeName;
		}
	}

	$diaspora = false;
	$friendica = false;
	$gnusocial = false;

	if (is_array($nodeinfo->protocols->inbound)) {
		foreach ($nodeinfo->protocols->inbound as $inbound) {
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
		$server['network'] = NETWORK_OSTATUS;
	}
	if ($diaspora) {
		$server['network'] = NETWORK_DIASPORA;
	}
	if ($friendica) {
		$server['network'] = NETWORK_DFRN;
	}

	if (!$server) {
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
function poco_detect_server_type($body) {
	$server = false;

	$doc = new DOMDocument();
	@$doc->loadHTML($body);
	$xpath = new DomXPath($doc);

	$list = $xpath->query("//meta[@name]");

	foreach ($list as $node) {
		$attr = array();
		if ($node->attributes->length) {
			foreach ($node->attributes as $attribute) {
				$attr[$attribute->name] = $attribute->value;
			}
		}
		if ($attr['name'] == 'generator') {
			$version_part = explode(" ", $attr['content']);
			if (count($version_part) == 2) {
				if (in_array($version_part[0], array("Friendika", "Friendica"))) {
					$server = array();
					$server["platform"] = $version_part[0];
					$server["version"] = $version_part[1];
					$server["network"] = NETWORK_DFRN;
				}
			}
		}
	}

	if (!$server) {
		$list = $xpath->query("//meta[@property]");

		foreach ($list as $node) {
			$attr = array();
			if ($node->attributes->length) {
				foreach ($node->attributes as $attribute) {
					$attr[$attribute->name] = $attribute->value;
				}
			}
			if ($attr['property'] == 'generator' && in_array($attr['content'], array("hubzilla", "BlaBlaNet"))) {
				$server = array();
				$server["platform"] = $attr['content'];
				$server["version"] = "";
				$server["network"] = NETWORK_DIASPORA;
			}
		}
	}

	if (!$server) {
		return false;
	}

	$server["site_name"] = $xpath->evaluate($element."//head/title/text()", $context)->item(0)->nodeValue;
	return $server;
}

function poco_check_server($server_url, $network = "", $force = false) {

	// Unify the server address
	$server_url = trim($server_url, "/");
	$server_url = str_replace("/index.php", "", $server_url);

	if ($server_url == "") {
		return false;
	}

	$servers = q("SELECT * FROM `gserver` WHERE `nurl` = '%s'", dbesc(normalise_link($server_url)));
	if (DBM::is_result($servers)) {

		if ($servers[0]["created"] <= NULL_DATE) {
			q("UPDATE `gserver` SET `created` = '%s' WHERE `nurl` = '%s'",
				dbesc(datetime_convert()), dbesc(normalise_link($server_url)));
		}
		$poco = $servers[0]["poco"];
		$noscrape = $servers[0]["noscrape"];

		if ($network == "") {
			$network = $servers[0]["network"];
		}

		$last_contact = $servers[0]["last_contact"];
		$last_failure = $servers[0]["last_failure"];
		$version = $servers[0]["version"];
		$platform = $servers[0]["platform"];
		$site_name = $servers[0]["site_name"];
		$info = $servers[0]["info"];
		$register_policy = $servers[0]["register_policy"];

		if (!$force && !poco_do_update($servers[0]["created"], "", $last_failure, $last_contact)) {
			logger("Use cached data for server ".$server_url, LOGGER_DEBUG);
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

		$last_contact = NULL_DATE;
		$last_failure = NULL_DATE;
	}
	logger("Server ".$server_url." is outdated or unknown. Start discovery. Force: ".$force." Created: ".$servers[0]["created"]." Failure: ".$last_failure." Contact: ".$last_contact, LOGGER_DEBUG);

	$failure = false;
	$possible_failure = false;
	$orig_last_failure = $last_failure;
	$orig_last_contact = $last_contact;

	// Check if the page is accessible via SSL.
	$orig_server_url = $server_url;
	$server_url = str_replace("http://", "https://", $server_url);

	// We set the timeout to 20 seconds since this operation should be done in no time if the server was vital
	$serverret = z_fetch_url($server_url."/.well-known/host-meta", false, $redirects, array('timeout' => 20));

	// Quit if there is a timeout.
	// But we want to make sure to only quit if we are mostly sure that this server url fits.
	if (DBM::is_result($servers) && ($orig_server_url == $server_url) &&
		($serverret['errno'] == CURLE_OPERATION_TIMEDOUT)) {
		logger("Connection to server ".$server_url." timed out.", LOGGER_DEBUG);
		dba::update('gserver', array('last_failure' => datetime_convert()), array('nurl' => normalise_link($server_url)));
		return false;
	}

	// Maybe the page is unencrypted only?
	$xmlobj = @simplexml_load_string($serverret["body"],'SimpleXMLElement',0, "http://docs.oasis-open.org/ns/xri/xrd-1.0");
	if (!$serverret["success"] || ($serverret["body"] == "") || (@sizeof($xmlobj) == 0) || !is_object($xmlobj)) {
		$server_url = str_replace("https://", "http://", $server_url);

		// We set the timeout to 20 seconds since this operation should be done in no time if the server was vital
		$serverret = z_fetch_url($server_url."/.well-known/host-meta", false, $redirects, array('timeout' => 20));

		// Quit if there is a timeout
		if ($serverret['errno'] == CURLE_OPERATION_TIMEDOUT) {
			logger("Connection to server ".$server_url." timed out.", LOGGER_DEBUG);
			dba::update('gserver', array('last_failure' => datetime_convert()), array('nurl' => normalise_link($server_url)));
			return false;
		}

		$xmlobj = @simplexml_load_string($serverret["body"],'SimpleXMLElement',0, "http://docs.oasis-open.org/ns/xri/xrd-1.0");
	}

	if (!$serverret["success"] || ($serverret["body"] == "") || (sizeof($xmlobj) == 0) || !is_object($xmlobj)) {
		// Workaround for bad configured servers (known nginx problem)
		if (!in_array($serverret["debug"]["http_code"], array("403", "404"))) {
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

	// Look for poco
	if (!$failure) {
		$serverret = z_fetch_url($server_url."/poco");
		if ($serverret["success"]) {
			$data = json_decode($serverret["body"]);
			if (isset($data->totalResults)) {
				$poco = $server_url."/poco";
				$server = poco_detect_poco_data($data);
				if ($server) {
					$platform = $server['platform'];
					$network = $server['network'];
					$version = '';
					$site_name = '';
				}
			}
		}
	}

	if (!$failure) {
		// Test for Diaspora, Hubzilla, Mastodon or older Friendica servers
		$serverret = z_fetch_url($server_url);

		if (!$serverret["success"] || ($serverret["body"] == "")) {
			$failure = true;
		} else {
			$server = poco_detect_server_type($serverret["body"]);
			if ($server) {
				$platform = $server['platform'];
				$network = $server['network'];
				$version = $server['version'];
				$site_name = $server['site_name'];
			}

			$lines = explode("\n",$serverret["header"]);
			if (count($lines)) {
				foreach($lines as $line) {
					$line = trim($line);
					if (stristr($line,'X-Diaspora-Version:')) {
						$platform = "Diaspora";
						$version = trim(str_replace("X-Diaspora-Version:", "", $line));
						$version = trim(str_replace("x-diaspora-version:", "", $version));
						$network = NETWORK_DIASPORA;
						$versionparts = explode("-", $version);
						$version = $versionparts[0];
					}

					if (stristr($line,'Server: Mastodon')) {
						$platform = "Mastodon";
						$network = NETWORK_OSTATUS;
					}
				}
			}
		}
	}

	if (!$failure && ($poco == "")) {
		// Test for Statusnet
		// Will also return data for Friendica and GNU Social - but it will be overwritten later
		// The "not implemented" is a special treatment for really, really old Friendica versions
		$serverret = z_fetch_url($server_url."/api/statusnet/version.json");
		if ($serverret["success"] && ($serverret["body"] != '{"error":"not implemented"}') &&
			($serverret["body"] != '') && (strlen($serverret["body"]) < 30)) {
			$platform = "StatusNet";
			// Remove junk that some GNU Social servers return
			$version = str_replace(chr(239).chr(187).chr(191), "", $serverret["body"]);
			$version = trim($version, '"');
			$network = NETWORK_OSTATUS;
		}

		// Test for GNU Social
		$serverret = z_fetch_url($server_url."/api/gnusocial/version.json");
		if ($serverret["success"] && ($serverret["body"] != '{"error":"not implemented"}') &&
			($serverret["body"] != '') && (strlen($serverret["body"]) < 30)) {
			$platform = "GNU Social";
			// Remove junk that some GNU Social servers return
			$version = str_replace(chr(239).chr(187).chr(191), "", $serverret["body"]);
			$version = trim($version, '"');
			$network = NETWORK_OSTATUS;
		}

		// Test for Mastodon
		$orig_version = $version;
		$serverret = z_fetch_url($server_url."/api/v1/instance");
		if ($serverret["success"] && ($serverret["body"] != '')) {
			$data = json_decode($serverret["body"]);
			if (isset($data->version)) {
				$platform = "Mastodon";
				$version = $data->version;
				$site_name = $data->title;
				$info = $data->description;
				$network = NETWORK_OSTATUS;
			}
		}
		if (strstr($orig_version.$version, 'Pleroma')) {
			$platform = 'Pleroma';
			$version = trim(str_replace('Pleroma', '', $version));
		}
	}

	if (!$failure) {
		// Test for Hubzilla and Red
		$serverret = z_fetch_url($server_url."/siteinfo.json");
		if ($serverret["success"]) {
			$data = json_decode($serverret["body"]);
			if (isset($data->url)) {
				$platform = $data->platform;
				$version = $data->version;
				$network = NETWORK_DIASPORA;
			}
			if (!empty($data->site_name)) {
				$site_name = $data->site_name;
			}
			switch ($data->register_policy) {
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
		} else {
			// Test for Hubzilla, Redmatrix or Friendica
			$serverret = z_fetch_url($server_url."/api/statusnet/config.json");
			if ($serverret["success"]) {
				$data = json_decode($serverret["body"]);
				if (isset($data->site->server)) {
					if (isset($data->site->platform)) {
						$platform = $data->site->platform->PLATFORM_NAME;
						$version = $data->site->platform->STD_VERSION;
						$network = NETWORK_DIASPORA;
					}
					if (isset($data->site->BlaBlaNet)) {
						$platform = $data->site->BlaBlaNet->PLATFORM_NAME;
						$version = $data->site->BlaBlaNet->STD_VERSION;
						$network = NETWORK_DIASPORA;
					}
					if (isset($data->site->hubzilla)) {
						$platform = $data->site->hubzilla->PLATFORM_NAME;
						$version = $data->site->hubzilla->RED_VERSION;
						$network = NETWORK_DIASPORA;
					}
					if (isset($data->site->redmatrix)) {
						if (isset($data->site->redmatrix->PLATFORM_NAME)) {
							$platform = $data->site->redmatrix->PLATFORM_NAME;
						} elseif (isset($data->site->redmatrix->RED_PLATFORM)) {
							$platform = $data->site->redmatrix->RED_PLATFORM;
						}

						$version = $data->site->redmatrix->RED_VERSION;
						$network = NETWORK_DIASPORA;
					}
					if (isset($data->site->friendica)) {
						$platform = $data->site->friendica->FRIENDICA_PLATFORM;
						$version = $data->site->friendica->FRIENDICA_VERSION;
						$network = NETWORK_DFRN;
					}

					$site_name = $data->site->name;

					$data->site->closed = poco_to_boolean($data->site->closed);
					$data->site->private = poco_to_boolean($data->site->private);
					$data->site->inviteonly = poco_to_boolean($data->site->inviteonly);

					if (!$data->site->closed && !$data->site->private and $data->site->inviteonly) {
						$register_policy = REGISTER_APPROVE;
					} elseif (!$data->site->closed && !$data->site->private) {
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
		$serverret = z_fetch_url($server_url."/statistics.json");
		if ($serverret["success"]) {
			$data = json_decode($serverret["body"]);
			if (isset($data->version)) {
				$version = $data->version;
				// Version numbers on statistics.json are presented with additional info, e.g.:
				// 0.6.3.0-p1702cc1c, 0.6.99.0-p1b9ab160 or 3.4.3-2-1191.
				$version = preg_replace("=(.+)-(.{4,})=ism", "$1", $version);
			}

			if (!empty($data->name)) {
				$site_name = $data->name;
			}

			if (!empty($data->network)) {
				$platform = $data->network;
			}

			if ($platform == "Diaspora") {
				$network = NETWORK_DIASPORA;
			}

			if ($data->registrations_open) {
				$register_policy = REGISTER_OPEN;
			} else {
				$register_policy = REGISTER_CLOSED;
			}
		}
	}

	// Query nodeinfo. Working for (at least) Diaspora and Friendica.
	if (!$failure) {
		$server = poco_fetch_nodeinfo($server_url);
		if ($server) {
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
		}
	}

	// Check for noscrape
	// Friendica servers could be detected as OStatus servers
	if (!$failure && in_array($network, array(NETWORK_DFRN, NETWORK_OSTATUS))) {
		$serverret = z_fetch_url($server_url."/friendica/json");

		if (!$serverret["success"]) {
			$serverret = z_fetch_url($server_url."/friendika/json");
		}

		if ($serverret["success"]) {
			$data = json_decode($serverret["body"]);

			if (isset($data->version)) {
				$network = NETWORK_DFRN;

				$noscrape = $data->no_scrape_url;
				$version = $data->version;
				$site_name = $data->site_name;
				$info = $data->info;
				$register_policy_str = $data->register_policy;
				$platform = $data->platform;

				switch ($register_policy_str) {
					case "REGISTER_CLOSED":
						$register_policy = REGISTER_CLOSED;
						break;
					case "REGISTER_APPROVE":
						$register_policy = REGISTER_APPROVE;
						break;
					case "REGISTER_OPEN":
						$register_policy = REGISTER_OPEN;
						break;
				}
			}
		}
	}

	if ($possible_failure && !$failure) {
		$failure = true;
	}

	if ($failure) {
		$last_contact = $orig_last_contact;
		$last_failure = datetime_convert();
	} else {
		$last_contact = datetime_convert();
		$last_failure = $orig_last_failure;
	}

	if (($last_contact <= $last_failure) && !$failure) {
		logger("Server ".$server_url." seems to be alive, but last contact wasn't set - could be a bug", LOGGER_DEBUG);
	} elseif (($last_contact >= $last_failure) && $failure) {
		logger("Server ".$server_url." seems to be dead, but last failure wasn't set - could be a bug", LOGGER_DEBUG);
	}

	// Check again if the server exists
	$servers = q("SELECT `nurl` FROM `gserver` WHERE `nurl` = '%s'", dbesc(normalise_link($server_url)));

	$version = strip_tags($version);
	$site_name = strip_tags($site_name);
	$info = strip_tags($info);
	$platform = strip_tags($platform);

	if ($servers) {
		 q("UPDATE `gserver` SET `url` = '%s', `version` = '%s', `site_name` = '%s', `info` = '%s', `register_policy` = %d, `poco` = '%s', `noscrape` = '%s',
			`network` = '%s', `platform` = '%s', `last_contact` = '%s', `last_failure` = '%s' WHERE `nurl` = '%s'",
			dbesc($server_url),
			dbesc($version),
			dbesc($site_name),
			dbesc($info),
			intval($register_policy),
			dbesc($poco),
			dbesc($noscrape),
			dbesc($network),
			dbesc($platform),
			dbesc($last_contact),
			dbesc($last_failure),
			dbesc(normalise_link($server_url))
		);
	} elseif (!$failure) {
		q("INSERT INTO `gserver` (`url`, `nurl`, `version`, `site_name`, `info`, `register_policy`, `poco`, `noscrape`, `network`, `platform`, `created`, `last_contact`, `last_failure`)
					VALUES ('%s', '%s', '%s', '%s', '%s', %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s')",
				dbesc($server_url),
				dbesc(normalise_link($server_url)),
				dbesc($version),
				dbesc($site_name),
				dbesc($info),
				intval($register_policy),
				dbesc($poco),
				dbesc($noscrape),
				dbesc($network),
				dbesc($platform),
				dbesc(datetime_convert()),
				dbesc($last_contact),
				dbesc($last_failure),
				dbesc(datetime_convert())
		);
	}
	logger("End discovery for server " . $server_url, LOGGER_DEBUG);

	return !$failure;
}

function count_common_friends($uid, $cid) {

	$r = q("SELECT count(*) as `total`
		FROM `glink` INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		WHERE `glink`.`cid` = %d AND `glink`.`uid` = %d AND
		((`gcontact`.`last_contact` >= `gcontact`.`last_failure`) OR (`gcontact`.`updated` >= `gcontact`.`last_failure`))
		AND `gcontact`.`nurl` IN (select nurl from contact where uid = %d and self = 0 and blocked = 0 and hidden = 0 and id != %d ) ",
		intval($cid),
		intval($uid),
		intval($uid),
		intval($cid)
	);

	// logger("count_common_friends: $uid $cid {$r[0]['total']}");
	if (DBM::is_result($r)) {
		return $r[0]['total'];
	}
	return 0;

}


function common_friends($uid, $cid, $start = 0, $limit = 9999, $shuffle = false) {

	if ($shuffle) {
		$sql_extra = " order by rand() ";
	} else {
		$sql_extra = " order by `gcontact`.`name` asc ";
	}

	$r = q("SELECT `gcontact`.*, `contact`.`id` AS `cid`
		FROM `glink`
		INNER JOIN `gcontact` ON `glink`.`gcid` = `gcontact`.`id`
		INNER JOIN `contact` ON `gcontact`.`nurl` = `contact`.`nurl`
		WHERE `glink`.`cid` = %d and `glink`.`uid` = %d
			AND `contact`.`uid` = %d AND `contact`.`self` = 0 AND `contact`.`blocked` = 0
			AND `contact`.`hidden` = 0 AND `contact`.`id` != %d
			AND ((`gcontact`.`last_contact` >= `gcontact`.`last_failure`) OR (`gcontact`.`updated` >= `gcontact`.`last_failure`))
			$sql_extra LIMIT %d, %d",
		intval($cid),
		intval($uid),
		intval($uid),
		intval($cid),
		intval($start),
		intval($limit)
	);

	/// @TODO Check all calling-findings of this function if they properly use DBM::is_result()
	return $r;

}


function count_common_friends_zcid($uid, $zcid) {

	$r = q("SELECT count(*) as `total`
		FROM `glink` INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		where `glink`.`zcid` = %d
		and `gcontact`.`nurl` in (select nurl from contact where uid = %d and self = 0 and blocked = 0 and hidden = 0 ) ",
		intval($zcid),
		intval($uid)
	);

	if (DBM::is_result($r)) {
		return $r[0]['total'];
	}
	return 0;

}

function common_friends_zcid($uid, $zcid, $start = 0, $limit = 9999, $shuffle = false) {

	if ($shuffle) {
		$sql_extra = " order by rand() ";
	} else {
		$sql_extra = " order by `gcontact`.`name` asc ";
	}

	$r = q("SELECT `gcontact`.*
		FROM `glink` INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		where `glink`.`zcid` = %d
		and `gcontact`.`nurl` in (select nurl from contact where uid = %d and self = 0 and blocked = 0 and hidden = 0 )
		$sql_extra limit %d, %d",
		intval($zcid),
		intval($uid),
		intval($start),
		intval($limit)
	);

	/// @TODO Check all calling-findings of this function if they properly use DBM::is_result()
	return $r;

}


function count_all_friends($uid, $cid) {

	$r = q("SELECT count(*) as `total`
		FROM `glink` INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		where `glink`.`cid` = %d and `glink`.`uid` = %d AND
		((`gcontact`.`last_contact` >= `gcontact`.`last_failure`) OR (`gcontact`.`updated` >= `gcontact`.`last_failure`))",
		intval($cid),
		intval($uid)
	);

	if (DBM::is_result($r)) {
		return $r[0]['total'];
	}
	return 0;

}


function all_friends($uid, $cid, $start = 0, $limit = 80) {

	$r = q("SELECT `gcontact`.*, `contact`.`id` AS `cid`
		FROM `glink`
		INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		LEFT JOIN `contact` ON `contact`.`nurl` = `gcontact`.`nurl` AND `contact`.`uid` = %d
		WHERE `glink`.`cid` = %d AND `glink`.`uid` = %d AND
		((`gcontact`.`last_contact` >= `gcontact`.`last_failure`) OR (`gcontact`.`updated` >= `gcontact`.`last_failure`))
		ORDER BY `gcontact`.`name` ASC LIMIT %d, %d ",
		intval($uid),
		intval($cid),
		intval($uid),
		intval($start),
		intval($limit)
	);

	/// @TODO Check all calling-findings of this function if they properly use DBM::is_result()
	return $r;
}



function suggestion_query($uid, $start = 0, $limit = 80) {

	if (!$uid) {
		return array();
	}

	/*
	 * Uncommented because the result of the queries are to big to store it in the cache.
	 * We need to decide if we want to change the db column type or if we want to delete it.
	 */
	//$list = Cache::get("suggestion_query:".$uid.":".$start.":".$limit);
	//if (!is_null($list)) {
	//	return $list;
	//}

	$network = array(NETWORK_DFRN);

	if (Config::get('system','diaspora_enabled')) {
		$network[] = NETWORK_DIASPORA;
	}

	if (!Config::get('system','ostatus_disabled')) {
		$network[] = NETWORK_OSTATUS;
	}

	$sql_network = implode("', '", $network);
	$sql_network = "'".$sql_network."'";

	/// @todo This query is really slow
	// By now we cache the data for five minutes
	$r = q("SELECT count(glink.gcid) as `total`, gcontact.* from gcontact
		INNER JOIN `glink` ON `glink`.`gcid` = `gcontact`.`id`
		where uid = %d and not gcontact.nurl in ( select nurl from contact where uid = %d )
		AND NOT `gcontact`.`name` IN (SELECT `name` FROM `contact` WHERE `uid` = %d)
		AND NOT `gcontact`.`id` IN (SELECT `gcid` FROM `gcign` WHERE `uid` = %d)
		AND `gcontact`.`updated` >= '%s'
		AND `gcontact`.`last_contact` >= `gcontact`.`last_failure`
		AND `gcontact`.`network` IN (%s)
		GROUP BY `glink`.`gcid` ORDER BY `gcontact`.`updated` DESC,`total` DESC LIMIT %d, %d",
		intval($uid),
		intval($uid),
		intval($uid),
		intval($uid),
		dbesc(NULL_DATE),
		$sql_network,
		intval($start),
		intval($limit)
	);

	if (DBM::is_result($r) && count($r) >= ($limit -1)) {
		/*
		 * Uncommented because the result of the queries are to big to store it in the cache.
		 * We need to decide if we want to change the db column type or if we want to delete it.
		 */
		//Cache::set("suggestion_query:".$uid.":".$start.":".$limit, $r, CACHE_FIVE_MINUTES);

		return $r;
	}

	$r2 = q("SELECT gcontact.* FROM gcontact
		INNER JOIN `glink` ON `glink`.`gcid` = `gcontact`.`id`
		WHERE `glink`.`uid` = 0 AND `glink`.`cid` = 0 AND `glink`.`zcid` = 0 AND NOT `gcontact`.`nurl` IN (SELECT `nurl` FROM `contact` WHERE `uid` = %d)
		AND NOT `gcontact`.`name` IN (SELECT `name` FROM `contact` WHERE `uid` = %d)
		AND NOT `gcontact`.`id` IN (SELECT `gcid` FROM `gcign` WHERE `uid` = %d)
		AND `gcontact`.`updated` >= '%s'
		AND `gcontact`.`last_contact` >= `gcontact`.`last_failure`
		AND `gcontact`.`network` IN (%s)
		ORDER BY rand() LIMIT %d, %d",
		intval($uid),
		intval($uid),
		intval($uid),
		dbesc(NULL_DATE),
		$sql_network,
		intval($start),
		intval($limit)
	);

	$list = array();
	foreach ($r2 as $suggestion) {
		$list[$suggestion["nurl"]] = $suggestion;
	}

	foreach ($r as $suggestion) {
		$list[$suggestion["nurl"]] = $suggestion;
	}

	while (sizeof($list) > ($limit)) {
		array_pop($list);
	}

	/*
	 * Uncommented because the result of the queries are to big to store it in the cache.
	 * We need to decide if we want to change the db column type or if we want to delete it.
	 */
	//Cache::set("suggestion_query:".$uid.":".$start.":".$limit, $list, CACHE_FIVE_MINUTES);
	return $list;
}

function update_suggestions() {

	$a = get_app();

	$done = array();

	/// @TODO Check if it is really neccessary to poll the own server
	poco_load(0, 0, 0, System::baseUrl() . '/poco');

	$done[] = System::baseUrl() . '/poco';

	if (strlen(Config::get('system','directory'))) {
		$x = fetch_url(get_server()."/pubsites");
		if ($x) {
			$j = json_decode($x);
			if ($j->entries) {
				foreach ($j->entries as $entry) {

					poco_check_server($entry->url);

					$url = $entry->url . '/poco';
					if (! in_array($url,$done)) {
						poco_load(0,0,0,$entry->url . '/poco');
					}
				}
			}
		}
	}

	// Query your contacts from Friendica and Redmatrix/Hubzilla for their contacts
	$r = q("SELECT DISTINCT(`poco`) AS `poco` FROM `contact` WHERE `network` IN ('%s', '%s')",
		dbesc(NETWORK_DFRN), dbesc(NETWORK_DIASPORA)
	);

	if (DBM::is_result($r)) {
		foreach ($r as $rr) {
			$base = substr($rr['poco'],0,strrpos($rr['poco'],'/'));
			if (! in_array($base,$done)) {
				poco_load(0,0,0,$base);
			}
		}
	}
}

/**
 * @brief Fetch server list from remote servers and adds them when they are new.
 *
 * @param string $poco URL to the POCO endpoint
 */
function poco_fetch_serverlist($poco) {
	$serverret = z_fetch_url($poco."/@server");
	if (!$serverret["success"]) {
		return;
	}
	$serverlist = json_decode($serverret['body']);

	if (!is_array($serverlist)) {
		return;
	}

	foreach ($serverlist as $server) {
		$server_url = str_replace("/index.php", "", $server->url);

		$r = q("SELECT `nurl` FROM `gserver` WHERE `nurl` = '%s'", dbesc(normalise_link($server_url)));
		if (!DBM::is_result($r)) {
			logger("Call server check for server ".$server_url, LOGGER_DEBUG);
			Worker::add(PRIORITY_LOW, "discover_poco", "server", $server_url);
		}
	}
}

function poco_discover_federation() {
	$last = Config::get('poco','last_federation_discovery');

	if ($last) {
		$next = $last + (24 * 60 * 60);
		if ($next > time()) {
			return;
		}
	}

	// Discover Friendica, Hubzilla and Diaspora servers
	$serverdata = fetch_url("http://the-federation.info/pods.json");

	if ($serverdata) {
		$servers = json_decode($serverdata);

		foreach ($servers->pods as $server) {
			Worker::add(PRIORITY_LOW, "discover_poco", "server", "https://".$server->host);
		}
	}

	// Disvover Mastodon servers
	if (!Config::get('system','ostatus_disabled')) {
		$serverdata = fetch_url("https://instances.mastodon.xyz/instances.json");

		if ($serverdata) {
			$servers = json_decode($serverdata);

			foreach ($servers as $server) {
				$url = (is_null($server->https_score) ? 'http' : 'https').'://'.$server->name;
				Worker::add(PRIORITY_LOW, "discover_poco", "server", $url);
			}
		}
	}

	// Currently disabled, since the service isn't available anymore.
	// It is not removed since I hope that there will be a successor.
	// Discover GNU Social Servers.
	//if (!Config::get('system','ostatus_disabled')) {
	//	$serverdata = "http://gstools.org/api/get_open_instances/";

	//	$result = z_fetch_url($serverdata);
	//	if ($result["success"]) {
	//		$servers = json_decode($result["body"]);

	//		foreach($servers->data as $server)
	//			poco_check_server($server->instance_address);
	//	}
	//}

	Config::set('poco','last_federation_discovery', time());
}

function poco_discover_single_server($id) {
	$r = q("SELECT `poco`, `nurl`, `url`, `network` FROM `gserver` WHERE `id` = %d", intval($id));
	if (!DBM::is_result($r)) {
		return false;
	}

	$server = $r[0];

	// Discover new servers out there (Works from Friendica version 3.5.2)
	poco_fetch_serverlist($server["poco"]);

	// Fetch all users from the other server
	$url = $server["poco"]."/?fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation";

	logger("Fetch all users from the server ".$server["url"], LOGGER_DEBUG);

	$retdata = z_fetch_url($url);
	if ($retdata["success"]) {
		$data = json_decode($retdata["body"]);

		poco_discover_server($data, 2);

		if (Config::get('system','poco_discovery') > 1) {

			$timeframe = Config::get('system','poco_discovery_since');
			if ($timeframe == 0) {
				$timeframe = 30;
			}

			$updatedSince = date("Y-m-d H:i:s", time() - $timeframe * 86400);

			// Fetch all global contacts from the other server (Not working with Redmatrix and Friendica versions before 3.3)
			$url = $server["poco"]."/@global?updatedSince=".$updatedSince."&fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation";

			$success = false;

			$retdata = z_fetch_url($url);
			if ($retdata["success"]) {
				logger("Fetch all global contacts from the server ".$server["nurl"], LOGGER_DEBUG);
				$success = poco_discover_server(json_decode($retdata["body"]));
			}

			if (!$success && (Config::get('system','poco_discovery') > 2)) {
				logger("Fetch contacts from users of the server ".$server["nurl"], LOGGER_DEBUG);
				poco_discover_server_users($data, $server);
			}
		}

		q("UPDATE `gserver` SET `last_poco_query` = '%s' WHERE `nurl` = '%s'", dbesc(datetime_convert()), dbesc($server["nurl"]));

		return true;
	} else {
		// If the server hadn't replied correctly, then force a sanity check
		poco_check_server($server["url"], $server["network"], true);

		// If we couldn't reach the server, we will try it some time later
		q("UPDATE `gserver` SET `last_poco_query` = '%s' WHERE `nurl` = '%s'", dbesc(datetime_convert()), dbesc($server["nurl"]));

		return false;
	}
}

function poco_discover($complete = false) {

	// Update the server list
	poco_discover_federation();

	$no_of_queries = 5;

	$requery_days = intval(Config::get("system", "poco_requery_days"));

	if ($requery_days == 0) {
		$requery_days = 7;
	}
	$last_update = date("c", time() - (60 * 60 * 24 * $requery_days));

	$r = q("SELECT `id`, `url`, `network` FROM `gserver` WHERE `last_contact` >= `last_failure` AND `poco` != '' AND `last_poco_query` < '%s' ORDER BY RAND()", dbesc($last_update));
	if (DBM::is_result($r)) {
		foreach ($r as $server) {

			if (!poco_check_server($server["url"], $server["network"])) {
				// The server is not reachable? Okay, then we will try it later
				q("UPDATE `gserver` SET `last_poco_query` = '%s' WHERE `nurl` = '%s'", dbesc(datetime_convert()), dbesc($server["nurl"]));
				continue;
			}

			logger('Update directory from server '.$server['url'].' with ID '.$server['id'], LOGGER_DEBUG);
			Worker::add(PRIORITY_LOW, "discover_poco", "update_server_directory", (int)$server['id']);

			if (!$complete && (--$no_of_queries == 0)) {
				break;
			}
		}
	}
}

function poco_discover_server_users($data, $server) {

	if (!isset($data->entry)) {
		return;
	}

	foreach ($data->entry as $entry) {
		$username = "";
		if (isset($entry->urls)) {
			foreach ($entry->urls as $url) {
				if ($url->type == 'profile') {
					$profile_url = $url->value;
					$urlparts = parse_url($profile_url);
					$username = end(explode("/", $urlparts["path"]));
				}
			}
		}
		if ($username != "") {
			logger("Fetch contacts for the user ".$username." from the server ".$server["nurl"], LOGGER_DEBUG);

			// Fetch all contacts from a given user from the other server
			$url = $server["poco"]."/".$username."/?fields=displayName,urls,photos,updated,network,aboutMe,currentLocation,tags,gender,contactType,generation";

			$retdata = z_fetch_url($url);
			if ($retdata["success"]) {
				poco_discover_server(json_decode($retdata["body"]), 3);
			}
		}
	}
}

function poco_discover_server($data, $default_generation = 0) {

	if (!isset($data->entry) || !count($data->entry)) {
		return false;
	}

	$success = false;

	foreach ($data->entry as $entry) {
		$profile_url = '';
		$profile_photo = '';
		$connect_url = '';
		$name = '';
		$network = '';
		$updated = NULL_DATE;
		$location = '';
		$about = '';
		$keywords = '';
		$gender = '';
		$contact_type = -1;
		$generation = $default_generation;

		$name = $entry->displayName;

		if (isset($entry->urls)) {
			foreach ($entry->urls as $url) {
				if ($url->type == 'profile') {
					$profile_url = $url->value;
					continue;
				}
				if ($url->type == 'webfinger') {
					$connect_url = str_replace('acct:' , '', $url->value);
					continue;
				}
			}
		}

		if (isset($entry->photos)) {
			foreach ($entry->photos as $photo) {
				if ($photo->type == 'profile') {
					$profile_photo = $photo->value;
					continue;
				}
			}
		}

		if (isset($entry->updated)) {
			$updated = date("Y-m-d H:i:s", strtotime($entry->updated));
		}

		if (isset($entry->network)) {
			$network = $entry->network;
		}

		if (isset($entry->currentLocation)) {
			$location = $entry->currentLocation;
		}

		if (isset($entry->aboutMe)) {
			$about = html2bbcode($entry->aboutMe);
		}

		if (isset($entry->gender)) {
			$gender = $entry->gender;
		}

		if(isset($entry->generation) && ($entry->generation > 0)) {
			$generation = ++$entry->generation;
		}

		if(isset($entry->contactType) && ($entry->contactType >= 0)) {
			$contact_type = $entry->contactType;
		}

		if (isset($entry->tags)) {
			foreach ($entry->tags as $tag) {
				$keywords = implode(", ", $tag);
			}
		}

		if ($generation > 0) {
			$success = true;

			logger("Store profile ".$profile_url, LOGGER_DEBUG);

			$gcontact = array("url" => $profile_url,
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
					"generation" => $generation);

			try {
				$gcontact = sanitize_gcontact($gcontact);
				update_gcontact($gcontact);
			} catch (Exception $e) {
				logger($e->getMessage(), LOGGER_DEBUG);
			}

			logger("Done for profile ".$profile_url, LOGGER_DEBUG);
		}
	}
	return $success;
}

/**
 * @brief Removes unwanted parts from a contact url
 *
 * @param string $url Contact url
 * @return string Contact url with the wanted parts
 */
function clean_contact_url($url) {
	$parts = parse_url($url);

	if (!isset($parts["scheme"]) || !isset($parts["host"])) {
		return $url;
	}

	$new_url = $parts["scheme"]."://".$parts["host"];

	if (isset($parts["port"])) {
		$new_url .= ":".$parts["port"];
	}

	if (isset($parts["path"])) {
		$new_url .= $parts["path"];
	}

	if ($new_url != $url) {
		logger("Cleaned contact url ".$url." to ".$new_url." - Called by: ".System::callstack(), LOGGER_DEBUG);
	}

	return $new_url;
}

/**
 * @brief Replace alternate OStatus user format with the primary one
 *
 * @param arr $contact contact array (called by reference)
 */
function fix_alternate_contact_address(&$contact) {
	if (($contact["network"] == NETWORK_OSTATUS) && poco_alternate_ostatus_url($contact["url"])) {
		$data = Probe::uri($contact["url"]);
		if ($contact["network"] == NETWORK_OSTATUS) {
			logger("Fix primary url from ".$contact["url"]." to ".$data["url"]." - Called by: ".System::callstack(), LOGGER_DEBUG);
			$contact["url"] = $data["url"];
			$contact["addr"] = $data["addr"];
			$contact["alias"] = $data["alias"];
			$contact["server_url"] = $data["baseurl"];
		}
	}
}

/**
 * @brief Fetch the gcontact id, add an entry if not existed
 *
 * @param arr $contact contact array
 * @return bool|int Returns false if not found, integer if contact was found
 */
function get_gcontact_id($contact) {

	$gcontact_id = 0;
	$doprobing = false;

	if (in_array($contact["network"], array(NETWORK_PHANTOM))) {
		logger("Invalid network for contact url ".$contact["url"]." - Called by: ".System::callstack(), LOGGER_DEBUG);
		return false;
	}

	if ($contact["network"] == NETWORK_STATUSNET) {
		$contact["network"] = NETWORK_OSTATUS;
	}

	// All new contacts are hidden by default
	if (!isset($contact["hide"])) {
		$contact["hide"] = true;
	}

	// Replace alternate OStatus user format with the primary one
	fix_alternate_contact_address($contact);

	// Remove unwanted parts from the contact url (e.g. "?zrl=...")
	if (in_array($contact["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS))) {
		$contact["url"] = clean_contact_url($contact["url"]);
	}

	dba::lock('gcontact');
	$r = q("SELECT `id`, `last_contact`, `last_failure`, `network` FROM `gcontact` WHERE `nurl` = '%s' LIMIT 1",
		dbesc(normalise_link($contact["url"])));

	if (DBM::is_result($r)) {
		$gcontact_id = $r[0]["id"];

		// Update every 90 days
		if (in_array($r[0]["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS, ""))) {
			$last_failure_str = $r[0]["last_failure"];
			$last_failure = strtotime($r[0]["last_failure"]);
			$last_contact_str = $r[0]["last_contact"];
			$last_contact = strtotime($r[0]["last_contact"]);
			$doprobing = (((time() - $last_contact) > (90 * 86400)) && ((time() - $last_failure) > (90 * 86400)));
		}
	} else {
		q("INSERT INTO `gcontact` (`name`, `nick`, `addr` , `network`, `url`, `nurl`, `photo`, `created`, `updated`, `location`, `about`, `hide`, `generation`)
			VALUES ('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d, %d)",
			dbesc($contact["name"]),
			dbesc($contact["nick"]),
			dbesc($contact["addr"]),
			dbesc($contact["network"]),
			dbesc($contact["url"]),
			dbesc(normalise_link($contact["url"])),
			dbesc($contact["photo"]),
			dbesc(datetime_convert()),
			dbesc(datetime_convert()),
			dbesc($contact["location"]),
			dbesc($contact["about"]),
			intval($contact["hide"]),
			intval($contact["generation"])
		);

		$r = q("SELECT `id`, `network` FROM `gcontact` WHERE `nurl` = '%s' ORDER BY `id` LIMIT 2",
			dbesc(normalise_link($contact["url"])));

		if (DBM::is_result($r)) {
			$gcontact_id = $r[0]["id"];

			$doprobing = in_array($r[0]["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS, ""));
		}
	}
	dba::unlock();

	if ($doprobing) {
		logger("Last Contact: ". $last_contact_str." - Last Failure: ".$last_failure_str." - Checking: ".$contact["url"], LOGGER_DEBUG);
		Worker::add(PRIORITY_LOW, 'gprobe', $contact["url"]);
	}

	return $gcontact_id;
}

/**
 * @brief Updates the gcontact table from a given array
 *
 * @param arr $contact contact array
 * @return bool|int Returns false if not found, integer if contact was found
 */
function update_gcontact($contact) {

	// Check for invalid "contact-type" value
	if (isset($contact['contact-type']) && (intval($contact['contact-type']) < 0)) {
		$contact['contact-type'] = 0;
	}

	/// @todo update contact table as well

	$gcontact_id = get_gcontact_id($contact);

	if (!$gcontact_id) {
		return false;
	}

	$r = q("SELECT `name`, `nick`, `photo`, `location`, `about`, `addr`, `generation`, `birthday`, `gender`, `keywords`,
			`contact-type`, `hide`, `nsfw`, `network`, `alias`, `notify`, `server_url`, `connect`, `updated`, `url`
		FROM `gcontact` WHERE `id` = %d LIMIT 1",
		intval($gcontact_id));

	// Get all field names
	$fields = array();
	foreach ($r[0] as $field => $data) {
		$fields[$field] = $data;
	}

	unset($fields["url"]);
	unset($fields["updated"]);
	unset($fields["hide"]);

	// Bugfix: We had an error in the storing of keywords which lead to the "0"
	// This value is still transmitted via poco.
	if ($contact["keywords"] == "0") {
		unset($contact["keywords"]);
	}

	if ($r[0]["keywords"] == "0") {
		$r[0]["keywords"] = "";
	}

	// assign all unassigned fields from the database entry
	foreach ($fields as $field => $data) {
		if (!isset($contact[$field]) || ($contact[$field] == "")) {
			$contact[$field] = $r[0][$field];
		}
	}

	if (!isset($contact["hide"])) {
		$contact["hide"] = $r[0]["hide"];
	}

	$fields["hide"] = $r[0]["hide"];

	if ($contact["network"] == NETWORK_STATUSNET) {
		$contact["network"] = NETWORK_OSTATUS;
	}

	// Replace alternate OStatus user format with the primary one
	fix_alternate_contact_address($contact);

	if (!isset($contact["updated"])) {
		$contact["updated"] = DBM::date();
	}

	if ($contact["network"] == NETWORK_TWITTER) {
		$contact["server_url"] = 'http://twitter.com';
	}

	if ($contact["server_url"] == "") {
		$data = Probe::uri($contact["url"]);
		if ($data["network"] != NETWORK_PHANTOM) {
			$contact["server_url"] = $data['baseurl'];
		}
	} else {
		$contact["server_url"] = normalise_link($contact["server_url"]);
	}

	if (($contact["addr"] == "") && ($contact["server_url"] != "") && ($contact["nick"] != "")) {
		$hostname = str_replace("http://", "", $contact["server_url"]);
		$contact["addr"] = $contact["nick"]."@".$hostname;
	}

	// Check if any field changed
	$update = false;
	unset($fields["generation"]);

	if ((($contact["generation"] > 0) && ($contact["generation"] <= $r[0]["generation"])) || ($r[0]["generation"] == 0)) {
		foreach ($fields as $field => $data) {
			if ($contact[$field] != $r[0][$field]) {
				logger("Difference for contact ".$contact["url"]." in field '".$field."'. New value: '".$contact[$field]."', old value '".$r[0][$field]."'", LOGGER_DEBUG);
				$update = true;
			}
		}

		if ($contact["generation"] < $r[0]["generation"]) {
			logger("Difference for contact ".$contact["url"]." in field 'generation'. new value: '".$contact["generation"]."', old value '".$r[0]["generation"]."'", LOGGER_DEBUG);
			$update = true;
		}
	}

	if ($update) {
		logger("Update gcontact for ".$contact["url"], LOGGER_DEBUG);
		$condition = array('`nurl` = ? AND (`generation` = 0 OR `generation` >= ?)',
				normalise_link($contact["url"]), $contact["generation"]);
		$contact["updated"] = DBM::date($contact["updated"]);

		$updated = array('photo' => $contact['photo'], 'name' => $contact['name'],
				'nick' => $contact['nick'], 'addr' => $contact['addr'],
				'network' => $contact['network'], 'birthday' => $contact['birthday'],
				'gender' => $contact['gender'], 'keywords' => $contact['keywords'],
				'hide' => $contact['hide'], 'nsfw' => $contact['nsfw'],
				'contact-type' => $contact['contact-type'], 'alias' => $contact['alias'],
				'notify' => $contact['notify'], 'url' => $contact['url'],
				'location' => $contact['location'], 'about' => $contact['about'],
				'generation' => $contact['generation'], 'updated' => $contact['updated'],
				'server_url' => $contact['server_url'], 'connect' => $contact['connect']);

		dba::update('gcontact', $updated, $condition, $fields);

		// Now update the contact entry with the user id "0" as well.
		// This is used for the shadow copies of public items.
		$r = q("SELECT `id` FROM `contact` WHERE `nurl` = '%s' AND `uid` = 0 ORDER BY `id` LIMIT 1",
			dbesc(normalise_link($contact["url"])));

		if (DBM::is_result($r)) {
			logger("Update public contact ".$r[0]["id"], LOGGER_DEBUG);

			update_contact_avatar($contact["photo"], 0, $r[0]["id"]);

			$fields = array('name', 'nick', 'addr',
					'network', 'bd', 'gender',
					'keywords', 'alias', 'contact-type',
					'url', 'location', 'about');
			$old_contact = dba::select('contact', $fields, array('id' => $r[0]["id"]), array('limit' => 1));

			// Update it with the current values
			$fields = array('name' => $contact['name'], 'nick' => $contact['nick'],
					'addr' => $contact['addr'], 'network' => $contact['network'],
					'bd' => $contact['birthday'], 'gender' => $contact['gender'],
					'keywords' => $contact['keywords'], 'alias' => $contact['alias'],
					'contact-type' => $contact['contact-type'], 'url' => $contact['url'],
					'location' => $contact['location'], 'about' => $contact['about']);

			dba::update('contact', $fields, array('id' => $r[0]["id"]), $old_contact);
		}
	}

	return $gcontact_id;
}

/**
 * @brief Updates the gcontact entry from probe
 *
 * @param str $url profile link
 */
function update_gcontact_from_probe($url) {
	$data = Probe::uri($url);

	if (in_array($data["network"], array(NETWORK_PHANTOM))) {
		logger("Invalid network for contact url ".$data["url"]." - Called by: ".System::callstack(), LOGGER_DEBUG);
		return;
	}

	$data["server_url"] = $data["baseurl"];

	update_gcontact($data);
}

/**
 * @brief Update the gcontact entry for a given user id
 *
 * @param int $uid User ID
 */
function update_gcontact_for_user($uid) {
	$r = q("SELECT `profile`.`locality`, `profile`.`region`, `profile`.`country-name`,
			`profile`.`name`, `profile`.`about`, `profile`.`gender`,
			`profile`.`pub_keywords`, `profile`.`dob`, `profile`.`photo`,
			`profile`.`net-publish`, `user`.`nickname`, `user`.`hidewall`,
			`contact`.`notify`, `contact`.`url`, `contact`.`addr`
		FROM `profile`
			INNER JOIN `user` ON `user`.`uid` = `profile`.`uid`
			INNER JOIN `contact` ON `contact`.`uid` = `profile`.`uid`
		WHERE `profile`.`uid` = %d AND `profile`.`is-default` AND `contact`.`self`",
		intval($uid));

	$location = formatted_location(array("locality" => $r[0]["locality"], "region" => $r[0]["region"],
						"country-name" => $r[0]["country-name"]));

	// The "addr" field was added in 3.4.3 so it can be empty for older users
	if ($r[0]["addr"] != "") {
		$addr = $r[0]["nickname"].'@'.str_replace(array("http://", "https://"), "", System::baseUrl());
	} else {
		$addr = $r[0]["addr"];
	}

	$gcontact = array("name" => $r[0]["name"], "location" => $location, "about" => $r[0]["about"],
			"gender" => $r[0]["gender"], "keywords" => $r[0]["pub_keywords"],
			"birthday" => $r[0]["dob"], "photo" => $r[0]["photo"],
			"notify" => $r[0]["notify"], "url" => $r[0]["url"],
			"hide" => ($r[0]["hidewall"] || !$r[0]["net-publish"]),
			"nick" => $r[0]["nickname"], "addr" => $addr,
			"connect" => $addr, "server_url" => System::baseUrl(),
			"generation" => 1, "network" => NETWORK_DFRN);

	update_gcontact($gcontact);
}

/**
 * @brief Fetches users of given GNU Social server
 *
 * If the "Statistics" plugin is enabled (See http://gstools.org/ for details) we query user data with this.
 *
 * @param str $server Server address
 */
function gs_fetch_users($server) {

	logger("Fetching users from GNU Social server ".$server, LOGGER_DEBUG);

	$url = $server."/main/statistics";

	$result = z_fetch_url($url);
	if (!$result["success"]) {
		return false;
	}

	$statistics = json_decode($result["body"]);

	if (is_object($statistics->config)) {
		if ($statistics->config->instance_with_ssl) {
			$server = "https://";
		} else {
			$server = "http://";
		}

		$server .= $statistics->config->instance_address;

		$hostname = $statistics->config->instance_address;
	} else {
		/// @TODO is_object() above means here no object, still $statistics is being used as object
		if ($statistics->instance_with_ssl) {
			$server = "https://";
		} else {
			$server = "http://";
		}

		$server .= $statistics->instance_address;

		$hostname = $statistics->instance_address;
	}

	if (is_object($statistics->users)) {
		foreach ($statistics->users as $nick => $user) {
			$profile_url = $server."/".$user->nickname;

			$contact = array("url" => $profile_url,
					"name" => $user->fullname,
					"addr" => $user->nickname."@".$hostname,
					"nick" => $user->nickname,
					"about" => $user->bio,
					"network" => NETWORK_OSTATUS,
					"photo" => System::baseUrl()."/images/person-175.jpg");
			get_gcontact_id($contact);
		}
	}
}

/**
 * @brief Asking GNU Social server on a regular base for their user data
 *
 */
function gs_discover() {

	$requery_days = intval(Config::get("system", "poco_requery_days"));

	$last_update = date("c", time() - (60 * 60 * 24 * $requery_days));

	$r = q("SELECT `nurl`, `url` FROM `gserver` WHERE `last_contact` >= `last_failure` AND `network` = '%s' AND `last_poco_query` < '%s' ORDER BY RAND() LIMIT 5",
		dbesc(NETWORK_OSTATUS), dbesc($last_update));

	if (!DBM::is_result($r)) {
		return;
	}

	foreach ($r as $server) {
		gs_fetch_users($server["url"]);
		q("UPDATE `gserver` SET `last_poco_query` = '%s' WHERE `nurl` = '%s'", dbesc(datetime_convert()), dbesc($server["nurl"]));
	}
}

/**
 * @brief Returns a list of all known servers
 * @return array List of server urls
 */
function poco_serverlist() {
	$r = q("SELECT `url`, `site_name` AS `displayName`, `network`, `platform`, `version` FROM `gserver`
		WHERE `network` IN ('%s', '%s', '%s') AND `last_contact` > `last_failure`
		ORDER BY `last_contact`
		LIMIT 1000",
		dbesc(NETWORK_DFRN), dbesc(NETWORK_DIASPORA), dbesc(NETWORK_OSTATUS));
	if (!DBM::is_result($r)) {
		return false;
	}

	return $r;
}
