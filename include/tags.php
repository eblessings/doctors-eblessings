<?php
function create_tags_from_item($itemid) {
	global $a;

	$profile_base = $a->get_baseurl();
	$profile_data = parse_url($profile_base);
	$profile_base_friendica = $profile_data['host'].$profile_data['path']."/profile/";
	$profile_base_diaspora = $profile_data['host'].$profile_data['path']."/u/";

	$searchpath = $a->get_baseurl()."/search?tag=";

	$messages = q("SELECT `guid`, `uid`, `id`, `edited`, `deleted`, `created`, `received`, `title`, `body`, `tag`, `parent` FROM `item` WHERE `id` = %d LIMIT 1", intval($itemid));

	if (!$messages)
		return;

	$message = $messages[0];

	// Clean up all tags
	q("DELETE FROM `term` WHERE `otype` = %d AND `oid` = %d AND `type` IN (%d, %d)",
		intval(TERM_OBJ_POST),
		intval($itemid),
		intval(TERM_HASHTAG),
		intval(TERM_MENTION));

	if ($message["deleted"])
		return;

	$taglist = explode(",", $message["tag"]);

	$tags = "";
	foreach ($taglist as $tag)
		if ((substr(trim($tag), 0, 1) == "#") OR (substr(trim($tag), 0, 1) == "@"))
			$tags .= " ".trim($tag);
		else
			$tags .= " #".trim($tag);

	$data = " ".$message["title"]." ".$message["body"]." ".$tags." ";

	$tags = array();

	$pattern = "/\W\#([^\[].*?)[\s'\".,:;\?!\[\]\/]/ism";
	if (preg_match_all($pattern, $data, $matches))
		foreach ($matches[1] as $match)
			$tags["#".strtolower($match)] = ""; // $searchpath.strtolower($match);

	$pattern = "/\W([\#@])\[url\=(.*?)\](.*?)\[\/url\]/ism";
	if (preg_match_all($pattern, $data, $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match)
			$tags[$match[1].strtolower(trim($match[3], ',.:;[]/\"?!'))] = $match[2];
	}

	foreach ($tags as $tag=>$link) {

		if (substr(trim($tag), 0, 1) == "#") {
			// try to ignore #039 or #1 or anything like that
			if(ctype_digit(substr(trim($tag),1)))
				continue;
			// try to ignore html hex escapes, e.g. #x2317
			if((substr(trim($tag),1,1) == 'x' || substr(trim($tag),1,1) == 'X') && ctype_digit(substr(trim($tag),2)))
				continue;
			$type = TERM_HASHTAG;
			$term = substr($tag, 1);
		} elseif (substr(trim($tag), 0, 1) == "@") {
			$type = TERM_MENTION;
			$term = substr($tag, 1);
		} else { // This shouldn't happen
			$type = TERM_HASHTAG;
			$term = $tag;
		}

		$r = q("INSERT INTO `term` (`uid`, `oid`, `otype`, `type`, `term`, `url`, `guid`, `created`, `received`)
				VALUES (%d, %d, %d, %d, '%s', '%s', '%s', '%s', '%s')",
			intval($message["uid"]), intval($itemid), intval(TERM_OBJ_POST), intval($type),
			dbesc($term), dbesc($link), dbesc($message["guid"]), dbesc($message["created"]), dbesc($message["received"]));

		// Search for mentions
		if ((substr($tag, 0, 1) == '@') AND (strpos($link, $profile_base_friendica) OR strpos($link, $profile_base_diaspora))) {
			$users = q("SELECT `uid` FROM `contact` WHERE self AND (`url` = '%s' OR `nurl` = '%s')", $link, $link);
			foreach ($users AS $user) {
				if ($user["uid"] == $message["uid"]) {
					q("UPDATE `item` SET `mention` = 1 WHERE `id` = %d", intval($itemid));

					q("UPDATE `thread` SET `mention` = 1 WHERE `iid` = %d", intval($message["parent"]));
				}
			}
		}
	}
}

function create_tags_from_itemuri($itemuri, $uid) {
	$messages = q("SELECT `id` FROM `item` WHERE uri ='%s' AND uid=%d", dbesc($itemuri), intval($uid));

	if(count($messages)) {
		foreach ($messages as $message)
			create_tags_from_item($message["id"]);
	}
}

function update_items() {
	global $db;

        $messages = $db->q("SELECT `oid`,`item`.`guid`, `item`.`created`, `item`.`received` FROM `term` INNER JOIN `item` ON `item`.`id`=`term`.`oid` WHERE `term`.`otype` = 1 AND `term`.`guid` = ''", true);

        logger("fetched messages: ".count($messages));
        while ($message = $db->qfetch()) {
		q("UPDATE `term` SET `guid` = '%s', `created` = '%s', `received` = '%s' WHERE `otype` = %d AND `oid` = %d",
			dbesc($message["guid"]), dbesc($message["created"]), dbesc($message["received"]),
			intval(TERM_OBJ_POST), intval($message["oid"]));
	}

        $db->qclose();
}
?>
