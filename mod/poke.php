<?php
/**
 * Poke, prod, finger, or otherwise do unspeakable things to somebody - who must be a connection in your address book
 * This function can be invoked with the required arguments (verb and cid and private and possibly parent) silently via ajax or
 * other web request. You must be logged in and connected to a profile.
 * If the required arguments aren't present, we'll display a simple form to choose a recipient and a verb.
 * parent is a special argument which let's you attach this activity as a comment to an existing conversation, which
 * may have started with somebody else poking (etc.) somebody, but this isn't necessary. This can be used in the more pokes
 * addon version to have entire conversations where Alice poked Bob, Bob fingered Alice, Alice hugged Bob, etc.
 *
 * private creates a private conversation with the recipient. Otherwise your profile's default post privacy is used.
 *
 * @file mod/poke.php
 */

use Friendica\App;
use Friendica\Core\Addon;
use Friendica\Core\L10n;
use Friendica\Core\System;
use Friendica\Core\Worker;
use Friendica\Database\DBM;
use Friendica\Model\Item;

require_once 'include/security.php';
require_once 'include/items.php';

function poke_init(App $a) {

	if (!local_user()) {
		return;
	}

	$uid = local_user();
	$verb = notags(trim($_GET['verb']));

	if (!$verb) {
		return;
	}

	$verbs = get_poke_verbs();

	if (!array_key_exists($verb, $verbs)) {
		return;
	}

	$activity = ACTIVITY_POKE . '#' . urlencode($verbs[$verb][0]);

	$contact_id = intval($_GET['cid']);
	if (!$contact_id) {
		return;
	}

	$parent = (x($_GET,'parent') ? intval($_GET['parent']) : 0);


	logger('poke: verb ' . $verb . ' contact ' . $contact_id, LOGGER_DEBUG);


	$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
		intval($contact_id),
		intval($uid)
	);

	if (!DBM::is_result($r)) {
		logger('poke: no contact ' . $contact_id);
		return;
	}

	$target = $r[0];

	if ($parent) {
		$fields = ['uri', 'private', 'allow_cid', 'allow_gid', 'deny_cid', 'deny_gid'];
		$condition = ['id' => $parent, 'parent' => $parent, 'uid' => $uid];
		$item = Item::selectFirst($fields, $condition);

		if (DBM::is_result($item)) {
			$parent_uri = $item['uri'];
			$private    = $item['private'];
			$allow_cid  = $item['allow_cid'];
			$allow_gid  = $item['allow_gid'];
			$deny_cid   = $item['deny_cid'];
			$deny_gid   = $item['deny_gid'];
		}
	} else {
		$private = (x($_GET,'private') ? intval($_GET['private']) : 0);

		$allow_cid     = ($private ? '<' . $target['id']. '>' : $a->user['allow_cid']);
		$allow_gid     = ($private ? '' : $a->user['allow_gid']);
		$deny_cid      = ($private ? '' : $a->user['deny_cid']);
		$deny_gid      = ($private ? '' : $a->user['deny_gid']);
	}

	$poster = $a->contact;

	$uri = Item::newURI($uid);

	$arr = [];

	$arr['guid']          = System::createGUID(32);
	$arr['uid']           = $uid;
	$arr['uri']           = $uri;
	$arr['parent-uri']    = ($parent_uri ? $parent_uri : $uri);
	$arr['type']          = 'activity';
	$arr['wall']          = 1;
	$arr['contact-id']    = $poster['id'];
	$arr['owner-name']    = $poster['name'];
	$arr['owner-link']    = $poster['url'];
	$arr['owner-avatar']  = $poster['thumb'];
	$arr['author-name']   = $poster['name'];
	$arr['author-link']   = $poster['url'];
	$arr['author-avatar'] = $poster['thumb'];
	$arr['title']         = '';
	$arr['allow_cid']     = $allow_cid;
	$arr['allow_gid']     = $allow_gid;
	$arr['deny_cid']      = $deny_cid;
	$arr['deny_gid']      = $deny_gid;
	$arr['visible']       = 1;
	$arr['verb']          = $activity;
	$arr['private']       = $private;
	$arr['object-type']   = ACTIVITY_OBJ_PERSON;

	$arr['origin']        = 1;
	$arr['body']          = '[url=' . $poster['url'] . ']' . $poster['name'] . '[/url]' . ' ' . L10n::t($verbs[$verb][0]) . ' ' . '[url=' . $target['url'] . ']' . $target['name'] . '[/url]';

	$arr['object'] = '<object><type>' . ACTIVITY_OBJ_PERSON . '</type><title>' . $target['name'] . '</title><id>' . System::baseUrl() . '/contact/' . $target['id'] . '</id>';
	$arr['object'] .= '<link>' . xmlify('<link rel="alternate" type="text/html" href="' . $target['url'] . '" />' . "\n");

	$arr['object'] .= xmlify('<link rel="photo" type="image/jpeg" href="' . $target['photo'] . '" />' . "\n");
	$arr['object'] .= '</link></object>' . "\n";

	$item_id = Item::insert($arr);
	if ($item_id) {
		Worker::add(PRIORITY_HIGH, "Notifier", "tag", $item_id);
	}

	Addon::callHooks('post_local_end', $arr);

	return;
}



function poke_content(App $a) {

	if (!local_user()) {
		notice(L10n::t('Permission denied.') . EOL);
		return;
	}

	$name = '';
	$id = '';

	if (intval($_GET['c'])) {
		$r = q("SELECT `id`,`name` FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($_GET['c']),
			intval(local_user())
		);
		if (DBM::is_result($r)) {
			$name = $item['name'];
			$id = $item['id'];
		}
	}


	$base = System::baseUrl();

	$head_tpl = get_markup_template('poke_head.tpl');
	$a->page['htmlhead'] .= replace_macros($head_tpl,[
		'$baseurl' => System::baseUrl(true),
		'$base' => $base
	]);


	$parent = (x($_GET,'parent') ? intval($_GET['parent']) : '0');


	$verbs = get_poke_verbs();

	$shortlist = [];
	foreach ($verbs as $k => $v) {
		if ($v[1] !== 'NOTRANSLATION') {
			$shortlist[] = [$k, $v[1]];
		}
	}

	$tpl = get_markup_template('poke_content.tpl');

	$o = replace_macros($tpl,[
		'$title' => L10n::t('Poke/Prod'),
		'$desc' => L10n::t('poke, prod or do other things to somebody'),
		'$clabel' => L10n::t('Recipient'),
		'$choice' => L10n::t('Choose what you wish to do to recipient'),
		'$verbs' => $shortlist,
		'$parent' => $parent,
		'$prv_desc' => L10n::t('Make this post private'),
		'$submit' => L10n::t('Submit'),
		'$name' => $name,
		'$id' => $id
	]);

	return $o;
}
