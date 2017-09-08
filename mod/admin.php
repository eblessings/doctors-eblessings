<?php

 /**
 * @file mod/admin.php
 *
 * @brief Friendica admin
 */

use Friendica\App;
use Friendica\Core\System;
use Friendica\Core\Config;

require_once("include/enotify.php");
require_once("include/text.php");
require_once('include/items.php');

/**
 * @brief Process send data from the admin panels subpages
 *
 * This function acts as relais for processing the data send from the subpages
 * of the admin panel. Depending on the 1st parameter of the url (argv[1])
 * specialized functions are called to process the data from the subpages.
 *
 * The function itself does not return anything, but the subsequencely function
 * return the HTML for the pages of the admin panel.
 *
 * @param App $a
 *
 */
function admin_post(App $a) {


	if (!is_site_admin()) {
		return;
	}

	// do not allow a page manager to access the admin panel at all.

	if (x($_SESSION,'submanage') && intval($_SESSION['submanage'])) {
		return;
	}

	// urls
	if ($a->argc > 1) {
		switch ($a->argv[1]) {
			case 'site':
				admin_page_site_post($a);
				break;
			case 'users':
				admin_page_users_post($a);
				break;
			case 'plugins':
				if ($a->argc > 2 &&
					is_file("addon/".$a->argv[2]."/".$a->argv[2].".php")) {
						@include_once("addon/".$a->argv[2]."/".$a->argv[2].".php");
						if (function_exists($a->argv[2].'_plugin_admin_post')) {
							$func = $a->argv[2].'_plugin_admin_post';
							$func($a);
						}
				}
				goaway('admin/plugins/'.$a->argv[2]);
				return; // NOTREACHED
				break;
			case 'themes':
				if ($a->argc < 2) {
					if (is_ajax()) {
						return;
					}
					goaway('admin/');
					return;
				}

				$theme = $a->argv[2];
				if (is_file("view/theme/$theme/config.php")) {
					function __call_theme_admin_post(App $a, $theme) {
						$orig_theme = $a->theme;
						$orig_page = $a->page;
						$orig_session_theme = $_SESSION['theme'];
						require_once("view/theme/$theme/theme.php");
						require_once("view/theme/$theme/config.php");
						$_SESSION['theme'] = $theme;


						$init = $theme."_init";
						if (function_exists($init)) {
							$init($a);
						}
						if (function_exists("theme_admin_post")) {
							$admin_form = theme_admin_post($a);
						}

						$_SESSION['theme'] = $orig_session_theme;
						$a->theme = $orig_theme;
						$a->page = $orig_page;
						return $admin_form;
					}
					__call_theme_admin_post($a, $theme);
				}
				info(t('Theme settings updated.'));
				if (is_ajax()) {
					return;
				}
				goaway('admin/themes/'.$theme);
				return;
				break;
			case 'features':
				admin_page_features_post($a);
				break;
			case 'logs':
				admin_page_logs_post($a);
				break;
			case 'dbsync':
				admin_page_dbsync_post($a);
				break;
			case 'blocklist':
				admin_page_blocklist_post($a);
				break;
			case 'deleteitem':
				admin_page_deleteitem_post($a);
				break;
		}
	}

	goaway('admin');
	return; // NOTREACHED
}

/**
 * @brief Generates content of the admin panel pages
 *
 * This function generates the content for the admin panel. It consists of the
 * aside menu (same for the entire admin panel) and the code for the soecified
 * subpage of the panel.
 *
 * The structure of the adress is: /admin/subpage/details though "details" is
 * only necessary for some subpages, like themes or addons where it is the name
 * of one theme resp. addon from which the details should be shown. Content for
 * the subpages is generated in separate functions for each of the subpages.
 *
 * The returned string hold the generated HTML code of the page.
 *
 * @param App $a
 * @return string
 */
function admin_content(App $a) {

	if (!is_site_admin()) {
		return login(false);
	}

	if (x($_SESSION,'submanage') && intval($_SESSION['submanage'])) {
		return "";
	}

	// APC deactivated, since there are problems with PHP 5.5
	//if (function_exists("apc_delete")) {
	//	$toDelete = new APCIterator('user', APC_ITER_VALUE);
	//	apc_delete($toDelete);
	//}

	// Header stuff
	$a->page['htmlhead'] .= replace_macros(get_markup_template('admin_settings_head.tpl'), array());

	/*
	 * Side bar links
	 */
	$aside_tools = array();
	// array(url, name, extra css classes)
	// not part of $aside to make the template more adjustable
	$aside_sub = array(
		'site'	 =>	array("admin/site/", t("Site") , "site"),
		'users'	 =>	array("admin/users/", t("Users") , "users"),
		'plugins'=>	array("admin/plugins/", t("Plugins") , "plugins"),
		'themes' =>	array("admin/themes/", t("Themes") , "themes"),
		'features' =>	array("admin/features/", t("Additional features") , "features"),
		'dbsync' => 	array("admin/dbsync/", t('DB updates'), "dbsync"),
		'queue'	 =>	array("admin/queue/", t('Inspect Queue'), "queue"),
		'blocklist' => array("admin/blocklist/", t('Server Blocklist'), "blocklist"),
		'federation' => array("admin/federation/", t('Federation Statistics'), "federation"),
		'deleteitem' => array("admin/deleteitem/", t('Delete Item'), 'deleteitem'),
	);

	/* get plugins admin page */

	$r = q("SELECT `name` FROM `addon` WHERE `plugin_admin` = 1 ORDER BY `name`");
	$aside_tools['plugins_admin']=array();
	foreach ($r as $h) {
		$plugin =$h['name'];
		$aside_tools['plugins_admin'][] = array("admin/plugins/".$plugin, $plugin, "plugin");
		// temp plugins with admin
		$a->plugins_admin[] = $plugin;
	}

	$aside_tools['logs'] = array("admin/logs/", t("Logs"), "logs");
	$aside_tools['viewlogs'] = array("admin/viewlogs/", t("View Logs"), 'viewlogs');
	$aside_tools['diagnostics_probe'] = array('probe/', t('probe address'), 'probe');
	$aside_tools['diagnostics_webfinger'] = array('webfinger/', t('check webfinger'), 'webfinger');

	$t = get_markup_template("admin_aside.tpl");
	$a->page['aside'] .= replace_macros($t, array(
		'$admin' => $aside_tools,
		'$subpages' => $aside_sub,
		'$admtxt' => t('Admin'),
		'$plugadmtxt' => t('Plugin Features'),
		'$logtxt' => t('Logs'),
		'$diagnosticstxt' => t('diagnostics'),
		'$h_pending' => t('User registrations waiting for confirmation'),
		'$admurl'=> "admin/"
	));



	/*
	 * Page content
	 */
	$o = '';
	// urls
	if ($a->argc > 1) {
		switch ($a->argv[1]) {
			case 'site':
				$o = admin_page_site($a);
				break;
			case 'users':
				$o = admin_page_users($a);
				break;
			case 'plugins':
				$o = admin_page_plugins($a);
				break;
			case 'themes':
				$o = admin_page_themes($a);
				break;
			case 'features':
				$o = admin_page_features($a);
				break;
			case 'logs':
				$o = admin_page_logs($a);
				break;
			case 'viewlogs':
				$o = admin_page_viewlogs($a);
				break;
			case 'dbsync':
				$o = admin_page_dbsync($a);
				break;
			case 'queue':
				$o = admin_page_queue($a);
				break;
			case 'federation':
				$o = admin_page_federation($a);
				break;
			case 'blocklist':
				$o = admin_page_blocklist($a);
				break;
			case 'deleteitem':
				$o = admin_page_deleteitem($a);
				break;
			default:
				notice(t("Item not found."));
		}
	} else {
		$o = admin_page_summary($a);
	}

	if (is_ajax()) {
		echo $o;
		killme();
		return '';
	} else {
		return $o;
	}
}

/**
 * @brief Subpage to modify the server wide block list via the admin panel.
 *
 * This function generates the subpage of the admin panel to allow the
 * modification of the node wide block/black list to block entire
 * remote servers from communication with this node. The page allows
 * adding, removing and editing of entries from the blocklist.
 *
 * @param App $a
 * @return string
 */
function admin_page_blocklist(App $a) {
	$blocklist = Config::get('system', 'blocklist');
	$blocklistform = array();
	if (is_array($blocklist)) {
		foreach($blocklist as $id => $b) {
			$blocklistform[] = array(
				'domain' => array("domain[$id]", t('Blocked domain'), $b['domain'], '', t('The blocked domain'), 'required', '', ''),
				'reason' => array("reason[$id]", t("Reason for the block"), $b['reason'], t('The reason why you blocked this domain.').'('.$b['domain'].')', 'required', '', ''),
				'delete' => array("delete[$id]", t("Delete domain").' ('.$b['domain'].')', False , t("Check to delete this entry from the blocklist"))
			);
		}
	}
	$t = get_markup_template("admin_blocklist.tpl");
	return replace_macros($t, array(
		'$title' => t('Administration'),
		'$page' => t('Server Blocklist'),
		'$intro' => t('This page can be used to define a black list of servers from the federated network that are not allowed to interact with your node. For all entered domains you should also give a reason why you have blocked the remote server.'),
		'$public' => t('The list of blocked servers will be made publically available on the /friendica page so that your users and people investigating communication problems can find the reason easily.'),
		'$addtitle' => t('Add new entry to block list'),
		'$newdomain' => array('newentry_domain', t('Server Domain'), '', t('The domain of the new server to add to the block list. Do not include the protocol.'), 'required', '', ''),
		'$newreason' => array('newentry_reason', t('Block reason'), '', t('The reason why you blocked this domain.'), 'required', '', ''),
		'$submit' => t('Add Entry'),
		'$savechanges' => t('Save changes to the blocklist'),
		'$currenttitle' => t('Current Entries in the Blocklist'),
		'$thurl' => t('Blocked domain'),
		'$threason' => t('Reason for the block'),
		'$delentry' => t('Delete entry from blocklist'),
		'$entries' => $blocklistform,
		'$baseurl' => System::baseUrl(true),
		'$confirm_delete' => t('Delete entry from blocklist?'),
		'$form_security_token'	=> get_form_security_token("admin_blocklist")
	));
}

/**
 * @brief Process send data from Admin Blocklist Page
 *
 * @param App $a
 */
function admin_page_blocklist_post(App $a) {
	if (!x($_POST,"page_blocklist_save") && (!x($_POST['page_blocklist_edit']))) {
		return;
	}

	check_form_security_token_redirectOnErr('/admin/blocklist', 'admin_blocklist');

	if (x($_POST['page_blocklist_save'])) {
		//  Add new item to blocklist
		$blocklist = get_config('system', 'blocklist');
		$blocklist[] = array(
			'domain' => notags(trim($_POST['newentry_domain'])),
			'reason' => notags(trim($_POST['newentry_reason']))
		);
		Config::set('system', 'blocklist', $blocklist);
		info(t('Server added to blocklist.').EOL);
	} else {
		// Edit the entries from blocklist
		$blocklist = array();
		foreach ($_POST['domain'] as $id => $domain) {
			// Trimming whitespaces as well as any lingering slashes
			$domain = notags(trim($domain, "\x00..\x1F/"));
			$reason = notags(trim($_POST['reason'][$id]));
			if (!x($_POST['delete'][$id])) {
				$blocklist[] = array(
					'domain' => $domain,
					'reason' => $reason
				);
			}
		}
		Config::set('system', 'blocklist', $blocklist);
		info(t('Site blocklist updated.').EOL);
	}
	goaway('admin/blocklist');

	return; // NOTREACHED
}

/**
 * @brief Subpage where the admin can delete an item from their node given the GUID
 *
 * This subpage of the admin panel offers the nodes admin to delete an item from
 * the node, given the GUID or the display URL such as http://example.com/display/123456.
 * The item will then be marked as deleted in the database and processed accordingly.
 * 
 * @param App $a
 * @return string
 */
