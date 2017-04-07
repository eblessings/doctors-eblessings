<?php

use \Friendica\Core\Config;

function update_gcontact_run(&$argv, &$argc) {
	global $a;

	require_once 'include/Scrape.php';
	require_once 'include/socgraph.php';

	logger('update_gcontact: start');

	if (($argc > 1) && (intval($argv[1]))) {
		$contact_id = intval($argv[1]);
	}

	if (!$contact_id) {
		logger('update_gcontact: no contact');
		return;
	}

	$r = q("SELECT * FROM `gcontact` WHERE `id` = %d", intval($contact_id));

	if (!dbm::is_result($r)) {
		return;
	}

	if (!in_array($r[0]["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS))) {
		return;
	}

	$data = probe_url($r[0]["url"]);

	if (!in_array($data["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS))) {
		if ($r[0]["server_url"] != "")
			poco_check_server($r[0]["server_url"], $r[0]["network"]);

		q("UPDATE `gcontact` SET `last_failure` = '%s' WHERE `id` = %d",
			dbesc(datetime_convert()), intval($contact_id));
		return;
	}

	if (($data["name"] == "") AND ($r[0]['name'] != ""))
		$data["name"] = $r[0]['name'];

	if (($data["nick"] == "") AND ($r[0]['nick'] != ""))
		$data["nick"] = $r[0]['nick'];

	if (($data["addr"] == "") AND ($r[0]['addr'] != ""))
		$data["addr"] = $r[0]['addr'];

	if (($data["photo"] == "") AND ($r[0]['photo'] != ""))
		$data["photo"] = $r[0]['photo'];


	q("UPDATE `gcontact` SET `name` = '%s', `nick` = '%s', `addr` = '%s', `photo` = '%s'
				WHERE `id` = %d",
				dbesc($data["name"]),
				dbesc($data["nick"]),
				dbesc($data["addr"]),
				dbesc($data["photo"]),
				intval($contact_id)
			);

	q("UPDATE `contact` SET `name` = '%s', `nick` = '%s', `addr` = '%s', `photo` = '%s'
				WHERE `uid` = 0 AND `addr` = '' AND `nurl` = '%s'",
				dbesc($data["name"]),
				dbesc($data["nick"]),
				dbesc($data["addr"]),
				dbesc($data["photo"]),
				dbesc(normalise_link($data["url"]))
			);

	q("UPDATE `contact` SET `addr` = '%s'
				WHERE `uid` != 0 AND `addr` = '' AND `nurl` = '%s'",
				dbesc($data["addr"]),
				dbesc(normalise_link($data["url"]))
			);
}
