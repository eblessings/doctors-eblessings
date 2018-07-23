<?php
/**
 * @file mod/display.php
 */

use Friendica\App;
use Friendica\Content\Text\BBCode;
use Friendica\Content\Text\HTML;
use Friendica\Core\ACL;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\Group;
use Friendica\Model\Item;
use Friendica\Model\Profile;
use Friendica\Protocol\DFRN;

function display_init(App $a)
{
	if (Config::get('system', 'block_public') && !local_user() && !remote_user()) {
		return;
	}

	$nick = (($a->argc > 1) ? $a->argv[1] : '');
	$profiledata = [];

	if ($a->argc == 3) {
		if (substr($a->argv[2], -5) == '.atom') {
			$item_id = substr($a->argv[2], 0, -5);
			displayShowFeed($item_id, false);
		}
	}

	if ($a->argc == 4) {
		if ($a->argv[3] == 'conversation.atom') {
			$item_id = $a->argv[2];
			displayShowFeed($item_id, true);
		}
	}

	$item = null;

	$fields = ['id', 'parent', 'author-id', 'body', 'uid'];

	// If there is only one parameter, then check if this parameter could be a guid
	if ($a->argc == 2) {
		$nick = "";

		// Does the local user have this item?
		if (local_user()) {
			$item = Item::selectFirstForUser(local_user(), $fields, ['guid' => $a->argv[1], 'uid' => local_user()]);
			if (DBA::isResult($item)) {
				$nick = $a->user["nickname"];
			}
		}

		// Is it an item with uid=0?
		if (!DBA::isResult($item)) {
			$item = Item::selectFirstForUser(local_user(), $fields, ['guid' => $a->argv[1], 'private' => [0, 2], 'uid' => 0]);
		}
	} elseif (($a->argc == 3) && ($nick == 'feed-item')) {
		$item = Item::selectFirstForUser(local_user(), $fields, ['id' => $a->argv[2], 'private' => [0, 2], 'uid' => 0]);
	}

	if (!DBA::isResult($item)) {
		$a->error = 404;
		notice(L10n::t('Item not found.') . EOL);
		return;
	}

	if (!empty($_SERVER['HTTP_ACCEPT']) && strstr($_SERVER['HTTP_ACCEPT'], 'application/atom+xml')) {
		logger('Directly serving XML for id '.$item["id"], LOGGER_DEBUG);
		displayShowFeed($item["id"], false);
	}

	if ($item["id"] != $item["parent"]) {
		$item = Item::selectFirstForUser(local_user(), $fields, ['id' => $item["parent"]]);
	}

	$profiledata = display_fetchauthor($a, $item);

	if (strstr(normalise_link($profiledata["url"]), normalise_link(System::baseUrl()))) {
		$nickname = str_replace(normalise_link(System::baseUrl())."/profile/", "", normalise_link($profiledata["url"]));

		if (($nickname != $a->user["nickname"])) {
			$profile = DBA::fetchFirst("SELECT `profile`.`uid` AS `profile_uid`, `profile`.* , `contact`.`avatar-date` AS picdate, `user`.* FROM `profile`
				INNER JOIN `contact` on `contact`.`uid` = `profile`.`uid` INNER JOIN `user` ON `profile`.`uid` = `user`.`uid`
				WHERE `user`.`nickname` = ? AND `profile`.`is-default` AND `contact`.`self` LIMIT 1",
				$nickname
			);
			if (DBA::isResult($profile)) {
				$profiledata = $profile;
			}
			$profiledata["network"] = NETWORK_DFRN;
		} else {
			$profiledata = [];
		}
	}

	Profile::load($a, $nick, 0, $profiledata);
}

