<?php

/**
 * @file mod/network.php
 */

use Friendica\App;
use Friendica\Content\Feature;
use Friendica\Content\ForumManager;
use Friendica\Content\Nav;
use Friendica\Content\Widget;
use Friendica\Core\ACL;
use Friendica\Core\Addon;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\Group;
use Friendica\Model\Item;
use Friendica\Model\Profile;
use Friendica\Module\Login;
use Friendica\Util\DateTimeFormat;

require_once 'include/conversation.php';
require_once 'include/items.php';

function network_init(App $a)
{
	if (!local_user()) {
		notice(L10n::t('Permission denied.') . EOL);
		return;
	}

	$search = (x($_GET, 'search') ? escape_tags($_GET['search']) : '');

	if (($search != '') && !empty($_GET['submit'])) {
		goaway('search?search=' . urlencode($search));
	}

	if (x($_GET, 'save')) {
		$exists = DBA::exists('search', ['uid' => local_user(), 'term' => $search]);
		if (!$exists) {
			DBA::insert('search', ['uid' => local_user(), 'term' => $search]);
		}
	}
	if (x($_GET, 'remove')) {
		DBA::delete('search', ['uid' => local_user(), 'term' => $search]);
	}

	$is_a_date_query = false;

	$group_id = (($a->argc > 1 && is_numeric($a->argv[1])) ? intval($a->argv[1]) : 0);

	$cid = 0;
	if (x($_GET, 'cid') && intval($_GET['cid']) != 0) {
		$cid = $_GET['cid'];
		$_GET['nets'] = 'all';
		$group_id = 0;
	}

	if ($a->argc > 1) {
		for ($x = 1; $x < $a->argc; $x ++) {
			if (is_a_date_arg($a->argv[$x])) {
				$is_a_date_query = true;
				break;
			}
		}
	}

	// convert query string to array. remove friendica args
	$query_array = [];
	$query_string = str_replace($a->cmd . '?', '', $a->query_string);
	parse_str($query_string, $query_array);
	array_shift($query_array);

	// fetch last used network view and redirect if needed
	if (!$is_a_date_query) {
		$sel_nets = defaults($_GET, 'nets', false);
		$sel_tabs = network_query_get_sel_tab($a);
		$sel_groups = network_query_get_sel_group($a);
		$last_sel_tabs = PConfig::get(local_user(), 'network.view', 'tab.selected');

		$remember_tab = ($sel_tabs[0] === 'active' && is_array($last_sel_tabs) && $last_sel_tabs[0] !== 'active');

		$net_baseurl = '/network';
		$net_args = [];

		if ($sel_groups !== false) {
			$net_baseurl .= '/' . $sel_groups;
		}

		if ($remember_tab) {
			// redirect if current selected tab is '/network' and
			// last selected tab is _not_ '/network?f=&order=comment'.
			// and this isn't a date query

			$tab_baseurls = [
				'',     //all
				'',     //postord
				'',     //conv
				'/new', //new
				'',     //starred
				'',     //bookmarked
			];
			$tab_args = [
				'f=&order=comment', //all
				'f=&order=post',    //postord
				'f=&conv=1',        //conv
				'',                 //new
				'f=&star=1',        //starred
				'f=&bmark=1',       //bookmarked
			];

			$k = array_search('active', $last_sel_tabs);

			if ($k != 3) {
				$net_baseurl .= $tab_baseurls[$k];

				// parse out tab queries
				$dest_qa = [];
				$dest_qs = $tab_args[$k];
				parse_str($dest_qs, $dest_qa);
				$net_args = array_merge($net_args, $dest_qa);
			} else {
				$remember_tab = false;
			}
		}

		if ($sel_nets !== false) {
			$net_args['nets'] = $sel_nets;
		}

		if ($remember_tab) {
			$net_args = array_merge($query_array, $net_args);
			$net_queries = build_querystring($net_args);

			$redir_url = ($net_queries ? $net_baseurl . '?' . $net_queries : $net_baseurl);

			goaway(System::baseUrl() . $redir_url);
		}
	}

	// If nets is set to all, unset it
	if (x($_GET, 'nets') && $_GET['nets'] === 'all') {
		unset($_GET['nets']);
	}

	if (!x($a->page, 'aside')) {
		$a->page['aside'] = '';
	}

	$a->page['aside'] .= (Feature::isEnabled(local_user(), 'groups') ?
		Group::sidebarWidget('network/0', 'network', 'standard', $group_id) : '');
	$a->page['aside'] .= (Feature::isEnabled(local_user(), 'forumlist_widget') ? ForumManager::widget(local_user(), $cid) : '');
	$a->page['aside'] .= posted_date_widget('network', local_user(), false);
	$a->page['aside'] .= Widget::networks('network', (x($_GET, 'nets') ? $_GET['nets'] : ''));
	$a->page['aside'] .= saved_searches($search);
	$a->page['aside'] .= Widget::fileAs('network', (x($_GET, 'file') ? $_GET['file'] : ''));
}

