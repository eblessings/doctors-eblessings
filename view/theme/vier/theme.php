<?php
/**
 * Name: Vier
 * Version: 1.2
 * Author: Fabio <http://kirgroup.com/profile/fabrixxm>
 * Author: Ike <http://pirati.ca/profile/heluecht>
 * Author: Beanow <https://fc.oscp.info/profile/beanow>
 * Maintainer: Ike <http://pirati.ca/profile/heluecht>
 * Description: "Vier" is a very compact and modern theme. It uses the font awesome font library: http://fortawesome.github.com/Font-Awesome/
 */

use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\PConfig;
use Friendica\Core\System;
use Friendica\Database\DBM;
use Friendica\Model\GlobalContact;

require_once "include/plugin.php";
require_once "include/socgraph.php";
require_once "mod/proxy.php";

function vier_init(App $a) {

	$a->theme_events_in_profile = false;

	$a->set_template_engine('smarty3');

	if ($a->argv[0].$a->argv[1] === "profile".$a->user['nickname'] || $a->argv[0] === "network" && local_user()) {
		vier_community_info();

		$a->page['htmlhead'] .= "<link rel='stylesheet' type='text/css' href='view/theme/vier/wide.css' media='screen and (min-width: 1300px)'/>\n";
	}

	if ($a->is_mobile || $a->is_tablet) {
		$a->page['htmlhead'] .= '<meta name=viewport content="width=device-width, initial-scale=1">'."\n";
		$a->page['htmlhead'] .= '<link rel="stylesheet" type="text/css" href="view/theme/vier/mobile.css" media="screen"/>'."\n";
	}
	/// @todo deactivated since it doesn't work with desktop browsers at the moment
	//$a->page['htmlhead'] .= '<link rel="stylesheet" type="text/css" href="view/theme/vier/mobile.css" media="screen and (max-width: 1000px)"/>'."\n";

	$a->page['htmlhead'] .= <<< EOT
<link rel='stylesheet' type='text/css' href='view/theme/vier/narrow.css' media='screen and (max-width: 1100px)' />
<script type="text/javascript">

function insertFormatting(BBcode, id) {
	var tmpStr = $("#comment-edit-text-" + id).val();
	if (tmpStr == "") {
		$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		openMenu("comment-edit-submit-wrapper-" + id);
	}

	textarea = document.getElementById("comment-edit-text-" +id);
	if (document.selection) {
		textarea.focus();
		selected = document.selection.createRange();
		selected.text = "["+BBcode+"]" + selected.text + "[/"+BBcode+"]";
	} else if (textarea.selectionStart || textarea.selectionStart == "0") {
		var start = textarea.selectionStart;
		var end = textarea.selectionEnd;
		textarea.value = textarea.value.substring(0, start) + "["+BBcode+"]" + textarea.value.substring(start, end) + "[/"+BBcode+"]" + textarea.value.substring(end, textarea.value.length);
	}

	return true;
}

function showThread(id) {
	$("#collapsed-comments-" + id).show()
	$("#collapsed-comments-" + id + " .collapsed-comments").show()
}
function hideThread(id) {
	$("#collapsed-comments-" + id).hide()
	$("#collapsed-comments-" + id + " .collapsed-comments").hide()
}

function cmtBbOpen(id) {
	$("#comment-edit-bb-" + id).show();
}
function cmtBbClose(id) {
	$("#comment-edit-bb-" + id).hide();
}
</script>
EOT;

	if ($a->is_mobile || $a->is_tablet){
		$a->page['htmlhead'] .= <<< EOT
<script>
	$(document).ready(function() {
		$(".mobile-aside-toggle a").click(function(e){
			e.preventDefault();
			$("aside").toggleClass("show");
		});
		$(".tabs").click(function(e){
			$(this).toggleClass("show");
		});
	});
</script>
EOT;
	}

	// Hide the left menu bar
	/// @TODO maybe move this static array out where it should belong?
	if (($a->page['aside'] == "") && in_array($a->argv[0], array("community", "events", "help", "manage", "notifications",
			"probe", "webfinger", "login", "invite", "credits"))) {
		$a->page['htmlhead'] .= "<link rel='stylesheet' href='view/theme/vier/hide.css' />";
	}
}

function get_vier_config($key, $default = false, $admin = false) {
	if (local_user() && !$admin) {
		$result = PConfig::get(local_user(), "vier", $key);
		if ($result !== false) {
			return $result;
		}
	}

	$result = Config::get("vier", $key);
	if ($result !== false) {
		return $result;
	}

	return $default;
}

