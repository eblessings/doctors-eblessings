<?php
/**
 * @file src/Worker/DiscoverPoCo.php
 */
namespace Friendica\Worker;

use Friendica\Core\Cache;
use Friendica\Core\Config;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\Model\GContact;
use Friendica\Model\Contact;
use Friendica\Model\GServer;
use Friendica\Network\Probe;
use Friendica\Protocol\PortableContact;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Network;
use Friendica\Util\Strings;

class DiscoverPoCo
{
	/// @todo Clean up this mess of a parameter hell and split it in several classes
	public static function execute($command = '', $param1 = '', $param2 = '', $param3 = '', $param4 = '')
	{
		/*
		This function can be called in these ways:
		- checkcontact: Updates gcontact entries
		- server <poco url>: Searches for the poco server list. "poco url" is base64 encoded.
		- update_server: Frequently check the first 250 servers for vitality.
		- PortableContact::load: Load POCO data from a given POCO address
		*/

		$search = "";
		$mode = 0;
		if (($command == "checkcontact") && Config::get('system', 'poco_completion')) {
			self::discoverUsers();
		} elseif ($command == "server") {
			$server_url = $param1;
			if ($server_url == "") {
				return;
			}
			$server_url = filter_var($server_url, FILTER_SANITIZE_URL);
			if (substr(Strings::normaliseLink($server_url), 0, 7) != "http://") {
				return;
			}
			$result = "Checking server ".$server_url." - ";
			$ret = GServer::check($server_url);
			if ($ret) {
				$result .= "success";
			} else {
				$result .= "failed";
			}
			Logger::log($result, Logger::DEBUG);
		} elseif ($command == "update_server") {
			self::updateServer();
		} elseif ($command == "load") {
			if (!empty($param4)) {
				$url = $param4;
			} else {
				$url = '';
			}
			PortableContact::load(intval($param1), intval($param2), intval($param3), $url);
		} elseif ($command !== "") {
			Logger::log("Unknown or missing parameter ".$command."\n");
			return;
		}

		Logger::log('start '.$search);

		if (($mode == 0) && ($search == "") && (Config::get('system', 'poco_discovery') != PortableContact::DISABLED)) {
			// Query Friendica and Hubzilla servers for their users
			PortableContact::discover();

			// Query GNU Social servers for their users ("statistics" addon has to be enabled on the GS server)
			if (!Config::get('system', 'ostatus_disabled')) {
				GContact::discoverGsUsers();
			}
		}

		Logger::log('end '.$search);

		return;
	}

	/**
	 * @brief Updates the first 250 servers
	 *
	 */
	private static function updateServer() {
		$r = q("SELECT `url`, `created`, `last_failure`, `last_contact` FROM `gserver` ORDER BY rand()");

		if (!DBA::isResult($r)) {
			return;
		}

		$updated = 0;

		foreach ($r AS $server) {
			if (!PortableContact::updateNeeded($server["created"], "", $server["last_failure"], $server["last_contact"])) {
				continue;
			}
			Logger::log('Update server status for server '.$server["url"], Logger::DEBUG);

			Worker::add(PRIORITY_LOW, "DiscoverPoCo", "server", $server["url"]);

			if (++$updated > 250) {
				return;
			}
		}
	}

	private static function discoverUsers() {
		Logger::log("Discover users", Logger::DEBUG);

		$starttime = time();

		$users = q("SELECT `url`, `created`, `updated`, `last_failure`, `last_contact`, `server_url`, `network` FROM `gcontact`
				WHERE `last_contact` < UTC_TIMESTAMP - INTERVAL 1 MONTH AND
					`last_failure` < UTC_TIMESTAMP - INTERVAL 1 MONTH AND
					`network` IN ('%s', '%s', '%s', '%s', '') ORDER BY rand()",
				DBA::escape(Protocol::DFRN), DBA::escape(Protocol::DIASPORA),
				DBA::escape(Protocol::OSTATUS), DBA::escape(Protocol::FEED));

		if (!$users) {
			return;
		}
		$checked = 0;

		foreach ($users AS $user) {

			$urlparts = parse_url($user["url"]);
			if (!isset($urlparts["scheme"])) {
				DBA::update('gcontact', ['network' => Protocol::PHANTOM],
					['nurl' => Strings::normaliseLink($user["url"])]);
				continue;
			 }

			if (in_array($urlparts["host"], ["twitter.com", "identi.ca"])) {
				$networks = ["twitter.com" => Protocol::TWITTER, "identi.ca" => Protocol::PUMPIO];

				DBA::update('gcontact', ['network' => $networks[$urlparts["host"]]],
					['nurl' => Strings::normaliseLink($user["url"])]);
				continue;
			}

			$server_url = Contact::getBasepath($user["url"]);
			$force_update = false;

			if ($user["server_url"] != "") {

				$force_update = (Strings::normaliseLink($user["server_url"]) != Strings::normaliseLink($server_url));

				$server_url = $user["server_url"];
			}

			if ((($server_url == "") && ($user["network"] == Protocol::FEED)) || $force_update || GServer::check($server_url, $user["network"])) {
				Logger::log('Check profile '.$user["url"]);
				Worker::add(PRIORITY_LOW, 'UpdateGContact', $user['url'], 'force');

				if (++$checked > 100) {
					return;
				}
			} else {
				DBA::update('gcontact', ['last_failure' => DateTimeFormat::utcNow()],
					['nurl' => Strings::normaliseLink($user["url"])]);
			}

			// Quit the loop after 3 minutes
			if (time() > ($starttime + 180)) {
				return;
			}
		}
	}
}