function display_fetchauthor($a, $item)
{
	$author = DBA::selectFirst('contact', ['name', 'nick', 'photo', 'network', 'url'], ['id' => $item['author-id']]);

	$profiledata = [];
	$profiledata['uid'] = -1;
	$profiledata['nickname'] = $author['nick'];
	$profiledata['name'] = $author['name'];
	$profiledata['picdate'] = '';
	$profiledata['photo'] = $author['photo'];
	$profiledata['url'] = $author['url'];
	$profiledata['network'] = $author['network'];

	// Check for a repeated message
	$skip = false;
	$body = trim($item["body"]);

	// Skip if it isn't a pure repeated messages
	// Does it start with a share?
	if (!$skip && strpos($body, "[share") > 0) {
		$skip = true;
	}
	// Does it end with a share?
	if (!$skip && (strlen($body) > (strrpos($body, "[/share]") + 8))) {
		$skip = true;
	}
	if (!$skip) {
		$attributes = preg_replace("/\[share(.*?)\]\s?(.*?)\s?\[\/share\]\s?/ism","$1",$body);
		// Skip if there is no shared message in there
		if ($body == $attributes) {
			$skip = true;
		}
	}

	if (!$skip) {
		$author = "";
		preg_match("/author='(.*?)'/ism", $attributes, $matches);
		if (!empty($matches[1])) {
			$profiledata["name"] = html_entity_decode($matches[1],ENT_QUOTES,'UTF-8');
		}
		preg_match('/author="(.*?)"/ism', $attributes, $matches);
		if (!empty($matches[1])) {
			$profiledata["name"] = html_entity_decode($matches[1],ENT_QUOTES,'UTF-8');
		}
		$profile = "";
		preg_match("/profile='(.*?)'/ism", $attributes, $matches);
		if (!empty($matches[1])) {
			$profiledata["url"] = $matches[1];
		}
		preg_match('/profile="(.*?)"/ism', $attributes, $matches);
		if (!empty($matches[1])) {
			$profiledata["url"] = $matches[1];
		}
		$avatar = "";
		preg_match("/avatar='(.*?)'/ism", $attributes, $matches);
		if (!empty($matches[1])) {
			$profiledata["photo"] = $matches[1];
		}
		preg_match('/avatar="(.*?)"/ism', $attributes, $matches);
		if (!empty($matches[1])) {
			$profiledata["photo"] = $matches[1];
		}
		$profiledata["nickname"] = $profiledata["name"];
		$profiledata["network"] = Protocol::matchByProfileUrl($profiledata["url"]);

		$profiledata["address"] = "";
		$profiledata["about"] = "";
	}

	$profiledata = Contact::getDetailsByURL($profiledata["url"], local_user(), $profiledata);

	$profiledata["photo"] = System::removedBaseUrl($profiledata["photo"]);

	if (local_user()) {
		if (in_array($profiledata["network"], [NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS])) {
			$profiledata["remoteconnect"] = System::baseUrl()."/follow?url=".urlencode($profiledata["url"]);
		}
	} elseif ($profiledata["network"] == NETWORK_DFRN) {
		$connect = str_replace("/profile/", "/dfrn_request/", $profiledata["url"]);
		$profiledata["remoteconnect"] = $connect;
	}

	return($profiledata);
}