function saved_searches($search)
{
	if (!Feature::isEnabled(local_user(), 'savedsearch')) {
		return '';
	}

	$a = get_app();

	$srchurl = '/network?f='
		. ((x($_GET, 'cid'))   ? '&cid='   . $_GET['cid']   : '')
		. ((x($_GET, 'star'))  ? '&star='  . $_GET['star']  : '')
		. ((x($_GET, 'bmark')) ? '&bmark=' . $_GET['bmark'] : '')
		. ((x($_GET, 'conv'))  ? '&conv='  . $_GET['conv']  : '')
		. ((x($_GET, 'nets'))  ? '&nets='  . $_GET['nets']  : '')
		. ((x($_GET, 'cmin'))  ? '&cmin='  . $_GET['cmin']  : '')
		. ((x($_GET, 'cmax'))  ? '&cmax='  . $_GET['cmax']  : '')
		. ((x($_GET, 'file'))  ? '&file='  . $_GET['file']  : '');
	;

	$o = '';

	$terms = DBA::select('search', ['id', 'term'], ['uid' => local_user()]);
	$saved = [];

	while ($rr = DBA::fetch($terms)) {
		$saved[] = [
			'id'          => $rr['id'],
			'term'        => $rr['term'],
			'encodedterm' => urlencode($rr['term']),
			'delete'      => L10n::t('Remove term'),
			'selected'    => ($search == $rr['term']),
		];
	}

	$tpl = get_markup_template('saved_searches_aside.tpl');
	$o = replace_macros($tpl, [
		'$title'     => L10n::t('Saved Searches'),
		'$add'       => L10n::t('add'),
		'$searchbox' => search($search, 'netsearch-box', $srchurl, true),
		'$saved'     => $saved,
	]);

	return $o;
}

/**
 * Return selected tab from query
 *
 * urls -> returns
 * 		'/network'					=> $no_active = 'active'
 * 		'/network?f=&order=comment'	=> $comment_active = 'active'
 * 		'/network?f=&order=post'	=> $postord_active = 'active'
 * 		'/network?f=&conv=1',		=> $conv_active = 'active'
 * 		'/network/new',				=> $new_active = 'active'
 * 		'/network?f=&star=1',		=> $starred_active = 'active'
 * 		'/network?f=&bmark=1',		=> $bookmarked_active = 'active'
 *
 * @return Array ($no_active, $comment_active, $postord_active, $conv_active, $new_active, $starred_active, $bookmarked_active);
 */
function network_query_get_sel_tab(App $a)
{
	$no_active = '';
	$starred_active = '';
	$new_active = '';
	$bookmarked_active = '';
	$all_active = '';
	$conv_active = '';
	$postord_active = '';

	if (($a->argc > 1 && $a->argv[1] === 'new') || ($a->argc > 2 && $a->argv[2] === 'new')) {
		$new_active = 'active';
	}

	if (x($_GET, 'star')) {
		$starred_active = 'active';
	}

	if (x($_GET, 'bmark')) {
		$bookmarked_active = 'active';
	}

	if (x($_GET, 'conv')) {
		$conv_active = 'active';
	}

	if (($new_active == '') && ($starred_active == '') && ($bookmarked_active == '') && ($conv_active == '')) {
		$no_active = 'active';
	}

	if ($no_active == 'active' && x($_GET, 'order')) {
		switch($_GET['order']) {
			case 'post'    : $postord_active = 'active'; $no_active=''; break;
			case 'comment' : $all_active     = 'active'; $no_active=''; break;
		}
	}

	return [$no_active, $all_active, $postord_active, $conv_active, $new_active, $starred_active, $bookmarked_active];
}

function network_query_get_sel_group(App $a)
{
	$group = false;

	if ($a->argc >= 2 && is_numeric($a->argv[1])) {
		$group = $a->argv[1];
	}

	return $group;
}

/**
 * @brief Sets the pager data and returns SQL
 *
 * @param App $a The global App
 * @param integer $update Used for the automatic reloading
 * @return string SQL with the appropriate LIMIT clause
 */
