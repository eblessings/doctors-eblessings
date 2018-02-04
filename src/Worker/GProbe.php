<?php
/**
 * @file src/Worker/GProbe.php
 */

namespace Friendica\Worker;

use Friendica\Core\Cache;
use Friendica\Database\DBM;
use Friendica\Model\GContact;
use Friendica\Network\Probe;
use Friendica\Protocol\PortableContact;

require_once 'include/datetime.php';

class GProbe {
	public static function execute($url = '')
	{
		if (empty($url)) {
			return;
		}

		$r = q(
			"SELECT `id`, `url`, `network` FROM `gcontact` WHERE `nurl` = '%s' ORDER BY `id` LIMIT 1",
			dbesc(normalise_link($url))
		);

		logger("gprobe start for ".normalise_link($url), LOGGER_DEBUG);

		if (!DBM::is_result($r)) {
			// Is it a DDoS attempt?
			$urlparts = parse_url($url);

			$result = Cache::get("gprobe:".$urlparts["host"]);
			if (!is_null($result)) {
				if (in_array($result["network"], [NETWORK_FEED, NETWORK_PHANTOM])) {
					logger("DDoS attempt detected for ".$urlparts["host"]." by ".$_SERVER["REMOTE_ADDR"].". server data: ".print_r($_SERVER, true), LOGGER_DEBUG);
					return;
				}
			}

			$arr = Probe::uri($url);

			if (is_null($result)) {
				Cache::set("gprobe:".$urlparts["host"], $arr);
			}

			if (!in_array($arr["network"], [NETWORK_FEED, NETWORK_PHANTOM])) {
				GContact::update($arr);
			}

			$r = q(
				"SELECT `id`, `url`, `network` FROM `gcontact` WHERE `nurl` = '%s' ORDER BY `id` LIMIT 1",
				dbesc(normalise_link($url))
			);
		}
		if (DBM::is_result($r)) {
			// Check for accessibility and do a poco discovery
			if (PortableContact::lastUpdated($r[0]['url'], true) && ($r[0]["network"] == NETWORK_DFRN)) {
				PortableContact::loadWorker(0, 0, $r[0]['id'], str_replace('/profile/', '/poco/', $r[0]['url']));
			}
		}

		logger("gprobe end for ".normalise_link($url), LOGGER_DEBUG);
		return;
	}
}
