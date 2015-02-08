<?php
function add_thread($itemid, $onlyshadow = false) {
	$items = q("SELECT `uid`, `created`, `edited`, `commented`, `received`, `changed`, `wall`, `private`, `pubmail`, `moderated`, `visible`, `spam`, `starred`, `bookmark`, `contact-id`,
			`deleted`, `origin`, `forum_mode`, `mention`, `network`  FROM `item` WHERE `id` = %d AND (`parent` = %d OR `parent` = 0) LIMIT 1", intval($itemid), intval($itemid));

	if (!$items)
		return;

	$item = $items[0];
	$item['iid'] = $itemid;

	if (!$onlyshadow) {
		$result = dbq("INSERT INTO `thread` (`"
				.implode("`, `", array_keys($item))
				."`) VALUES ('"
				.implode("', '", array_values($item))
				."')");

		logger("add_thread: Add thread for item ".$itemid." - ".print_r($result, true), LOGGER_DEBUG);
	}

	// Store a shadow copy of public items for displaying a global community page?
	//if (!get_config('system', 'global_community'))
	//	return;

	// is it already a copy?
	if (($itemid == 0) OR ($item['uid'] == 0))
		return;

	// Is it a visible public post?
	if (!$item["visible"] OR $item["deleted"] OR $item["moderated"] OR $item["private"])
		return;

	// is it an entry from a connector? Only add an entry for natively connected networks
	if (!in_array($item["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS, "")))
		return;

	// Only do these checks if the post isn't a wall post
	if (!$item["wall"]) {
		// Check, if hide-friends is activated - then don't do a shadow entry
		$r = q("SELECT `hide-friends` FROM `profile` WHERE `is-default` AND `uid` = %d AND NOT `hide-friends`",
			$item['uid']);
		if (!count($r))
			return;
		// Check if the contact is hidden or blocked
		$r = q("SELECT `id` FROM `contact` WHERE NOT `hidden` AND NOT `blocked` AND `id` = %d",
			$item['contact-id']);
		if (!count($r))
			return;
	}

	// Only add a shadow, if the profile isn't hidden
	$r = q("SELECT `uid` FROM `user` where `uid` = %d AND NOT `hidewall`", $item['uid']);
	if (!count($r))
		return;

	$item = q("SELECT * FROM `item` WHERE `id` = %d",
		intval($itemid));

	if (count($item) AND ($item[0]["allow_cid"] == '')  AND ($item[0]["allow_gid"] == '') AND
		($item[0]["deny_cid"] == '') AND ($item[0]["deny_gid"] == '')) {

		$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND `uid` = 0 LIMIT 1",
			dbesc($item['uri']));

		if (!$r) {
			// Preparing public shadow (removing user specific data)
			require_once("include/items.php");
			require_once("include/Contact.php");

			unset($item[0]['id']);
			$item[0]['uid'] = 0;
			$item[0]['contact-id'] = get_contact($item[0]['author-link'], 0);
			$public_shadow = item_store($item[0], false, false, true);

			logger("add_thread: Stored public shadow for post ".$itemid." under id ".$public_shadow, LOGGER_DEBUG);
		}
	}
}

function update_thread_uri($itemuri, $uid) {
	$messages = q("SELECT `id` FROM `item` WHERE uri ='%s' AND uid=%d", dbesc($itemuri), intval($uid));

	if(count($messages))
		foreach ($messages as $message)
			update_thread($message["id"]);
}

function update_thread($itemid, $setmention = false) {
	$items = q("SELECT `uid`, `uri`, `created`, `edited`, `commented`, `received`, `changed`, `wall`, `private`, `pubmail`, `moderated`, `visible`, `spam`, `starred`, `bookmark`, `contact-id`,
			`deleted`, `origin`, `forum_mode`, `network`  FROM `item` WHERE `id` = %d AND (`parent` = %d OR `parent` = 0) LIMIT 1", intval($itemid), intval($itemid));

	if (!$items)
		return;

	$item = $items[0];

	if ($setmention)
		$item["mention"] = 1;

	$sql = "";

	foreach ($item AS $field => $data)
		if ($field != "uri") {
			if ($sql != "")
				$sql .= ", ";

			$sql .= "`".$field."` = '".dbesc($data)."'";
		}

	$result = q("UPDATE `thread` SET ".$sql." WHERE `iid` = %d", intval($itemid));

	logger("update_thread: Update thread for item ".$itemid." - ".print_r($result, true)." ".print_r($item, true), LOGGER_DEBUG);

	// Updating a shadow item entry
	$items = q("SELECT `id`, `title`, `body`, `created`, `edited`, `commented`, `received`, `changed`, `wall`, `private`, `pubmail`,
			`moderated`, `visible`, `spam`, `starred`, `bookmark`, `deleted`, `origin`, `forum_mode`, `network`
			FROM `item` WHERE `uri` = '%s' AND `uid` = 0 LIMIT 1", dbesc($item["uri"]));

	if (!$items)
		return;

	$item = $items[0];

	$result = q("UPDATE `item` SET `title` = '%s', `body` = '%s', `network` = '%s' WHERE `id` = %d",
			dbesc($item["title"]),
			dbesc($item["body"]),
			dbesc($item["network"]),
			intval($item["id"])
		);
	logger("update_thread: Updating public shadow for post ".$item["id"]." Result: ".print_r($result, true), LOGGER_DEBUG);

	/*
	$sql = "";

	foreach ($item AS $field => $data)
		if ($field != "id") {
			if ($sql != "")
				$sql .= ", ";

			$sql .= "`".$field."` = '".dbesc($data)."'";
		}
	//logger("update_thread: Updating public shadow for post ".$item["id"]." SQL: ".$sql, LOGGER_DEBUG);
	$result = q("UPDATE `item` SET ".$sql." WHERE `id` = %d", intval($item["id"]));
	logger("update_thread: Updating public shadow for post ".$item["id"]." Result: ".print_r($result, true), LOGGER_DEBUG);
	*/
}

function delete_thread_uri($itemuri, $uid) {
	$messages = q("SELECT `id` FROM `item` WHERE uri ='%s' AND uid=%d", dbesc($itemuri), intval($uid));

	if(count($messages))
		foreach ($messages as $message)
			delete_thread($message["id"], $itemuri);
}

function delete_thread($itemid, $itemuri = "") {
	$item = q("SELECT `uid` FROM `thread` WHERE `iid` = %d", intval($itemid));

	$result = q("DELETE FROM `thread` WHERE `iid` = %d", intval($itemid));

	logger("delete_thread: Deleted thread for item ".$itemid." - ".print_r($result, true), LOGGER_DEBUG);

	if ($itemuri != "") {
		$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND NOT (`uid` IN (%d, 0))",
				dbesc($itemuri),
				intval($item["uid"])
			);
		if (!count($r)) {
			$r = q("DELETE FROM `item` WHERE `uri` = '%s' AND `uid` = 0)",
				dbesc($itemuri)
			);
			logger("delete_thread: Deleted shadow for item ".$itemuri." - ".print_r($result, true), LOGGER_DEBUG);
		}
	}
}

function update_threads() {
	global $db;

	logger("update_threads: start");

	$messages = $db->q("SELECT `id` FROM `item` WHERE `id` = `parent`", true);

	logger("update_threads: fetched messages: ".count($messages));

	while ($message = $db->qfetch())
		add_thread($message["id"]);
	$db->qclose();
}

function update_threads_mention() {
	$a = get_app();

	$users = q("SELECT `uid`, `nickname` FROM `user` ORDER BY `uid`");

	foreach ($users AS $user) {
		$self = normalise_link($a->get_baseurl() . '/profile/' . $user['nickname']);
		$selfhttps = str_replace("http://", "https://", $self);
		$parents = q("SELECT DISTINCT(`parent`) FROM `item` WHERE `uid` = %d AND
				((`owner-link` IN ('%s', '%s')) OR (`author-link` IN ('%s', '%s')))",
				$user["uid"], $self, $selfhttps, $self, $selfhttps);

		foreach ($parents AS $parent)
			q("UPDATE `thread` SET `mention` = 1 WHERE `iid` = %d", $parent["parent"]);
	}
}


function update_shadow_copy() {
	global $db;

	logger("start");

	$messages = $db->q(sprintf("SELECT `iid` FROM `thread` WHERE `uid` != 0 AND `network` IN ('', '%s', '%s', '%s')
					AND `visible` AND NOT `deleted` AND NOT `moderated` AND NOT `private` ORDER BY `created`",
				NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS), true);

	logger("fetched messages: ".count($messages));
	while ($message = $db->qfetch())
		add_thread($message["iid"], true);

	$db->qclose();
}
?>