function networkPager($a, $update)
{
	if ($update) {
		// only setup pagination on initial page view
		return ' LIMIT 100';
	}

	//  check if we serve a mobile device and get the user settings
	//  accordingly
	if ($a->is_mobile) {
		$itemspage_network = PConfig::get(local_user(), 'system', 'itemspage_mobile_network');
		$itemspage_network = ((intval($itemspage_network)) ? $itemspage_network : 20);
	} else {
		$itemspage_network = PConfig::get(local_user(), 'system', 'itemspage_network');
		$itemspage_network = ((intval($itemspage_network)) ? $itemspage_network : 40);
	}

	//  now that we have the user settings, see if the theme forces
	//  a maximum item number which is lower then the user choice
	if (($a->force_max_items > 0) && ($a->force_max_items < $itemspage_network)) {
		$itemspage_network = $a->force_max_items;
	}

	$a->set_pager_itemspage($itemspage_network);

	return sprintf(" LIMIT %d, %d ", intval($a->pager['start']), intval($a->pager['itemspage']));
}

/**
 * @brief Sets items as seen
 *
 * @param array $condition The array with the SQL condition
 */
function networkSetSeen($condition)
{
	if (empty($condition)) {
		return;
	}

	$unseen = DBA::exists('item', $condition);

	if ($unseen) {
		$r = Item::update(['unseen' => false], $condition);
	}
}

/**
 * @brief Create the conversation HTML
 *
 * @param App $a The global App
 * @param array $items Items of the conversation
 * @param string $mode Display mode for the conversation
 * @param integer $update Used for the automatic reloading
 * @return string HTML of the conversation
 */
function networkConversation($a, $items, $mode, $update, $ordering = '')
{
	// Set this so that the conversation function can find out contact info for our wall-wall items
	$a->page_contact = $a->contact;

	$o = conversation($a, $items, $mode, $update, false, $ordering, local_user());

	if (!$update) {
		if (PConfig::get(local_user(), 'system', 'infinite_scroll')) {
			$o .= scroll_loader();
		} else {
			$o .= alt_pager($a, count($items));
		}
	}

	return $o;
}

function network_content(App $a, $update = 0, $parent = 0)
{
	if (!local_user()) {
		return Login::form();
	}

	/// @TODO Is this really necessary? $a is already available to hooks
	$arr = ['query' => $a->query_string];
	Addon::callHooks('network_content_init', $arr);

	$flat_mode = false;

	if ($a->argc > 1) {
		for ($x = 1; $x < $a->argc; $x ++) {
			if ($a->argv[$x] === 'new') {
				$flat_mode = true;
			}
		}
	}

	if (x($_GET, 'file')) {
		$flat_mode = true;
	}

	if ($flat_mode) {
		$o = networkFlatView($a, $update);
	} else {
		$o = networkThreadedView($a, $update, $parent);
	}

	return $o;
}

/**
 * @brief Get the network content in flat view
 *
 * @param App $a The global App
 * @param integer $update Used for the automatic reloading
 * @return string HTML of the network content in flat view
 */
function networkFlatView(App $a, $update = 0)
{
	// Rawmode is used for fetching new content at the end of the page
	$rawmode = (isset($_GET['mode']) && ($_GET['mode'] == 'raw'));

	if (isset($_GET['last_id'])) {
		$last_id = intval($_GET['last_id']);
	} else {
		$last_id = 0;
	}

	$o = '';

	$file = ((x($_GET, 'file')) ? $_GET['file'] : '');

	if (!$update && !$rawmode) {
		$tabs = network_tabs($a);
		$o .= $tabs;

		Nav::setSelected('network');

		$x = [
			'is_owner' => true,
			'allow_location' => $a->user['allow_location'],
			'default_location' => $a->user['default-location'],
			'nickname' => $a->user['nickname'],
			'lockstate' => (((is_array($a->user) &&
			((strlen($a->user['allow_cid'])) || (strlen($a->user['allow_gid'])) ||
			(strlen($a->user['deny_cid'])) || (strlen($a->user['deny_gid']))))) ? 'lock' : 'unlock'),
			'default_perms' => ACL::getDefaultUserPermissions($a->user),
			'acl' => ACL::getFullSelectorHTML($a->user, true),
			'bang' => '',
			'visitor' => 'block',
			'profile_uid' => local_user(),
			'content' => '',
		];

		$o .= status_editor($a, $x);

		if (!Config::get('theme', 'hide_eventlist')) {
			$o .= Profile::getBirthdays();
			$o .= Profile::getEventsReminderHTML();
		}
	}

	$pager_sql = networkPager($a, $update);

	if (strlen($file)) {
		$condition = ["`term` = ? AND `otype` = ? AND `type` = ? AND `uid` = ?",
			$file, TERM_OBJ_POST, TERM_FILE, local_user()];
		$params = ['order' => ['tid' => true], 'limit' => [$a->pager['start'], $a->pager['itemspage']]];
		$result = DBA::select('term', ['oid'], $condition);

		$posts = [];
		while ($term = DBA::fetch($result)) {
			$posts[] = $term['oid'];
		}
		DBA::close($terms);

		$condition = ['uid' => local_user(), 'id' => $posts];
	} else {
		$condition = ['uid' => local_user()];
	}

	$params = ['order' => ['id' => true], 'limit' => [$a->pager['start'], $a->pager['itemspage']]];
	$result = Item::selectForUser(local_user(), [], $condition, $params);
	$items = Item::inArray($result);

	$condition = ['unseen' => true, 'uid' => local_user()];
	networkSetSeen($condition);

	$o .= networkConversation($a, $items, 'network-new', $update);

	return $o;
}