function display_content(App $a, $update = false, $update_uid = 0)
{
	if (Config::get('system','block_public') && !local_user() && !remote_user()) {
		notice(L10n::t('Public access denied.') . EOL);
		return;
	}

	require_once 'include/security.php';
	require_once 'include/conversation.php';

	$o = '';

	if ($update) {
		$item_id = $_REQUEST['item_id'];
		$item = Item::selectFirst(['uid', 'parent', 'parent-uri'], ['id' => $item_id]);
		if ($item['uid'] != 0) {
			$a->profile = ['uid' => intval($item['uid']), 'profile_uid' => intval($item['uid'])];
		} else {
			$a->profile = ['uid' => intval($update_uid), 'profile_uid' => intval($update_uid)];
		}
		$item_parent = $item['parent'];
		$item_parent_uri = $item['parent-uri'];
	} else {
		$item_id = (($a->argc > 2) ? $a->argv[2] : 0);
		$item_parent = $item_id;

		if ($a->argc == 2) {
			$item_parent = 0;
			$fields = ['id', 'parent', 'parent-uri'];

			if (local_user()) {
				$condition = ['guid' => $a->argv[1], 'uid' => local_user()];
				$item = Item::selectFirstForUser(local_user(), $fields, $condition);
				if (DBA::isResult($item)) {
					$item_id = $item["id"];
					$item_parent = $item["parent"];
					$item_parent_uri = $item['parent-uri'];
				}
			}

			if ($item_parent == 0) {
				$condition = ['private' => [0, 2], 'guid' => $a->argv[1], 'uid' => 0];
				$item = Item::selectFirstForUser(local_user(), $fields, $condition);
				if (DBA::isResult($item)) {
					$item_id = $item["id"];
					$item_parent = $item["parent"];
					$item_parent_uri = $item['parent-uri'];
				}
			}
		}
	}

	if (!$item_id) {
		$a->error = 404;
		notice(L10n::t('Item not found.').EOL);
		return;
	}

	// We are displaying an "alternate" link if that post was public. See issue 2864
	$is_public = DBA::exists('item', ['id' => $item_id, 'private' => [0, 2]]);
	if ($is_public) {
		// For the atom feed the nickname doesn't matter at all, we only need the item id.
		$alternate = System::baseUrl().'/display/feed-item/'.$item_id.'.atom';
		$conversation = System::baseUrl().'/display/feed-item/'.$item_parent.'/conversation.atom';
	} else {
		$alternate = '';
		$conversation = '';
	}

	$a->page['htmlhead'] .= replace_macros(get_markup_template('display-head.tpl'),
				['$alternate' => $alternate,
					'$conversation' => $conversation]);

	$groups = [];

	$contact = null;
	$is_remote_contact = false;

	$contact_id = 0;

	if (x($_SESSION, 'remote') && is_array($_SESSION['remote'])) {
		foreach ($_SESSION['remote'] as $v) {
			if ($v['uid'] == $a->profile['uid']) {
				$contact_id = $v['cid'];
				break;
			}
		}
	}

	if ($contact_id) {
		$groups = Group::getIdsByContactId($contact_id);
		$remote_contact = DBA::selectFirst('contact', [], ['id' => $contact_id, 'uid' => $a->profile['uid']]);
		if (DBA::isResult($remote_contact)) {
			$contact = $remote_contact;
			$is_remote_contact = true;
		}
	}

	if (!$is_remote_contact) {
		if (local_user()) {
			$contact_id = $_SESSION['cid'];
			$contact = $a->contact;
		}
	}

	$page_contact = DBA::selectFirst('contact', [], ['self' => true, 'uid' => $a->profile['uid']]);
	if (DBA::isResult($page_contact)) {
		$a->page_contact = $page_contact;
	}
	$is_owner = (local_user() && (in_array($a->profile['profile_uid'], [local_user(), 0])) ? true : false);

	if (x($a->profile, 'hidewall') && !$is_owner && !$is_remote_contact) {
		notice(L10n::t('Access to this profile has been restricted.') . EOL);
		return;
	}

	// We need the editor here to be able to reshare an item.
	if ($is_owner) {
		$x = [
			'is_owner' => true,
			'allow_location' => $a->user['allow_location'],
			'default_location' => $a->user['default-location'],
			'nickname' => $a->user['nickname'],
			'lockstate' => (is_array($a->user) && (strlen($a->user['allow_cid']) || strlen($a->user['allow_gid']) || strlen($a->user['deny_cid']) || strlen($a->user['deny_gid'])) ? 'lock' : 'unlock'),
			'acl' => ACL::getFullSelectorHTML($a->user, true),
			'bang' => '',
			'visitor' => 'block',
			'profile_uid' => local_user(),
		];
		$o .= status_editor($a, $x, 0, true);
	}

	$sql_extra = item_permissions_sql($a->profile['uid'], $is_remote_contact, $groups);

	if (local_user() && (local_user() == $a->profile['uid'])) {
		$condition = ['parent-uri' => $item_parent_uri, 'uid' => local_user(), 'unseen' => true];
		$unseen = DBA::exists('item', $condition);
	} else {
		$unseen = false;
	}

	if ($update && !$unseen) {
		return '';
	}

	$condition = ["`item`.`parent-uri` = (SELECT `parent-uri` FROM `item` WHERE `id` = ?)
		AND `item`.`uid` IN (0, ?) " . $sql_extra, $item_id, local_user()];
	$params = ['order' => ['uid', 'parent' => true, 'gravity', 'id']];
	$items_obj = Item::selectForUser(local_user(), [], $condition, $params);

	if (!DBA::isResult($items_obj)) {
		notice(L10n::t('Item not found.') . EOL);
		return $o;
	}

	if ($unseen) {
		$condition = ['parent-uri' => $item_parent_uri, 'uid' => local_user(), 'unseen' => true];
		Item::update(['unseen' => false], $condition);
	}

	$items = Item::inArray($items_obj);
	$conversation_items = conv_sort($items, "`commented`");

	if (!$update) {
		$o .= "<script> var netargs = '?f=&item_id=" . $item_id . "'; </script>";
	}
	$o .= conversation($a, $conversation_items, 'display', $update_uid, false, 'commented', local_user());

	// Preparing the meta header
	$description = trim(HTML::toPlaintext(BBCode::convert($items[0]["body"], false), 0, true));
	$title = trim(HTML::toPlaintext(BBCode::convert($items[0]["title"], false), 0, true));
	$author_name = $items[0]["author-name"];

	$image = $a->remove_baseurl($items[0]["author-avatar"]);

	if ($title == "") {
		$title = $author_name;
	}

	// Limit the description to 160 characters
	if (strlen($description) > 160) {
		$description = substr($description, 0, 157) . '...';
	}

	$description = htmlspecialchars($description, ENT_COMPAT, 'UTF-8', true); // allow double encoding here
	$title = htmlspecialchars($title, ENT_COMPAT, 'UTF-8', true); // allow double encoding here
	$author_name = htmlspecialchars($author_name, ENT_COMPAT, 'UTF-8', true); // allow double encoding here

	//<meta name="keywords" content="">
	$a->page['htmlhead'] .= '<meta name="author" content="'.$author_name.'" />'."\n";
	$a->page['htmlhead'] .= '<meta name="title" content="'.$title.'" />'."\n";
	$a->page['htmlhead'] .= '<meta name="fulltitle" content="'.$title.'" />'."\n";
	$a->page['htmlhead'] .= '<meta name="description" content="'.$description.'" />'."\n";

	// Schema.org microdata
	$a->page['htmlhead'] .= '<meta itemprop="name" content="'.$title.'" />'."\n";
	$a->page['htmlhead'] .= '<meta itemprop="description" content="'.$description.'" />'."\n";
	$a->page['htmlhead'] .= '<meta itemprop="image" content="'.$image.'" />'."\n";
	$a->page['htmlhead'] .= '<meta itemprop="author" content="'.$author_name.'" />'."\n";

	// Twitter cards
	$a->page['htmlhead'] .= '<meta name="twitter:card" content="summary" />'."\n";
	$a->page['htmlhead'] .= '<meta name="twitter:title" content="'.$title.'" />'."\n";
	$a->page['htmlhead'] .= '<meta name="twitter:description" content="'.$description.'" />'."\n";
	$a->page['htmlhead'] .= '<meta name="twitter:image" content="'.System::baseUrl().'/'.$image.'" />'."\n";
	$a->page['htmlhead'] .= '<meta name="twitter:url" content="'.$items[0]["plink"].'" />'."\n";

	// Dublin Core
	$a->page['htmlhead'] .= '<meta name="DC.title" content="'.$title.'" />'."\n";
	$a->page['htmlhead'] .= '<meta name="DC.description" content="'.$description.'" />'."\n";

	// Open Graph
	$a->page['htmlhead'] .= '<meta property="og:type" content="website" />'."\n";
	$a->page['htmlhead'] .= '<meta property="og:title" content="'.$title.'" />'."\n";
	$a->page['htmlhead'] .= '<meta property="og:image" content="'.System::baseUrl().'/'.$image.'" />'."\n";
	$a->page['htmlhead'] .= '<meta property="og:url" content="'.$items[0]["plink"].'" />'."\n";
	$a->page['htmlhead'] .= '<meta property="og:description" content="'.$description.'" />'."\n";
	$a->page['htmlhead'] .= '<meta name="og:article:author" content="'.$author_name.'" />'."\n";
	// article:tag

	return $o;
}

function displayShowFeed($item_id, $conversation)
{
	$xml = DFRN::itemFeed($item_id, $conversation);
	if ($xml == '') {
		System::httpExit(500);
	}
	header("Content-type: application/atom+xml");
	echo $xml;
	killme();
}
