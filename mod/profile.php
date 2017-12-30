<?php

use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\PConfig;
use Friendica\Core\System;
use Friendica\Database\DBM;
use Friendica\Model\Group;

require_once('include/contact_widgets.php');
require_once('include/redir.php');

function profile_init(App $a) {

	if(! x($a->page,'aside'))
		$a->page['aside'] = '';

	if($a->argc > 1)
		$which = htmlspecialchars($a->argv[1]);
	else {
		$r = q("select nickname from user where blocked = 0 and account_expired = 0 and account_removed = 0 and verified = 1 order by rand() limit 1");
		if (DBM::is_result($r)) {
			goaway(System::baseUrl() . '/profile/' . $r[0]['nickname']);
		}
		else {
			logger('profile error: mod_profile ' . $a->query_string, LOGGER_DEBUG);
			notice( t('Requested profile is not available.') . EOL );
			$a->error = 404;
			return;
		}
	}

	$profile = 0;
	if((local_user()) && ($a->argc > 2) && ($a->argv[2] === 'view')) {
		$which = $a->user['nickname'];
		$profile = htmlspecialchars($a->argv[1]);
	}
	else {
		auto_redir($a, $which);
	}

	profile_load($a,$which,$profile);

	$blocked = (((Config::get('system','block_public')) && (! local_user()) && (! remote_user())) ? true : false);
	$userblock = (($a->profile['hidewall'] && (! local_user()) && (! remote_user())) ? true : false);

	if((x($a->profile,'page-flags')) && ($a->profile['page-flags'] == PAGE_COMMUNITY)) {
		$a->page['htmlhead'] .= '<meta name="friendica.community" content="true" />';
	}
	if (x($a->profile,'openidserver')) {
		$a->page['htmlhead'] .= '<link rel="openid.server" href="' . $a->profile['openidserver'] . '" />' . "\r\n";
	}
	if (x($a->profile,'openid')) {
		$delegate = ((strstr($a->profile['openid'],'://')) ? $a->profile['openid'] : 'https://' . $a->profile['openid']);
		$a->page['htmlhead'] .= '<link rel="openid.delegate" href="' . $delegate . '" />' . "\r\n";
	}
	// site block
	if ((! $blocked) && (! $userblock)) {
		$keywords = ((x($a->profile,'pub_keywords')) ? $a->profile['pub_keywords'] : '');
		$keywords = str_replace(array('#',',',' ',',,'),array('',' ',',',','),$keywords);
		if(strlen($keywords))
			$a->page['htmlhead'] .= '<meta name="keywords" content="' . $keywords . '" />' . "\r\n" ;
	}

	$a->page['htmlhead'] .= '<meta name="dfrn-global-visibility" content="' . (($a->profile['net-publish']) ? 'true' : 'false') . '" />' . "\r\n" ;
	$a->page['htmlhead'] .= '<link rel="alternate" type="application/atom+xml" href="' . System::baseUrl() . '/dfrn_poll/' . $which .'" />' . "\r\n" ;
	$uri = urlencode('acct:' . $a->profile['nickname'] . '@' . $a->get_hostname() . (($a->path) ? '/' . $a->path : ''));
	$a->page['htmlhead'] .= '<link rel="lrdd" type="application/xrd+xml" href="' . System::baseUrl() . '/xrd/?uri=' . $uri . '" />' . "\r\n";
	header('Link: <' . System::baseUrl() . '/xrd/?uri=' . $uri . '>; rel="lrdd"; type="application/xrd+xml"', false);

	$dfrn_pages = array('request', 'confirm', 'notify', 'poll');
	foreach ($dfrn_pages as $dfrn) {
		$a->page['htmlhead'] .= "<link rel=\"dfrn-{$dfrn}\" href=\"".System::baseUrl()."/dfrn_{$dfrn}/{$which}\" />\r\n";
	}
	$a->page['htmlhead'] .= "<link rel=\"dfrn-poco\" href=\"".System::baseUrl()."/poco/{$which}\" />\r\n";

}