/**
 * @brief Get the network content in threaded view
 *
 * @param App $a The global App
 * @param integer $update Used for the automatic reloading
 * @return string HTML of the network content in flat view
 */
function networkThreadedView(App $a, $update, $parent)
{
	// Rawmode is used for fetching new content at the end of the page
	$rawmode = (isset($_GET['mode']) AND ( $_GET['mode'] == 'raw'));

	if (isset($_GET['last_received']) && isset($_GET['last_commented']) && isset($_GET['last_created']) && isset($_GET['last_id'])) {
		$last_received = DateTimeFormat::utc($_GET['last_received']);
		$last_commented = DateTimeFormat::utc($_GET['last_commented']);
		$last_created = DateTimeFormat::utc($_GET['last_created']);
		$last_id = intval($_GET['last_id']);
	} else {
		$last_received = '';
		$last_commented = '';
		$last_created = '';
		$last_id = 0;
	}

	$datequery = $datequery2 = '';

	$gid = 0;

	if ($a->argc > 1) {
		for ($x = 1; $x < $a->argc; $x ++) {
			if (is_a_date_arg($a->argv[$x])) {
				if ($datequery) {
					$datequery2 = escape_tags($a->argv[$x]);
				} else {
					$datequery = escape_tags($a->argv[$x]);
					$_GET['order'] = 'post';
				}
			} elseif (intval($a->argv[$x])) {
				$gid = intval($a->argv[$x]);
				$def_acl = ['allow_gid' => '<' . $gid . '>'];
			}
		}
	}

	$o = '';

	$cid   = intval(defaults($_GET, 'cid'  , 0));
	$star  = intval(defaults($_GET, 'star' , 0));
	$bmark = intval(defaults($_GET, 'bmark', 0));
	$conv  = intval(defaults($_GET, 'conv' , 0));
	$order = notags(defaults($_GET, 'order', 'comment'));
	$nets  =        defaults($_GET, 'nets' , '');

	if ($cid) {
		$def_acl = ['allow_cid' => '<' . intval($cid) . '>'];
	}

	if ($nets) {
		$r = DBA::select('contact', ['id'], ['uid' => local_user(), 'network' => $nets], ['self' => false]);

		$str = '';
		while ($rr = DBA::fetch($r)) {
			$str .= '<' . $rr['id'] . '>';
		}
		if (strlen($str)) {
			$def_acl = ['allow_cid' => $str];
		}
	}

	if (!$update && !$rawmode) {
		$tabs = network_tabs($a);
		$o .= $tabs;

		if ($gid) {
			if (($t = Contact::getOStatusCountByGroupId($gid)) && !PConfig::get(local_user(), 'system', 'nowarn_insecure')) {
				notice(L10n::tt("Warning: This group contains %s member from a network that doesn't allow non public messages.",
						"Warning: This group contains %s members from a network that doesn't allow non public messages.",
						$t) . EOL);
				notice(L10n::t("Messages in this group won't be send to these receivers.").EOL);
			}
		}

		Nav::setSelected('network');

		$content = '';

		if ($cid) {
			// If $cid belongs to a communitity forum or a privat goup,.add a mention to the status editor
			$condition = ["`id` = ? AND (`forum` OR `prv`)", $cid];
			$contact = DBA::selectFirst('contact', ['addr', 'nick'], $condition);
			if (DBA::isResult($contact)) {
				if ($contact['addr'] != '') {
					$content = '!' . $contact['addr'];
				} else {
					$content = '!' . $contact['nick'] . '+' . $cid;
				}
			}
		}

		$x = [
			'is_owner' => true,
			'allow_location' => $a->user['allow_location'],
			'default_location' => $a->user['default-location'],
			'nickname' => $a->user['nickname'],
			'lockstate' => ((($gid) || ($cid) || ($nets) || (is_array($a->user) &&
			((strlen($a->user['allow_cid'])) || (strlen($a->user['allow_gid'])) ||
			(strlen($a->user['deny_cid'])) || (strlen($a->user['deny_gid']))))) ? 'lock' : 'unlock'),
			'default_perms' => ACL::getDefaultUserPermissions($a->user),
			'acl' => ACL::getFullSelectorHTML((($gid || $cid || $nets) ? $def_acl : $a->user), true),
			'bang' => (($gid || $cid || $nets) ? '!' : ''),
			'visitor' => 'block',
			'profile_uid' => local_user(),
			'content' => $content,
		];

		$o .= status_editor($a, $x);
	}

	// We don't have to deal with ACLs on this page. You're looking at everything
	// that belongs to you, hence you can see all of it. We will filter by group if
	// desired.

	$sql_post_table = '';
	$sql_options = ($star ? " AND `thread`.`starred` " : '');
	$sql_options .= ($bmark ? sprintf(" AND `thread`.`post-type` = %d ", Item::PT_PAGE) : '');
	$sql_extra = $sql_options;
	$sql_extra2 = '';
	$sql_extra3 = '';
	$sql_table = '`thread`';
	$sql_parent = '`iid`';
	$sql_order = '';

	if ($update) {
		$sql_table = '`item`';
		$sql_parent = '`parent`';
		$sql_post_table = " INNER JOIN `thread` ON `thread`.`iid` = `item`.`parent`";
	}

	$sql_nets = (($nets) ? sprintf(" AND $sql_table.`network` = '%s' ", DBA::escape($nets)) : '');
	$sql_tag_nets = (($nets) ? sprintf(" AND `item`.`network` = '%s' ", DBA::escape($nets)) : '');

	if ($gid) {
		$group = DBA::selectFirst('group', ['name'], ['id' => $gid, 'uid' => local_user()]);
		if (!DBA::isResult($group)) {
			if ($update) {
				killme();
			}
			notice(L10n::t('No such group') . EOL);
			goaway('network/0');
			// NOTREACHED
		}

		$contacts = Group::expand([$gid]);

		if ((is_array($contacts)) && count($contacts)) {
			$contact_str_self = '';

			$contact_str = implode(',', $contacts);
			$self = DBA::selectFirst('contact', ['id'], ['uid' => local_user(), 'self' => true]);
			if (DBA::isResult($self)) {
				$contact_str_self = $self['id'];
			}

			$sql_post_table .= " INNER JOIN `item` AS `temp1` ON `temp1`.`id` = " . $sql_table . "." . $sql_parent;
			$sql_extra3 .= " AND (`thread`.`contact-id` IN ($contact_str) ";
			$sql_extra3 .= " OR (`thread`.`contact-id` = '$contact_str_self' AND `temp1`.`allow_gid` LIKE '" . protect_sprintf('%<' . intval($gid) . '>%') . "' AND `temp1`.`private`))";
		} else {
			$sql_extra3 .= " AND false ";
			info(L10n::t('Group is empty'));
		}

		$o = replace_macros(get_markup_template('section_title.tpl'), [
			'$title' => L10n::t('Group: %s', $group['name'])
		]) . $o;
	} elseif ($cid) {
		$fields = ['id', 'name', 'network', 'writable', 'nurl',
			'forum', 'prv', 'contact-type', 'addr', 'thumb', 'location'];
		$condition = ["`id` = ? AND (NOT `blocked` OR `pending`)", $cid];
		$contact = DBA::selectFirst('contact', $fields, $condition);
		if (DBA::isResult($contact)) {
			$sql_extra = " AND " . $sql_table . ".`contact-id` = " . intval($cid);

			$entries[0] = [
				'id' => 'network',
				'name' => htmlentities($contact['name']),
				'itemurl' => defaults($contact, 'addr', $contact['nurl']),
				'thumb' => proxy_url($contact['thumb'], false, PROXY_SIZE_THUMB),
				'details' => $contact['location'],
			];

			$entries[0]['account_type'] = Contact::getAccountType($contact);

			$o = replace_macros(get_markup_template('viewcontact_template.tpl'), [
				'contacts' => $entries,
				'id' => 'network',
			]) . $o;

			if ($contact['network'] === NETWORK_OSTATUS && $contact['writable'] && !PConfig::get(local_user(),'system','nowarn_insecure')) {
				notice(L10n::t('Private messages to this person are at risk of public disclosure.') . EOL);
			}
		} else {
			notice(L10n::t('Invalid contact.') . EOL);
			goaway('network');
			// NOTREACHED
		}
	}

	if (!$gid && !$cid && !$update && !Config::get('theme', 'hide_eventlist')) {
		$o .= Profile::getBirthdays();
		$o .= Profile::getEventsReminderHTML();
	}

	if ($datequery) {
		$sql_extra3 .= protect_sprintf(sprintf(" AND $sql_table.created <= '%s' ",
				DBA::escape(DateTimeFormat::convert($datequery, 'UTC', date_default_timezone_get()))));
	}
	if ($datequery2) {
		$sql_extra3 .= protect_sprintf(sprintf(" AND $sql_table.created >= '%s' ",
				DBA::escape(DateTimeFormat::convert($datequery2, 'UTC', date_default_timezone_get()))));
	}

	if ($conv) {
		$sql_extra3 .= " AND $sql_table.`mention`";
	}

	// Normal conversation view
	if ($order === 'post') {
		$ordering = '`created`';
		$order_mode = 'created';
	} else {
		$ordering = '`commented`';
		$order_mode = 'commented';
	}

	$sql_order = "$sql_table.$ordering";

	if (x($_GET, 'offset')) {
		$sql_range = sprintf(" AND $sql_order <= '%s'", DBA::escape($_GET['offset']));
	} else {
		$sql_range = '';
	}

	$pager_sql = networkPager($a, $update);

	$last_date = '';

	switch ($order_mode) {
		case 'received':
			if ($last_received != '') {
				$last_date = $last_received;
				$sql_range .= sprintf(" AND $sql_table.`received` < '%s'", DBA::escape($last_received));
				$a->set_pager_page(1);
				$pager_sql = sprintf(" LIMIT %d, %d ", intval($a->pager['start']), intval($a->pager['itemspage']));
			}
			break;
		case 'commented':
			if ($last_commented != '') {
				$last_date = $last_commented;
				$sql_range .= sprintf(" AND $sql_table.`commented` < '%s'", DBA::escape($last_commented));
				$a->set_pager_page(1);
				$pager_sql = sprintf(" LIMIT %d, %d ", intval($a->pager['start']), intval($a->pager['itemspage']));
			}
			break;
		case 'created':
			if ($last_created != '') {
				$last_date = $last_created;
				$sql_range .= sprintf(" AND $sql_table.`created` < '%s'", DBA::escape($last_created));
				$a->set_pager_page(1);
				$pager_sql = sprintf(" LIMIT %d, %d ", intval($a->pager['start']), intval($a->pager['itemspage']));
			}
			break;
		case 'id':
			if (($last_id > 0) && ($sql_table == '`thread`')) {
				$sql_range .= sprintf(" AND $sql_table.`iid` < '%s'", DBA::escape($last_id));
				$a->set_pager_page(1);
				$pager_sql = sprintf(" LIMIT %d, %d ", intval($a->pager['start']), intval($a->pager['itemspage']));
			}
			break;
	}

	// Fetch a page full of parent items for this page
	if ($update) {
		if (!empty($parent)) {
			// Load only a single thread
			$sql_extra4 = "`item`.`id` = ".intval($parent);
		} else {
			// Load all unseen items
			$sql_extra4 = "`item`.`unseen`";
			if (Config::get("system", "like_no_comment")) {
				$sql_extra4 .= " AND `item`.`gravity` IN (" . GRAVITY_PARENT . "," . GRAVITY_COMMENT . ")";
			}
			if ($order === 'post') {
				// Only show toplevel posts when updating posts in this order mode
				$sql_extra4 .= " AND `item`.`id` = `item`.`parent`";
			}
		}

		$r = q("SELECT `item`.`parent-uri` AS `uri`, `item`.`parent` AS `item_id`, $sql_order AS `order_date`
			FROM `item` $sql_post_table
			STRAIGHT_JOIN `contact` ON `contact`.`id` = `item`.`contact-id`
				AND (NOT `contact`.`blocked` OR `contact`.`pending`)
				AND (`item`.`parent-uri` != `item`.`uri`
					OR `contact`.`uid` = `item`.`uid` AND `contact`.`self`
					OR `contact`.`rel` IN (%d, %d) AND NOT `contact`.`readonly`)
			LEFT JOIN `user-item` ON `user-item`.`iid` = `item`.`id` AND `user-item`.`uid` = %d
			WHERE `item`.`uid` = %d AND `item`.`visible` AND NOT `item`.`deleted`
			AND (`user-item`.`hidden` IS NULL OR NOT `user-item`.`hidden`)
			AND NOT `item`.`moderated` AND $sql_extra4
			$sql_extra3 $sql_extra $sql_range $sql_nets
			ORDER BY `order_date` DESC LIMIT 100",
			intval(CONTACT_IS_SHARING),
			intval(CONTACT_IS_FRIEND),
			intval(local_user()),
			intval(local_user())
		);
	} else {
		$r = q("SELECT `item`.`uri`, `thread`.`iid` AS `item_id`, $sql_order AS `order_date`
			FROM `thread` $sql_post_table
			STRAIGHT_JOIN `contact` ON `contact`.`id` = `thread`.`contact-id`
				AND (NOT `contact`.`blocked` OR `contact`.`pending`)
			STRAIGHT_JOIN `item` ON `item`.`id` = `thread`.`iid`
				AND (`item`.`parent-uri` != `item`.`uri`
					OR `contact`.`uid` = `item`.`uid` AND `contact`.`self`
					OR `contact`.`rel` IN (%d, %d) AND NOT `contact`.`readonly`)
			LEFT JOIN `user-item` ON `user-item`.`iid` = `item`.`id` AND `user-item`.`uid` = %d
			WHERE `thread`.`uid` = %d AND `thread`.`visible` AND NOT `thread`.`deleted`
			AND NOT `thread`.`moderated`
			AND (`user-item`.`hidden` IS NULL OR NOT `user-item`.`hidden`)
			$sql_extra2 $sql_extra3 $sql_range $sql_extra $sql_nets
			ORDER BY `order_date` DESC $pager_sql",
			intval(CONTACT_IS_SHARING),
			intval(CONTACT_IS_FRIEND),
			intval(local_user()),
			intval(local_user())
		);
	}

	// Only show it when unfiltered (no groups, no networks, ...)
	if (in_array($nets, ['', NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS]) && (strlen($sql_extra . $sql_extra2 . $sql_extra3) == 0)) {
		if (DBA::isResult($r)) {
			$top_limit = current($r)['order_date'];
			$bottom_limit = end($r)['order_date'];
			if (empty($_SESSION['network_last_top_limit']) || ($_SESSION['network_last_top_limit'] < $top_limit)) {
				$_SESSION['network_last_top_limit'] = $top_limit;
			}
		} else {
			$top_limit = $bottom_limit = DateTimeFormat::utcNow();
		}

		// When checking for updates we need to fetch from the newest date to the newest date before
		// Only do this, when the last stored date isn't too long ago (10 times the update interval)
		$browser_update = PConfig::get(local_user(), 'system', 'update_interval', 40000) / 1000;

		if (($browser_update > 0) && $update && !empty($_SESSION['network_last_date']) &&
			(($bottom_limit < $_SESSION['network_last_date']) || ($top_limit == $bottom_limit)) &&
			((time() - $_SESSION['network_last_date_timestamp']) < ($browser_update * 10))) {
			$bottom_limit = $_SESSION['network_last_date'];
		}
		$_SESSION['network_last_date'] = defaults($_SESSION, 'network_last_top_limit', $top_limit);
		$_SESSION['network_last_date_timestamp'] = time();

		if ($last_date > $top_limit) {
			$top_limit = $last_date;
		} elseif ($a->pager['page'] == 1) {
			// Highest possible top limit when we are on the first page
			$top_limit = DateTimeFormat::utcNow();
		}

		$items = DBA::p("SELECT `item`.`parent-uri` AS `uri`, 0 AS `item_id`, `item`.$ordering AS `order_date`, `author`.`url` AS `author-link` FROM `item`
			STRAIGHT_JOIN (SELECT `oid` FROM `term` WHERE `term` IN
				(SELECT SUBSTR(`term`, 2) FROM `search` WHERE `uid` = ? AND `term` LIKE '#%') AND `otype` = ? AND `type` = ? AND `uid` = 0) AS `term`
			ON `item`.`id` = `term`.`oid`
			STRAIGHT_JOIN `contact` AS `author` ON `author`.`id` = `item`.`author-id`
			WHERE `item`.`uid` = 0 AND `item`.$ordering < ? AND `item`.$ordering > ?
				AND NOT `author`.`hidden` AND NOT `author`.`blocked`" . $sql_tag_nets,
			local_user(), TERM_OBJ_POST, TERM_HASHTAG,
			$top_limit, $bottom_limit);

		$data = DBA::toArray($items);

		if (count($data) > 0) {
			$tag_top_limit = current($data)['order_date'];
			if ($_SESSION['network_last_date'] < $tag_top_limit) {
				$_SESSION['network_last_date'] = $tag_top_limit;
			}

			logger('Tagged items: ' . count($data) . ' - ' . $bottom_limit . ' - ' . $top_limit . ' - ' . local_user().' - '.(int)$update);
			$s = [];
			foreach ($r as $item) {
				$s[$item['uri']] = $item;
			}
			foreach ($data as $item) {
				// Don't show hash tag posts from blocked or ignored contacts
				$condition = ["`nurl` = ? AND `uid` = ? AND (`blocked` OR `readonly`)",
					normalise_link($item['author-link']), local_user()];
				if (!DBA::exists('contact', $condition)) {
					$s[$item['uri']] = $item;
				}
			}
			$r = $s;
		}
	}

	$parents_str = '';
	$date_offset = '';

	$items = $r;

	if (DBA::isResult($items)) {
		$parents_arr = [];

		foreach ($items as $item) {
			if ($date_offset < $item['order_date']) {
				$date_offset = $item['order_date'];
			}
			if (!in_array($item['item_id'], $parents_arr) && ($item['item_id'] > 0)) {
				$parents_arr[] = $item['item_id'];
			}
		}
		$parents_str = implode(', ', $parents_arr);
	}

	if (x($_GET, 'offset')) {
		$date_offset = $_GET['offset'];
	}

	$a->page_offset = $date_offset;

	// We aren't going to try and figure out at the item, group, and page
	// level which items you've seen and which you haven't. If you're looking
	// at the top level network page just mark everything seen.

	if (!$gid && !$cid && !$star) {
		$condition = ['unseen' => true, 'uid' => local_user()];
		networkSetSeen($condition);
	} elseif ($parents_str) {
		$condition = ["`uid` = ? AND `unseen` AND `parent` IN (" . DBA::escape($parents_str) . ")", local_user()];
		networkSetSeen($condition);
	}


	$mode = 'network';
	$o .= networkConversation($a, $items, $mode, $update, $ordering);

	return $o;
}

