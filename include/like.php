<?php
require_once("include/diaspora.php");

/**
 * @brief add/remove activity to an item
 *
 * Toggle activities as like,dislike,attend of an item
 *
 * @param string $item_id
 * @param string $verb
 * 		Activity verb. One of
 * 			like, unlike, dislike, undislike, attendyes, unattendyes,
 * 			attendno, unattendno, attendmaybe, unattendmaybe
 * @hook 'post_local_end'
 * 		array $arr
 * 			'post_id' => ID of posted item
 */
function do_like($item_id, $verb) {
	$a = get_app();

	if(! local_user() && ! remote_user()) {
		return false;
	}

	switch($verb) {
		case 'like':
		case 'unlike':
			$activity = ACTIVITY_LIKE;
			break;
		case 'dislike':
		case 'undislike':
			$activity = ACTIVITY_DISLIKE;
			break;
		case 'attendyes':
		case 'unattendyes':
			$activity = ACTIVITY_ATTEND;
			break;
		case 'attendno':
		case 'unattendno':
			$activity = ACTIVITY_ATTENDNO;
			break;
		case 'attendmaybe':
		case 'unattendmaybe':
			$activity = ACTIVITY_ATTENDMAYBE;
			break;
		default:
			return false;
			break;
	}


	logger('like: verb ' . $verb . ' item ' . $item_id);


	$r = q("SELECT * FROM `item` WHERE `id` = '%s' OR `uri` = '%s' LIMIT 1",
		dbesc($item_id),
		dbesc($item_id)
	);

	if(! $item_id || (! dbm::is_result($r))) {
		logger('like: no item ' . $item_id);
		return false;
	}

	$item = $r[0];

	$owner_uid = $item['uid'];

	if (! can_write_wall($a,$owner_uid)) {
		return false;
	}

	$remote_owner = null;

	if(! $item['wall']) {
		// The top level post may have been written by somebody on another system
		$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($item['contact-id']),
			intval($item['uid'])
		);
		if (! dbm::is_result($r)) {
			return false;
		}
		if (! $r[0]['self']) {
			$remote_owner = $r[0];
		}
	}

	// this represents the post owner on this system.

	$r = q("SELECT `contact`.*, `user`.`nickname` FROM `contact` LEFT JOIN `user` ON `contact`.`uid` = `user`.`uid`
		WHERE `contact`.`self` = 1 AND `contact`.`uid` = %d LIMIT 1",
		intval($owner_uid)
	);
	if (dbm::is_result($r)) {
		$owner = $r[0];
	}

	if (! $owner) {
		logger('like: no owner');
		return false;
	}

	if (! $remote_owner) {
		$remote_owner = $owner;
	}

	// This represents the person posting

	if ((local_user()) && (local_user() == $owner_uid)) {
		$contact = $owner;
	}
	else {
		$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($_SESSION['visitor_id']),
			intval($owner_uid)
		);
		if (dbm::is_result($r))
			$contact = $r[0];
	}
	if (! $contact) {
		return false;
	}


	$verbs = " '".dbesc($activity)."' ";

	// event participation are essentially radio toggles. If you make a subsequent choice,
	// we need to eradicate your first choice.
	if ($activity === ACTIVITY_ATTEND || $activity === ACTIVITY_ATTENDNO || $activity === ACTIVITY_ATTENDMAYBE) {
		$verbs = " '" . dbesc(ACTIVITY_ATTEND) . "','" . dbesc(ACTIVITY_ATTENDNO) . "','" . dbesc(ACTIVITY_ATTENDMAYBE) . "' ";
	}

	$r = q("SELECT `id`, `guid` FROM `item` WHERE `verb` IN ( $verbs ) AND `deleted` = 0
		AND `contact-id` = %d AND `uid` = %d
		AND (`parent` = '%s' OR `parent-uri` = '%s' OR `thr-parent` = '%s') LIMIT 1",
		intval($contact['id']), intval($owner_uid),
		dbesc($item_id), dbesc($item_id), dbesc($item['uri'])
	);

	if (dbm::is_result($r)) {
		$like_item = $r[0];

		// Already voted, undo it
		$r = q("UPDATE `item` SET `deleted` = 1, `unseen` = 1, `changed` = '%s' WHERE `id` = %d",
			dbesc(datetime_convert()),
			intval($like_item['id'])
		);


		// Clean up the Diaspora signatures for this like
		// Go ahead and do it even if Diaspora support is disabled. We still want to clean up
		// if it had been enabled in the past
		$r = q("DELETE FROM `sign` WHERE `iid` = %d",
			intval($like_item['id'])
		);

		$like_item_id = $like_item['id'];
		proc_run(PRIORITY_HIGH, "include/notifier.php", "like", $like_item_id);

		return true;
	}

	$uri = item_new_uri($a->get_hostname(),$owner_uid);

	$post_type = (($item['resource-id']) ? t('photo') : t('status'));
	if ($item['object-type'] === ACTIVITY_OBJ_EVENT) {
		$post_type = t('event');
	$objtype = (($item['resource-id']) ? ACTIVITY_OBJ_IMAGE : ACTIVITY_OBJ_NOTE );
	$link = xmlify('<link rel="alternate" type="text/html" href="' . App::get_baseurl() . '/display/' . $owner['nickname'] . '/' . $item['id'] . '" />' . "\n") ;
	$body = $item['body'];

	$obj = <<< EOT

	<object>
		<type>$objtype</type>
		<local>1</local>
		<id>{$item['uri']}</id>
		<link>$link</link>
		<title></title>
		<content>$body</content>
	</object>
EOT;
	if ($verb === 'like') {
		$bodyverb = t('%1$s likes %2$s\'s %3$s');
	}
	if ($verb === 'dislike') {
		$bodyverb = t('%1$s doesn\'t like %2$s\'s %3$s');
	}
	if ($verb === 'attendyes') {
		$bodyverb = t('%1$s is attending %2$s\'s %3$s');
	}
	if ($verb === 'attendno') {
		$bodyverb = t('%1$s is not attending %2$s\'s %3$s');
	}
	if ($verb === 'attendmaybe') {
		$bodyverb = t('%1$s may attend %2$s\'s %3$s');
	}

	if (! isset($bodyverb)) {
		return false;
	}

	$ulink = '[url=' . $contact['url'] . ']' . $contact['name'] . '[/url]';
	$alink = '[url=' . $item['author-link'] . ']' . $item['author-name'] . '[/url]';
	$plink = '[url=' . App::get_baseurl() . '/display/' . $owner['nickname'] . '/' . $item['id'] . ']' . $post_type . '[/url]';

	/// @TODO Or rewrite this to multi-line initialization of the array?
	$arr = array();

	$arr['guid'] = get_guid(32);
	$arr['uri'] = $uri;
	$arr['uid'] = $owner_uid;
	$arr['contact-id'] = $contact['id'];
	$arr['type'] = 'activity';
	$arr['wall'] = $item['wall'];
	$arr['origin'] = 1;
	$arr['gravity'] = GRAVITY_LIKE;
	$arr['parent'] = $item['id'];
	$arr['parent-uri'] = $item['uri'];
	$arr['thr-parent'] = $item['uri'];
	$arr['owner-name'] = $remote_owner['name'];
	$arr['owner-link'] = $remote_owner['url'];
	$arr['owner-avatar'] = $remote_owner['thumb'];
	$arr['author-name'] = $contact['name'];
	$arr['author-link'] = $contact['url'];
	$arr['author-avatar'] = $contact['thumb'];
	$arr['body'] =  sprintf( $bodyverb, $ulink, $alink, $plink );
	$arr['verb'] = $activity;
	$arr['object-type'] = $objtype;
	$arr['object'] = $obj;
	$arr['allow_cid'] = $item['allow_cid'];
	$arr['allow_gid'] = $item['allow_gid'];
	$arr['deny_cid'] = $item['deny_cid'];
	$arr['deny_gid'] = $item['deny_gid'];
	$arr['visible'] = 1;
	$arr['unseen'] = 1;
	$arr['last-child'] = 0;

	$post_id = item_store($arr);

	if (! $item['visible']) {
		$r = q("UPDATE `item` SET `visible` = 1 WHERE `id` = %d AND `uid` = %d",
			intval($item['id']),
			intval($owner_uid)
		);
	}


	// Save the author information for the like in case we need to relay to Diaspora
	diaspora::store_like_signature($contact, $post_id);

	$arr['id'] = $post_id;

	call_hooks('post_local_end', $arr);

	proc_run(PRIORITY_HIGH, "include/notifier.php", "like", $post_id);

	return true;
}