function admin_page_deleteitem(App $a) {
	$t = get_markup_template("admin_deleteitem.tpl");

	return replace_macros($t, array(
		'$title' => t('Administration'),
		'$page' => t('Delete Item'),
		'$submit' => t('Delete this Item'),
		'$intro1' => t('On this page you can delete an item from your node. If the item is a top level posting, the entire thread will be deleted.'),
		'$intro2' => t('You need to know the GUID of the item. You can find it e.g. by looking at the display URL. The last part of http://example.com/display/123456 is the GUID, here 123456.'),
		'$deleteitemguid' => array('deleteitemguid', t("GUID"), '', t("The GUID of the item you want to delete."), 'required', 'autofocus'),
		'$baseurl' => System::baseUrl(),
		'$form_security_token'	=> get_form_security_token("admin_deleteitem")
	));
}
/**
 * @brief Process send data from Admin Delete Item Page
 *
 * The GUID passed through the form should be only the GUID. But we also parse
 * URLs like the full /display URL to make the process more easy for the admin.
 *
 * @param App $a
 */
function admin_page_deleteitem_post(App $a) {
	if (!x($_POST['page_deleteitem_submit'])) {
		return;
	}

	check_form_security_token_redirectOnErr('/admin/deleteitem/', 'admin_deleteitem');

	if (x($_POST['page_deleteitem_submit'])) {
		$guid = trim(notags($_POST['deleteitemguid']));
		// The GUID should not include a "/", so if there is one, we got an URL
		// and the last part of it is most likely the GUID.
		if (strpos($guid, '/')) {
			$guid = substr($guid, strrpos($guid, '/')+1);
		}
		// Now that we have the GUID get all IDs of the associated entries in the
		// item table of the DB and drop those items, which will also delete the
		// associated threads.
		$r = dba::select('item', array('id'), array('guid'=>$guid));
		while ($row = dba::fetch($r)) {
			drop_item($row['id'], false);
		}
		dba::close($r);
	}

	info(t('Item marked for deletion.').EOL);
	goaway('admin/deleteitem');
	return; // NOTREACHED
}

/**
 * @brief Subpage with some stats about "the federation" network
 *
 * This function generates the "Federation Statistics" subpage for the admin
 * panel. The page lists some numbers to the part of "The Federation" known to
 * the node. This data includes the different connected networks (e.g.
 * Diaspora, Hubzilla, GNU Social) and the used versions in the different
 * networks.
 *
 * The returned string contains the HTML code of the subpage for display.
 *
 * @param App $a
 * @return string
 */
