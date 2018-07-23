<?php
/**
 * @file mod/subthread.php
 */
use Friendica\App;
use Friendica\Core\Addon;
use Friendica\Core\L10n;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Model\Item;

require_once 'include/security.php';
require_once 'include/items.php';

function subthread_content(App $a) {

	if (!local_user() && !remote_user()) {
		return;
	}

	$activity = ACTIVITY_FOLLOW;

	$item_id = (($a->argc > 1) ? notags(trim($a->argv[1])) : 0);

	$condition = ["`parent` = ? OR `parent-uri` = ? AND `parent` = `id`", $item_id, $item_id];
	$item = Item::selectFirst([], $condition);

	if (empty($item_id) || !DBA::is_result($item)) {
		logger('subthread: no item ' . $item_id);
		return;
	}

	$owner_uid = $item['uid'];

	if (!can_write_wall($owner_uid)) {
		return;
	}

	$remote_owner = null;

	if (!$item['wall']) {
		// The top level post may have been written by somebody on another system
		$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($item['contact-id']),
			intval($item['uid'])
		);
		if (!DBA::is_result($r)) {
			return;
		}
		if (!$r[0]['self']) {
			$remote_owner = $r[0];
		}
	}

	$owner = null;
	// this represents the post owner on this system.

	$r = q("SELECT `contact`.*, `user`.`nickname` FROM `contact` LEFT JOIN `user` ON `contact`.`uid` = `user`.`uid`
		WHERE `contact`.`self` = 1 AND `contact`.`uid` = %d LIMIT 1",
		intval($owner_uid)
	);

	if (DBA::is_result($r)) {
		$owner = $r[0];
	}

	if (!$owner) {
		logger('like: no owner');
		return;
	}

	if (!$remote_owner) {
		$remote_owner = $owner;
	}

	$contact = null;
	// This represents the person posting

	if (local_user() && (local_user() == $owner_uid)) {
		$contact = $owner;
	} else {
		$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($_SESSION['visitor_id']),
			intval($owner_uid)
		);

		if (DBA::is_result($r)) {
			$contact = $r[0];
		}
	}
	if (!$contact) {
		return;
	}

	$uri = Item::newURI($owner_uid);

	$post_type = (($item['resource-id']) ? L10n::t('photo') : L10n::t('status'));
	$objtype = (($item['resource-id']) ? ACTIVITY_OBJ_IMAGE : ACTIVITY_OBJ_NOTE );
	$link = xmlify('<link rel="alternate" type="text/html" href="' . System::baseUrl() . '/display/' . $owner['nickname'] . '/' . $item['id'] . '" />' . "\n") ;
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
	$bodyverb = L10n::t('%1$s is following %2$s\'s %3$s');

	if (!isset($bodyverb)) {
		return;
	}

	$arr = [];

	$arr['guid'] = System::createGUID(32);
	$arr['uri'] = $uri;
	$arr['uid'] = $owner_uid;
	$arr['contact-id'] = $contact['id'];
	$arr['wall'] = $item['wall'];
	$arr['origin'] = 1;
	$arr['gravity'] = GRAVITY_ACTIVITY;
	$arr['parent'] = $item['id'];
	$arr['parent-uri'] = $item['uri'];
	$arr['thr-parent'] = $item['uri'];
	$arr['owner-name'] = $remote_owner['name'];
	$arr['owner-link'] = $remote_owner['url'];
	$arr['owner-avatar'] = $remote_owner['thumb'];
	$arr['author-name'] = $contact['name'];
	$arr['author-link'] = $contact['url'];
	$arr['author-avatar'] = $contact['thumb'];

	$ulink = '[url=' . $contact['url'] . ']' . $contact['name'] . '[/url]';
	$alink = '[url=' . $item['author-link'] . ']' . $item['author-name'] . '[/url]';
	$plink = '[url=' . System::baseUrl() . '/display/' . $owner['nickname'] . '/' . $item['id'] . ']' . $post_type . '[/url]';
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

	$post_id = Item::insert($arr);

	if (!$item['visible']) {
		Item::update(['visible' => true], ['id' => $item['id']]);
	}

	$arr['id'] = $post_id;

	Addon::callHooks('post_local_end', $arr);

	killme();

}
