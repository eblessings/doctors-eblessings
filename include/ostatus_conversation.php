<?php
/*require_once("boot.php");
if(@is_null($a)) {
        $a = new App;
}

if(is_null($db)) {
        @include(".htconfig.php");
        require_once("dba.php");
        $db = new dba($db_host, $db_user, $db_pass, $db_data);
        unset($db_host, $db_user, $db_pass, $db_data);
};*/

function complete_conversation($itemid, $conversation_url) {
	global $a;

	require_once('include/html2bbcode.php');
	require_once('include/items.php');

	//logger('complete_conversation: completing conversation url '.$conversation_url.' for id '.$itemid);

	$messages = q("SELECT `uid`, `parent` FROM `item` WHERE `id` = %d LIMIT 1", intval($itemid));
	if (!$messages)
		return;
	$message = $messages[0];

	// Get the parent
	$parents = q("SELECT `id`, `uri`, `contact-id`, `type`, `verb`, `visible` FROM `item` WHERE `uid` = %d AND `id` = %d LIMIT 1",
			intval($message["uid"]), intval($message["parent"]));
	if (!$parents)
		return;
	$parent = $parents[0];

	// Store conversation url if not done before
	$conversation = q("SELECT `url` FROM `term` WHERE `uid` = %d AND `oid` = %d AND `otype` = %d AND `type` = %d",
		intval($message["uid"]), intval($itemid), intval(TERM_OBJ_POST), intval(TERM_CONVERSATION));

	if (!$conversation) {
		$r = q("INSERT INTO `term` (`uid`, `oid`, `otype`, `type`, `term`, `url`) VALUES (%d, %d, %d, %d, '%s', '%s')",
			intval($message["uid"]), intval($itemid), intval(TERM_OBJ_POST), intval(TERM_CONVERSATION), dbesc(datetime_convert()), dbesc($conversation_url));
		logger('complete_conversation: Storing conversation url '.$conversation_url.' for id '.$itemid);
	}

	$conv = str_replace("/conversation/", "/api/statusnet/conversation/", $conversation_url).".as";

	logger('complete_conversation: fetching conversation url '.$conv.' for '.$itemid);
	$conv_as = fetch_url($conv);

	if ($conv_as) {
		$conv_as = str_replace(',"statusnet:notice_info":', ',"statusnet_notice_info":', $conv_as);
		$conv_as = json_decode($conv_as);

		$first_id = "";
		$items = array_reverse($conv_as->items);

		foreach ($items as $single_conv) {
			//print_r($single_conv);

			if ($first_id == "") {
				$first_id = $single_conv->id;

				$new_parents = q("SELECT `id`, `uri`, `contact-id`, `type`, `verb`, `visible` FROM `item` WHERE `uid` = %d AND `uri` = '%s' LIMIT 1",
					intval($message["uid"]), dbesc($first_id));
				if ($new_parents AND ($itemid != $parent["id"])) {
					$parent = $new_parents[0];
					logger('complete_conversation: adopting new parent '.$parent["id"].' for '.$itemid);
				}
			}

			if (isset($single_conv->context->inReplyTo->id))
				$parent_uri = $single_conv->context->inReplyTo->id;
			else
				$parent_uri = $parent["uri"];

			$message_exists = q("SELECT `id` FROM `item` WHERE `uid` = %d AND `uri` = '%s' LIMIT 1",
						intval($message["uid"]), dbesc($single_conv->id));
			if ($message_exists) {
				$existing_message = $message_exists[0];
				$r = q("UPDATE `item` SET `parent` = %d, `parent-uri` = '%s', `thr-parent` = '%s' WHERE `id` = %d LIMIT 1",
					intval($parent["id"]),
					dbesc($parent["uri"]),
					dbesc($parent_uri),
					intval($existing_message["id"]));
				continue;
			}

			$arr = array();
			$arr["uri"] = $single_conv->id;
			$arr["uid"] = $message["uid"];
			$arr["contact-id"] = $parent["contact-id"]; // To-Do
			$arr["parent"] = $parent["id"];
			$arr["parent-uri"] = $parent["uri"];
			$arr["thr-parent"] = $parent_uri;
			$arr["created"] = $single_conv->published;
			$arr["edited"] = $single_conv->published;
			$arr["owner-name"] = $single_conv->actor->contact->displayName;
			//$arr["owner-name"] = $single_conv->actor->contact->preferredUsername;
			$arr["owner-link"] = $single_conv->actor->id;
			$arr["owner-avatar"] = $single_conv->actor->image->url;
			$arr["author-name"] = $single_conv->actor->contact->displayName;
			//$arr["author-name"] = $single_conv->actor->contact->preferredUsername;
			$arr["author-link"] = $single_conv->actor->id;
			$arr["author-avatar"] = $single_conv->actor->image->url;
			$arr["body"] = html2bbcode($single_conv->content);
			$arr["app"] = strip_tags($single_conv->statusnet_notice_info->source);
			if ($arr["app"] == "")
				$arr["app"] = $single_conv->provider->displayName;
			$arr["verb"] = $parent["verb"];
			$arr["visible"] = $parent["visible"];
			$arr["location"] = $single_conv->location->displayName;
			$arr["coord"] = trim($single_conv->location->lat." ".$single_conv->location->lon);

			if ($arr["location"] == "")
				unset($arr["location"]);

			if ($arr["coord"] == "")
				unset($arr["coord"]);

			$newitem = item_store($arr);

			// If the newly created item is the top item then change the parent settings of the thread
			if ($newitem AND ($arr["uri"] == $first_id)) {
				logger('complete_conversation: changing parents to parent '.$newitem.' old parent: '.$parent["id"].' new uri: '.$arr["uri"]);
				$r = q("UPDATE `item` SET `parent` = %d, `parent-uri` = '%s' WHERE `parent` = %d",
					intval($newitem),
					dbesc($arr["uri"]),
					intval($parent["id"]));
				logger('complete_conversation: done changing parents to parent '.$newitem);
			}
			//print_r($arr);
		}
	}
}
/*
$id = 282481;
$conversation = "http://identi.ca/conversation/98268580";

complete_conversation($id, $conversation);
*/
?>