/**
 * @brief Get the network tabs menu
 *
 * @param App $a The global App
 * @return string Html of the networktab
 */
function network_tabs(App $a)
{
	// item filter tabs
	/// @TODO fix this logic, reduce duplication
	/// $a->page['content'] .= '<div class="tabs-wrapper">';
	list($no_active, $all_active, $postord_active, $conv_active, $new_active, $starred_active, $bookmarked_active) = network_query_get_sel_tab($a);

	// if no tabs are selected, defaults to comments
	if ($no_active == 'active') {
		$all_active = 'active';
	}

	$cmd = $a->cmd;

	// tabs
	$tabs = [
		[
			'label'	=> L10n::t('Commented Order'),
			'url'	=> str_replace('/new', '', $cmd) . '?f=&order=comment' . ((x($_GET,'cid')) ? '&cid=' . $_GET['cid'] : ''),
			'sel'	=> $all_active,
			'title'	=> L10n::t('Sort by Comment Date'),
			'id'	=> 'commented-order-tab',
			'accesskey' => 'e',
		],
		[
			'label'	=> L10n::t('Posted Order'),
			'url'	=> str_replace('/new', '', $cmd) . '?f=&order=post' . ((x($_GET,'cid')) ? '&cid=' . $_GET['cid'] : ''),
			'sel'	=> $postord_active,
			'title'	=> L10n::t('Sort by Post Date'),
			'id'	=> 'posted-order-tab',
			'accesskey' => 't',
		],
	];

	if (Feature::isEnabled(local_user(), 'personal_tab')) {
		$tabs[] = [
			'label'	=> L10n::t('Personal'),
			'url'	=> str_replace('/new', '', $cmd) . ((x($_GET,'cid')) ? '/?f=&cid=' . $_GET['cid'] : '/?f=') . '&conv=1',
			'sel'	=> $conv_active,
			'title'	=> L10n::t('Posts that mention or involve you'),
			'id'	=> 'personal-tab',
			'accesskey' => 'r',
		];
	}

	if (Feature::isEnabled(local_user(), 'new_tab')) {
		$tabs[] = [
			'label'	=> L10n::t('New'),
			'url'	=> 'network/new' . ((x($_GET,'cid')) ? '/?f=&cid=' . $_GET['cid'] : ''),
			'sel'	=> $new_active,
			'title'	=> L10n::t('Activity Stream - by date'),
			'id'	=> 'activitiy-by-date-tab',
			'accesskey' => 'w',
		];
	}

	if (Feature::isEnabled(local_user(), 'link_tab')) {
		$tabs[] = [
			'label'	=> L10n::t('Shared Links'),
			'url'	=> str_replace('/new', '', $cmd) . ((x($_GET,'cid')) ? '/?f=&cid=' . $_GET['cid'] : '/?f=') . '&bmark=1',
			'sel'	=> $bookmarked_active,
			'title'	=> L10n::t('Interesting Links'),
			'id'	=> 'shared-links-tab',
			'accesskey' => 'b',
		];
	}

	if (Feature::isEnabled(local_user(), 'star_posts')) {
		$tabs[] = [
			'label'	=> L10n::t('Starred'),
			'url'	=> str_replace('/new', '', $cmd) . ((x($_GET,'cid')) ? '/?f=&cid=' . $_GET['cid'] : '/?f=') . '&star=1',
			'sel'	=> $starred_active,
			'title'	=> L10n::t('Favourite Posts'),
			'id'	=> 'starred-posts-tab',
			'accesskey' => 'm',
		];
	}

	// save selected tab, but only if not in file mode
	if (!x($_GET, 'file')) {
		PConfig::set(local_user(), 'network.view', 'tab.selected', [
			$all_active, $postord_active, $conv_active, $new_active, $starred_active, $bookmarked_active
		]);
	}

	$arr = ['tabs' => $tabs];
	Addon::callHooks('network_tabs', $arr);

	$tpl = get_markup_template('common_tabs.tpl');

	return replace_macros($tpl, ['$tabs' => $arr['tabs']]);

	// --- end item filter tabs
}
