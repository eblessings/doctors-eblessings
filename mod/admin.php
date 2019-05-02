<?php
/**
 * @file mod/admin.php
 *
 * @brief Friendica admin
 */

use Friendica\App;
use Friendica\BaseModule;
use Friendica\Content\Feature;
use Friendica\Content\Pager;
use Friendica\Content\Text\Markdown;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\Logger;
use Friendica\Core\Renderer;
use Friendica\Core\StorageManager;
use Friendica\Core\System;
use Friendica\Core\Theme;
use Friendica\Core\Update;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\Database\DBStructure;
use Friendica\Model\Contact;
use Friendica\Model\Item;
use Friendica\Model\Register;
use Friendica\Model\User;
use Friendica\Module;
use Friendica\Module\Login;
use Friendica\Module\Tos;
use Friendica\Protocol\PortableContact;
use Friendica\Util\Arrays;
use Friendica\Util\BasePath;
use Friendica\Util\BaseURL;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Network;
use Friendica\Util\Strings;
use Friendica\Util\Temporal;
use Psr\Log\LogLevel;

/**
 * Sets the current theme for theme settings pages.
 *
 * This needs to be done before the post() or content() methods are called.
 *
 * @param App $a
 */
function admin_init(App $a)
{
	if ($a->argc > 2 && $a->argv[1] == 'themes') {
		$theme = $a->argv[2];
		if (is_file("view/theme/$theme/config.php")) {
			$a->setCurrentTheme($theme);
		}
	}
}