function profile_content(App $a, $update = 0) {

	$category = $datequery = $datequery2 = '';

	if ($a->argc > 2) {
		for ($x = 2; $x < $a->argc; $x ++) {
			if (is_a_date_arg($a->argv[$x])) {
				if ($datequery) {
					$datequery2 = escape_tags($a->argv[$x]);
				} else {
					$datequery = escape_tags($a->argv[$x]);
				}
			} else {
				$category = $a->argv[$x];
			}
		}
	}

	if (! x($category)) {
		$category = ((x($_GET,'category')) ? $_GET['category'] : '');
	}

	$hashtags = (x($_GET, 'tag') ? $_GET['tag'] : '');

	if (Config::get('system','block_public') && (! local_user()) && (! remote_user())) {
		return login();
	}

	require_once("include/bbcode.php");
	require_once('include/security.php');
	require_once('include/conversation.php');
	require_once('include/acl_selectors.php');
	require_once('include/items.php');

	$groups = array();

	$tab = 'posts';
	$o = '';

	if ($update) {
		// Ensure we've got a profile owner if updating.
		$a->profile['profile_uid'] = $update;
	} elseif ($a->profile['profile_uid'] == local_user()) {
		nav_set_selected('home');
	}

	$contact = null;
	$remote_contact = false;

	$contact_id = 0;

	if (is_array($_SESSION['remote'])) {
		foreach ($_SESSION['remote'] as $v) {
			if ($v['uid'] == $a->profile['profile_uid']) {
				$contact_id = $v['cid'];
				break;
			}
		}
	}

	if ($contact_id) {
		$groups = Group::getIdsByContactId($contact_id);
		$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($contact_id),
			intval($a->profile['profile_uid'])
		);
		if (DBM::is_result($r)) {
			$contact = $r[0];
			$remote_contact = true;
		}
	}

	if (! $remote_contact) {
		if (local_user()) {
			$contact_id = $_SESSION['cid'];
			$contact = $a->contact;
		}
	}

	$is_owner = ((local_user()) && (local_user() == $a->profile['profile_uid']) ? true : false);
	$last_updated_key = "profile:" . $a->profile['profile_uid'] . ":" . local_user() . ":" . remote_user();

	if ($a->profile['hidewall'] && (! $is_owner) && (! $remote_contact)) {
		notice( t('Access to this profile has been restricted.') . EOL);
		return;
	}

	if (! $update) {
		if (x($_GET,'tab')) {
			$tab = notags(trim($_GET['tab']));
		}

		$o.=profile_tabs($a, $is_owner, $a->profile['nickname']);

		if ($tab === 'profile') {
			$o .= advanced_profile($a);
			call_hooks('profile_advanced',$o);
			return $o;
		}

		$o .= common_friends_visitor_widget($a->profile['profile_uid']);

		if (x($_SESSION,'new_member') && $_SESSION['new_member'] && $is_owner) {
			$o .= '<a href="newmember" id="newmember-tips" style="font-size: 1.2em;"><b>' . t('Tips for New Members') . '</b></a>' . EOL;
		}

		$commpage = (($a->profile['page-flags'] == PAGE_COMMUNITY) ? true : false);
		$commvisitor = (($commpage && $remote_contact == true) ? true : false);

		$a->page['aside'] .= posted_date_widget(System::baseUrl(true) . '/profile/' . $a->profile['nickname'],$a->profile['profile_uid'],true);
		$a->page['aside'] .= categories_widget(System::baseUrl(true) . '/profile/' . $a->profile['nickname'],(x($category) ? xmlify($category) : ''));
		$a->page['aside'] .= tagcloud_wall_widget();

		if (can_write_wall($a,$a->profile['profile_uid'])) {

			$x = array(
				'is_owner' => $is_owner,
				'allow_location' => ((($is_owner || $commvisitor) && $a->profile['allow_location']) ? true : false),
				'default_location' => (($is_owner) ? $a->user['default-location'] : ''),
				'nickname' => $a->profile['nickname'],
				'lockstate' => (((is_array($a->user) && ((strlen($a->user['allow_cid'])) ||
						(strlen($a->user['allow_gid'])) || (strlen($a->user['deny_cid'])) ||
						(strlen($a->user['deny_gid']))))) ? 'lock' : 'unlock'),
				'acl' => (($is_owner) ? populate_acl($a->user, true) : ''),
				'bang' => '',
				'visitor' => (($is_owner || $commvisitor) ? 'block' : 'none'),
				'profile_uid' => $a->profile['profile_uid'],
				'acl_data' => ( $is_owner ? construct_acl_data($a, $a->user) : '' ), // For non-Javascript ACL selector
			);

			$o .= status_editor($a,$x);
		}
	}


	/**
	 * Get permissions SQL - if $remote_contact is true, our remote user has been pre-verified and we already have fetched his/her groups
	 */
	$sql_extra = item_permissions_sql($a->profile['profile_uid'],$remote_contact,$groups);


	if ($update) {
		$last_updated = (x($_SESSION['last_updated'], $last_updated_key) ? $_SESSION['last_updated'][$last_updated_key] : 0);

		// If the page user is the owner of the page we should query for unseen
		// items. Otherwise use a timestamp of the last succesful update request.
		if ($is_owner || !$last_updated) {
			$sql_extra4 = " AND `item`.`unseen`";
		} else {
			$gmupdate = gmdate("Y-m-d H:i:s", $last_updated);
			$sql_extra4 = " AND `item`.`received` > '" . $gmupdate . "'";
		}

		$r = q("SELECT distinct(parent) AS `item_id`, `item`.`network` AS `item_network`, `item`.`created`
			FROM `item` INNER JOIN `contact` ON `contact`.`id` = `item`.`contact-id`
			AND `contact`.`blocked` = 0 AND `contact`.`pending` = 0
			WHERE `item`.`uid` = %d AND `item`.`visible` = 1 AND
			(`item`.`deleted` = 0 OR item.verb = '" . ACTIVITY_LIKE ."'
			OR item.verb = '" . ACTIVITY_DISLIKE . "' OR item.verb = '" . ACTIVITY_ATTEND . "'
			OR item.verb = '" . ACTIVITY_ATTENDNO . "' OR item.verb = '" . ACTIVITY_ATTENDMAYBE . "')
			AND `item`.`moderated` = 0
			AND `item`.`wall` = 1
			$sql_extra4
			$sql_extra
			ORDER BY `item`.`created` DESC",
			intval($a->profile['profile_uid'])
		);

		if (!DBM::is_result($r)) {
			return '';
		}

	} else {
		$sql_post_table = "";

		if (x($category)) {
			$sql_post_table = sprintf("INNER JOIN (SELECT `oid` FROM `term` WHERE `term` = '%s' AND `otype` = %d AND `type` = %d AND `uid` = %d ORDER BY `tid` DESC) AS `term` ON `item`.`id` = `term`.`oid` ",
				dbesc(protect_sprintf($category)), intval(TERM_OBJ_POST), intval(TERM_CATEGORY), intval($a->profile['profile_uid']));
			//$sql_extra .= protect_sprintf(file_tag_file_query('item',$category,'category'));
		}

		if (x($hashtags)) {
			$sql_post_table .= sprintf("INNER JOIN (SELECT `oid` FROM `term` WHERE `term` = '%s' AND `otype` = %d AND `type` = %d AND `uid` = %d ORDER BY `tid` DESC) AS `term` ON `item`.`id` = `term`.`oid` ",
				dbesc(protect_sprintf($hashtags)), intval(TERM_OBJ_POST), intval(TERM_HASHTAG), intval($a->profile['profile_uid']));
		}

		if ($datequery) {
			$sql_extra2 .= protect_sprintf(sprintf(" AND `thread`.`created` <= '%s' ", dbesc(datetime_convert(date_default_timezone_get(),'',$datequery))));
		}
		if ($datequery2) {
			$sql_extra2 .= protect_sprintf(sprintf(" AND `thread`.`created` >= '%s' ", dbesc(datetime_convert(date_default_timezone_get(),'',$datequery2))));
		}

		// Belongs the profile page to a forum?
		// If not then we can improve the performance with an additional condition
		$r = q("SELECT `uid` FROM `user` WHERE `uid` = %d AND `page-flags` IN (%d, %d)",
			intval($a->profile['profile_uid']),
			intval(PAGE_COMMUNITY),
			intval(PAGE_PRVGROUP));

		if (!DBM::is_result($r)) {
			$sql_extra3 = sprintf(" AND `thread`.`contact-id` = %d ", intval(intval($a->profile['contact_id'])));
		}

		//  check if we serve a mobile device and get the user settings
		//  accordingly
		if ($a->is_mobile) {
			$itemspage_network = PConfig::get(local_user(),'system','itemspage_mobile_network');
			$itemspage_network = ((intval($itemspage_network)) ? $itemspage_network : 10);
		} else {
			$itemspage_network = PConfig::get(local_user(),'system','itemspage_network');
			$itemspage_network = ((intval($itemspage_network)) ? $itemspage_network : 20);
		}
		//  now that we have the user settings, see if the theme forces
		//  a maximum item number which is lower then the user choice
		if(($a->force_max_items > 0) && ($a->force_max_items < $itemspage_network))
			$itemspage_network = $a->force_max_items;

		$a->set_pager_itemspage($itemspage_network);

		$pager_sql = sprintf(" LIMIT %d, %d ",intval($a->pager['start']), intval($a->pager['itemspage']));

		$r = q("SELECT `thread`.`iid` AS `item_id`, `thread`.`network` AS `item_network`
			FROM `thread`
			STRAIGHT_JOIN `item` ON `item`.`id` = `thread`.`iid`
			$sql_post_table
			STRAIGHT_JOIN `contact` ON `contact`.`id` = `thread`.`contact-id`
				AND NOT `contact`.`blocked` AND NOT `contact`.`pending`
			WHERE `thread`.`uid` = %d AND `thread`.`visible`
				AND NOT `thread`.`deleted`
				AND NOT `thread`.`moderated`
				AND `thread`.`wall`
				$sql_extra3 $sql_extra $sql_extra2
			ORDER BY `thread`.`created` DESC $pager_sql",
			intval($a->profile['profile_uid'])
		);

	}

	$parents_arr = array();
	$parents_str = '';

	// Set a time stamp for this page. We will make use of it when we
	// search for new items (update routine)
	$_SESSION['last_updated'][$last_updated_key] = time();

	if (DBM::is_result($r)) {
		foreach($r as $rr)
			$parents_arr[] = $rr['item_id'];
		$parents_str = implode(', ', $parents_arr);

		$items = q(item_query()." AND `item`.`uid` = %d
			AND `item`.`parent` IN (%s)
			$sql_extra ",
			intval($a->profile['profile_uid']),
			dbesc($parents_str)
		);

		$items = conv_sort($items,'created');
	} else {
		$items = array();
	}

	if($is_owner && (! $update) && (! Config::get('theme','hide_eventlist'))) {
		$o .= get_birthdays();
		$o .= get_events();
	}


	if ($is_owner) {
		$unseen = dba::exists('item', array('wall' => true, 'unseen' => true, 'uid' => local_user()));
		if ($unseen) {
			$r = dba::update('item', array('unseen' => false),
					array('wall' => true, 'unseen' => true, 'uid' => local_user()));
		}
	}

	$o .= conversation($a, $items, 'profile', $update);

	if (!$update) {
		$o .= alt_pager($a, count($items));
	}

	return $o;
}