function vier_community_info() {
	$a = get_app();

	$show_pages      = get_vier_config("show_pages", 1);
	$show_profiles   = get_vier_config("show_profiles", 1);
	$show_helpers    = get_vier_config("show_helpers", 1);
	$show_services   = get_vier_config("show_services", 1);
	$show_friends    = get_vier_config("show_friends", 1);
	$show_lastusers  = get_vier_config("show_lastusers", 1);

	// get_baseurl
	$url = System::baseUrl($ssl_state);
	$aside['$url'] = $url;

	// comunity_profiles
	if ($show_profiles) {
		$r = GlobalContact::suggestionQuery(local_user(), 0, 9);

		$tpl = get_markup_template('ch_directory_item.tpl');
		if (DBM::is_result($r)) {

			$aside['$comunity_profiles_title'] = t('Community Profiles');
			$aside['$comunity_profiles_items'] = array();

			foreach ($r as $rr) {
				$entry = replace_macros($tpl,array(
					'$id' => $rr['id'],
					//'$profile_link' => zrl($rr['url']),
					'$profile_link' => 'follow/?url='.urlencode($rr['url']),
					'$photo' => proxy_url($rr['photo'], false, PROXY_SIZE_MICRO),
					'$alt_text' => $rr['name'],
				));
				$aside['$comunity_profiles_items'][] = $entry;
			}
		}
	}

	// last 9 users
	if ($show_lastusers) {
		$publish = (Config::get('system', 'publish_all') ? '' : " AND `publish` = 1 ");
		$order = " ORDER BY `register_date` DESC ";

		$tpl = get_markup_template('ch_directory_item.tpl');

		$r = q("SELECT `profile`.*, `profile`.`uid` AS `profile_uid`, `user`.`nickname`
				FROM `profile` LEFT JOIN `user` ON `user`.`uid` = `profile`.`uid`
				WHERE `is-default` = 1 $publish AND `user`.`blocked` = 0 $order LIMIT %d , %d ",
				0, 9);

		if (DBM::is_result($r)) {

			$aside['$lastusers_title'] = t('Last users');
			$aside['$lastusers_items'] = array();

			foreach ($r as $rr) {
				$profile_link = 'profile/' . ((strlen($rr['nickname'])) ? $rr['nickname'] : $rr['profile_uid']);
				$entry = replace_macros($tpl,array(
					'$id' => $rr['id'],
					'$profile_link' => $profile_link,
					'$photo' => $a->remove_baseurl($rr['thumb']),
					'$alt_text' => $rr['name']));
				$aside['$lastusers_items'][] = $entry;
			}
		}
	}

	//right_aside FIND FRIENDS
	if ($show_friends && local_user()) {
		$nv = array();
		$nv['title'] = array("", t('Find Friends'), "", "");
		$nv['directory'] = array('directory', t('Local Directory'), "", "");
		$nv['global_directory'] = Array(get_server(), t('Global Directory'), "", "");
		$nv['match'] = array('match', t('Similar Interests'), "", "");
		$nv['suggest'] = array('suggest', t('Friend Suggestions'), "", "");
		$nv['invite'] = array('invite', t('Invite Friends'), "", "");

		$nv['search'] = '<form name="simple_bar" method="get" action="dirfind">
						<span class="sbox_l"></span>
						<span class="sbox">
						<input type="text" name="search" size="13" maxlength="50">
						</span>
						<span class="sbox_r" id="srch_clear"></span>';

		$aside['$nv'] = $nv;
	}

	//Community_Pages at right_aside
	if ($show_pages && local_user()) {

		require_once 'include/ForumManager.php';

		if (x($_GET, 'cid') && intval($_GET['cid']) != 0) {
			$cid = $_GET['cid'];
		}

		//sort by last updated item
		$lastitem = true;

		$contacts = ForumManager::get_list($a->user['uid'],true,$lastitem, true);
		$total = count($contacts);
		$visible_forums = 10;

		if (count($contacts)) {

			$id = 0;

			foreach ($contacts as $contact) {

				$selected = (($cid == $contact['id']) ? ' forum-selected' : '');

				$entry = array(
					'url'          => 'network?f=&cid=' . $contact['id'],
					'external_url' => 'redir/' . $contact['id'],
					'name'         => $contact['name'],
					'cid'          => $contact['id'],
					'selected'     => $selected,
					'micro'        => System::removedBaseUrl(proxy_url($contact['micro'], false, PROXY_SIZE_MICRO)),
					'id'           => ++$id,
				);
				$entries[] = $entry;
			}


			$tpl = get_markup_template('widget_forumlist_right.tpl');

			$page .= replace_macros($tpl, array(
				'$title'          => t('Forums'),
				'$forums'         => $entries,
				'$link_desc'      => t('External link to forum'),
				'$total'          => $total,
				'$visible_forums' => $visible_forums,
				'$showmore'       => t('show more'),
			));

			$aside['$page'] = $page;
		}
	}
	// END Community Page

	// helpers
	if ($show_helpers) {
		$r = array();

		$helperlist = Config::get("vier", "helperlist");

		$helpers = explode(",",$helperlist);

		if ($helpers) {
			$query = "";
			foreach ($helpers AS $index=>$helper) {
				if ($query != "")
					$query .= ",";

				$query .= "'".dbesc(normalise_link(trim($helper)))."'";
			}

			$r = q("SELECT `url`, `name` FROM `gcontact` WHERE `nurl` IN (%s)", $query);
		}

		foreach ($r AS $index => $helper)
			$r[$index]["url"] = zrl($helper["url"]);

		$r[] = array("url" => "help/Quick-Start-guide", "name" => t("Quick Start"));

		$tpl = get_markup_template('ch_helpers.tpl');

		if ($r) {

			$helpers = array();
			$helpers['title'] = array("", t('Help'), "", "");

			$aside['$helpers_items'] = array();

			foreach ($r as $rr) {
				$entry = replace_macros($tpl,array(
					'$url' => $rr['url'],
					'$title' => $rr['name'],
				));
				$aside['$helpers_items'][] = $entry;
			}

			$aside['$helpers'] = $helpers;
		}
	}
	// end helpers

	// connectable services
	if ($show_services) {

		/// @TODO This whole thing is hard-coded, better rewrite to Intercepting Filter Pattern (future-todo)
		$r = array();

		if (plugin_enabled("appnet")) {
			$r[] = array("photo" => "images/appnet.png", "name" => "App.net");
		}

		if (plugin_enabled("buffer")) {
			$r[] = array("photo" => "images/buffer.png", "name" => "Buffer");
		}

		if (plugin_enabled("blogger")) {
			$r[] = array("photo" => "images/blogger.png", "name" => "Blogger");
		}

		if (plugin_enabled("dwpost")) {
			$r[] = array("photo" => "images/dreamwidth.png", "name" => "Dreamwidth");
		}

		if (plugin_enabled("fbpost")) {
			$r[] = array("photo" => "images/facebook.png", "name" => "Facebook");
		}

		if (plugin_enabled("ifttt")) {
			$r[] = array("photo" => "addon/ifttt/ifttt.png", "name" => "IFTTT");
		}

		if (plugin_enabled("statusnet")) {
			$r[] = array("photo" => "images/gnusocial.png", "name" => "GNU Social");
		}

		if (plugin_enabled("gpluspost")) {
			$r[] = array("photo" => "images/googleplus.png", "name" => "Google+");
		}

		/// @TODO old-lost code (and below)?
		//if (plugin_enabled("ijpost")) {
		//	$r[] = array("photo" => "images/", "name" => "");
		//}

		if (plugin_enabled("libertree")) {
			$r[] = array("photo" => "images/libertree.png", "name" => "Libertree");
		}

		//if (plugin_enabled("ljpost")) {
		//	$r[] = array("photo" => "images/", "name" => "");
		//}

		if (plugin_enabled("pumpio")) {
			$r[] = array("photo" => "images/pumpio.png", "name" => "pump.io");
		}

		if (plugin_enabled("tumblr")) {
			$r[] = array("photo" => "images/tumblr.png", "name" => "Tumblr");
		}

		if (plugin_enabled("twitter")) {
			$r[] = array("photo" => "images/twitter.png", "name" => "Twitter");
		}

		if (plugin_enabled("wppost")) {
			$r[] = array("photo" => "images/wordpress.png", "name" => "Wordpress");
		}

		if (function_exists("imap_open") && !Config::get("system","imap_disabled") && !Config::get("system","dfrn_only")) {
			$r[] = array("photo" => "images/mail.png", "name" => "E-Mail");
		}

		$tpl = get_markup_template('ch_connectors.tpl');

		if (DBM::is_result($r)) {

			$con_services = array();
			$con_services['title'] = array("", t('Connect Services'), "", "");
			$aside['$con_services'] = $con_services;

			foreach ($r as $rr) {
				$entry = replace_macros($tpl,array(
					'$url' => $url,
					'$photo' => $rr['photo'],
					'$alt_text' => $rr['name'],
				));
				$aside['$connector_items'][] = $entry;
			}
		}

	}
	//end connectable services

	//print right_aside
	$tpl = get_markup_template('communityhome.tpl');
	$a->page['right_aside'] = replace_macros($tpl, $aside);
}