/**
 * @brief Process send data from the admin panels subpages
 *
 * This function acts as relay for processing the data send from the subpages
 * of the admin panel. Depending on the 1st parameter of the url (argv[1])
 * specialized functions are called to process the data from the subpages.
 *
 * The function itself does not return anything, but the subsequently function
 * return the HTML for the pages of the admin panel.
 *
 * @param App $a
 * @throws ImagickException
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_post(App $a)
{
	if (!is_site_admin()) {
		return;
	}

	// do not allow a page manager to access the admin panel at all.

	if (!empty($_SESSION['submanage'])) {
		return;
	}

	$return_path = 'admin';
	if ($a->argc > 1) {
		switch ($a->argv[1]) {
			case 'site':
				admin_page_site_post($a);
				break;
			case 'themes':
				if ($a->argc < 2) {
					if ($a->isAjax()) {
						return;
					}
					$a->internalRedirect('admin/');
					return;
				}

				$theme = $a->argv[2];
				if (is_file("view/theme/$theme/config.php")) {
					require_once "view/theme/$theme/config.php";

					if (function_exists('theme_admin_post')) {
						theme_admin_post($a);
					}
				}

				info(L10n::t('Theme settings updated.'));
				if ($a->isAjax()) {
					return;
				}
				$return_path = 'admin/themes/' . $theme . (!empty($_GET['mode']) ? '?mode=' . $_GET['mode'] : '');
				break;
			case 'logs':
				admin_page_logs_post($a);
				break;
			case 'blocklist':
				admin_page_blocklist_post($a);
				break;
			case 'deleteitem':
				admin_page_deleteitem_post($a);
				break;
		}
	}

	$a->internalRedirect($return_path);
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
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_content(App $a)
{
	if (!is_site_admin()) {
		return Login::form();
	}

	if (!empty($_SESSION['submanage'])) {
		return "";
	}

	// APC deactivated, since there are problems with PHP 5.5
	//if (function_exists("apc_delete")) {
	// $toDelete = new APCIterator('user', APC_ITER_VALUE);
	// apc_delete($toDelete);
	//}
	// Header stuff
	$a->page['htmlhead'] .= Renderer::replaceMacros(Renderer::getMarkupTemplate('admin/settings_head.tpl'), []);

	/*
	 * Side bar links
	 */
	$aside_tools = [];
	// array(url, name, extra css classes)
	// not part of $aside to make the template more adjustable
	$aside_sub = [
		'information' => [L10n::t('Information'), [
			'overview' => ['admin/', L10n::t('Overview'), 'overview'],
			'federation'   => ['admin/federation/'  , L10n::t('Federation Statistics'), 'federation']]],
		'configuration' => [L10n::t('Configuration'), [
			'site'         => ['admin/site/'        , L10n::t('Site')                    , 'site'],
			'users'        => ['admin/users/'       , L10n::t('Users')                   , 'users'],
			'addons'       => ['admin/addons/'      , L10n::t('Addons')                  , 'addons'],
			'themes'       => ['admin/themes/'      , L10n::t('Themes')                  , 'themes'],
			'features'     => ['admin/features/'    , L10n::t('Additional features')     , 'features'],
			'tos'          => ['admin/tos/'         , L10n::t('Terms of Service')        , 'tos']]],
		'database' => [L10n::t('Database'), [
			'dbsync'       => ['admin/dbsync/'      , L10n::t('DB updates')              , 'dbsync'],
			'deferred'     => ['admin/deferred/'    , L10n::t('Inspect Deferred Workers'), 'deferred'],
			'workerqueue'  => ['admin/workerqueue/' , L10n::t('Inspect worker Queue')    , 'workerqueue']]],
		'tools' => [L10n::t('Tools'), [
			'contactblock' => ['admin/contactblock/', L10n::t('Contact Blocklist')       , 'contactblock'],
			'blocklist'    => ['admin/blocklist/'   , L10n::t('Server Blocklist')        , 'blocklist'],
			'deleteitem'   => ['admin/deleteitem/'  , L10n::t('Delete Item')             , 'deleteitem'],]],
		'logs' => [L10n::t('Logs'), [
			'logsconfig' => ['admin/logs/', L10n::t('Logs'), 'logs'],
			'logsview' => ['admin/viewlogs/', L10n::t('View Logs'), 'viewlogs']
		]],
		'diagnostics' => [L10n::t('Diagnostics'), [
			'phpinfo' => ['phpinfo/', L10n::t('PHP Info'), 'phpinfo'],
			'probe' => ['probe/', L10n::t('probe address'), 'probe'],
			'webfinger' =>['webfinger/', L10n::t('check webfinger'), 'webfinger']
		]]
	];

	$aside_tools['addons_admin'] = [];

	$t = Renderer::getMarkupTemplate('admin/aside.tpl');
	$a->page['aside'] .= Renderer::replaceMacros($t, [
		'$admin' => $aside_tools,
		'$subpages' => $aside_sub,
		'$admtxt' => L10n::t('Admin'),
		'$plugadmtxt' => L10n::t('Addon Features'),
		'$h_pending' => L10n::t('User registrations waiting for confirmation'),
		'$admurl' => "admin/"
	]);

	// Page content
	$o = '';
	// urls
	if ($a->argc > 1) {
		switch ($a->argv[1]) {
			case 'site':
				$o = admin_page_site($a);
				break;
			case 'themes':
				$o = admin_page_themes($a);
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
			case 'deferred':
				$o = admin_page_workerqueue($a, true);
				break;
			case 'workerqueue':
				$o = admin_page_workerqueue($a, false);
				break;
			case 'blocklist':
				$o = admin_page_blocklist($a);
				break;
			case 'deleteitem':
				$o = admin_page_deleteitem($a);
				break;
			default:
				notice(L10n::t("Item not found."));
		}
	}

	if ($a->isAjax()) {
		echo $o;
		exit();
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
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_blocklist(App $a)
{
	$blocklist = Config::get('system', 'blocklist');
	$blocklistform = [];
	if (is_array($blocklist)) {
		foreach ($blocklist as $id => $b) {
			$blocklistform[] = [
				'domain' => ["domain[$id]", L10n::t('Blocked domain'), $b['domain'], '', L10n::t('The blocked domain'), 'required', '', ''],
				'reason' => ["reason[$id]", L10n::t("Reason for the block"), $b['reason'], L10n::t('The reason why you blocked this domain.') . '(' . $b['domain'] . ')', 'required', '', ''],
				'delete' => ["delete[$id]", L10n::t("Delete domain") . ' (' . $b['domain'] . ')', false, L10n::t("Check to delete this entry from the blocklist")]
			];
		}
	}
	$t = Renderer::getMarkupTemplate('admin/blocklist.tpl');
	return Renderer::replaceMacros($t, [
		'$title' => L10n::t('Administration'),
		'$page' => L10n::t('Server Blocklist'),
		'$intro' => L10n::t('This page can be used to define a black list of servers from the federated network that are not allowed to interact with your node. For all entered domains you should also give a reason why you have blocked the remote server.'),
		'$public' => L10n::t('The list of blocked servers will be made publically available on the /friendica page so that your users and people investigating communication problems can find the reason easily.'),
		'$addtitle' => L10n::t('Add new entry to block list'),
		'$newdomain' => ['newentry_domain', L10n::t('Server Domain'), '', L10n::t('The domain of the new server to add to the block list. Do not include the protocol.'), 'required', '', ''],
		'$newreason' => ['newentry_reason', L10n::t('Block reason'), '', L10n::t('The reason why you blocked this domain.'), 'required', '', ''],
		'$submit' => L10n::t('Add Entry'),
		'$savechanges' => L10n::t('Save changes to the blocklist'),
		'$currenttitle' => L10n::t('Current Entries in the Blocklist'),
		'$thurl' => L10n::t('Blocked domain'),
		'$threason' => L10n::t('Reason for the block'),
		'$delentry' => L10n::t('Delete entry from blocklist'),
		'$entries' => $blocklistform,
		'$baseurl' => System::baseUrl(true),
		'$confirm_delete' => L10n::t('Delete entry from blocklist?'),
		'$form_security_token' => BaseModule::getFormSecurityToken("admin_blocklist")
	]);
}

/**
 * @brief Process send data from Admin Blocklist Page
 *
 * @param App $a
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_blocklist_post(App $a)
{
	if (empty($_POST['page_blocklist_save']) && empty($_POST['page_blocklist_edit'])) {
		return;
	}

	BaseModule::checkFormSecurityTokenRedirectOnError('/admin/blocklist', 'admin_blocklist');

	if (!empty($_POST['page_blocklist_save'])) {
		//  Add new item to blocklist
		$blocklist = Config::get('system', 'blocklist');
		$blocklist[] = [
			'domain' => Strings::escapeTags(trim($_POST['newentry_domain'])),
			'reason' => Strings::escapeTags(trim($_POST['newentry_reason']))
		];
		Config::set('system', 'blocklist', $blocklist);
		info(L10n::t('Server added to blocklist.') . EOL);
	} else {
		// Edit the entries from blocklist
		$blocklist = [];
		foreach ($_POST['domain'] as $id => $domain) {
			// Trimming whitespaces as well as any lingering slashes
			$domain = Strings::escapeTags(trim($domain, "\x00..\x1F/"));
			$reason = Strings::escapeTags(trim($_POST['reason'][$id]));
			if (empty($_POST['delete'][$id])) {
				$blocklist[] = [
					'domain' => $domain,
					'reason' => $reason
				];
			}
		}
		Config::set('system', 'blocklist', $blocklist);
		info(L10n::t('Site blocklist updated.') . EOL);
	}
	$a->internalRedirect('admin/blocklist');

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
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_deleteitem(App $a)
{
	$t = Renderer::getMarkupTemplate('admin/deleteitem.tpl');

	return Renderer::replaceMacros($t, [
		'$title' => L10n::t('Administration'),
		'$page' => L10n::t('Delete Item'),
		'$submit' => L10n::t('Delete this Item'),
		'$intro1' => L10n::t('On this page you can delete an item from your node. If the item is a top level posting, the entire thread will be deleted.'),
		'$intro2' => L10n::t('You need to know the GUID of the item. You can find it e.g. by looking at the display URL. The last part of http://example.com/display/123456 is the GUID, here 123456.'),
		'$deleteitemguid' => ['deleteitemguid', L10n::t("GUID"), '', L10n::t("The GUID of the item you want to delete."), 'required', 'autofocus'],
		'$baseurl' => System::baseUrl(),
		'$form_security_token' => BaseModule::getFormSecurityToken("admin_deleteitem")
	]);
}

/**
 * @brief Process send data from Admin Delete Item Page
 *
 * The GUID passed through the form should be only the GUID. But we also parse
 * URLs like the full /display URL to make the process more easy for the admin.
 *
 * @param App $a
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_deleteitem_post(App $a)
{
	if (empty($_POST['page_deleteitem_submit'])) {
		return;
	}

	BaseModule::checkFormSecurityTokenRedirectOnError('/admin/deleteitem/', 'admin_deleteitem');

	if (!empty($_POST['page_deleteitem_submit'])) {
		$guid = trim(Strings::escapeTags($_POST['deleteitemguid']));
		// The GUID should not include a "/", so if there is one, we got an URL
		// and the last part of it is most likely the GUID.
		if (strpos($guid, '/')) {
			$guid = substr($guid, strrpos($guid, '/') + 1);
		}
		// Now that we have the GUID, drop those items, which will also delete the
		// associated threads.
		Item::delete(['guid' => $guid]);
	}

	info(L10n::t('Item marked for deletion.') . EOL);
	$a->internalRedirect('admin/deleteitem');
	return; // NOTREACHED
}

/**
 * @brief Admin Inspect Worker Queue Page
 *
 * Generates a page for the admin to have a look into the current queue of
 * worker jobs. Shown are the parameters for the job and its priority.
 *
 * The returned string holds the content of the page.
 *
 * @param App $a
 * @param     $deferred
 * @return string
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_workerqueue(App $a, $deferred)
{
	// get jobs from the workerqueue table
	if ($deferred) {
		$condition = ["NOT `done` AND `next_try` > ?", DateTimeFormat::utcNow()];
		$sub_title = L10n::t('Inspect Deferred Worker Queue');
		$info = L10n::t("This page lists the deferred worker jobs. This are jobs that couldn't be executed at the first time.");
	} else {
		$condition = ["NOT `done` AND `next_try` < ?", DateTimeFormat::utcNow()];
		$sub_title = L10n::t('Inspect Worker Queue');
		$info = L10n::t('This page lists the currently queued worker jobs. These jobs are handled by the worker cronjob you\'ve set up during install.');
	}

	$entries = DBA::select('workerqueue', ['id', 'parameter', 'created', 'priority'], $condition, ['order' => ['priority']]);

	$r = [];
	while ($entry = DBA::fetch($entries)) {
		// fix GH-5469. ref: src/Core/Worker.php:217
		$entry['parameter'] = Arrays::recursiveImplode(json_decode($entry['parameter'], true), ': ');
		$entry['created'] = DateTimeFormat::local($entry['created']);
		$r[] = $entry;
	}
	DBA::close($entries);

	$t = Renderer::getMarkupTemplate('admin/workerqueue.tpl');
	return Renderer::replaceMacros($t, [
		'$title' => L10n::t('Administration'),
		'$page' => $sub_title,
		'$count' => count($r),
		'$id_header' => L10n::t('ID'),
		'$param_header' => L10n::t('Job Parameters'),
		'$created_header' => L10n::t('Created'),
		'$prio_header' => L10n::t('Priority'),
		'$info' => $info,
		'$entries' => $r,
	]);
}

/**
 * @brief Process send data from Admin Site Page
 *
 * @param App $a
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_site_post(App $a)
{
	BaseModule::checkFormSecurityTokenRedirectOnError('/admin/site', 'admin_site');

	if (!empty($_POST['republish_directory'])) {
		Worker::add(PRIORITY_LOW, 'Directory');
		return;
	}

	if (empty($_POST['page_site'])) {
		return;
	}

	// relocate
	if (!empty($_POST['relocate']) && !empty($_POST['relocate_url']) && $_POST['relocate_url'] != "") {
		$new_url = $_POST['relocate_url'];
		$new_url = rtrim($new_url, "/");

		$parsed = @parse_url($new_url);
		if (!is_array($parsed) || empty($parsed['host']) || empty($parsed['scheme'])) {
			notice(L10n::t("Can not parse base url. Must have at least <scheme>://<domain>"));
			$a->internalRedirect('admin/site');
		}

		/* steps:
		 * replace all "baseurl" to "new_url" in config, profile, term, items and contacts
		 * send relocate for every local user
		 * */

		$old_url = $a->getBaseURL(true);

		// Generate host names for relocation the addresses in the format user@address.tld
		$new_host = str_replace("http://", "@", Strings::normaliseLink($new_url));
		$old_host = str_replace("http://", "@", Strings::normaliseLink($old_url));

		function update_table(App $a, $table_name, $fields, $old_url, $new_url)
		{
			$dbold = DBA::escape($old_url);
			$dbnew = DBA::escape($new_url);

			$upd = [];
			foreach ($fields as $f) {
				$upd[] = "`$f` = REPLACE(`$f`, '$dbold', '$dbnew')";
			}

			$upds = implode(", ", $upd);

			$r = q("UPDATE %s SET %s;", $table_name, $upds);

			if (!DBA::isResult($r)) {
				notice("Failed updating '$table_name': " . DBA::errorMessage());
				$a->internalRedirect('admin/site');
			}
		}

		// update tables
		// update profile links in the format "http://server.tld"
		update_table($a, "profile", ['photo', 'thumb'], $old_url, $new_url);
		update_table($a, "term", ['url'], $old_url, $new_url);
		update_table($a, "contact", ['photo', 'thumb', 'micro', 'url', 'nurl', 'alias', 'request', 'notify', 'poll', 'confirm', 'poco', 'avatar'], $old_url, $new_url);
		update_table($a, "gcontact", ['url', 'nurl', 'photo', 'server_url', 'notify', 'alias'], $old_url, $new_url);
		update_table($a, "item", ['owner-link', 'author-link', 'body', 'plink', 'tag'], $old_url, $new_url);

		// update profile addresses in the format "user@server.tld"
		update_table($a, "contact", ['addr'], $old_host, $new_host);
		update_table($a, "gcontact", ['connect', 'addr'], $old_host, $new_host);

		// update config
		Config::set('system', 'url', $new_url);
		$a->setBaseURL($new_url);

		// send relocate
		$users = q("SELECT `uid` FROM `user` WHERE `account_removed` = 0 AND `account_expired` = 0");

		foreach ($users as $user) {
			Worker::add(PRIORITY_HIGH, 'Notifier', 'relocate', $user['uid']);
		}

		info("Relocation started. Could take a while to complete.");

		$a->internalRedirect('admin/site');
	}
	// end relocate

	$sitename         = (!empty($_POST['sitename'])         ? Strings::escapeTags(trim($_POST['sitename']))      : '');
	$sender_email     = (!empty($_POST['sender_email'])     ? Strings::escapeTags(trim($_POST['sender_email']))  : '');
	$banner           = (!empty($_POST['banner'])           ? trim($_POST['banner'])                             : false);
	$shortcut_icon    = (!empty($_POST['shortcut_icon'])    ? Strings::escapeTags(trim($_POST['shortcut_icon'])) : '');
	$touch_icon       = (!empty($_POST['touch_icon'])       ? Strings::escapeTags(trim($_POST['touch_icon']))    : '');
	$additional_info  = (!empty($_POST['additional_info'])  ? trim($_POST['additional_info'])                    : '');
	$language         = (!empty($_POST['language'])         ? Strings::escapeTags(trim($_POST['language']))      : '');
	$theme            = (!empty($_POST['theme'])            ? Strings::escapeTags(trim($_POST['theme']))         : '');
	$theme_mobile     = (!empty($_POST['theme_mobile'])     ? Strings::escapeTags(trim($_POST['theme_mobile']))  : '');
	$maximagesize     = (!empty($_POST['maximagesize'])     ? intval(trim($_POST['maximagesize']))               : 0);
	$maximagelength   = (!empty($_POST['maximagelength'])   ? intval(trim($_POST['maximagelength']))             : MAX_IMAGE_LENGTH);
	$jpegimagequality = (!empty($_POST['jpegimagequality']) ? intval(trim($_POST['jpegimagequality']))           : JPEG_QUALITY);

	$register_policy        = (!empty($_POST['register_policy'])         ? intval(trim($_POST['register_policy']))             : 0);
	$daily_registrations    = (!empty($_POST['max_daily_registrations']) ? intval(trim($_POST['max_daily_registrations']))     : 0);
	$abandon_days           = (!empty($_POST['abandon_days'])            ? intval(trim($_POST['abandon_days']))                : 0);

	$register_text          = (!empty($_POST['register_text'])           ? strip_tags(trim($_POST['register_text']))           : '');

	$allowed_sites          = (!empty($_POST['allowed_sites'])           ? Strings::escapeTags(trim($_POST['allowed_sites']))  : '');
	$allowed_email          = (!empty($_POST['allowed_email'])           ? Strings::escapeTags(trim($_POST['allowed_email']))  : '');
	$forbidden_nicknames    = (!empty($_POST['forbidden_nicknames'])     ? strtolower(Strings::escapeTags(trim($_POST['forbidden_nicknames']))) : '');
	$no_oembed_rich_content = !empty($_POST['no_oembed_rich_content']);
	$allowed_oembed         = (!empty($_POST['allowed_oembed'])          ? Strings::escapeTags(trim($_POST['allowed_oembed'])) : '');
	$block_public           = !empty($_POST['block_public']);
	$force_publish          = !empty($_POST['publish_all']);
	$global_directory       = (!empty($_POST['directory'])               ? Strings::escapeTags(trim($_POST['directory']))      : '');
	$newuser_private        = !empty($_POST['newuser_private']);
	$enotify_no_content     = !empty($_POST['enotify_no_content']);
	$private_addons         = !empty($_POST['private_addons']);
	$disable_embedded       = !empty($_POST['disable_embedded']);
	$allow_users_remote_self = !empty($_POST['allow_users_remote_self']);
	$explicit_content       = !empty($_POST['explicit_content']);

	$no_multi_reg           = !empty($_POST['no_multi_reg']);
	$no_openid              = !empty($_POST['no_openid']);
	$no_regfullname         = !empty($_POST['no_regfullname']);
	$community_page_style   = (!empty($_POST['community_page_style']) ? intval(trim($_POST['community_page_style'])) : 0);
	$max_author_posts_community_page = (!empty($_POST['max_author_posts_community_page']) ? intval(trim($_POST['max_author_posts_community_page'])) : 0);

	$verifyssl              = !empty($_POST['verifyssl']);
	$proxyuser              = (!empty($_POST['proxyuser'])              ? Strings::escapeTags(trim($_POST['proxyuser'])) : '');
	$proxy                  = (!empty($_POST['proxy'])                  ? Strings::escapeTags(trim($_POST['proxy']))     : '');
	$timeout                = (!empty($_POST['timeout'])                ? intval(trim($_POST['timeout']))                : 60);
	$maxloadavg             = (!empty($_POST['maxloadavg'])             ? intval(trim($_POST['maxloadavg']))             : 20);
	$maxloadavg_frontend    = (!empty($_POST['maxloadavg_frontend'])    ? intval(trim($_POST['maxloadavg_frontend']))    : 50);
	$min_memory             = (!empty($_POST['min_memory'])             ? intval(trim($_POST['min_memory']))             : 0);
	$optimize_max_tablesize = (!empty($_POST['optimize_max_tablesize']) ? intval(trim($_POST['optimize_max_tablesize'])) : 100);
	$optimize_fragmentation = (!empty($_POST['optimize_fragmentation']) ? intval(trim($_POST['optimize_fragmentation'])) : 30);
	$poco_completion        = (!empty($_POST['poco_completion'])        ? intval(trim($_POST['poco_completion']))        : false);
	$poco_requery_days      = (!empty($_POST['poco_requery_days'])      ? intval(trim($_POST['poco_requery_days']))      : 7);
	$poco_discovery         = (!empty($_POST['poco_discovery'])         ? intval(trim($_POST['poco_discovery']))         : PortableContact::DISABLED);
	$poco_discovery_since   = (!empty($_POST['poco_discovery_since'])   ? intval(trim($_POST['poco_discovery_since']))   : 30);
	$poco_local_search      = !empty($_POST['poco_local_search']);
	$nodeinfo               = !empty($_POST['nodeinfo']);
	$dfrn_only              = !empty($_POST['dfrn_only']);
	$ostatus_disabled       = !empty($_POST['ostatus_disabled']);
	$ostatus_full_threads   = !empty($_POST['ostatus_full_threads']);
	$diaspora_enabled       = !empty($_POST['diaspora_enabled']);
	$ssl_policy             = (!empty($_POST['ssl_policy'])             ? intval($_POST['ssl_policy'])                    : 0);
	$force_ssl              = !empty($_POST['force_ssl']);
	$hide_help              = !empty($_POST['hide_help']);
	$dbclean                = !empty($_POST['dbclean']);
	$dbclean_expire_days    = (!empty($_POST['dbclean_expire_days'])    ? intval($_POST['dbclean_expire_days'])           : 0);
	$dbclean_unclaimed      = (!empty($_POST['dbclean_unclaimed'])      ? intval($_POST['dbclean_unclaimed'])             : 0);
	$dbclean_expire_conv    = (!empty($_POST['dbclean_expire_conv'])    ? intval($_POST['dbclean_expire_conv'])           : 0);
	$suppress_tags          = !empty($_POST['suppress_tags']);
	$itemcache              = (!empty($_POST['itemcache'])              ? Strings::escapeTags(trim($_POST['itemcache']))  : '');
	$itemcache_duration     = (!empty($_POST['itemcache_duration'])     ? intval($_POST['itemcache_duration'])            : 0);
	$max_comments           = (!empty($_POST['max_comments'])           ? intval($_POST['max_comments'])                  : 0);
	$temppath               = (!empty($_POST['temppath'])               ? Strings::escapeTags(trim($_POST['temppath']))   : '');
	$singleuser             = (!empty($_POST['singleuser'])             ? Strings::escapeTags(trim($_POST['singleuser'])) : '');
	$proxy_disabled         = !empty($_POST['proxy_disabled']);
	$only_tag_search        = !empty($_POST['only_tag_search']);
	$rino                   = (!empty($_POST['rino'])                   ? intval($_POST['rino'])                          : 0);
	$check_new_version_url  = (!empty($_POST['check_new_version_url'])  ? Strings::escapeTags(trim($_POST['check_new_version_url'])) : 'none');

	$worker_queues    = (!empty($_POST['worker_queues'])                ? intval($_POST['worker_queues'])                 : 10);
	$worker_dont_fork = !empty($_POST['worker_dont_fork']);
	$worker_fastlane  = !empty($_POST['worker_fastlane']);
	$worker_frontend  = !empty($_POST['worker_frontend']);

	$relay_directly    = !empty($_POST['relay_directly']);
	$relay_server      = (!empty($_POST['relay_server'])      ? Strings::escapeTags(trim($_POST['relay_server']))       : '');
	$relay_subscribe   = !empty($_POST['relay_subscribe']);
	$relay_scope       = (!empty($_POST['relay_scope'])       ? Strings::escapeTags(trim($_POST['relay_scope']))        : '');
	$relay_server_tags = (!empty($_POST['relay_server_tags']) ? Strings::escapeTags(trim($_POST['relay_server_tags']))  : '');
	$relay_user_tags   = !empty($_POST['relay_user_tags']);
	$active_panel      = (!empty($_POST['active_panel'])      ? "#" . Strings::escapeTags(trim($_POST['active_panel'])) : '');

	/**
	 * @var $storagebackend \Friendica\Model\Storage\IStorage
	 */
	$storagebackend    = Strings::escapeTags(trim(defaults($_POST, 'storagebackend', '')));

	// save storage backend form
	if (!is_null($storagebackend) && $storagebackend != "") {
		if (StorageManager::setBackend($storagebackend)) {
			$storage_opts = $storagebackend::getOptions();
			$storage_form_prefix = preg_replace('|[^a-zA-Z0-9]|', '', $storagebackend);
			$storage_opts_data = [];
			foreach ($storage_opts as $name => $info) {
				$fieldname = $storage_form_prefix . '_' . $name;
				switch ($info[0]) { // type
					case 'checkbox':
					case 'yesno':
						$value = !empty($_POST[$fieldname]);
						break;
					default:
						$value = defaults($_POST, $fieldname, '');
				}
				$storage_opts_data[$name] = $value;
			}
			unset($name);
			unset($info);

			$storage_form_errors = $storagebackend::saveOptions($storage_opts_data);
			if (count($storage_form_errors)) {
				foreach ($storage_form_errors as $name => $err) {
					notice('Storage backend, ' . $storage_opts[$name][1] . ': ' . $err);
				}
				$a->internalRedirect('admin/site' . $active_panel);
			}
		} else {
			info(L10n::t('Invalid storage backend setting value.'));
		}
	}



	// Has the directory url changed? If yes, then resubmit the existing profiles there
	if ($global_directory != Config::get('system', 'directory') && ($global_directory != '')) {
		Config::set('system', 'directory', $global_directory);
		Worker::add(PRIORITY_LOW, 'Directory');
	}

	if ($a->getURLPath() != "") {
		$diaspora_enabled = false;
	}
	if ($ssl_policy != intval(Config::get('system', 'ssl_policy'))) {
		if ($ssl_policy == BaseURL::SSL_POLICY_FULL) {
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
		} elseif ($ssl_policy == BaseURL::SSL_POLICY_SELFSIGN) {
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
	Config::set('system', 'ssl_policy'            , $ssl_policy);
	Config::set('system', 'maxloadavg'            , $maxloadavg);
	Config::set('system', 'maxloadavg_frontend'   , $maxloadavg_frontend);
	Config::set('system', 'min_memory'            , $min_memory);
	Config::set('system', 'optimize_max_tablesize', $optimize_max_tablesize);
	Config::set('system', 'optimize_fragmentation', $optimize_fragmentation);
	Config::set('system', 'poco_completion'       , $poco_completion);
	Config::set('system', 'poco_requery_days'     , $poco_requery_days);
	Config::set('system', 'poco_discovery'        , $poco_discovery);
	Config::set('system', 'poco_discovery_since'  , $poco_discovery_since);
	Config::set('system', 'poco_local_search'     , $poco_local_search);
	Config::set('system', 'nodeinfo'              , $nodeinfo);
	Config::set('config', 'sitename'              , $sitename);
	Config::set('config', 'sender_email'          , $sender_email);
	Config::set('system', 'suppress_tags'         , $suppress_tags);
	Config::set('system', 'shortcut_icon'         , $shortcut_icon);
	Config::set('system', 'touch_icon'            , $touch_icon);

	if ($banner == "") {
		Config::delete('system', 'banner');
	} else {
		Config::set('system', 'banner', $banner);
	}

	if (empty($additional_info)) {
		Config::delete('config', 'info');
	} else {
		Config::set('config', 'info', $additional_info);
	}
	Config::set('system', 'language', $language);
	Config::set('system', 'theme', $theme);
	Theme::install($theme);

	if ($theme_mobile == '---') {
		Config::delete('system', 'mobile-theme');
	} else {
		Config::set('system', 'mobile-theme', $theme_mobile);
	}
	if ($singleuser == '---') {
		Config::delete('system', 'singleuser');
	} else {
		Config::set('system', 'singleuser', $singleuser);
	}
	Config::set('system', 'maximagesize'           , $maximagesize);
	Config::set('system', 'max_image_length'       , $maximagelength);
	Config::set('system', 'jpeg_quality'           , $jpegimagequality);

	Config::set('config', 'register_policy'        , $register_policy);
	Config::set('system', 'max_daily_registrations', $daily_registrations);
	Config::set('system', 'account_abandon_days'   , $abandon_days);
	Config::set('config', 'register_text'          , $register_text);
	Config::set('system', 'allowed_sites'          , $allowed_sites);
	Config::set('system', 'allowed_email'          , $allowed_email);
	Config::set('system', 'forbidden_nicknames'    , $forbidden_nicknames);
	Config::set('system', 'no_oembed_rich_content' , $no_oembed_rich_content);
	Config::set('system', 'allowed_oembed'         , $allowed_oembed);
	Config::set('system', 'block_public'           , $block_public);
	Config::set('system', 'publish_all'            , $force_publish);
	Config::set('system', 'newuser_private'        , $newuser_private);
	Config::set('system', 'enotify_no_content'     , $enotify_no_content);
	Config::set('system', 'disable_embedded'       , $disable_embedded);
	Config::set('system', 'allow_users_remote_self', $allow_users_remote_self);
	Config::set('system', 'explicit_content'       , $explicit_content);
	Config::set('system', 'check_new_version_url'  , $check_new_version_url);

	Config::set('system', 'block_extended_register', $no_multi_reg);
	Config::set('system', 'no_openid'              , $no_openid);
	Config::set('system', 'no_regfullname'         , $no_regfullname);
	Config::set('system', 'community_page_style'   , $community_page_style);
	Config::set('system', 'max_author_posts_community_page', $max_author_posts_community_page);
	Config::set('system', 'verifyssl'              , $verifyssl);
	Config::set('system', 'proxyuser'              , $proxyuser);
	Config::set('system', 'proxy'                  , $proxy);
	Config::set('system', 'curl_timeout'           , $timeout);
	Config::set('system', 'dfrn_only'              , $dfrn_only);
	Config::set('system', 'ostatus_disabled'       , $ostatus_disabled);
	Config::set('system', 'ostatus_full_threads'   , $ostatus_full_threads);
	Config::set('system', 'diaspora_enabled'       , $diaspora_enabled);

	Config::set('config', 'private_addons'         , $private_addons);

	Config::set('system', 'force_ssl'              , $force_ssl);
	Config::set('system', 'hide_help'              , $hide_help);

	Config::set('system', 'dbclean'                , $dbclean);
	Config::set('system', 'dbclean-expire-days'    , $dbclean_expire_days);
	Config::set('system', 'dbclean_expire_conversation', $dbclean_expire_conv);

	if ($dbclean_unclaimed == 0) {
		$dbclean_unclaimed = $dbclean_expire_days;
	}

	Config::set('system', 'dbclean-expire-unclaimed', $dbclean_unclaimed);

	if ($itemcache != '') {
		$itemcache = BasePath::getRealPath($itemcache);
	}

	Config::set('system', 'itemcache', $itemcache);
	Config::set('system', 'itemcache_duration', $itemcache_duration);
	Config::set('system', 'max_comments', $max_comments);

	if ($temppath != '') {
		$temppath = BasePath::getRealPath($temppath);
	}

	Config::set('system', 'temppath', $temppath);

	Config::set('system', 'proxy_disabled'   , $proxy_disabled);
	Config::set('system', 'only_tag_search'  , $only_tag_search);

	Config::set('system', 'worker_queues'    , $worker_queues);
	Config::set('system', 'worker_dont_fork' , $worker_dont_fork);
	Config::set('system', 'worker_fastlane'  , $worker_fastlane);
	Config::set('system', 'frontend_worker'  , $worker_frontend);

	Config::set('system', 'relay_directly'   , $relay_directly);
	Config::set('system', 'relay_server'     , $relay_server);
	Config::set('system', 'relay_subscribe'  , $relay_subscribe);
	Config::set('system', 'relay_scope'      , $relay_scope);
	Config::set('system', 'relay_server_tags', $relay_server_tags);
	Config::set('system', 'relay_user_tags'  , $relay_user_tags);

	Config::set('system', 'rino_encrypt'     , $rino);

	info(L10n::t('Site settings updated.') . EOL);

	$a->internalRedirect('admin/site' . $active_panel);
	return; // NOTREACHED
}

/**
 * @brief Generate Admin Site subpage
 *
 * This function generates the main configuration page of the admin panel.
 *
 * @param  App $a
 * @return string
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_site(App $a)
{
	/* Installed langs */
	$lang_choices = L10n::getAvailableLanguages();

	if (strlen(Config::get('system', 'directory_submit_url')) &&
		!strlen(Config::get('system', 'directory'))) {
		Config::set('system', 'directory', dirname(Config::get('system', 'directory_submit_url')));
		Config::delete('system', 'directory_submit_url');
	}

	/* Installed themes */
	$theme_choices = [];
	$theme_choices_mobile = [];
	$theme_choices_mobile["---"] = L10n::t("No special theme for mobile devices");
	$files = glob('view/theme/*');
	if (is_array($files)) {
		$allowed_theme_list = Config::get('system', 'allowed_themes');

		foreach ($files as $file) {
			if (intval(file_exists($file . '/unsupported'))) {
				continue;
			}

			$f = basename($file);

			// Only show allowed themes here
			if (($allowed_theme_list != '') && !strstr($allowed_theme_list, $f)) {
				continue;
			}

			$theme_name = ((file_exists($file . '/experimental')) ? sprintf("%s - \x28Experimental\x29", $f) : $f);

			if (file_exists($file . '/mobile')) {
				$theme_choices_mobile[$f] = $theme_name;
			} else {
				$theme_choices[$f] = $theme_name;
			}
		}
	}

	/* Community page style */
	$community_page_style_choices = [
		CP_NO_INTERNAL_COMMUNITY => L10n::t("No community page for local users"),
		CP_NO_COMMUNITY_PAGE => L10n::t("No community page"),
		CP_USERS_ON_SERVER => L10n::t("Public postings from users of this site"),
		CP_GLOBAL_COMMUNITY => L10n::t("Public postings from the federated network"),
		CP_USERS_AND_GLOBAL => L10n::t("Public postings from local users and the federated network")
	];

	$poco_discovery_choices = [
		PortableContact::DISABLED => L10n::t("Disabled"),
		PortableContact::USERS => L10n::t("Users"),
		PortableContact::USERS_GCONTACTS => L10n::t("Users, Global Contacts"),
		PortableContact::USERS_GCONTACTS_FALLBACK => L10n::t("Users, Global Contacts/fallback"),
	];

	$poco_discovery_since_choices = [
		"30" => L10n::t("One month"),
		"91" => L10n::t("Three months"),
		"182" => L10n::t("Half a year"),
		"365" => L10n::t("One year"),
	];

	/* get user names to make the install a personal install of X */
	$user_names = [];
	$user_names['---'] = L10n::t('Multi user instance');
	$users = q("SELECT `username`, `nickname` FROM `user`");

	foreach ($users as $user) {
		$user_names[$user['nickname']] = $user['username'];
	}

	/* Banner */
	$banner = Config::get('system', 'banner');

	if ($banner == false) {
		$banner = '<a href="https://friendi.ca"><img id="logo-img" src="images/friendica-32.png" alt="logo" /></a><span id="logo-text"><a href="https://friendi.ca">Friendica</a></span>';
	}

	$additional_info = Config::get('config', 'info');

	// Automatically create temporary paths
	get_temppath();
	get_itemcachepath();

	//echo "<pre>"; var_dump($lang_choices); die("</pre>");

	/* Register policy */
	$register_choices = [
		Module\Register::CLOSED => L10n::t("Closed"),
		Module\Register::APPROVE => L10n::t("Requires approval"),
		Module\Register::OPEN => L10n::t("Open")
	];

	$ssl_choices = [
		BaseURL::SSL_POLICY_NONE => L10n::t("No SSL policy, links will track page SSL state"),
		BaseURL::SSL_POLICY_FULL => L10n::t("Force all links to use SSL"),
		BaseURL::SSL_POLICY_SELFSIGN => L10n::t("Self-signed certificate, use SSL for local links only \x28discouraged\x29")
	];

	$check_git_version_choices = [
		"none" => L10n::t("Don't check"),
		"master" => L10n::t("check the stable version"),
		"develop" => L10n::t("check the development version")
	];

	$diaspora_able = ($a->getURLPath() == "");

	$optimize_max_tablesize = Config::get('system', 'optimize_max_tablesize', -1);

	if ($optimize_max_tablesize <= 0) {
		$optimize_max_tablesize = -1;
	}

	/* storage backend */
	$storage_backends = StorageManager::listBackends();
	/**
	 * @var $storage_current_backend \Friendica\Model\Storage\IStorage
	 */
	$storage_current_backend = StorageManager::getBackend();

	$storage_backends_choices = [];

	// show legacy option only if it is the current backend:
	// once changed can't be selected anymore
	if ($storage_current_backend == '') {
		$storage_backends_choices[''] = L10n::t('Database (legacy)');
	};

	foreach ($storage_backends as $name => $class) {
		$storage_backends_choices[$class] = $name;
	}
	unset($storage_backends);

	// build storage config form,
	$storage_form_prefix=preg_replace('|[^a-zA-Z0-9]|' ,'', $storage_current_backend);

	$storage_form = [];
	if (!is_null($storage_current_backend) && $storage_current_backend != "") {
		foreach ($storage_current_backend::getOptions() as $name => $info) {
			$type = $info[0];
			$info[0] = $storage_form_prefix . '_' . $name;
			$info['type'] = $type;
			$info['field'] = 'field_' . $type . '.tpl';
			$storage_form[$name] = $info;
		}
	}


	$t = Renderer::getMarkupTemplate('admin/site.tpl');
	return Renderer::replaceMacros($t, [
		'$title'             => L10n::t('Administration'),
		'$page'              => L10n::t('Site'),
		'$submit'            => L10n::t('Save Settings'),
		'$republish'         => L10n::t('Republish users to directory'),
		'$registration'      => L10n::t('Registration'),
		'$upload'            => L10n::t('File upload'),
		'$corporate'         => L10n::t('Policies'),
		'$advanced'          => L10n::t('Advanced'),
		'$portable_contacts' => L10n::t('Auto Discovered Contact Directory'),
		'$performance'       => L10n::t('Performance'),
		'$worker_title'      => L10n::t('Worker'),
		'$relay_title'       => L10n::t('Message Relay'),
		'$relocate'          => L10n::t('Relocate Instance'),
		'$relocate_warning'  => L10n::t('Warning! Advanced function. Could make this server unreachable.'),
		'$baseurl'           => System::baseUrl(true),

		// name, label, value, help string, extra data...
		'$sitename'         => ['sitename', L10n::t("Site name"), Config::get('config', 'sitename'), ''],
		'$sender_email'     => ['sender_email', L10n::t("Sender Email"), Config::get('config', 'sender_email'), L10n::t("The email address your server shall use to send notification emails from."), "", "", "email"],
		'$banner'           => ['banner', L10n::t("Banner/Logo"), $banner, ""],
		'$shortcut_icon'    => ['shortcut_icon', L10n::t("Shortcut icon"), Config::get('system', 'shortcut_icon'), L10n::t("Link to an icon that will be used for browsers.")],
		'$touch_icon'       => ['touch_icon', L10n::t("Touch icon"), Config::get('system', 'touch_icon'), L10n::t("Link to an icon that will be used for tablets and mobiles.")],
		'$additional_info'  => ['additional_info', L10n::t('Additional Info'), $additional_info, L10n::t('For public servers: you can add additional information here that will be listed at %s/servers.', get_server())],
		'$language'         => ['language', L10n::t("System language"), Config::get('system', 'language'), "", $lang_choices],
		'$theme'            => ['theme', L10n::t("System theme"), Config::get('system', 'theme'), L10n::t("Default system theme - may be over-ridden by user profiles - <a href='#' id='cnftheme'>change theme settings</a>"), $theme_choices],
		'$theme_mobile'     => ['theme_mobile', L10n::t("Mobile system theme"), Config::get('system', 'mobile-theme', '---'), L10n::t("Theme for mobile devices"), $theme_choices_mobile],
		'$ssl_policy'       => ['ssl_policy', L10n::t("SSL link policy"), (string)intval(Config::get('system', 'ssl_policy')), L10n::t("Determines whether generated links should be forced to use SSL"), $ssl_choices],
		'$force_ssl'        => ['force_ssl', L10n::t("Force SSL"), Config::get('system', 'force_ssl'), L10n::t("Force all Non-SSL requests to SSL - Attention: on some systems it could lead to endless loops.")],
		'$hide_help'        => ['hide_help', L10n::t("Hide help entry from navigation menu"), Config::get('system', 'hide_help'), L10n::t("Hides the menu entry for the Help pages from the navigation menu. You can still access it calling /help directly.")],
		'$singleuser'       => ['singleuser', L10n::t("Single user instance"), Config::get('system', 'singleuser', '---'), L10n::t("Make this instance multi-user or single-user for the named user"), $user_names],

		'$storagebackend'   => ['storagebackend', L10n::t("File storage backend"), $storage_current_backend, L10n::t('The backend used to store uploaded data. If you change the storage backend, you can manually move the existing files. If you do not do so, the files uploaded before the change will still be available at the old backend. Please see <a href="/help/Settings#1_2_3_1">the settings documentation</a> for more information about the choices and the moving procedure.'), $storage_backends_choices],
		'$storageform'      => $storage_form,
		'$maximagesize'     => ['maximagesize', L10n::t("Maximum image size"), Config::get('system', 'maximagesize'), L10n::t("Maximum size in bytes of uploaded images. Default is 0, which means no limits.")],
		'$maximagelength'   => ['maximagelength', L10n::t("Maximum image length"), Config::get('system', 'max_image_length'), L10n::t("Maximum length in pixels of the longest side of uploaded images. Default is -1, which means no limits.")],
		'$jpegimagequality' => ['jpegimagequality', L10n::t("JPEG image quality"), Config::get('system', 'jpeg_quality'), L10n::t("Uploaded JPEGS will be saved at this quality setting [0-100]. Default is 100, which is full quality.")],

		'$register_policy'        => ['register_policy', L10n::t("Register policy"), Config::get('config', 'register_policy'), "", $register_choices],
		'$daily_registrations'    => ['max_daily_registrations', L10n::t("Maximum Daily Registrations"), Config::get('system', 'max_daily_registrations'), L10n::t("If registration is permitted above, this sets the maximum number of new user registrations to accept per day.  If register is set to closed, this setting has no effect.")],
		'$register_text'          => ['register_text', L10n::t("Register text"), Config::get('config', 'register_text'), L10n::t("Will be displayed prominently on the registration page. You can use BBCode here.")],
		'$forbidden_nicknames'    => ['forbidden_nicknames', L10n::t('Forbidden Nicknames'), Config::get('system', 'forbidden_nicknames'), L10n::t('Comma separated list of nicknames that are forbidden from registration. Preset is a list of role names according RFC 2142.')],
		'$abandon_days'           => ['abandon_days', L10n::t('Accounts abandoned after x days'), Config::get('system', 'account_abandon_days'), L10n::t('Will not waste system resources polling external sites for abandonded accounts. Enter 0 for no time limit.')],
		'$allowed_sites'          => ['allowed_sites', L10n::t("Allowed friend domains"), Config::get('system', 'allowed_sites'), L10n::t("Comma separated list of domains which are allowed to establish friendships with this site. Wildcards are accepted. Empty to allow any domains")],
		'$allowed_email'          => ['allowed_email', L10n::t("Allowed email domains"), Config::get('system', 'allowed_email'), L10n::t("Comma separated list of domains which are allowed in email addresses for registrations to this site. Wildcards are accepted. Empty to allow any domains")],
		'$no_oembed_rich_content' => ['no_oembed_rich_content', L10n::t("No OEmbed rich content"), Config::get('system', 'no_oembed_rich_content'), L10n::t("Don't show the rich content \x28e.g. embedded PDF\x29, except from the domains listed below.")],
		'$allowed_oembed'         => ['allowed_oembed', L10n::t("Allowed OEmbed domains"), Config::get('system', 'allowed_oembed'), L10n::t("Comma separated list of domains which oembed content is allowed to be displayed. Wildcards are accepted.")],
		'$block_public'           => ['block_public', L10n::t("Block public"), Config::get('system', 'block_public'), L10n::t("Check to block public access to all otherwise public personal pages on this site unless you are currently logged in.")],
		'$force_publish'          => ['publish_all', L10n::t("Force publish"), Config::get('system', 'publish_all'), L10n::t("Check to force all profiles on this site to be listed in the site directory.") . '<strong>' . L10n::t('Enabling this may violate privacy laws like the GDPR') . '</strong>'],
		'$global_directory'       => ['directory', L10n::t("Global directory URL"), Config::get('system', 'directory', 'https://dir.friendica.social'), L10n::t("URL to the global directory. If this is not set, the global directory is completely unavailable to the application.")],
		'$newuser_private'        => ['newuser_private', L10n::t("Private posts by default for new users"), Config::get('system', 'newuser_private'), L10n::t("Set default post permissions for all new members to the default privacy group rather than public.")],
		'$enotify_no_content'     => ['enotify_no_content', L10n::t("Don't include post content in email notifications"), Config::get('system', 'enotify_no_content'), L10n::t("Don't include the content of a post/comment/private message/etc. in the email notifications that are sent out from this site, as a privacy measure.")],
		'$private_addons'         => ['private_addons', L10n::t("Disallow public access to addons listed in the apps menu."), Config::get('config', 'private_addons'), L10n::t("Checking this box will restrict addons listed in the apps menu to members only.")],
		'$disable_embedded'       => ['disable_embedded', L10n::t("Don't embed private images in posts"), Config::get('system', 'disable_embedded'), L10n::t("Don't replace locally-hosted private photos in posts with an embedded copy of the image. This means that contacts who receive posts containing private photos will have to authenticate and load each image, which may take a while.")],
		'$explicit_content'       => ['explicit_content', L10n::t('Explicit Content'), Config::get('system', 'explicit_content', false), L10n::t('Set this to announce that your node is used mostly for explicit content that might not be suited for minors. This information will be published in the node information and might be used, e.g. by the global directory, to filter your node from listings of nodes to join. Additionally a note about this will be shown at the user registration page.')],
		'$allow_users_remote_self'=> ['allow_users_remote_self', L10n::t('Allow Users to set remote_self'), Config::get('system', 'allow_users_remote_self'), L10n::t('With checking this, every user is allowed to mark every contact as a remote_self in the repair contact dialog. Setting this flag on a contact causes mirroring every posting of that contact in the users stream.')],
		'$no_multi_reg'           => ['no_multi_reg', L10n::t("Block multiple registrations"), Config::get('system', 'block_extended_register'), L10n::t("Disallow users to register additional accounts for use as pages.")],
		'$no_openid'              => ['no_openid', L10n::t("Disable OpenID"), Config::get('system', 'no_openid'), L10n::t("Disable OpenID support for registration and logins.")],
		'$no_regfullname'         => ['no_regfullname', L10n::t("No Fullname check"), Config::get('system', 'no_regfullname'), L10n::t("Allow users to register without a space between the first name and the last name in their full name.")],
		'$community_page_style'   => ['community_page_style', L10n::t("Community pages for visitors"), Config::get('system', 'community_page_style'), L10n::t("Which community pages should be available for visitors. Local users always see both pages."), $community_page_style_choices],
		'$max_author_posts_community_page' => ['max_author_posts_community_page', L10n::t("Posts per user on community page"), Config::get('system', 'max_author_posts_community_page'), L10n::t("The maximum number of posts per user on the community page. \x28Not valid for 'Global Community'\x29")],
		'$ostatus_disabled'       => ['ostatus_disabled', L10n::t("Disable OStatus support"), Config::get('system', 'ostatus_disabled'), L10n::t("Disable built-in OStatus (StatusNet, GNU Social etc.) compatibility. All communications in OStatus are public, so privacy warnings will be occasionally displayed.")],
		'$ostatus_full_threads'   => ['ostatus_full_threads', L10n::t("Only import OStatus/ActivityPub threads from our contacts"), Config::get('system', 'ostatus_full_threads'), L10n::t("Normally we import every content from our OStatus and ActivityPub contacts. With this option we only store threads that are started by a contact that is known on our system.")],
		'$ostatus_not_able'       => L10n::t("OStatus support can only be enabled if threading is enabled."),
		'$diaspora_able'          => $diaspora_able,
		'$diaspora_not_able'      => L10n::t("Diaspora support can't be enabled because Friendica was installed into a sub directory."),
		'$diaspora_enabled'       => ['diaspora_enabled', L10n::t("Enable Diaspora support"), Config::get('system', 'diaspora_enabled', $diaspora_able), L10n::t("Provide built-in Diaspora network compatibility.")],
		'$dfrn_only'              => ['dfrn_only', L10n::t('Only allow Friendica contacts'), Config::get('system', 'dfrn_only'), L10n::t("All contacts must use Friendica protocols. All other built-in communication protocols disabled.")],
		'$verifyssl'              => ['verifyssl', L10n::t("Verify SSL"), Config::get('system', 'verifyssl'), L10n::t("If you wish, you can turn on strict certificate checking. This will mean you cannot connect \x28at all\x29 to self-signed SSL sites.")],
		'$proxyuser'              => ['proxyuser', L10n::t("Proxy user"), Config::get('system', 'proxyuser'), ""],
		'$proxy'                  => ['proxy', L10n::t("Proxy URL"), Config::get('system', 'proxy'), ""],
		'$timeout'                => ['timeout', L10n::t("Network timeout"), Config::get('system', 'curl_timeout', 60), L10n::t("Value is in seconds. Set to 0 for unlimited \x28not recommended\x29.")],
		'$maxloadavg'             => ['maxloadavg', L10n::t("Maximum Load Average"), Config::get('system', 'maxloadavg', 20), L10n::t("Maximum system load before delivery and poll processes are deferred - default %d.", 20)],
		'$maxloadavg_frontend'    => ['maxloadavg_frontend', L10n::t("Maximum Load Average \x28Frontend\x29"), Config::get('system', 'maxloadavg_frontend', 50), L10n::t("Maximum system load before the frontend quits service - default 50.")],
		'$min_memory'             => ['min_memory', L10n::t("Minimal Memory"), Config::get('system', 'min_memory', 0), L10n::t("Minimal free memory in MB for the worker. Needs access to /proc/meminfo - default 0 \x28deactivated\x29.")],
		'$optimize_max_tablesize' => ['optimize_max_tablesize', L10n::t("Maximum table size for optimization"), $optimize_max_tablesize, L10n::t("Maximum table size \x28in MB\x29 for the automatic optimization. Enter -1 to disable it.")],
		'$optimize_fragmentation' => ['optimize_fragmentation', L10n::t("Minimum level of fragmentation"), Config::get('system', 'optimize_fragmentation', 30), L10n::t("Minimum fragmenation level to start the automatic optimization - default value is 30%.")],

		'$poco_completion'        => ['poco_completion', L10n::t("Periodical check of global contacts"), Config::get('system', 'poco_completion'), L10n::t("If enabled, the global contacts are checked periodically for missing or outdated data and the vitality of the contacts and servers.")],
		'$poco_requery_days'      => ['poco_requery_days', L10n::t("Days between requery"), Config::get('system', 'poco_requery_days'), L10n::t("Number of days after which a server is requeried for his contacts.")],
		'$poco_discovery'         => ['poco_discovery', L10n::t("Discover contacts from other servers"), (string)intval(Config::get('system', 'poco_discovery')), L10n::t("Periodically query other servers for contacts. You can choose between 'users': the users on the remote system, 'Global Contacts': active contacts that are known on the system. The fallback is meant for Redmatrix servers and older friendica servers, where global contacts weren't available. The fallback increases the server load, so the recommended setting is 'Users, Global Contacts'."), $poco_discovery_choices],
		'$poco_discovery_since'   => ['poco_discovery_since', L10n::t("Timeframe for fetching global contacts"), (string)intval(Config::get('system', 'poco_discovery_since')), L10n::t("When the discovery is activated, this value defines the timeframe for the activity of the global contacts that are fetched from other servers."), $poco_discovery_since_choices],
		'$poco_local_search'      => ['poco_local_search', L10n::t("Search the local directory"), Config::get('system', 'poco_local_search'), L10n::t("Search the local directory instead of the global directory. When searching locally, every search will be executed on the global directory in the background. This improves the search results when the search is repeated.")],

		'$nodeinfo'               => ['nodeinfo', L10n::t("Publish server information"), Config::get('system', 'nodeinfo'), L10n::t("If enabled, general server and usage data will be published. The data contains the name and version of the server, number of users with public profiles, number of posts and the activated protocols and connectors. See <a href='http://the-federation.info/'>the-federation.info</a> for details.")],

		'$check_new_version_url'  => ['check_new_version_url', L10n::t("Check upstream version"), Config::get('system', 'check_new_version_url'), L10n::t("Enables checking for new Friendica versions at github. If there is a new version, you will be informed in the admin panel overview."), $check_git_version_choices],
		'$suppress_tags'          => ['suppress_tags', L10n::t("Suppress Tags"), Config::get('system', 'suppress_tags'), L10n::t("Suppress showing a list of hashtags at the end of the posting.")],
		'$dbclean'                => ['dbclean', L10n::t("Clean database"), Config::get('system', 'dbclean', false), L10n::t("Remove old remote items, orphaned database records and old content from some other helper tables.")],
		'$dbclean_expire_days'    => ['dbclean_expire_days', L10n::t("Lifespan of remote items"), Config::get('system', 'dbclean-expire-days', 0), L10n::t("When the database cleanup is enabled, this defines the days after which remote items will be deleted. Own items, and marked or filed items are always kept. 0 disables this behaviour.")],
		'$dbclean_unclaimed'      => ['dbclean_unclaimed', L10n::t("Lifespan of unclaimed items"), Config::get('system', 'dbclean-expire-unclaimed', 90), L10n::t("When the database cleanup is enabled, this defines the days after which unclaimed remote items (mostly content from the relay) will be deleted. Default value is 90 days. Defaults to the general lifespan value of remote items if set to 0.")],
		'$dbclean_expire_conv'    => ['dbclean_expire_conv', L10n::t("Lifespan of raw conversation data"), Config::get('system', 'dbclean_expire_conversation', 90), L10n::t("The conversation data is used for ActivityPub and OStatus, as well as for debug purposes. It should be safe to remove it after 14 days, default is 90 days.")],
		'$itemcache'              => ['itemcache', L10n::t("Path to item cache"), Config::get('system', 'itemcache'), L10n::t("The item caches buffers generated bbcode and external images.")],
		'$itemcache_duration'     => ['itemcache_duration', L10n::t("Cache duration in seconds"), Config::get('system', 'itemcache_duration'), L10n::t("How long should the cache files be hold? Default value is 86400 seconds \x28One day\x29. To disable the item cache, set the value to -1.")],
		'$max_comments'           => ['max_comments', L10n::t("Maximum numbers of comments per post"), Config::get('system', 'max_comments'), L10n::t("How much comments should be shown for each post? Default value is 100.")],
		'$temppath'               => ['temppath', L10n::t("Temp path"), Config::get('system', 'temppath'), L10n::t("If you have a restricted system where the webserver can't access the system temp path, enter another path here.")],
		'$proxy_disabled'         => ['proxy_disabled', L10n::t("Disable picture proxy"), Config::get('system', 'proxy_disabled'), L10n::t("The picture proxy increases performance and privacy. It shouldn't be used on systems with very low bandwidth.")],
		'$only_tag_search'        => ['only_tag_search', L10n::t("Only search in tags"), Config::get('system', 'only_tag_search'), L10n::t("On large systems the text search can slow down the system extremely.")],

		'$relocate_url'           => ['relocate_url', L10n::t("New base url"), System::baseUrl(), L10n::t("Change base url for this server. Sends relocate message to all Friendica and Diaspora* contacts of all users.")],

		'$rino'                   => ['rino', L10n::t("RINO Encryption"), intval(Config::get('system', 'rino_encrypt')), L10n::t("Encryption layer between nodes."), [0 => L10n::t("Disabled"), 1 => L10n::t("Enabled")]],

		'$worker_queues'          => ['worker_queues', L10n::t("Maximum number of parallel workers"), Config::get('system', 'worker_queues'), L10n::t("On shared hosters set this to %d. On larger systems, values of %d are great. Default value is %d.", 5, 20, 10)],
		'$worker_dont_fork'       => ['worker_dont_fork', L10n::t("Don't use 'proc_open' with the worker"), Config::get('system', 'worker_dont_fork'), L10n::t("Enable this if your system doesn't allow the use of 'proc_open'. This can happen on shared hosters. If this is enabled you should increase the frequency of worker calls in your crontab.")],
		'$worker_fastlane'        => ['worker_fastlane', L10n::t("Enable fastlane"), Config::get('system', 'worker_fastlane'), L10n::t("When enabed, the fastlane mechanism starts an additional worker if processes with higher priority are blocked by processes of lower priority.")],
		'$worker_frontend'        => ['worker_frontend', L10n::t('Enable frontend worker'), Config::get('system', 'frontend_worker'), L10n::t("When enabled the Worker process is triggered when backend access is performed \x28e.g. messages being delivered\x29. On smaller sites you might want to call %s/worker on a regular basis via an external cron job. You should only enable this option if you cannot utilize cron/scheduled jobs on your server.", System::baseUrl())],

		'$relay_subscribe'        => ['relay_subscribe', L10n::t("Subscribe to relay"), Config::get('system', 'relay_subscribe'), L10n::t("Enables the receiving of public posts from the relay. They will be included in the search, subscribed tags and on the global community page.")],
		'$relay_server'           => ['relay_server', L10n::t("Relay server"), Config::get('system', 'relay_server', 'https://relay.diasp.org'), L10n::t("Address of the relay server where public posts should be send to. For example https://relay.diasp.org")],
		'$relay_directly'         => ['relay_directly', L10n::t("Direct relay transfer"), Config::get('system', 'relay_directly'), L10n::t("Enables the direct transfer to other servers without using the relay servers")],
		'$relay_scope'            => ['relay_scope', L10n::t("Relay scope"), Config::get('system', 'relay_scope'), L10n::t("Can be 'all' or 'tags'. 'all' means that every public post should be received. 'tags' means that only posts with selected tags should be received."), ['' => L10n::t('Disabled'), 'all' => L10n::t('all'), 'tags' => L10n::t('tags')]],
		'$relay_server_tags'      => ['relay_server_tags', L10n::t("Server tags"), Config::get('system', 'relay_server_tags'), L10n::t("Comma separated list of tags for the 'tags' subscription.")],
		'$relay_user_tags'        => ['relay_user_tags', L10n::t("Allow user tags"), Config::get('system', 'relay_user_tags', true), L10n::t("If enabled, the tags from the saved searches will used for the 'tags' subscription in addition to the 'relay_server_tags'.")],

		'$form_security_token'    => BaseModule::getFormSecurityToken("admin_site"),
		'$relocate_button'        => L10n::t('Start Relocation'),
	]);
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
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_dbsync(App $a)
{
	$o = '';

	if ($a->argc > 3 && intval($a->argv[3]) && $a->argv[2] === 'mark') {
		Config::set('database', 'update_' . intval($a->argv[3]), 'success');
		$curr = Config::get('system', 'build');
		if (intval($curr) == intval($a->argv[3])) {
			Config::set('system', 'build', intval($curr) + 1);
		}
		info(L10n::t('Update has been marked successful') . EOL);
		$a->internalRedirect('admin/dbsync');
	}

	if (($a->argc > 2) && (intval($a->argv[2]) || ($a->argv[2] === 'check'))) {
		$retval = DBStructure::update($a->getBasePath(), false, true);
		if ($retval === '') {
			$o .= L10n::t("Database structure update %s was successfully applied.", DB_UPDATE_VERSION) . "<br />";
			Config::set('database', 'last_successful_update', DB_UPDATE_VERSION);
			Config::set('database', 'last_successful_update_time', time());
		} else {
			$o .= L10n::t("Executing of database structure update %s failed with error: %s", DB_UPDATE_VERSION, $retval) . "<br />";
		}
		if ($a->argv[2] === 'check') {
			return $o;
		}
	}

	if ($a->argc > 2 && intval($a->argv[2])) {
		require_once 'update.php';

		$func = 'update_' . intval($a->argv[2]);

		if (function_exists($func)) {
			$retval = $func();

			if ($retval === Update::FAILED) {
				$o .= L10n::t("Executing %s failed with error: %s", $func, $retval);
			} elseif ($retval === Update::SUCCESS) {
				$o .= L10n::t('Update %s was successfully applied.', $func);
				Config::set('database', $func, 'success');
			} else {
				$o .= L10n::t('Update %s did not return a status. Unknown if it succeeded.', $func);
			}
		} else {
			$o .= L10n::t('There was no additional update function %s that needed to be called.', $func) . "<br />";
			Config::set('database', $func, 'success');
		}

		return $o;
	}

	$failed = [];
	$r = q("SELECT `k`, `v` FROM `config` WHERE `cat` = 'database' ");

	if (DBA::isResult($r)) {
		foreach ($r as $rr) {
			$upd = intval(substr($rr['k'], 7));
			if ($upd < 1139 || $rr['v'] === 'success') {
				continue;
			}
			$failed[] = $upd;
		}
	}

	if (!count($failed)) {
		$o = Renderer::replaceMacros(Renderer::getMarkupTemplate('structure_check.tpl'), [
			'$base' => System::baseUrl(true),
			'$banner' => L10n::t('No failed updates.'),
			'$check' => L10n::t('Check database structure'),
		]);
	} else {
		$o = Renderer::replaceMacros(Renderer::getMarkupTemplate('failed_updates.tpl'), [
			'$base' => System::baseUrl(true),
			'$banner' => L10n::t('Failed Updates'),
			'$desc' => L10n::t('This does not include updates prior to 1139, which did not return a status.'),
			'$mark' => L10n::t("Mark success \x28if update was manually applied\x29"),
			'$apply' => L10n::t('Attempt to execute this update step automatically'),
			'$failed' => $failed
		]);
	}

	return $o;
}

/**
 * @param array  $themes
 * @param string $th
 * @param int    $result
 */
function toggle_theme(&$themes, $th, &$result)
{
	$count = count($themes);
	for ($x = 0; $x < $count; $x++) {
		if ($themes[$x]['name'] === $th) {
			if ($themes[$x]['allowed']) {
				$themes[$x]['allowed'] = 0;
				$result = 0;
			} else {
				$themes[$x]['allowed'] = 1;
				$result = 1;
			}
		}
	}
}

/**
 * @param array  $themes
 * @param string $th
 * @return int
 */
function theme_status($themes, $th)
{
	$count = count($themes);
	for ($x = 0; $x < $count; $x++) {
		if ($themes[$x]['name'] === $th) {
			if ($themes[$x]['allowed']) {
				return 1;
			} else {
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
function rebuild_theme_table($themes)
{
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
 * themes are the same as used for the addons.
 *
 * The returned string contains the HTML code of the admin panel page.
 *
 * @param App $a
 * @return string
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_themes(App $a)
{
	$allowed_themes_str = Config::get('system', 'allowed_themes');
	$allowed_themes_raw = explode(',', $allowed_themes_str);
	$allowed_themes = [];
	if (count($allowed_themes_raw)) {
		foreach ($allowed_themes_raw as $x) {
			if (strlen(trim($x))) {
				$allowed_themes[] = trim($x);
			}
		}
	}

	$themes = [];
	$files = glob('view/theme/*');
	if (is_array($files)) {
		foreach ($files as $file) {
			$f = basename($file);

			// Is there a style file?
			$theme_files = glob('view/theme/' . $f . '/style.*');

			// If not then quit
			if (count($theme_files) == 0) {
				continue;
			}

			$is_experimental = intval(file_exists($file . '/experimental'));
			$is_supported = 1 - (intval(file_exists($file . '/unsupported')));
			$is_allowed = intval(in_array($f, $allowed_themes));

			if ($is_allowed || $is_supported || Config::get("system", "show_unsupported_themes")) {
				$themes[] = ['name' => $f, 'experimental' => $is_experimental, 'supported' => $is_supported, 'allowed' => $is_allowed];
			}
		}
	}

	if (!count($themes)) {
		notice(L10n::t('No themes found.'));
		return '';
	}

	/*
	 * Single theme
	 */

	if ($a->argc == 3) {
		$theme = $a->argv[2];
		if (!is_dir("view/theme/$theme")) {
			notice(L10n::t("Item not found."));
			return '';
		}

		if (!empty($_GET['a']) && $_GET['a'] == "t") {
			BaseModule::checkFormSecurityTokenRedirectOnError('/admin/themes', 'admin_themes', 't');

			// Toggle theme status

			toggle_theme($themes, $theme, $result);
			$s = rebuild_theme_table($themes);
			if ($result) {
				Theme::install($theme);
				info(sprintf('Theme %s enabled.', $theme));
			} else {
				Theme::uninstall($theme);
				info(sprintf('Theme %s disabled.', $theme));
			}

			Config::set('system', 'allowed_themes', $s);
			$a->internalRedirect('admin/themes');
			return ''; // NOTREACHED
		}

		// display theme details
		if (theme_status($themes, $theme)) {
			$status = "on";
			$action = L10n::t("Disable");
		} else {
			$status = "off";
			$action = L10n::t("Enable");
		}

		$readme = null;

		if (is_file("view/theme/$theme/README.md")) {
			$readme = Markdown::convert(file_get_contents("view/theme/$theme/README.md"), false);
		} elseif (is_file("view/theme/$theme/README")) {
			$readme = "<pre>" . file_get_contents("view/theme/$theme/README") . "</pre>";
		}

		$admin_form = '';
		if (is_file("view/theme/$theme/config.php")) {
			require_once "view/theme/$theme/config.php";

			if (function_exists('theme_admin')) {
				$admin_form = theme_admin($a);
			}
		}

		$screenshot = [Theme::getScreenshot($theme), L10n::t('Screenshot')];
		if (!stristr($screenshot[0], $theme)) {
			$screenshot = null;
		}

		$t = Renderer::getMarkupTemplate('admin/addon_details.tpl');
		return Renderer::replaceMacros($t, [
			'$title' => L10n::t('Administration'),
			'$page' => L10n::t('Themes'),
			'$toggle' => L10n::t('Toggle'),
			'$settings' => L10n::t('Settings'),
			'$baseurl' => System::baseUrl(true),
			'$addon' => $theme . (!empty($_GET['mode']) ? '?mode=' . $_GET['mode'] : ''),
			'$status' => $status,
			'$action' => $action,
			'$info' => Theme::getInfo($theme),
			'$function' => 'themes',
			'$admin_form' => $admin_form,
			'$str_author' => L10n::t('Author: '),
			'$str_maintainer' => L10n::t('Maintainer: '),
			'$screenshot' => $screenshot,
			'$readme' => $readme,

			'$form_security_token' => BaseModule::getFormSecurityToken("admin_themes"),
		]);
	}
}

/**
 * @brief Prosesses data send by Logs admin page
 *
 * @param App $a
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_logs_post(App $a)
{
	if (!empty($_POST['page_logs'])) {
		BaseModule::checkFormSecurityTokenRedirectOnError('/admin/logs', 'admin_logs');

		$logfile   = (!empty($_POST['logfile']) ? Strings::escapeTags(trim($_POST['logfile'])) : '');
		$debugging = !empty($_POST['debugging']);
		$loglevel  = defaults($_POST, 'loglevel', LogLevel::ERROR);

		Config::set('system', 'logfile', $logfile);
		Config::set('system', 'debugging', $debugging);
		Config::set('system', 'loglevel', $loglevel);
	}

	info(L10n::t("Log settings updated."));
	$a->internalRedirect('admin/logs');
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
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_logs(App $a)
{
	$log_choices = [
		LogLevel::ERROR   => 'Error',
		LogLevel::WARNING => 'Warning',
		LogLevel::NOTICE  => 'Notice',
		LogLevel::INFO    => 'Info',
		LogLevel::DEBUG   => 'Debug',
	];

	if (ini_get('log_errors')) {
		$phplogenabled = L10n::t('PHP log currently enabled.');
	} else {
		$phplogenabled = L10n::t('PHP log currently disabled.');
	}

	$t = Renderer::getMarkupTemplate('admin/logs.tpl');

	return Renderer::replaceMacros($t, [
		'$title' => L10n::t('Administration'),
		'$page' => L10n::t('Logs'),
		'$submit' => L10n::t('Save Settings'),
		'$clear' => L10n::t('Clear'),
		'$baseurl' => System::baseUrl(true),
		'$logname' => Config::get('system', 'logfile'),
		// name, label, value, help string, extra data...
		'$debugging' => ['debugging', L10n::t("Enable Debugging"), Config::get('system', 'debugging'), ""],
		'$logfile' => ['logfile', L10n::t("Log file"), Config::get('system', 'logfile'), L10n::t("Must be writable by web server. Relative to your Friendica top-level directory.")],
		'$loglevel' => ['loglevel', L10n::t("Log level"), Config::get('system', 'loglevel'), "", $log_choices],
		'$form_security_token' => BaseModule::getFormSecurityToken("admin_logs"),
		'$phpheader' => L10n::t("PHP logging"),
		'$phphint' => L10n::t("To temporarily enable logging of PHP errors and warnings you can prepend the following to the index.php file of your installation. The filename set in the 'error_log' line is relative to the friendica top-level directory and must be writeable by the web server. The option '1' for 'log_errors' and 'display_errors' is to enable these options, set to '0' to disable them."),
		'$phplogcode' => "error_reporting(E_ERROR | E_WARNING | E_PARSE);\nini_set('error_log','php.out');\nini_set('log_errors','1');\nini_set('display_errors', '1');",
		'$phplogenabled' => $phplogenabled,
	]);
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
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function admin_page_viewlogs(App $a)
{
	$t = Renderer::getMarkupTemplate('admin/viewlogs.tpl');
	$f = Config::get('system', 'logfile');
	$data = '';

	if (!file_exists($f)) {
		$data = L10n::t('Error trying to open <strong>%1$s</strong> log file.\r\n<br/>Check to see if file %1$s exist and is readable.', $f);
	} else {
		$fp = fopen($f, 'r');
		if (!$fp) {
			$data = L10n::t('Couldn\'t open <strong>%1$s</strong> log file.\r\n<br/>Check to see if file %1$s is readable.', $f);
		} else {
			$fstat = fstat($fp);
			$size = $fstat['size'];
			if ($size != 0) {
				if ($size > 5000000 || $size < 0) {
					$size = 5000000;
				}
				$seek = fseek($fp, 0 - $size, SEEK_END);
				if ($seek === 0) {
					$data = Strings::escapeHtml(fread($fp, $size));
					while (!feof($fp)) {
						$data .= Strings::escapeHtml(fread($fp, 4096));
					}
				}
			}
			fclose($fp);
		}
	}
	return Renderer::replaceMacros($t, [
		'$title' => L10n::t('Administration'),
		'$page' => L10n::t('View Logs'),
		'$data' => $data,
		'$logname' => Config::get('system', 'logfile')
	]);
}

function admin_page_server_vital()
{
	// Fetch the host-meta to check if this really is a vital server
	return Network::curl(System::baseUrl() . '/.well-known/host-meta')->isSuccess();
}
