<?php

use Friendica\App;
use Friendica\Core\System;

function nav(App $a) {

	/*
	 *
	 * Build page header and site navigation bars
	 *
	 */

	if (!(x($a->page,'nav')))
		$a->page['nav'] = '';

	$a->page['htmlhead'] .= replace_macros(get_markup_template('nav_head.tpl'), array());

	/*
	 * Placeholder div for popup panel
	 */

	$a->page['nav'] .= '<div id="panel" style="display: none;"></div>' ;

	$nav_info = nav_info($a);

	/*
	 * Build the page
	 */

	$tpl = get_markup_template('nav.tpl');

	$a->page['nav'] .= replace_macros($tpl, array(
		'$baseurl' => System::baseUrl(),
		'$sitelocation' => $nav_info['sitelocation'],
		'$nav' => $nav_info['nav'],
		'$banner' => $nav_info['banner'],
		'$emptynotifications' => t('Nothing new here'),
		'$userinfo' => $nav_info['userinfo'],
		'$sel' =>  $a->nav_sel,
		'$apps' => $a->apps,
		'$clear_notifs' => t('Clear notifications'),
		'$search_hint' => t('@name, !forum, #tags, content')
	));

	call_hooks('page_header', $a->page['nav']);
}

/**
 * @brief Prepares a list of navigation links
 *
 * @param App $a
 * @return array Navigation links
 *	string 'sitelocation' => The webbie (username@site.com)
 *	array 'nav' => Array of links used in the nav menu
 *	string 'banner' => Formatted html link with banner image
 *	array 'userinfo' => Array of user information (name, icon)
 */