function admin_page_federation(App $a) {
	// get counts on active friendica, diaspora, redmatrix, hubzilla, gnu
	// social and statusnet nodes this node is knowing
	//
	// We are looking for the following platforms in the DB, "Red" should find
	// all variants of that platform ID string as the q() function is stripping
	// off one % two of them are needed in the query
	// Add more platforms if you like, when one returns 0 known nodes it is not
	// displayed on the stats page.
	$platforms = array('Friendi%%a', 'Diaspora', '%%red%%', 'Hubzilla', 'BlaBlaNet', 'GNU Social', 'StatusNet', 'Mastodon', 'Pleroma');
	$colors    = array('Friendi%%a' => '#ffc018',     // orange from the logo
			   'Diaspora'  => '#a1a1a1',     // logo is black and white, makes a gray
			   '%%red%%'   => '#c50001',     // fire red from the logo
			   'Hubzilla'  => '#43488a',     // blue from the logo
			   'BlaBlaNet' => '#3B5998',     // blue from the navbar at blablanet-dot-com
			   'GNU Social'=> '#a22430',     // dark red from the logo
			   'StatusNet' => '#789240',     // the green from the logo (red and blue have already others
			   'Mastodon'  => '#1a9df9',    // blue from the Mastodon logo
			   'Pleroma'  => '#E46F0F');    // Orange from the text that is used on Pleroma instances
	$counts = array();
	$total = 0;

	foreach ($platforms as $p) {
		// get a total count for the platform, the name and version of the
		// highest version and the protocol tpe
		$c = qu('SELECT COUNT(*) AS `total`, ANY_VALUE(`platform`) AS `platform`,
				ANY_VALUE(`network`) AS `network`, MAX(`version`) AS `version` FROM `gserver`
				WHERE `platform` LIKE "%s" AND `last_contact` >= `last_failure`
				ORDER BY `version` ASC;', $p);
		$total = $total + $c[0]['total'];

		// what versions for that platform do we know at all?
		// again only the active nodes
		$v = qu('SELECT COUNT(*) AS `total`, `version` FROM `gserver`
				WHERE `last_contact` >= `last_failure` AND `platform` LIKE "%s"
				GROUP BY `version`
				ORDER BY `version`;', $p);

		//
		// clean up version numbers
		//
		// some platforms do not provide version information, add a unkown there
		// to the version string for the displayed list.
		foreach ($v as $key => $value) {
			if ($v[$key]['version'] == '') {
				$v[$key] = array('total'=>$v[$key]['total'], 'version'=>t('unknown'));
			}
		}
		// in the DB the Diaspora versions have the format x.x.x.x-xx the last
		// part (-xx) should be removed to clean up the versions from the "head
		// commit" information and combined into a single entry for x.x.x.x
		if ($p == 'Diaspora') {
			$newV = array();
			$newVv = array();
			foreach ($v as $vv) {
				$newVC = $vv['total'];
				$newVV = $vv['version'];
				$posDash = strpos($newVV, '-');
				if ($posDash) {
					$newVV = substr($newVV, 0, $posDash);
				}
				if (isset($newV[$newVV])) {
					$newV[$newVV] += $newVC;
				} else {
					$newV[$newVV] = $newVC;
				}
			}
			foreach ($newV as $key => $value) {
				array_push($newVv, array('total'=>$value, 'version'=>$key));
			}
			$v = $newVv;
		}

		// early friendica versions have the format x.x.xxxx where xxxx is the
		// DB version stamp; those should be operated out and versions be
		// conbined
		if ($p == 'Friendi%%a') {
			$newV = array();
			$newVv = array();
			foreach ($v as $vv) {
				$newVC = $vv['total'];
				$newVV = $vv['version'];
				$lastDot = strrpos($newVV,'.');
				$len = strlen($newVV)-1;
				if (($lastDot == $len-4) && (!strrpos($newVV,'-rc') == $len-3)) {
					$newVV = substr($newVV, 0, $lastDot);
				}
				if (isset($newV[$newVV])) {
					$newV[$newVV] += $newVC;
				} else {
					$newV[$newVV] = $newVC;
				}
			}
			foreach ($newV as $key => $value) {
				array_push($newVv, array('total'=>$value, 'version'=>$key));
			}
			$v = $newVv;
		}

		foreach ($v as $key => $vv)
			$v[$key]["version"] = trim(strip_tags($vv["version"]));

		// the 3rd array item is needed for the JavaScript graphs as JS does
		// not like some characters in the names of variables...
		$counts[$p]=array($c[0], $v, str_replace(array(' ','%'),'',$p), $colors[$p]);
	}

	// some helpful text
	$intro = t('This page offers you some numbers to the known part of the federated social network your Friendica node is part of. These numbers are not complete but only reflect the part of the network your node is aware of.');
	$hint = t('The <em>Auto Discovered Contact Directory</em> feature is not enabled, it will improve the data displayed here.');

	// load the template, replace the macros and return the page content
	$t = get_markup_template("admin_federation.tpl");
	return replace_macros($t, array(
		'$title' => t('Administration'),
		'$page' => t('Federation Statistics'),
		'$intro' => $intro,
		'$hint' => $hint,
		'$autoactive' => get_config('system', 'poco_completion'),
		'$counts' => $counts,
		'$version' => FRIENDICA_VERSION,
		'$legendtext' => sprintf(t('Currently this node is aware of %d nodes from the following platforms:'), $total),
		'$baseurl' => System::baseUrl(),
	));
}

/**
 * @brief Admin Inspect Queue Page
 *
 * Generates a page for the admin to have a look into the current queue of
 * postings that are not deliverabke. Shown are the name and url of the
 * recipient, the delivery network and the dates when the posting was generated
 * and the last time tried to deliver the posting.
 *
 * The returned string holds the content of the page.
 *
 * @param App $a
 * @return string
 */
function admin_page_queue(App $a) {
	// get content from the queue table
	$r = q("SELECT `c`.`name`, `c`.`nurl`, `q`.`id`, `q`.`network`, `q`.`created`, `q`.`last`
			FROM `queue` AS `q`, `contact` AS `c`
			WHERE `c`.`id` = `q`.`cid`
			ORDER BY `q`.`cid`, `q`.`created`;");

	$t = get_markup_template("admin_queue.tpl");
	return replace_macros($t, array(
		'$title' => t('Administration'),
		'$page' => t('Inspect Queue'),
		'$count' => sizeof($r),
		'id_header' => t('ID'),
		'$to_header' => t('Recipient Name'),
		'$url_header' => t('Recipient Profile'),
		'$network_header' => t('Network'),
		'$created_header' => t('Created'),
		'$last_header' => t('Last Tried'),
		'$info' => t('This page lists the content of the queue for outgoing postings. These are postings the initial delivery failed for. They will be resend later and eventually deleted if the delivery fails permanently.'),
		'$entries' => $r,
	));
}

/**
 * @brief Admin Summary Page
 *
 * The summary page is the "start page" of the admin panel. It gives the admin
 * a first overview of the open adminastrative tasks.
 *
 * The returned string contains the HTML content of the generated page.
 *
 * @param App $a
 * @return string
 */
function admin_page_summary(App $a) {
	global $db;
	// are there MyISAM tables in the DB? If so, trigger a warning message
	$r = q("SELECT `engine` FROM `information_schema`.`tables` WHERE `engine` = 'myisam' AND `table_schema` = '%s' LIMIT 1",
		dbesc($db->database_name()));
	$showwarning = false;
	$warningtext = array();
	if (dbm::is_result($r)) {
		$showwarning = true;
		$warningtext[] = sprintf(t('Your DB still runs with MyISAM tables. You should change the engine type to InnoDB. As Friendica will use InnoDB only features in the future, you should change this! See <a href="%s">here</a> for a guide that may be helpful converting the table engines. You may also use the command <tt>php include/dbstructure.php toinnodb</tt> of your Friendica installation for an automatic conversion.<br />'), 'https://dev.mysql.com/doc/refman/5.7/en/converting-tables-to-innodb.html');
	}

	if (Config::get('system', 'dbupdate', DB_UPDATE_NOT_CHECKED) == DB_UPDATE_NOT_CHECKED) {
		require_once("include/dbstructure.php");
		update_structure(false, true);
	}
	if (Config::get('system', 'dbupdate') == DB_UPDATE_FAILED) {
		$showwarning = true;
		$warningtext[] = t('The database update failed. Please run "php include/dbstructure.php update" from the command line and have a look at the errors that might appear.');
	}

	$r = q("SELECT `page-flags`, COUNT(`uid`) AS `count` FROM `user` GROUP BY `page-flags`");
	$accounts = array(
		array(t('Normal Account'), 0),
		array(t('Automatic Follower Account'), 0),
		array(t('Public Forum Account'), 0),
		array(t('Automatic Friend Account'), 0),
		array(t('Blog Account'), 0),
		array(t('Private Forum Account'), 0)
	);

	$users=0;
	foreach ($r as $u) {
		$accounts[$u['page-flags']][1] = $u['count'];
		$users+= $u['count'];
	}

	logger('accounts: '.print_r($accounts,true),LOGGER_DATA);

	$r = qu("SELECT COUNT(`id`) AS `count` FROM `register`");
	$pending = $r[0]['count'];

	$r = qu("SELECT COUNT(*) AS `total` FROM `queue` WHERE 1");
	$queue = (($r) ? $r[0]['total'] : 0);

	$r = qu("SELECT COUNT(*) AS `total` FROM `workerqueue` WHERE NOT `done`");
	$workerqueue = (($r) ? $r[0]['total'] : 0);

	// We can do better, but this is a quick queue status

	$queues = array('label' => t('Message queues'), 'queue' => $queue, 'workerq' => $workerqueue);


	$t = get_markup_template("admin_summary.tpl");
	return replace_macros($t, array(
		'$title' => t('Administration'),
		'$page' => t('Summary'),
		'$queues' => $queues,
		'$users' => array(t('Registered users'), $users),
		'$accounts' => $accounts,
		'$pending' => array(t('Pending registrations'), $pending),
		'$version' => array(t('Version'), FRIENDICA_VERSION),
		'$baseurl' => System::baseUrl(),
		'$platform' => FRIENDICA_PLATFORM,
		'$codename' => FRIENDICA_CODENAME,
		'$build' =>  get_config('system','build'),
		'$plugins' => array(t('Active plugins'), $a->plugins),
		'$showwarning' => $showwarning,
		'$warningtext' => $warningtext
	));
}

/**
 * @brief Process send data from Admin Site Page
 *
 * @param App $a
 */
function admin_page_site_post(App $a) {
	if (!x($_POST,"page_site")) {
		return;
	}

	check_form_security_token_redirectOnErr('/admin/site', 'admin_site');

	// relocate
	if (x($_POST,'relocate') && x($_POST,'relocate_url') && $_POST['relocate_url'] != "") {
		$new_url = $_POST['relocate_url'];
		$new_url = rtrim($new_url,"/");

		$parsed = @parse_url($new_url);
		if (!$parsed || (!x($parsed,'host') || !x($parsed,'scheme'))) {
			notice(t("Can not parse base url. Must have at least <scheme>://<domain>"));
			goaway('admin/site');
		}

		/* steps:
		 * replace all "baseurl" to "new_url" in config, profile, term, items and contacts
		 * send relocate for every local user
		 * */

		$old_url = System::baseUrl(true);

		// Generate host names for relocation the addresses in the format user@address.tld
		$new_host = str_replace("http://", "@", normalise_link($new_url));
		$old_host = str_replace("http://", "@", normalise_link($old_url));

		function update_table($table_name, $fields, $old_url, $new_url) {
			global $db, $a;

			$dbold = dbesc($old_url);
			$dbnew = dbesc($new_url);

			$upd = array();
			foreach ($fields as $f) {
				$upd[] = "`$f` = REPLACE(`$f`, '$dbold', '$dbnew')";
			}

			$upds = implode(", ", $upd);



			$q = sprintf("UPDATE %s SET %s;", $table_name, $upds);
			$r = q($q);
			if (!$r) {
				notice("Failed updating '$table_name': ".$db->error);
				goaway('admin/site');
			}
		}

		// update tables
		// update profile links in the format "http://server.tld"
		update_table("profile", array('photo', 'thumb'), $old_url, $new_url);
		update_table("term", array('url'), $old_url, $new_url);
		update_table("contact", array('photo','thumb','micro','url','nurl','alias','request','notify','poll','confirm','poco', 'avatar'), $old_url, $new_url);
		update_table("gcontact", array('url','nurl','photo','server_url','notify','alias'), $old_url, $new_url);
		update_table("item", array('owner-link','owner-avatar','author-link','author-avatar','body','plink','tag'), $old_url, $new_url);

		// update profile addresses in the format "user@server.tld"
		update_table("contact", array('addr'), $old_host, $new_host);
		update_table("gcontact", array('connect','addr'), $old_host, $new_host);

		// update config
		$a->set_baseurl($new_url);
	 	set_config('system','url',$new_url);

		// send relocate
		$users = q("SELECT `uid` FROM `user` WHERE `account_removed` = 0 AND `account_expired` = 0");

		foreach ($users as $user) {
			proc_run(PRIORITY_HIGH, 'include/notifier.php', 'relocate', $user['uid']);
		}

		info("Relocation started. Could take a while to complete.");

		goaway('admin/site');
	}
	// end relocate

	$sitename 		=	((x($_POST,'sitename'))			? notags(trim($_POST['sitename']))		: '');
	$hostname 		=	((x($_POST,'hostname'))			? notags(trim($_POST['hostname']))		: '');
	$sender_email		=	((x($_POST,'sender_email'))		? notags(trim($_POST['sender_email']))		: '');
	$banner			=	((x($_POST,'banner'))			? trim($_POST['banner'])			: false);
	$shortcut_icon 		=	((x($_POST,'shortcut_icon'))		? notags(trim($_POST['shortcut_icon']))		: '');
	$touch_icon 		=	((x($_POST,'touch_icon'))		? notags(trim($_POST['touch_icon']))		: '');
	$info			=	((x($_POST,'info'))			? trim($_POST['info'])				: false);
	$language		=	((x($_POST,'language'))			? notags(trim($_POST['language']))		: '');
	$theme			=	((x($_POST,'theme'))			? notags(trim($_POST['theme']))			: '');
	$theme_mobile		=	((x($_POST,'theme_mobile'))		? notags(trim($_POST['theme_mobile']))		: '');
	$maximagesize		=	((x($_POST,'maximagesize'))		? intval(trim($_POST['maximagesize']))		:  0);
	$maximagelength		=	((x($_POST,'maximagelength'))		? intval(trim($_POST['maximagelength']))	:  MAX_IMAGE_LENGTH);
	$jpegimagequality	=	((x($_POST,'jpegimagequality'))		? intval(trim($_POST['jpegimagequality']))	:  JPEG_QUALITY);


	$register_policy	=	((x($_POST,'register_policy'))		? intval(trim($_POST['register_policy']))	:  0);
	$daily_registrations	=	((x($_POST,'max_daily_registrations'))	? intval(trim($_POST['max_daily_registrations']))	:0);
	$abandon_days	    	=	((x($_POST,'abandon_days'))		? intval(trim($_POST['abandon_days']))		:  0);

	$register_text		=	((x($_POST,'register_text'))		? notags(trim($_POST['register_text']))		: '');

	$allowed_sites		=	((x($_POST,'allowed_sites'))		? notags(trim($_POST['allowed_sites']))		: '');
	$allowed_email		=	((x($_POST,'allowed_email'))		? notags(trim($_POST['allowed_email']))		: '');
	$block_public		=	((x($_POST,'block_public'))		? True						: False);
	$force_publish		=	((x($_POST,'publish_all'))		? True						: False);
	$global_directory	=	((x($_POST,'directory'))		? notags(trim($_POST['directory']))		: '');
	$thread_allow		=	((x($_POST,'thread_allow'))		? True						: False);
	$newuser_private		=	((x($_POST,'newuser_private'))		? True					: False);
	$enotify_no_content		=	((x($_POST,'enotify_no_content'))	? True					: False);
	$private_addons			=	((x($_POST,'private_addons'))		? True					: False);
	$disable_embedded		=	((x($_POST,'disable_embedded'))		? True					: False);
	$allow_users_remote_self	=	((x($_POST,'allow_users_remote_self'))	? True					: False);

	$no_multi_reg		=	((x($_POST,'no_multi_reg'))		? True						: False);
	$no_openid		=	!((x($_POST,'no_openid'))		? True						: False);
	$no_regfullname		=	!((x($_POST,'no_regfullname'))		? True						: False);
	$community_page_style	=	((x($_POST,'community_page_style'))	? intval(trim($_POST['community_page_style']))	: 0);
	$max_author_posts_community_page	=	((x($_POST,'max_author_posts_community_page'))	? intval(trim($_POST['max_author_posts_community_page']))	: 0);

	$verifyssl		=	((x($_POST,'verifyssl'))		? True						: False);
	$proxyuser		=	((x($_POST,'proxyuser'))		? notags(trim($_POST['proxyuser']))		: '');
	$proxy			=	((x($_POST,'proxy'))			? notags(trim($_POST['proxy']))			: '');
	$timeout		=	((x($_POST,'timeout'))			? intval(trim($_POST['timeout']))		: 60);
	$maxloadavg		=	((x($_POST,'maxloadavg'))		? intval(trim($_POST['maxloadavg']))		: 50);
	$maxloadavg_frontend	=	((x($_POST,'maxloadavg_frontend'))	? intval(trim($_POST['maxloadavg_frontend']))	: 50);
	$min_memory		=	((x($_POST,'min_memory'))		? intval(trim($_POST['min_memory']))		: 0);
	$optimize_max_tablesize	=	((x($_POST,'optimize_max_tablesize'))	? intval(trim($_POST['optimize_max_tablesize'])): 100);
	$optimize_fragmentation	=	((x($_POST,'optimize_fragmentation'))	? intval(trim($_POST['optimize_fragmentation'])): 30);
	$poco_completion	=	((x($_POST,'poco_completion'))		? intval(trim($_POST['poco_completion']))	: false);
	$poco_requery_days	=	((x($_POST,'poco_requery_days'))	? intval(trim($_POST['poco_requery_days']))	: 7);
	$poco_discovery		=	((x($_POST,'poco_discovery'))		? intval(trim($_POST['poco_discovery']))	: 0);
	$poco_discovery_since	=	((x($_POST,'poco_discovery_since'))	? intval(trim($_POST['poco_discovery_since']))	: 30);
	$poco_local_search	=	((x($_POST,'poco_local_search'))	? intval(trim($_POST['poco_local_search']))	: false);
	$nodeinfo		=	((x($_POST,'nodeinfo'))			? intval(trim($_POST['nodeinfo']))		: false);
	$dfrn_only		=	((x($_POST,'dfrn_only'))		? True						: False);
	$ostatus_disabled	=	!((x($_POST,'ostatus_disabled'))	? True  					: False);
	$ostatus_poll_interval	=	((x($_POST,'ostatus_poll_interval'))	? intval(trim($_POST['ostatus_poll_interval']))	:  0);
	$ostatus_full_threads	=	((x($_POST,'ostatus_full_threads'))	? True  					: False);
	$diaspora_enabled	=	((x($_POST,'diaspora_enabled'))		? True   					: False);
	$ssl_policy		=	((x($_POST,'ssl_policy'))		? intval($_POST['ssl_policy']) 			: 0);
	$force_ssl		=	((x($_POST,'force_ssl'))		? True   					: False);
	$hide_help		=	((x($_POST,'hide_help'))		? True   					: False);
	$suppress_tags		=	((x($_POST,'suppress_tags'))		? True   					: False);
	$itemcache		=	((x($_POST,'itemcache'))		? notags(trim($_POST['itemcache']))		: '');
	$itemcache_duration	=	((x($_POST,'itemcache_duration'))	? intval($_POST['itemcache_duration'])		: 0);
	$max_comments		=	((x($_POST,'max_comments'))		? intval($_POST['max_comments'])		: 0);
	$temppath		=	((x($_POST,'temppath'))			? notags(trim($_POST['temppath']))		: '');
	$basepath		=	((x($_POST,'basepath'))			? notags(trim($_POST['basepath']))		: '');
	$singleuser		=	((x($_POST,'singleuser'))		? notags(trim($_POST['singleuser']))		: '');
	$proxy_disabled		=	((x($_POST,'proxy_disabled'))		? True						: False);
	$only_tag_search	=	((x($_POST,'only_tag_search'))		? True						: False);
	$rino			=	((x($_POST,'rino'))			? intval($_POST['rino'])			: 0);
	$worker_queues		=	((x($_POST,'worker_queues'))		? intval($_POST['worker_queues'])		: 4);
	$worker_dont_fork	=	((x($_POST,'worker_dont_fork'))		? True						: False);
	$worker_fastlane	=	((x($_POST,'worker_fastlane'))		? True						: False);
	$worker_frontend	=	((x($_POST,'worker_frontend'))		? True						: False);

	// Has the directory url changed? If yes, then resubmit the existing profiles there
	if ($global_directory != Config::get('system', 'directory') && ($global_directory != '')) {
		Config::set('system', 'directory', $global_directory);
		proc_run(PRIORITY_LOW, 'include/directory.php');
	}

	if ($a->get_path() != "") {
		$diaspora_enabled = false;
	}
	if (!$thread_allow) {
		$ostatus_disabled = true;
	}
	if ($ssl_policy != intval(get_config('system','ssl_policy'))) {
		if ($ssl_policy == SSL_POLICY_FULL) {
			q("UPDATE `contact` SET
				`url`     = REPLACE(`url`    , 'http:' , 'https:'),
				`photo`   = REPLACE(`photo`  , 'http:' , 'https:'),
				`thumb`   = REPLACE(`thumb`  , 'http:' , 'https:'),
				`micro`   = REPLACE(`micro`  , 'http:' , 'https:'),
				`request` = REPLACE(`request`, 'http:' , 'https:'),
				`notify`  = REPLACE(`notify` , 'http:' , 'https:'),
				`poll`    = REPLACE(`poll`   , 'http:' , 'https:'),
				`confirm` = REPLACE(`confirm`, 'http:' , 'https:'),
				`poco`    = REPLACE(`poco`   , 'http:' , 'https:')
				WHERE `self` = 1"
			);
			q("UPDATE `profile` SET
				`photo`   = REPLACE(`photo`  , 'http:' , 'https:'),
				`thumb`   = REPLACE(`thumb`  , 'http:' , 'https:')
				WHERE 1 "
			);
		} elseif ($ssl_policy == SSL_POLICY_SELFSIGN) {
			q("UPDATE `contact` SET
				`url`     = REPLACE(`url`    , 'https:' , 'http:'),
				`photo`   = REPLACE(`photo`  , 'https:' , 'http:'),
				`thumb`   = REPLACE(`thumb`  , 'https:' , 'http:'),
				`micro`   = REPLACE(`micro`  , 'https:' , 'http:'),
				`request` = REPLACE(`request`, 'https:' , 'http:'),
				`notify`  = REPLACE(`notify` , 'https:' , 'http:'),
				`poll`    = REPLACE(`poll`   , 'https:' , 'http:'),
				`confirm` = REPLACE(`confirm`, 'https:' , 'http:'),
				`poco`    = REPLACE(`poco`   , 'https:' , 'http:')
				WHERE `self` = 1"
			);
			q("UPDATE `profile` SET
				`photo`   = REPLACE(`photo`  , 'https:' , 'http:'),
				`thumb`   = REPLACE(`thumb`  , 'https:' , 'http:')
				WHERE 1 "
			);
		}
	}
	set_config('system','ssl_policy',$ssl_policy);
	set_config('system','maxloadavg',$maxloadavg);
	set_config('system','maxloadavg_frontend',$maxloadavg_frontend);
	set_config('system','min_memory',$min_memory);
	set_config('system','optimize_max_tablesize',$optimize_max_tablesize);
	set_config('system','optimize_fragmentation',$optimize_fragmentation);
	set_config('system','poco_completion',$poco_completion);
	set_config('system','poco_requery_days',$poco_requery_days);
	set_config('system','poco_discovery',$poco_discovery);
	set_config('system','poco_discovery_since',$poco_discovery_since);
	set_config('system','poco_local_search',$poco_local_search);
	set_config('system','nodeinfo',$nodeinfo);
	set_config('config','sitename',$sitename);
	set_config('config','hostname',$hostname);
	set_config('config','sender_email', $sender_email);
	set_config('system','suppress_tags',$suppress_tags);
	set_config('system','shortcut_icon',$shortcut_icon);
	set_config('system','touch_icon',$touch_icon);

	if ($banner == "") {
		// don't know why, but del_config doesn't work...
		q("DELETE FROM `config` WHERE `cat` = '%s' AND `k` = '%s' LIMIT 1",
			dbesc("system"),
			dbesc("banner")
		);
	} else {
		set_config('system','banner', $banner);
	}

	if ($info == "") {
		del_config('config','info');
	} else {
		set_config('config','info',$info);
	}
	set_config('system','language', $language);
	set_config('system','theme', $theme);

	if ($theme_mobile === '---') {
		del_config('system','mobile-theme');
	} else {
		set_config('system','mobile-theme', $theme_mobile);
		}
		if ($singleuser === '---') {
			del_config('system','singleuser');
		} else {
			set_config('system','singleuser', $singleuser);
		}
	set_config('system', 'maximagesize', $maximagesize);
	set_config('system', 'max_image_length', $maximagelength);
	set_config('system', 'jpeg_quality', $jpegimagequality);

	set_config('config', 'register_policy', $register_policy);
	set_config('system', 'max_daily_registrations', $daily_registrations);
	set_config('system', 'account_abandon_days', $abandon_days);
	set_config('config', 'register_text', $register_text);
	set_config('system', 'allowed_sites', $allowed_sites);
	set_config('system', 'allowed_email', $allowed_email);
	set_config('system', 'block_public', $block_public);
	set_config('system', 'publish_all', $force_publish);
	set_config('system', 'thread_allow', $thread_allow);
	set_config('system', 'newuser_private', $newuser_private);
	set_config('system', 'enotify_no_content', $enotify_no_content);
	set_config('system', 'disable_embedded', $disable_embedded);
	set_config('system', 'allow_users_remote_self', $allow_users_remote_self);

	set_config('system', 'block_extended_register', $no_multi_reg);
	set_config('system', 'no_openid', $no_openid);
	set_config('system', 'no_regfullname', $no_regfullname);
	set_config('system', 'community_page_style', $community_page_style);
	set_config('system', 'max_author_posts_community_page', $max_author_posts_community_page);
	set_config('system', 'verifyssl', $verifyssl);
	set_config('system', 'proxyuser', $proxyuser);
	set_config('system', 'proxy', $proxy);
	set_config('system', 'curl_timeout', $timeout);
	set_config('system', 'dfrn_only', $dfrn_only);
	set_config('system', 'ostatus_disabled', $ostatus_disabled);
	set_config('system', 'ostatus_poll_interval', $ostatus_poll_interval);
	set_config('system', 'ostatus_full_threads', $ostatus_full_threads);
	set_config('system', 'diaspora_enabled', $diaspora_enabled);

	set_config('config', 'private_addons', $private_addons);

	set_config('system', 'force_ssl', $force_ssl);
	set_config('system', 'hide_help', $hide_help);

	if ($itemcache != '') {
		$itemcache = App::realpath($itemcache);
	}

	set_config('system', 'itemcache', $itemcache);
	set_config('system', 'itemcache_duration', $itemcache_duration);
	set_config('system', 'max_comments', $max_comments);

	if ($temppath != '') {
		$temppath = App::realpath($temppath);
	}

	set_config('system', 'temppath', $temppath);

	if ($basepath != '') {
		$basepath = App::realpath($basepath);
	}

	set_config('system', 'basepath', $basepath);
	set_config('system', 'proxy_disabled', $proxy_disabled);
	set_config('system', 'only_tag_search', $only_tag_search);
	set_config('system', 'worker_queues', $worker_queues);
	set_config('system', 'worker_dont_fork', $worker_dont_fork);
	set_config('system', 'worker_fastlane', $worker_fastlane);
	set_config('system', 'frontend_worker', $worker_frontend);
	set_config('system', 'rino_encrypt', $rino);

	info(t('Site settings updated.').EOL);
	goaway('admin/site');
	return; // NOTREACHED

}

/**
 * @brief Generate Admin Site subpage
 *
 * This function generates the main configuration page of the admin panel.
 *
 * @param  App $a
 * @return string
 */
function admin_page_site(App $a) {

	/* Installed langs */
	$lang_choices = get_available_languages();

	if (strlen(get_config('system','directory_submit_url')) &&
		!strlen(get_config('system','directory'))) {
			set_config('system','directory', dirname(get_config('system','directory_submit_url')));
			del_config('system','directory_submit_url');
	}

	/* Installed themes */
	$theme_choices = array();
	$theme_choices_mobile = array();
	$theme_choices_mobile["---"] = t("No special theme for mobile devices");
	$files = glob('view/theme/*');
	if ($files) {

		$allowed_theme_list = Config::get('system', 'allowed_themes');

		foreach ($files as $file) {
			if (intval(file_exists($file.'/unsupported')))
				continue;

			$f = basename($file);

			// Only show allowed themes here
			if (($allowed_theme_list != '') && !strstr($allowed_theme_list, $f)) {
				continue;
			}

			$theme_name = ((file_exists($file.'/experimental')) ?  sprintf("%s - \x28Experimental\x29", $f) : $f);

			if (file_exists($file.'/mobile')) {
				$theme_choices_mobile[$f] = $theme_name;
			} else {
				$theme_choices[$f] = $theme_name;
			}
		}
	}

	/* Community page style */
	$community_page_style_choices = array(
		CP_NO_COMMUNITY_PAGE => t("No community page"),
		CP_USERS_ON_SERVER => t("Public postings from users of this site"),
		CP_GLOBAL_COMMUNITY => t("Global community page")
		);

	/* OStatus conversation poll choices */
	$ostatus_poll_choices = array(
		"-2" => t("Never"),
		"-1" => t("At post arrival"),
		"0" => t("Frequently"),
		"60" => t("Hourly"),
		"720" => t("Twice daily"),
		"1440" => t("Daily")
		);

	$poco_discovery_choices = array(
		"0" => t("Disabled"),
		"1" => t("Users"),
		"2" => t("Users, Global Contacts"),
		"3" => t("Users, Global Contacts/fallback"),
		);

	$poco_discovery_since_choices = array(
		"30" => t("One month"),
		"91" => t("Three months"),
		"182" => t("Half a year"),
		"365" => t("One year"),
		);

	/* get user names to make the install a personal install of X */
	$user_names = array();
	$user_names['---'] = t('Multi user instance');
	$users = q("SELECT `username`, `nickname` FROM `user`");
	foreach ($users as $user) {
		$user_names[$user['nickname']] = $user['username'];
	}

	/* Banner */
	$banner = get_config('system','banner');
	if ($banner == false) {
		$banner = '<a href="http://friendica.com"><img id="logo-img" src="images/friendica-32.png" alt="logo" /></a><span id="logo-text"><a href="http://friendica.com">Friendica</a></span>';
	}
	$banner = htmlspecialchars($banner);
	$info = get_config('config','info');
	$info = htmlspecialchars($info);

	// Automatically create temporary paths
	get_temppath();
	get_itemcachepath();

	//echo "<pre>"; var_dump($lang_choices); die("</pre>");

	/* Register policy */
	$register_choices = array(
		REGISTER_CLOSED => t("Closed"),
		REGISTER_APPROVE => t("Requires approval"),
		REGISTER_OPEN => t("Open")
	);

	$ssl_choices = array(
		SSL_POLICY_NONE => t("No SSL policy, links will track page SSL state"),
		SSL_POLICY_FULL => t("Force all links to use SSL"),
		SSL_POLICY_SELFSIGN => t("Self-signed certificate, use SSL for local links only (discouraged)")
	);

	if ($a->config['hostname'] == "") {
		$a->config['hostname'] = $a->get_hostname();
	}
	$diaspora_able = ($a->get_path() == "");

	$optimize_max_tablesize = Config::get('system','optimize_max_tablesize', 100);

	if ($optimize_max_tablesize < -1) {
		$optimize_max_tablesize = -1;
	}

	if ($optimize_max_tablesize == 0) {
		$optimize_max_tablesize = 100;
	}

	$t = get_markup_template("admin_site.tpl");
	return replace_macros($t, array(
		'$title' => t('Administration'),
		'$page' => t('Site'),
		'$submit' => t('Save Settings'),
		'$registration' => t('Registration'),
		'$upload' => t('File upload'),
		'$corporate' => t('Policies'),
		'$advanced' => t('Advanced'),
		'$portable_contacts' => t('Auto Discovered Contact Directory'),
		'$performance' => t('Performance'),
		'$worker_title' => t('Worker'),
		'$relocate'=> t('Relocate - WARNING: advanced function. Could make this server unreachable.'),
		'$baseurl' => System::baseUrl(true),
		// name, label, value, help string, extra data...
		'$sitename' 		=> array('sitename', t("Site name"), $a->config['sitename'],''),
		'$hostname' 		=> array('hostname', t("Host name"), $a->config['hostname'], ""),
		'$sender_email'		=> array('sender_email', t("Sender Email"), $a->config['sender_email'], t("The email address your server shall use to send notification emails from."), "", "", "email"),
		'$banner'		=> array('banner', t("Banner/Logo"), $banner, ""),
		'$shortcut_icon'	=> array('shortcut_icon', t("Shortcut icon"), get_config('system','shortcut_icon'),  t("Link to an icon that will be used for browsers.")),
		'$touch_icon'		=> array('touch_icon', t("Touch icon"), get_config('system','touch_icon'),  t("Link to an icon that will be used for tablets and mobiles.")),
		'$info'			=> array('info', t('Additional Info'), $info, sprintf(t('For public servers: you can add additional information here that will be listed at %s/siteinfo.'), get_server())),
		'$language' 		=> array('language', t("System language"), get_config('system','language'), "", $lang_choices),
		'$theme' 		=> array('theme', t("System theme"), get_config('system','theme'), t("Default system theme - may be over-ridden by user profiles - <a href='#' id='cnftheme'>change theme settings</a>"), $theme_choices),
		'$theme_mobile' 	=> array('theme_mobile', t("Mobile system theme"), get_config('system','mobile-theme'), t("Theme for mobile devices"), $theme_choices_mobile),
		'$ssl_policy'		=> array('ssl_policy', t("SSL link policy"), (string) intval(get_config('system','ssl_policy')), t("Determines whether generated links should be forced to use SSL"), $ssl_choices),
		'$force_ssl'		=> array('force_ssl', t("Force SSL"), get_config('system','force_ssl'), t("Force all Non-SSL requests to SSL - Attention: on some systems it could lead to endless loops.")),
		'$hide_help'		=> array('hide_help', t("Hide help entry from navigation menu"), get_config('system','hide_help'), t("Hides the menu entry for the Help pages from the navigation menu. You can still access it calling /help directly.")),
		'$singleuser' 		=> array('singleuser', t("Single user instance"), get_config('system','singleuser'), t("Make this instance multi-user or single-user for the named user"), $user_names),
		'$maximagesize'		=> array('maximagesize', t("Maximum image size"), get_config('system','maximagesize'), t("Maximum size in bytes of uploaded images. Default is 0, which means no limits.")),
		'$maximagelength'	=> array('maximagelength', t("Maximum image length"), get_config('system','max_image_length'), t("Maximum length in pixels of the longest side of uploaded images. Default is -1, which means no limits.")),
		'$jpegimagequality'	=> array('jpegimagequality', t("JPEG image quality"), get_config('system','jpeg_quality'), t("Uploaded JPEGS will be saved at this quality setting [0-100]. Default is 100, which is full quality.")),

		'$register_policy'	=> array('register_policy', t("Register policy"), $a->config['register_policy'], "", $register_choices),
		'$daily_registrations'	=> array('max_daily_registrations', t("Maximum Daily Registrations"), get_config('system', 'max_daily_registrations'), t("If registration is permitted above, this sets the maximum number of new user registrations to accept per day.  If register is set to closed, this setting has no effect.")),
		'$register_text'	=> array('register_text', t("Register text"), $a->config['register_text'], t("Will be displayed prominently on the registration page.")),
		'$abandon_days'		=> array('abandon_days', t('Accounts abandoned after x days'), get_config('system','account_abandon_days'), t('Will not waste system resources polling external sites for abandonded accounts. Enter 0 for no time limit.')),
		'$allowed_sites'	=> array('allowed_sites', t("Allowed friend domains"), get_config('system','allowed_sites'), t("Comma separated list of domains which are allowed to establish friendships with this site. Wildcards are accepted. Empty to allow any domains")),
		'$allowed_email'	=> array('allowed_email', t("Allowed email domains"), get_config('system','allowed_email'), t("Comma separated list of domains which are allowed in email addresses for registrations to this site. Wildcards are accepted. Empty to allow any domains")),
		'$block_public'		=> array('block_public', t("Block public"), get_config('system','block_public'), t("Check to block public access to all otherwise public personal pages on this site unless you are currently logged in.")),
		'$force_publish'	=> array('publish_all', t("Force publish"), get_config('system','publish_all'), t("Check to force all profiles on this site to be listed in the site directory.")),
		'$global_directory'	=> array('directory', t("Global directory URL"), get_config('system','directory'), t("URL to the global directory. If this is not set, the global directory is completely unavailable to the application.")),
		'$thread_allow'		=> array('thread_allow', t("Allow threaded items"), get_config('system','thread_allow'), t("Allow infinite level threading for items on this site.")),
		'$newuser_private'	=> array('newuser_private', t("Private posts by default for new users"), get_config('system','newuser_private'), t("Set default post permissions for all new members to the default privacy group rather than public.")),
		'$enotify_no_content'	=> array('enotify_no_content', t("Don't include post content in email notifications"), get_config('system','enotify_no_content'), t("Don't include the content of a post/comment/private message/etc. in the email notifications that are sent out from this site, as a privacy measure.")),
		'$private_addons'	=> array('private_addons', t("Disallow public access to addons listed in the apps menu."), get_config('config','private_addons'), t("Checking this box will restrict addons listed in the apps menu to members only.")),
		'$disable_embedded'	=> array('disable_embedded', t("Don't embed private images in posts"), get_config('system','disable_embedded'), t("Don't replace locally-hosted private photos in posts with an embedded copy of the image. This means that contacts who receive posts containing private photos will have to authenticate and load each image, which may take a while.")),
		'$allow_users_remote_self' => array('allow_users_remote_self', t('Allow Users to set remote_self'), get_config('system','allow_users_remote_self'), t('With checking this, every user is allowed to mark every contact as a remote_self in the repair contact dialog. Setting this flag on a contact causes mirroring every posting of that contact in the users stream.')),
		'$no_multi_reg'		=> array('no_multi_reg', t("Block multiple registrations"),  get_config('system','block_extended_register'), t("Disallow users to register additional accounts for use as pages.")),
		'$no_openid'		=> array('no_openid', t("OpenID support"), !get_config('system','no_openid'), t("OpenID support for registration and logins.")),
		'$no_regfullname'	=> array('no_regfullname', t("Fullname check"), !get_config('system','no_regfullname'), t("Force users to register with a space between firstname and lastname in Full name, as an antispam measure")),
		'$community_page_style' => array('community_page_style', t("Community Page Style"), get_config('system','community_page_style'), t("Type of community page to show. 'Global community' shows every public posting from an open distributed network that arrived on this server."), $community_page_style_choices),
		'$max_author_posts_community_page' => array('max_author_posts_community_page', t("Posts per user on community page"), get_config('system','max_author_posts_community_page'), t("The maximum number of posts per user on the community page. (Not valid for 'Global Community')")),
		'$ostatus_disabled' 	=> array('ostatus_disabled', t("Enable OStatus support"), !get_config('system','ostatus_disabled'), t("Provide built-in OStatus \x28StatusNet, GNU Social etc.\x29 compatibility. All communications in OStatus are public, so privacy warnings will be occasionally displayed.")),
		'$ostatus_poll_interval' => array('ostatus_poll_interval', t("OStatus conversation completion interval"), (string) intval(get_config('system','ostatus_poll_interval')), t("How often shall the poller check for new entries in OStatus conversations? This can be a very ressource task."), $ostatus_poll_choices),
		'$ostatus_full_threads'	=> array('ostatus_full_threads', t("Only import OStatus threads from our contacts"), get_config('system','ostatus_full_threads'), t("Normally we import every content from our OStatus contacts. With this option we only store threads that are started by a contact that is known on our system.")),
		'$ostatus_not_able'	=> t("OStatus support can only be enabled if threading is enabled."),
		'$diaspora_able'	=> $diaspora_able,
		'$diaspora_not_able'	=> t("Diaspora support can't be enabled because Friendica was installed into a sub directory."),
		'$diaspora_enabled'	=> array('diaspora_enabled', t("Enable Diaspora support"), get_config('system','diaspora_enabled'), t("Provide built-in Diaspora network compatibility.")),
		'$dfrn_only'		=> array('dfrn_only', t('Only allow Friendica contacts'), get_config('system','dfrn_only'), t("All contacts must use Friendica protocols. All other built-in communication protocols disabled.")),
		'$verifyssl' 		=> array('verifyssl', t("Verify SSL"), get_config('system','verifyssl'), t("If you wish, you can turn on strict certificate checking. This will mean you cannot connect (at all) to self-signed SSL sites.")),
		'$proxyuser'		=> array('proxyuser', t("Proxy user"), get_config('system','proxyuser'), ""),
		'$proxy'		=> array('proxy', t("Proxy URL"), get_config('system','proxy'), ""),
		'$timeout'		=> array('timeout', t("Network timeout"), (x(get_config('system','curl_timeout'))?get_config('system','curl_timeout'):60), t("Value is in seconds. Set to 0 for unlimited (not recommended).")),
		'$maxloadavg'		=> array('maxloadavg', t("Maximum Load Average"), ((intval(get_config('system','maxloadavg')) > 0)?get_config('system','maxloadavg'):50), t("Maximum system load before delivery and poll processes are deferred - default 50.")),
		'$maxloadavg_frontend'	=> array('maxloadavg_frontend', t("Maximum Load Average (Frontend)"), ((intval(get_config('system','maxloadavg_frontend')) > 0)?get_config('system','maxloadavg_frontend'):50), t("Maximum system load before the frontend quits service - default 50.")),
		'$min_memory'		=> array('min_memory', t("Minimal Memory"), ((intval(get_config('system','min_memory')) > 0)?get_config('system','min_memory'):0), t("Minimal free memory in MB for the poller. Needs access to /proc/meminfo - default 0 (deactivated).")),
		'$optimize_max_tablesize'=> array('optimize_max_tablesize', t("Maximum table size for optimization"), $optimize_max_tablesize, t("Maximum table size (in MB) for the automatic optimization - default 100 MB. Enter -1 to disable it.")),
		'$optimize_fragmentation'=> array('optimize_fragmentation', t("Minimum level of fragmentation"), ((intval(get_config('system','optimize_fragmentation')) > 0)?get_config('system','optimize_fragmentation'):30), t("Minimum fragmenation level to start the automatic optimization - default value is 30%.")),

		'$poco_completion'	=> array('poco_completion', t("Periodical check of global contacts"), get_config('system','poco_completion'), t("If enabled, the global contacts are checked periodically for missing or outdated data and the vitality of the contacts and servers.")),
		'$poco_requery_days'	=> array('poco_requery_days', t("Days between requery"), get_config('system','poco_requery_days'), t("Number of days after which a server is requeried for his contacts.")),
		'$poco_discovery'	=> array('poco_discovery', t("Discover contacts from other servers"), (string) intval(get_config('system','poco_discovery')), t("Periodically query other servers for contacts. You can choose between 'users': the users on the remote system, 'Global Contacts': active contacts that are known on the system. The fallback is meant for Redmatrix servers and older friendica servers, where global contacts weren't available. The fallback increases the server load, so the recommened setting is 'Users, Global Contacts'."), $poco_discovery_choices),
		'$poco_discovery_since'	=> array('poco_discovery_since', t("Timeframe for fetching global contacts"), (string) intval(get_config('system','poco_discovery_since')), t("When the discovery is activated, this value defines the timeframe for the activity of the global contacts that are fetched from other servers."), $poco_discovery_since_choices),
		'$poco_local_search'	=> array('poco_local_search', t("Search the local directory"), get_config('system','poco_local_search'), t("Search the local directory instead of the global directory. When searching locally, every search will be executed on the global directory in the background. This improves the search results when the search is repeated.")),

		'$nodeinfo'		=> array('nodeinfo', t("Publish server information"), get_config('system','nodeinfo'), t("If enabled, general server and usage data will be published. The data contains the name and version of the server, number of users with public profiles, number of posts and the activated protocols and connectors. See <a href='http://the-federation.info/'>the-federation.info</a> for details.")),

		'$suppress_tags'	=> array('suppress_tags', t("Suppress Tags"), get_config('system','suppress_tags'), t("Suppress showing a list of hashtags at the end of the posting.")),
		'$itemcache'		=> array('itemcache', t("Path to item cache"), get_config('system','itemcache'), t("The item caches buffers generated bbcode and external images.")),
		'$itemcache_duration' 	=> array('itemcache_duration', t("Cache duration in seconds"), get_config('system','itemcache_duration'), t("How long should the cache files be hold? Default value is 86400 seconds (One day). To disable the item cache, set the value to -1.")),
		'$max_comments' 	=> array('max_comments', t("Maximum numbers of comments per post"), get_config('system','max_comments'), t("How much comments should be shown for each post? Default value is 100.")),
		'$temppath'		=> array('temppath', t("Temp path"), get_config('system','temppath'), t("If you have a restricted system where the webserver can't access the system temp path, enter another path here.")),
		'$basepath'		=> array('basepath', t("Base path to installation"), get_config('system','basepath'), t("If the system cannot detect the correct path to your installation, enter the correct path here. This setting should only be set if you are using a restricted system and symbolic links to your webroot.")),
		'$proxy_disabled'	=> array('proxy_disabled', t("Disable picture proxy"), get_config('system','proxy_disabled'), t("The picture proxy increases performance and privacy. It shouldn't be used on systems with very low bandwith.")),
		'$only_tag_search'	=> array('only_tag_search', t("Only search in tags"), get_config('system','only_tag_search'), t("On large systems the text search can slow down the system extremely.")),

		'$relocate_url'		=> array('relocate_url', t("New base url"), System::baseUrl(), t("Change base url for this server. Sends relocate message to all DFRN contacts of all users.")),

		'$rino' 		=> array('rino', t("RINO Encryption"), intval(get_config('system','rino_encrypt')), t("Encryption layer between nodes."), array("Disabled", "RINO1 (deprecated)", "RINO2")),

		'$worker_queues' 	=> array('worker_queues', t("Maximum number of parallel workers"), get_config('system','worker_queues'), t("On shared hosters set this to 2. On larger systems, values of 10 are great. Default value is 4.")),
		'$worker_dont_fork'	=> array('worker_dont_fork', t("Don't use 'proc_open' with the worker"), get_config('system','worker_dont_fork'), t("Enable this if your system doesn't allow the use of 'proc_open'. This can happen on shared hosters. If this is enabled you should increase the frequency of poller calls in your crontab.")),
		'$worker_fastlane'	=> array('worker_fastlane', t("Enable fastlane"), get_config('system','worker_fastlane'), t("When enabed, the fastlane mechanism starts an additional worker if processes with higher priority are blocked by processes of lower priority.")),
		'$worker_frontend'	=> array('worker_frontend', t('Enable frontend worker'), get_config('system','frontend_worker'), sprintf(t('When enabled the Worker process is triggered when backend access is performed (e.g. messages being delivered). On smaller sites you might want to call %s/worker on a regular basis via an external cron job. You should only enable this option if you cannot utilize cron/scheduled jobs on your server.'), System::baseUrl())),

		'$form_security_token'	=> get_form_security_token("admin_site")

	));

}

/**
 * @brief Generates admin panel subpage for DB syncronization
 *
 * This page checks if the database of friendica is in sync with the specs.
 * Should this not be the case, it attemps to sync the structure and notifies
 * the admin if the automatic process was failing.
 *
 * The returned string holds the HTML code of the page.
 *
 * @param App $a
 * @return string
 **/
function admin_page_dbsync(App $a) {

	$o = '';

	if ($a->argc > 3 && intval($a->argv[3]) && $a->argv[2] === 'mark') {
		set_config('database', 'update_'.intval($a->argv[3]), 'success');
		$curr = get_config('system','build');
		if (intval($curr) == intval($a->argv[3])) {
			set_config('system','build',intval($curr) + 1);
		}
		info(t('Update has been marked successful').EOL);
		goaway('admin/dbsync');
	}

	if (($a->argc > 2) && (intval($a->argv[2]) || ($a->argv[2] === 'check'))) {
		require_once("include/dbstructure.php");
		$retval = update_structure(false, true);
		if (!$retval) {
			$o .= sprintf(t("Database structure update %s was successfully applied."), DB_UPDATE_VERSION)."<br />";
			set_config('database', 'dbupdate_'.DB_UPDATE_VERSION, 'success');
		} else {
			$o .= sprintf(t("Executing of database structure update %s failed with error: %s"),
					DB_UPDATE_VERSION, $retval)."<br />";
		}
		if ($a->argv[2] === 'check') {
			return $o;
		}
	}

	if ($a->argc > 2 && intval($a->argv[2])) {
		require_once('update.php');
		$func = 'update_'.intval($a->argv[2]);
		if (function_exists($func)) {
			$retval = $func();
			if ($retval === UPDATE_FAILED) {
				$o .= sprintf(t("Executing %s failed with error: %s"), $func, $retval);
			}
			elseif ($retval === UPDATE_SUCCESS) {
				$o .= sprintf(t('Update %s was successfully applied.', $func));
				set_config('database',$func, 'success');
			} else {
				$o .= sprintf(t('Update %s did not return a status. Unknown if it succeeded.'), $func);
			}
		} else {
			$o .= sprintf(t('There was no additional update function %s that needed to be called.'), $func)."<br />";
			set_config('database',$func, 'success');
		}
		return $o;
	}

	$failed = array();
	$r = q("SELECT `k`, `v` FROM `config` WHERE `cat` = 'database' ");
	if (dbm::is_result($r)) {
		foreach ($r as $rr) {
			$upd = intval(substr($rr['k'],7));
			if ($upd < 1139 || $rr['v'] === 'success') {
				continue;
			}
			$failed[] = $upd;
		}
	}
	if (! count($failed)) {
		$o = replace_macros(get_markup_template('structure_check.tpl'),array(
			'$base'   => System::baseUrl(true),
			'$banner' => t('No failed updates.'),
			'$check'  => t('Check database structure'),
		));
	} else {
		$o = replace_macros(get_markup_template('failed_updates.tpl'),array(
			'$base'   => System::baseUrl(true),
			'$banner' => t('Failed Updates'),
			'$desc'   => t('This does not include updates prior to 1139, which did not return a status.'),
			'$mark'   => t('Mark success (if update was manually applied)'),
			'$apply'  => t('Attempt to execute this update step automatically'),
			'$failed' => $failed
		));
	}

	return $o;

}

/**
 * @brief Process data send by Users admin page
 *
 * @param App $a
 */
function admin_page_users_post(App $a) {
	$pending     = (x($_POST, 'pending')           ? $_POST['pending']           : array());
	$users       = (x($_POST, 'user')              ? $_POST['user']		      : array());
	$nu_name     = (x($_POST, 'new_user_name')     ? $_POST['new_user_name']     : '');
	$nu_nickname = (x($_POST, 'new_user_nickname') ? $_POST['new_user_nickname'] : '');
	$nu_email    = (x($_POST, 'new_user_email')    ? $_POST['new_user_email']    : '');
	$nu_language = get_config('system', 'language');

	check_form_security_token_redirectOnErr('/admin/users', 'admin_users');

	if (!($nu_name === "") && !($nu_email === "") && !($nu_nickname === "")) {
		require_once('include/user.php');

		$result = create_user(array('username'=>$nu_name, 'email'=>$nu_email,
			'nickname'=>$nu_nickname, 'verified'=>1, 'language'=>$nu_language));
		if (! $result['success']) {
			notice($result['message']);
			return;
		}
		$nu = $result['user'];
		$preamble = deindent(t('
			Dear %1$s,
				the administrator of %2$s has set up an account for you.'));
		$body = deindent(t('
			The login details are as follows:

			Site Location:	%1$s
			Login Name:		%2$s
			Password:		%3$s

			You may change your password from your account "Settings" page after logging
			in.

			Please take a few moments to review the other account settings on that page.

			You may also wish to add some basic information to your default profile
			(on the "Profiles" page) so that other people can easily find you.

			We recommend setting your full name, adding a profile photo,
			adding some profile "keywords" (very useful in making new friends) - and
			perhaps what country you live in; if you do not wish to be more specific
			than that.

			We fully respect your right to privacy, and none of these items are necessary.
			If you are new and do not know anybody here, they may help
			you to make some new and interesting friends.

			Thank you and welcome to %4$s.'));

		$preamble = sprintf($preamble, $nu['username'], $a->config['sitename']);
		$body = sprintf($body, System::baseUrl(), $nu['email'], $result['password'], $a->config['sitename']);

		notification(array(
			'type' => SYSTEM_EMAIL,
			'to_email' => $nu['email'],
			'subject'=> sprintf(t('Registration details for %s'), $a->config['sitename']),
			'preamble'=> $preamble,
			'body' => $body));

	}

	if (x($_POST,'page_users_block')) {
		foreach ($users as $uid) {
			q("UPDATE `user` SET `blocked` = 1-`blocked` WHERE `uid` = %s",
				intval($uid)
			);
		}
		notice(sprintf(tt("%s user blocked/unblocked", "%s users blocked/unblocked", count($users)), count($users)));
	}
	if (x($_POST,'page_users_delete')) {
		require_once("include/Contact.php");
		foreach ($users as $uid) {
			user_remove($uid);
		}
		notice(sprintf(tt("%s user deleted", "%s users deleted", count($users)), count($users)));
	}

	if (x($_POST,'page_users_approve')) {
		require_once("mod/regmod.php");
		foreach ($pending as $hash) {
			user_allow($hash);
		}
	}
	if (x($_POST,'page_users_deny')) {
		require_once("mod/regmod.php");
		foreach ($pending as $hash) {
			user_deny($hash);
		}
	}
	goaway('admin/users');
	return; // NOTREACHED
}

/**
 * @brief Admin panel subpage for User management
 *
 * This function generates the admin panel page for user management of the
 * node. It offers functionality to add/block/delete users and offers some
 * statistics about the userbase.
 *
 * The returned string holds the HTML code of the page.
 *
 * @param App $a
 * @return string
 */
function admin_page_users(App $a) {
	if ($a->argc>2) {
		$uid = $a->argv[3];
		$user = q("SELECT `username`, `blocked` FROM `user` WHERE `uid` = %d", intval($uid));
		if (count($user) == 0) {
			notice('User not found'.EOL);
			goaway('admin/users');
			return ''; // NOTREACHED
		}
		switch($a->argv[2]) {
			case "delete":
				check_form_security_token_redirectOnErr('/admin/users', 'admin_users', 't');
				// delete user
				require_once("include/Contact.php");
				user_remove($uid);

				notice(sprintf(t("User '%s' deleted"), $user[0]['username']).EOL);
				break;
			case "block":
				check_form_security_token_redirectOnErr('/admin/users', 'admin_users', 't');
				q("UPDATE `user` SET `blocked` = %d WHERE `uid` = %s",
					intval(1-$user[0]['blocked']),
					intval($uid)
				);
				notice(sprintf(($user[0]['blocked']?t("User '%s' unblocked"):t("User '%s' blocked")) , $user[0]['username']).EOL);
				break;
		}
		goaway('admin/users');
		return ''; // NOTREACHED

	}

	/* get pending */
	$pending = q("SELECT `register`.*, `contact`.`name`, `user`.`email`
				 FROM `register`
				 LEFT JOIN `contact` ON `register`.`uid` = `contact`.`uid`
				 LEFT JOIN `user` ON `register`.`uid` = `user`.`uid`;");


	/* get users */
	$total = qu("SELECT COUNT(*) AS `total` FROM `user` WHERE 1");
	if (count($total)) {
		$a->set_pager_total($total[0]['total']);
		$a->set_pager_itemspage(100);
	}

	/* ordering */
	$valid_orders = array(
		'contact.name',
		'user.email',
		'user.register_date',
		'user.login_date',
		'lastitem_date',
		'user.page-flags'
	);

	$order = "contact.name";
	$order_direction = "+";
	if (x($_GET,'o')) {
		$new_order = $_GET['o'];
		if ($new_order[0] === "-") {
			$order_direction = "-";
			$new_order = substr($new_order,1);
		}

		if (in_array($new_order, $valid_orders)) {
			$order = $new_order;
		}
		if (x($_GET,'d')) {
			$new_direction = $_GET['d'];
		}
	}
	$sql_order = "`".str_replace('.','`.`',$order)."`";
	$sql_order_direction = ($order_direction === "+")?"ASC":"DESC";

	$users = qu("SELECT `user`.*, `contact`.`name`, `contact`.`url`, `contact`.`micro`, `user`.`account_expired`, `contact`.`last-item` AS `lastitem_date`
				FROM `user`
				INNER JOIN `contact` ON `contact`.`uid` = `user`.`uid` AND `contact`.`self`
				WHERE `user`.`verified`
				ORDER BY $sql_order $sql_order_direction LIMIT %d, %d",
				intval($a->pager['start']),
				intval($a->pager['itemspage'])
				);

	//echo "<pre>$users"; killme();

	$adminlist = explode(",", str_replace(" ", "", $a->config['admin_email']));
	$_setup_users = function ($e) use ($adminlist) {
		$accounts = array(
			t('Normal Account'),
			t('Automatic Follower Account'),
			t('Public Forum Account'),
						t('Automatic Friend Account')
		);
		$e['page-flags'] = $accounts[$e['page-flags']];
		$e['register_date'] = relative_date($e['register_date']);
		$e['login_date'] = relative_date($e['login_date']);
		$e['lastitem_date'] = relative_date($e['lastitem_date']);
		//$e['is_admin'] = ($e['email'] === $a->config['admin_email']);
		$e['is_admin'] = in_array($e['email'], $adminlist);
		$e['is_deletable'] = (intval($e['uid']) != local_user());
		$e['deleted'] = ($e['account_removed']?relative_date($e['account_expires_on']):False);
		return $e;
	};
	$users = array_map($_setup_users, $users);


	// Get rid of dashes in key names, Smarty3 can't handle them
	// and extracting deleted users

	$tmp_users = array();
	$deleted = array();

	while (count($users)) {
		$new_user = array();
		foreach (array_pop($users) as $k => $v) {
			$k = str_replace('-','_',$k);
			$new_user[$k] = $v;
		}
		if ($new_user['deleted']) {
			array_push($deleted, $new_user);
		} else {
			array_push($tmp_users, $new_user);
		}
	}
	//Reversing the two array, and moving $tmp_users to $users
	array_reverse($deleted);
	while (count($tmp_users)) {
		array_push($users, array_pop($tmp_users));
	}

	$th_users = array_map(null,
		array(t('Name'), t('Email'), t('Register date'), t('Last login'), t('Last item'),  t('Account')),
		$valid_orders
	);

	$t = get_markup_template("admin_users.tpl");
	$o = replace_macros($t, array(
		// strings //
		'$title' => t('Administration'),
		'$page' => t('Users'),
		'$submit' => t('Add User'),
		'$select_all' => t('select all'),
		'$h_pending' => t('User registrations waiting for confirm'),
		'$h_deleted' => t('User waiting for permanent deletion'),
		'$th_pending' => array(t('Request date'), t('Name'), t('Email')),
		'$no_pending' =>  t('No registrations.'),
		'$pendingnotetext' => t('Note from the user'),
		'$approve' => t('Approve'),
		'$deny' => t('Deny'),
		'$delete' => t('Delete'),
		'$block' => t('Block'),
		'$unblock' => t('Unblock'),
		'$siteadmin' => t('Site admin'),
		'$accountexpired' => t('Account expired'),

		'$h_users' => t('Users'),
		'$h_newuser' => t('New User'),
		'$th_deleted' => array(t('Name'), t('Email'), t('Register date'), t('Last login'), t('Last item'), t('Deleted since')),
		'$th_users' => $th_users,
		'$order_users' => $order,
		'$order_direction_users' => $order_direction,

		'$confirm_delete_multi' => t('Selected users will be deleted!\n\nEverything these users had posted on this site will be permanently deleted!\n\nAre you sure?'),
		'$confirm_delete' => t('The user {0} will be deleted!\n\nEverything this user has posted on this site will be permanently deleted!\n\nAre you sure?'),

		'$form_security_token' => get_form_security_token("admin_users"),

		// values //
		'$baseurl' => System::baseUrl(true),

		'$pending' => $pending,
		'deleted' => $deleted,
		'$users' => $users,
		'$newusername' => array('new_user_name', t("Name"), '', t("Name of the new user.")),
		'$newusernickname' => array('new_user_nickname', t("Nickname"), '', t("Nickname of the new user.")),
		'$newuseremail' => array('new_user_email', t("Email"), '', t("Email address of the new user."), '', '', 'email'),
	));
	$o .= paginate($a);
	return $o;
}


/**
 * @brief Plugins admin page
 *
 * This function generates the admin panel page for managing plugins on the
 * friendica node. If a plugin name is given a single page showing the details
 * for this addon is generated. If no name is given, a list of available
 * plugins is shown.
 *
 * The template used for displaying the list of plugins and the details of the
 * plugin are the same as used for the templates.
 *
 * The returned string returned hulds the HTML code of the page.
 *
 * @param App $a
 * @return string
 */
function admin_page_plugins(App $a) {

	/*
	 * Single plugin
	 */
	if ($a->argc == 3) {
		$plugin = $a->argv[2];
		if (!is_file("addon/$plugin/$plugin.php")) {
			notice(t("Item not found."));
			return '';
		}

		if (x($_GET,"a") && $_GET['a']=="t") {
			check_form_security_token_redirectOnErr('/admin/plugins', 'admin_themes', 't');

			// Toggle plugin status
			$idx = array_search($plugin, $a->plugins);
			if ($idx !== false) {
				unset($a->plugins[$idx]);
				uninstall_plugin($plugin);
				info(sprintf(t("Plugin %s disabled."), $plugin));
			} else {
				$a->plugins[] = $plugin;
				install_plugin($plugin);
				info(sprintf(t("Plugin %s enabled."), $plugin));
			}
			set_config("system","addon", implode(", ",$a->plugins));
			goaway('admin/plugins');
			return ''; // NOTREACHED
		}

		// display plugin details
		require_once('library/markdown.php');

		if (in_array($plugin, $a->plugins)) {
			$status="on"; $action= t("Disable");
		} else {
			$status="off"; $action= t("Enable");
		}

		$readme=Null;
		if (is_file("addon/$plugin/README.md")) {
			$readme = file_get_contents("addon/$plugin/README.md");
			$readme = Markdown($readme, false);
		} elseif (is_file("addon/$plugin/README")) {
			$readme = "<pre>". file_get_contents("addon/$plugin/README") ."</pre>";
		}

		$admin_form="";
		if (is_array($a->plugins_admin) && in_array($plugin, $a->plugins_admin)) {
			@require_once("addon/$plugin/$plugin.php");
			$func = $plugin.'_plugin_admin';
			$func($a, $admin_form);
		}

		$t = get_markup_template("admin_plugins_details.tpl");

		return replace_macros($t, array(
			'$title' => t('Administration'),
			'$page' => t('Plugins'),
			'$toggle' => t('Toggle'),
			'$settings' => t('Settings'),
			'$baseurl' => System::baseUrl(true),

			'$plugin' => $plugin,
			'$status' => $status,
			'$action' => $action,
			'$info' => get_plugin_info($plugin),
			'$str_author' => t('Author: '),
			'$str_maintainer' => t('Maintainer: '),

			'$admin_form' => $admin_form,
			'$function' => 'plugins',
			'$screenshot' => '',
			'$readme' => $readme,

			'$form_security_token' => get_form_security_token("admin_themes"),
		));
	}



	/*
	 * List plugins
	 */

	if (x($_GET,"a") && $_GET['a']=="r") {
		check_form_security_token_redirectOnErr(System::baseUrl().'/admin/plugins', 'admin_themes', 't');
		reload_plugins();
		info("Plugins reloaded");
		goaway(System::baseUrl().'/admin/plugins');
	}

	$plugins = array();
	$files = glob("addon/*/");
	if ($files) {
		foreach ($files as $file) {
			if (is_dir($file)) {
				list($tmp, $id)=array_map("trim", explode("/",$file));
				$info = get_plugin_info($id);
				$show_plugin = true;

				// If the addon is unsupported, then only show it, when it is enabled
				if ((strtolower($info["status"]) == "unsupported") && !in_array($id,  $a->plugins)) {
					$show_plugin = false;
				}

				// Override the above szenario, when the admin really wants to see outdated stuff
				if (get_config("system", "show_unsupported_addons")) {
					$show_plugin = true;
				}

				if ($show_plugin) {
					$plugins[] = array($id, (in_array($id,  $a->plugins)?"on":"off") , $info);
				}
			}
		}
	}

	$t = get_markup_template("admin_plugins.tpl");
	return replace_macros($t, array(
		'$title' => t('Administration'),
		'$page' => t('Plugins'),
		'$submit' => t('Save Settings'),
		'$reload' => t('Reload active plugins'),
		'$baseurl' => System::baseUrl(true),
		'$function' => 'plugins',
		'$plugins' => $plugins,
		'$pcount' => count($plugins),
		'$noplugshint' => sprintf(t('There are currently no plugins available on your node. You can find the official plugin repository at %1$s and might find other interesting plugins in the open plugin registry at %2$s'), 'https://github.com/friendica/friendica-addons', 'http://addons.friendi.ca'),
		'$form_security_token' => get_form_security_token("admin_themes"),
	));
}

/**
 * @param array $themes
 * @param string $th
 * @param int $result
 */
function toggle_theme(&$themes,$th,&$result) {
	for($x = 0; $x < count($themes); $x ++) {
		if ($themes[$x]['name'] === $th) {
			if ($themes[$x]['allowed']) {
				$themes[$x]['allowed'] = 0;
				$result = 0;
			}
			else {
				$themes[$x]['allowed'] = 1;
				$result = 1;
			}
		}
	}
}

/**
 * @param array $themes
 * @param string $th
 * @return int
 */
function theme_status($themes,$th) {
	for($x = 0; $x < count($themes); $x ++) {
		if ($themes[$x]['name'] === $th) {
			if ($themes[$x]['allowed']) {
				return 1;
			}
			else {
				return 0;
			}
		}
	}
	return 0;
}


/**
 * @param array $themes
 * @return string
 */
function rebuild_theme_table($themes) {
	$o = '';
	if (count($themes)) {
		foreach ($themes as $th) {
			if ($th['allowed']) {
				if (strlen($o)) {
					$o .= ',';
				}
				$o .= $th['name'];
			}
		}
	}
	return $o;
}


/**
 * @brief Themes admin page
 *
 * This function generates the admin panel page to control the themes available
 * on the friendica node. If the name of a theme is given as parameter a page
 * with the details for the theme is shown. Otherwise a list of available
 * themes is generated.
 *
 * The template used for displaying the list of themes and the details of the
 * themes are the same as used for the plugins.
 *
 * The returned string contains the HTML code of the admin panel page.
 *
 * @param App $a
 * @return string
 */
function admin_page_themes(App $a) {

	$allowed_themes_str = get_config('system','allowed_themes');
	$allowed_themes_raw = explode(',',$allowed_themes_str);
	$allowed_themes = array();
	if (count($allowed_themes_raw)) {
		foreach ($allowed_themes_raw as $x) {
			if (strlen(trim($x))) {
				$allowed_themes[] = trim($x);
			}
		}
	}

	$themes = array();
	$files = glob('view/theme/*');
	if ($files) {
		foreach ($files as $file) {
			$f = basename($file);

			// Is there a style file?
			$theme_files = glob('view/theme/'.$f.'/style.*');

			// If not then quit
			if (count($theme_files) == 0) {
				continue;
			}

			$is_experimental = intval(file_exists($file.'/experimental'));
			$is_supported = 1-(intval(file_exists($file.'/unsupported')));
			$is_allowed = intval(in_array($f,$allowed_themes));

			if ($is_allowed || $is_supported || get_config("system", "show_unsupported_themes")) {
				$themes[] = array('name' => $f, 'experimental' => $is_experimental, 'supported' => $is_supported, 'allowed' => $is_allowed);
			}
		}
	}

	if (! count($themes)) {
		notice(t('No themes found.'));
		return '';
	}

	/*
	 * Single theme
	 */

	if ($a->argc == 3) {
		$theme = $a->argv[2];
		if (! is_dir("view/theme/$theme")) {
			notice(t("Item not found."));
			return '';
		}

		if (x($_GET,"a") && $_GET['a']=="t") {
			check_form_security_token_redirectOnErr('/admin/themes', 'admin_themes', 't');

			// Toggle theme status

			toggle_theme($themes,$theme,$result);
			$s = rebuild_theme_table($themes);
			if ($result) {
				install_theme($theme);
				info(sprintf('Theme %s enabled.',$theme));
			} else {
				uninstall_theme($theme);
				info(sprintf('Theme %s disabled.',$theme));
			}

			set_config('system','allowed_themes',$s);
			goaway('admin/themes');
			return ''; // NOTREACHED
		}

		// display theme details
		require_once('library/markdown.php');

		if (theme_status($themes,$theme)) {
			$status="on"; $action= t("Disable");
		} else {
			$status="off"; $action= t("Enable");
		}

		$readme = Null;
		if (is_file("view/theme/$theme/README.md")) {
			$readme = file_get_contents("view/theme/$theme/README.md");
			$readme = Markdown($readme, false);
		} elseif (is_file("view/theme/$theme/README")) {
			$readme = "<pre>". file_get_contents("view/theme/$theme/README") ."</pre>";
		}

		$admin_form = "";
		if (is_file("view/theme/$theme/config.php")) {
			function __get_theme_admin_form(App $a, $theme) {
				$orig_theme = $a->theme;
				$orig_page = $a->page;
				$orig_session_theme = $_SESSION['theme'];
				require_once("view/theme/$theme/theme.php");
				require_once("view/theme/$theme/config.php");
				$_SESSION['theme'] = $theme;


				$init = $theme."_init";
				if (function_exists($init)) {
					$init($a);
				}
				if (function_exists("theme_admin")) {
					$admin_form = theme_admin($a);
				}

				$_SESSION['theme'] = $orig_session_theme;
				$a->theme = $orig_theme;
				$a->page = $orig_page;
				return $admin_form;
			}
			$admin_form = __get_theme_admin_form($a, $theme);
		}

		$screenshot = array(get_theme_screenshot($theme), t('Screenshot'));
		if (! stristr($screenshot[0],$theme)) {
			$screenshot = null;
		}

		$t = get_markup_template("admin_plugins_details.tpl");
		return replace_macros($t, array(
			'$title' => t('Administration'),
			'$page' => t('Themes'),
			'$toggle' => t('Toggle'),
			'$settings' => t('Settings'),
			'$baseurl' => System::baseUrl(true),
			'$plugin' => $theme,
			'$status' => $status,
			'$action' => $action,
			'$info' => get_theme_info($theme),
			'$function' => 'themes',
			'$admin_form' => $admin_form,
			'$str_author' => t('Author: '),
			'$str_maintainer' => t('Maintainer: '),
			'$screenshot' => $screenshot,
			'$readme' => $readme,

			'$form_security_token' => get_form_security_token("admin_themes"),
		));
	}


	// reload active themes
	if (x($_GET,"a") && $_GET['a']=="r") {
		check_form_security_token_redirectOnErr(System::baseUrl().'/admin/themes', 'admin_themes', 't');
		if ($themes) {
			foreach ($themes as $th) {
				if ($th['allowed']) {
					uninstall_theme($th['name']);
					install_theme($th['name']);
				}
			}
		}
		info("Themes reloaded");
		goaway(System::baseUrl().'/admin/themes');
	}

	/*
	 * List themes
	 */

	$xthemes = array();
	if ($themes) {
		foreach ($themes as $th) {
			$xthemes[] = array($th['name'],(($th['allowed']) ? "on" : "off"), get_theme_info($th['name']));
		}
	}


	$t = get_markup_template("admin_plugins.tpl");
	return replace_macros($t, array(
		'$title'               => t('Administration'),
		'$page'                => t('Themes'),
		'$submit'              => t('Save Settings'),
		'$reload'              => t('Reload active themes'),
		'$baseurl'             => System::baseUrl(true),
		'$function'            => 'themes',
		'$plugins'             => $xthemes,
		'$pcount'              => count($themes),
		'$noplugshint'         => sprintf(t('No themes found on the system. They should be paced in %1$s'),'<code>/view/themes</code>'),
		'$experimental'        => t('[Experimental]'),
		'$unsupported'         => t('[Unsupported]'),
		'$form_security_token' => get_form_security_token("admin_themes"),
	));
}


/**
 * @brief Prosesses data send by Logs admin page
 *
 * @param App $a
 */
function admin_page_logs_post(App $a) {
	if (x($_POST,"page_logs")) {
		check_form_security_token_redirectOnErr('/admin/logs', 'admin_logs');

		$logfile   = ((x($_POST,'logfile'))   ? notags(trim($_POST['logfile']))  : '');
		$debugging = ((x($_POST,'debugging')) ? true                             : false);
		$loglevel  = ((x($_POST,'loglevel'))  ? intval(trim($_POST['loglevel'])) : 0);

		set_config('system','logfile', $logfile);
		set_config('system','debugging',  $debugging);
		set_config('system','loglevel', $loglevel);
	}

	info(t("Log settings updated."));
	goaway('admin/logs');
	return; // NOTREACHED
}

/**
 * @brief Generates admin panel subpage for configuration of the logs
 *
 * This function take the view/templates/admin_logs.tpl file and generates a
 * page where admin can configure the logging of friendica.
 *
 * Displaying the log is separated from the log config as the logfile can get
 * big depending on the settings and changing settings regarding the logs can
 * thus waste bandwidth.
 *
 * The string returned contains the content of the template file with replaced
 * macros.
 *
 * @param App $a
 * @return string
 */
function admin_page_logs(App $a) {

	$log_choices = array(
		LOGGER_NORMAL	=> 'Normal',
		LOGGER_TRACE	=> 'Trace',
		LOGGER_DEBUG	=> 'Debug',
		LOGGER_DATA	=> 'Data',
		LOGGER_ALL	=> 'All'
	);

	if (ini_get('log_errors')) {
		$phplogenabled = t('PHP log currently enabled.');
	} else {
		$phplogenabled = t('PHP log currently disabled.');
	}

	$t = get_markup_template("admin_logs.tpl");

	return replace_macros($t, array(
		'$title' => t('Administration'),
		'$page' => t('Logs'),
		'$submit' => t('Save Settings'),
		'$clear' => t('Clear'),
		'$baseurl' => System::baseUrl(true),
		'$logname' =>  get_config('system','logfile'),

		// name, label, value, help string, extra data...
		'$debugging' => array('debugging', t("Enable Debugging"),get_config('system','debugging'), ""),
		'$logfile' => array('logfile', t("Log file"), get_config('system','logfile'), t("Must be writable by web server. Relative to your Friendica top-level directory.")),
		'$loglevel' => array('loglevel', t("Log level"), get_config('system','loglevel'), "", $log_choices),

		'$form_security_token' => get_form_security_token("admin_logs"),
		'$phpheader' => t("PHP logging"),
		'$phphint' => t("To enable logging of PHP errors and warnings you can add the following to the .htconfig.php file of your installation. The filename set in the 'error_log' line is relative to the friendica top-level directory and must be writeable by the web server. The option '1' for 'log_errors' and 'display_errors' is to enable these options, set to '0' to disable them."),
		'$phplogcode' => "error_reporting(E_ERROR | E_WARNING | E_PARSE);\nini_set('error_log','php.out');\nini_set('log_errors','1');\nini_set('display_errors', '1');",
		'$phplogenabled' => $phplogenabled,
	));
}

/**
 * @brief Generates admin panel subpage to view the Friendica log
 *
 * This function loads the template view/templates/admin_viewlogs.tpl to
 * display the systemlog content. The filename for the systemlog of friendica
 * is relative to the base directory and taken from the config entry 'logfile'
 * in the 'system' category.
 *
 * Displaying the log is separated from the log config as the logfile can get
 * big depending on the settings and changing settings regarding the logs can
 * thus waste bandwidth.
 *
 * The string returned contains the content of the template file with replaced
 * macros.
 *
 * @param App $a
 * @return string
 */
function admin_page_viewlogs(App $a) {
	$t = get_markup_template("admin_viewlogs.tpl");
	$f = get_config('system','logfile');
	$data = '';

	if (!file_exists($f)) {
		$data = t("Error trying to open <strong>$f</strong> log file.\r\n<br/>Check to see if file $f exist and is readable.");
	} else {
		$fp = fopen($f, 'r');
		if (!$fp) {
			$data = t("Couldn't open <strong>$f</strong> log file.\r\n<br/>Check to see if file $f is readable.");
		} else {
			$fstat = fstat($fp);
			$size = $fstat['size'];
			if ($size != 0) {
				if ($size > 5000000 || $size < 0) {
					$size = 5000000;
				}
				$seek = fseek($fp,0-$size,SEEK_END);
				if ($seek === 0) {
					$data = escape_tags(fread($fp,$size));
					while (! feof($fp)) {
						$data .= escape_tags(fread($fp,4096));
					}
				}
			}
			fclose($fp);
		}
	}
	return replace_macros($t, array(
		'$title' => t('Administration'),
		'$page' => t('View Logs'),
		'$data' => $data,
		'$logname' =>  get_config('system','logfile')
	));
}

/**
 * @brief Prosesses data send by the features admin page
 *
 * @param App $a
 */
function admin_page_features_post(App $a) {

	check_form_security_token_redirectOnErr('/admin/features', 'admin_manage_features');

	logger('postvars: '.print_r($_POST,true),LOGGER_DATA);

	$arr = array();
	$features = get_features(false);

	foreach ($features as $fname => $fdata) {
		foreach (array_slice($fdata, 1) as $f) {
			$feature = $f[0];
			$feature_state = 'feature_' . $feature;
			$featurelock = 'featurelock_' . $feature;

			if (x($_POST, $feature_state)) {
				$val = intval($_POST[$feature_state]);
			} else {
				$val = 0;
			}
			set_config('feature',$feature,$val);

			if (x($_POST, $featurelock)) {
				set_config('feature_lock', $feature, $val);
			} else {
				del_config('feature_lock', $feature);
			}
		}
	}

	goaway('admin/features');
	return; // NOTREACHED
}

/**
 * @brief Subpage for global additional feature management
 *
 * This functin generates the subpage 'Manage Additional Features'
 * for the admin panel. At this page the admin can set preferences
 * for the user settings of the 'additional features'. If needed this
 * preferences can be locked through the admin.
 *
 * The returned string contains the HTML code of the subpage 'Manage
 * Additional Features'
 *
 * @param App $a
 * @return string
 */
function admin_page_features(App $a) {

	if ((argc() > 1) && (argv(1) === 'features')) {
		$arr = array();
		$features = get_features(false);

		foreach ($features as $fname => $fdata) {
			$arr[$fname] = array();
			$arr[$fname][0] = $fdata[0];
			foreach (array_slice($fdata,1) as $f) {

				$set = get_config('feature',$f[0]);
				if ($set === false) {
					$set = $f[3];
				}
				$arr[$fname][1][] = array(
					array('feature_' .$f[0],$f[1],$set,$f[2],array(t('Off'), t('On'))),
					array('featurelock_' .$f[0],sprintf(t('Lock feature %s'),$f[1]),(($f[4] !== false) ? "1" : ''),'',array(t('Off'), t('On')))
				);
			}
		}

		$tpl = get_markup_template("admin_settings_features.tpl");
		$o .= replace_macros($tpl, array(
			'$form_security_token' => get_form_security_token("admin_manage_features"),
			'$title' => t('Manage Additional Features'),
			'$features' => $arr,
			'$submit' => t('Save Settings'),
		));

		return $o;
	}
}
