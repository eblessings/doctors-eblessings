<?php
/**
 * @brief Calls the post update functions
 */
function post_update() {

	if (!post_update_1192())
		return;

	if (!post_update_1195())
		return;
}

/**
 * @brief set the gcontact-id in all item entries
 *
 * This job has to be started multiple times until all entries are set.
 * It isn't started in the update function since it would consume too much time and can be done in the background.
 *
 * @return bool "true" when the job is done
 */
function post_update_1192() {

	// Was the script completed?
	if (get_config("system", "post_update_version") >= 1192)
		return true;

	// Check if the first step is done (Setting "gcontact-id" in the item table)
	$r = q("SELECT `author-link`, `author-name`, `author-avatar`, `uid`, `network` FROM `item` WHERE `gcontact-id` = 0 LIMIT 1000");
	if (!$r) {
		// Are there unfinished entries in the thread table?
		$r = q("SELECT COUNT(*) AS `total` FROM `thread`
			INNER JOIN `item` ON `item`.`id` =`thread`.`iid`
			WHERE `thread`.`gcontact-id` = 0 AND
				(`thread`.`uid` IN (SELECT `uid` from `user`) OR `thread`.`uid` = 0)");

		if ($r AND ($r[0]["total"] == 0)) {
			set_config("system", "post_update_version", 1192);
			return true;
		}

		// Update the thread table from the item table
		q("UPDATE `thread` INNER JOIN `item` ON `item`.`id`=`thread`.`iid`
				SET `thread`.`gcontact-id` = `item`.`gcontact-id`
			WHERE `thread`.`gcontact-id` = 0 AND
				(`thread`.`uid` IN (SELECT `uid` from `user`) OR `thread`.`uid` = 0)");

		return false;
	}

	$item_arr = array();
	foreach ($r AS $item) {
		$index = $item["author-link"]."-".$item["uid"];
		$item_arr[$index] = array("author-link" => $item["author-link"],
						"uid" => $item["uid"],
						"network" => $item["network"]);
	}

	// Set the "gcontact-id" in the item table and add a new gcontact entry if needed
	foreach($item_arr AS $item) {
		$gcontact_id = get_gcontact_id(array("url" => $item['author-link'], "network" => $item['network'],
						"photo" => $item['author-avatar'], "name" => $item['author-name']));
		q("UPDATE `item` SET `gcontact-id` = %d WHERE `uid` = %d AND `author-link` = '%s' AND `gcontact-id` = 0",
			intval($gcontact_id), intval($item["uid"]), dbesc($item["author-link"]));
	}
	return false;
}

/**
 * @brief Updates the "shadow" field in the item table
 *
 * @return bool "true" when the job is done
 */
function post_update_1195() {

	// Was the script completed?
	if (get_config("system", "post_update_version") >= 1195)
		return true;

	$end_id = get_config("system", "post_update_1195_end");
	if (!$end_id) {
		$r = q("SELECT `id` FROM `item` WHERE `uid` != 0 ORDER BY `id` DESC LIMIT 1");
		if ($r) {
			set_config("system", "post_update_1195_end", $r[0]["id"]);
			$end_id = get_config("system", "post_update_1195_end");
		}
	}

	$start_id = get_config("system", "post_update_1195_start");

	$query1 = "SELECT `item`.`id` FROM `item` ";

	$query2 = "INNER JOIN `item` AS `shadow` ON `item`.`uri` = `shadow`.`uri` AND `shadow`.`uid` = 0 ";

	$query3 = "WHERE `item`.`uid` != 0 AND `item`.`id` >= %d AND `item`.`id` <= %d
			AND `item`.`visible` AND NOT `item`.`private`
			AND NOT `item`.`deleted` AND NOT `item`.`moderated`
			AND `item`.`network` IN ('%s', '%s', '%s', '')
			AND `item`.`allow_cid` = '' AND `item`.`allow_gid` = ''
			AND `item`.`deny_cid` = '' AND `item`.`deny_gid` = ''
			AND `item`.`shadow` = 0";

	$r = q($query1.$query2.$query3."  ORDER BY `item`.`id` LIMIT 1",
		intval($start_id), intval($end_id),
		dbesc(NETWORK_DFRN), dbesc(NETWORK_DIASPORA), dbesc(NETWORK_OSTATUS));
	if (!$r) {
		set_config("system", "post_update_version", 1195);
		return true;
	} else {
		set_config("system", "post_update_1195_start", $r[0]["id"]);
		$start_id = get_config("system", "post_update_1195_start");
	}


	$r = q($query1.$query2.$query3."  ORDER BY `item`.`id` LIMIT 10000,1",
		intval($start_id), intval($end_id),
		dbesc(NETWORK_DFRN), dbesc(NETWORK_DIASPORA), dbesc(NETWORK_OSTATUS));
	if ($r)
		$pos_id = $r[0]["id"];
	else
		$pos_id = $end_id;

	logger("Progress: Start: ".$start_id." position: ".$pos_id." end: ".$end_id, LOGGER_DEBUG);

	$r = q("UPDATE `item` ".$query2." SET `item`.`shadow` = `shadow`.`id` ".$query3,
		intval($start_id), intval($pos_id),
		dbesc(NETWORK_DFRN), dbesc(NETWORK_DIASPORA), dbesc(NETWORK_OSTATUS));
}
?>