function nav_info(App $a)
{
	$ssl_state = ((local_user()) ? true : false);

	/*
	 * Our network is distributed, and as you visit friends some of the
	 * sites look exactly the same - it isn't always easy to know where you are.
	 * Display the current site location as a navigation aid.
	 */

	$myident = ((is_array($a->user) && isset($a->user['nickname'])) ? $a->user['nickname'] . '@' : '');

	$sitelocation = $myident . substr(System::baseUrl($ssl_state), strpos(System::baseUrl($ssl_state), '//') + 2 );

	// nav links: array of array('href', 'text', 'extra css classes', 'title')
	$nav = array();

	// Display login or logout
	$nav['usermenu'] = array();
	$userinfo = null;

	if (local_user()) {
		$nav['logout'] = array('logout', t('Logout'), '', t('End this session'));

		// user menu
		$nav['usermenu'][] = array('profile/' . $a->user['nickname'], t('Status'), '', t('Your posts and conversations'));
		$nav['usermenu'][] = array('profile/' . $a->user['nickname'] . '?tab=profile', t('Profile'), '', t('Your profile page'));
		$nav['usermenu'][] = array('photos/' . $a->user['nickname'], t('Photos'), '', t('Your photos'));
		$nav['usermenu'][] = array('videos/' . $a->user['nickname'], t('Videos'), '', t('Your videos'));
		$nav['usermenu'][] = array('events/', t('Events'), '', t('Your events'));
		$nav['usermenu'][] = array('notes/', t('Personal notes'), '', t('Your personal notes'));

		// user info
		$r = dba::select('contact', array('micro'), array('uid' => $a->user['uid'], 'self' => true), array('limit' => 1));
		$userinfo = array(
			'icon' => (dbm::is_result($r) ? $a->remove_baseurl($r['micro']) : 'images/person-48.jpg'),
			'name' => $a->user['username'],
		);
	} else {
		$nav['login'] = array('login', t('Login'), ($a->module == 'login' ? 'selected' : ''), t('Sign in'));
	}

	// "Home" should also take you home from an authenticated remote profile connection
	$homelink = get_my_url();
	if (! $homelink) {
		$homelink = ((x($_SESSION,'visitor_home')) ? $_SESSION['visitor_home'] : '');
	}

	if (($a->module != 'home') && (! (local_user()))) {
		$nav['home'] = array($homelink, t('Home'), '', t('Home Page'));
	}

	if (($a->config['register_policy'] == REGISTER_OPEN) && (! local_user()) && (! remote_user())) {
		$nav['register'] = array('register', t('Register'), '', t('Create an account'));
	}

	$help_url = 'help';

	if (! get_config('system', 'hide_help')) {
		$nav['help'] = array($help_url, t('Help'), '', t('Help and documentation'));
	}

	if (count($a->apps) > 0) {
		$nav['apps'] = array('apps', t('Apps'), '', t('Addon applications, utilities, games'));
	}

	if (local_user() || !get_config('system', 'local_search')) {
		$nav['search'] = array('search', t('Search'), '', t('Search site content'));

		$nav['searchoption'] = array(
						t('Full Text'),
						t('Tags'),
						t('Contacts'));

		if (get_config('system', 'poco_local_search')) {
			$nav['searchoption'][] = t('Forums');
		}
	}

	$gdirpath = 'directory';

	if (strlen(get_config('system', 'singleuser'))) {
		$gdir = get_config('system', 'directory');
		if (strlen($gdir)) {
			$gdirpath = zrl($gdir, true);
		}
	} elseif (get_config('system', 'community_page_style') == CP_USERS_ON_SERVER) {
		$nav['community'] = array('community', t('Community'), '', t('Conversations on this site'));
	} elseif (get_config('system', 'community_page_style') == CP_GLOBAL_COMMUNITY) {
		$nav['community'] = array('community', t('Community'), '', t('Conversations on the network'));
	}

	if (local_user()) {
		$nav['events'] = array('events', t('Events'), '', t('Events and Calendar'));
	}

	$nav['directory'] = array($gdirpath, t('Directory'), '', t('People directory'));

	$nav['about'] = array('friendica', t('Information'), '', t('Information about this friendica instance'));

	// The following nav links are only show to logged in users
	if (local_user()) {
		$nav['network'] = array('network', t('Network'), '', t('Conversations from your friends'));
		$nav['net_reset'] = array('network/0?f=&order=comment&nets=all', t('Network Reset'), '', t('Load Network page with no filters'));

		$nav['home'] = array('profile/' . $a->user['nickname'], t('Home'), '', t('Your posts and conversations'));

		if (in_array($_SESSION['page_flags'], array(PAGE_NORMAL, PAGE_SOAPBOX, PAGE_FREELOVE, PAGE_PRVGROUP))) {
			// only show friend requests for normal pages. Other page types have automatic friendship.
			if (in_array($_SESSION['page_flags'], array(PAGE_NORMAL, PAGE_SOAPBOX, PAGE_PRVGROUP))) {
				$nav['introductions'] = array('notifications/intros', t('Introductions'), '', t('Friend Requests'));
			}
			if (in_array($_SESSION['page_flags'], array(PAGE_NORMAL, PAGE_SOAPBOX, PAGE_FREELOVE))) {
				$nav['notifications'] = array('notifications',	t('Notifications'), '', t('Notifications'));
				$nav['notifications']['all'] = array('notifications/system', t('See all notifications'), '', '');
				$nav['notifications']['mark'] = array('', t('Mark as seen'), '', t('Mark all system notifications seen'));
			}
		}

		$nav['messages'] = array('message', t('Messages'), '', t('Private mail'));
		$nav['messages']['inbox'] = array('message', t('Inbox'), '', t('Inbox'));
		$nav['messages']['outbox'] = array('message/sent', t('Outbox'), '', t('Outbox'));
		$nav['messages']['new'] = array('message/new', t('New Message'), '', t('New Message'));

		if (is_array($a->identities) && count($a->identities) > 1) {
			$nav['manage'] = array('manage', t('Manage'), '', t('Manage other pages'));
		}

		$nav['delegations'] = array('delegate', t('Delegations'), '', t('Delegate Page Management'));

		$nav['settings'] = array('settings', t('Settings'), '', t('Account settings'));

		if (feature_enabled(local_user(), 'multi_profiles')) {
			$nav['profiles'] = array('profiles', t('Profiles'), '', t('Manage/Edit Profiles'));
		}

		$nav['contacts'] = array('contacts', t('Contacts'), '', t('Manage/edit friends and contacts'));
	}

	// Show the link to the admin configuration page if user is admin
	if (is_site_admin()) {
		$nav['admin'] = array('admin/', t('Admin'), '', t('Site setup and configuration'));
	}

	$nav['navigation'] = array('navigation/', t('Navigation'), '', t('Site map'));

	// Provide a banner/logo/whatever
	$banner = get_config('system', 'banner');
	if ($banner === false) {
		$banner = '<a href="https://friendi.ca"><img id="logo-img" src="images/friendica-32.png" alt="logo" /></a><span id="logo-text"><a href="https://friendi.ca">Friendica</a></span>';
	}

	call_hooks('nav_info', $nav);

	return array(
		'sitelocation' => $sitelocation,
		'nav' => $nav,
		'banner' => $banner,
		'userinfo' => $userinfo,
	);
}

/**
 * Set a menu item in navbar as selected
 *
 */
function nav_set_selected($item){
	$a = get_app();
	$a->nav_sel = array(
		'community' 	=> null,
		'network' 	=> null,
		'home'		=> null,
		'profiles'	=> null,
		'introductions' => null,
		'notifications'	=> null,
		'messages'	=> null,
		'directory'	=> null,
		'settings'	=> null,
		'contacts'	=> null,
		'manage'	=> null,
		'events'	=> null,
		'register'	=> null,
	);
	$a->nav_sel[$item] = 'selected';
}
