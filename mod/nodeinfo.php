<?php
/**
 * @file mod/nodeinfo.php
 *
 * Documentation: http://nodeinfo.diaspora.software/schema.html
*/

use Friendica\App;
use Friendica\Core\System;
use Friendica\Core\Config;

require_once 'include/plugin.php';

function nodeinfo_wellknown(App $a) {
	$nodeinfo = array('links' => array(array('rel' => 'http://nodeinfo.diaspora.software/ns/schema/1.0',
					'href' => App::get_baseurl().'/nodeinfo/1.0')));

	header('Content-type: application/json; charset=utf-8');
	echo json_encode($nodeinfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	exit;
}

function nodeinfo_init(App $a) {
	if (!Config::get('system', 'nodeinfo')) {
		http_status_exit(404);
		killme();
	}

	if (($a->argc != 2) || ($a->argv[1] != '1.0')) {
		http_status_exit(404);
		killme();
	}

	$smtp = (function_exists('imap_open') && !Config::get('system', 'imap_disabled') && !Config::get('system', 'dfrn_only'));

	$nodeinfo = array();
	$nodeinfo['version'] = '1.0';
	$nodeinfo['software'] = array('name' => 'friendica', 'version' => FRIENDICA_VERSION.'-'.DB_UPDATE_VERSION);

	$nodeinfo['protocols'] = array();
	$nodeinfo['protocols']['inbound'] = array();
	$nodeinfo['protocols']['outbound'] = array();

	if (Config::get('system', 'diaspora_enabled')) {
		$nodeinfo['protocols']['inbound'][] = 'diaspora';
		$nodeinfo['protocols']['outbound'][] = 'diaspora';
	}

	$nodeinfo['protocols']['inbound'][] = 'friendica';
	$nodeinfo['protocols']['outbound'][] = 'friendica';

	if (!Config::get('system', 'ostatus_disabled')) {
		$nodeinfo['protocols']['inbound'][] = 'gnusocial';
		$nodeinfo['protocols']['outbound'][] = 'gnusocial';
	}

	$nodeinfo['services'] = array();
	$nodeinfo['services']['inbound'] = array();
	$nodeinfo['services']['outbound'] = array();

	$nodeinfo['usage'] = array();

	$nodeinfo['openRegistrations'] = ($a->config['register_policy'] != 0);

	$nodeinfo['metadata'] = array('nodeName' => $a->config['sitename']);

	if (Config::get('system', 'nodeinfo')) {

		$nodeinfo['usage']['users'] = array('total' => (int)Config::get('nodeinfo', 'total_users'),
					'activeHalfyear' => (int)Config::get('nodeinfo', 'active_users_halfyear'),
					'activeMonth' => (int)Config::get('nodeinfo', 'active_users_monthly'));
		$nodeinfo['usage']['localPosts'] = (int)Config::get('nodeinfo', 'local_posts');
		$nodeinfo['usage']['localComments'] = (int)Config::get('nodeinfo', 'local_comments');

		if (plugin_enabled('appnet')) {
			$nodeinfo['services']['inbound'][] = 'appnet';
		}
		if (plugin_enabled('appnet') || plugin_enabled('buffer')) {
			$nodeinfo['services']['outbound'][] = 'appnet';
		}
		if (plugin_enabled('blogger')) {
			$nodeinfo['services']['outbound'][] = 'blogger';
		}
		if (plugin_enabled('dwpost')) {
			$nodeinfo['services']['outbound'][] = 'dreamwidth';
		}
		if (plugin_enabled('fbpost') || plugin_enabled('buffer')) {
			$nodeinfo['services']['outbound'][] = 'facebook';
		}
		if (plugin_enabled('statusnet')) {
			$nodeinfo['services']['inbound'][] = 'gnusocial';
			$nodeinfo['services']['outbound'][] = 'gnusocial';
		}

		if (plugin_enabled('gpluspost') || plugin_enabled('buffer')) {
			$nodeinfo['services']['outbound'][] = 'google';
		}
		if (plugin_enabled('ijpost')) {
			$nodeinfo['services']['outbound'][] = 'insanejournal';
		}
		if (plugin_enabled('libertree')) {
			$nodeinfo['services']['outbound'][] = 'libertree';
		}
		if (plugin_enabled('buffer')) {
			$nodeinfo['services']['outbound'][] = 'linkedin';
		}
		if (plugin_enabled('ljpost')) {
			$nodeinfo['services']['outbound'][] = 'livejournal';
		}
		if (plugin_enabled('buffer')) {
			$nodeinfo['services']['outbound'][] = 'pinterest';
		}
		if (plugin_enabled('posterous')) {
			$nodeinfo['services']['outbound'][] = 'posterous';
		}
		if (plugin_enabled('pumpio')) {
			$nodeinfo['services']['inbound'][] = 'pumpio';
			$nodeinfo['services']['outbound'][] = 'pumpio';
		}

		if ($smtp) {
			$nodeinfo['services']['outbound'][] = 'smtp';
		}
		if (plugin_enabled('tumblr')) {
			$nodeinfo['services']['outbound'][] = 'tumblr';
		}
		if (plugin_enabled('twitter') || plugin_enabled('buffer')) {
			$nodeinfo['services']['outbound'][] = 'twitter';
		}
		if (plugin_enabled('wppost')) {
			$nodeinfo['services']['outbound'][] = 'wordpress';
		}
		$nodeinfo['metadata']['protocols'] = $nodeinfo['protocols'];
		$nodeinfo['metadata']['protocols']['outbound'][] = 'atom1.0';
		$nodeinfo['metadata']['protocols']['inbound'][] = 'atom1.0';
		$nodeinfo['metadata']['protocols']['inbound'][] = 'rss2.0';

		$nodeinfo['metadata']['services'] = $nodeinfo['services'];

		if (plugin_enabled('twitter')) {
			$nodeinfo['metadata']['services']['inbound'][] = 'twitter';
		}
	}

	header('Content-type: application/json; charset=utf-8');
	echo json_encode($nodeinfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	exit;
}



function nodeinfo_cron() {

	$a = get_app();

	// If the plugin 'statistics_json' is enabled then disable it and actrivate nodeinfo.
	if (plugin_enabled('statistics_json')) {
		Config::set('system', 'nodeinfo', true);

		$plugin = 'statistics_json';
		$plugins = Config::get('system', 'addon');
		$plugins_arr = array();

		if ($plugins) {
			$plugins_arr = explode(',',str_replace(' ', '',$plugins));

			$idx = array_search($plugin, $plugins_arr);
			if ($idx !== false) {
				unset($plugins_arr[$idx]);
				uninstall_plugin($plugin);
				Config::set('system', 'addon', implode(', ',$plugins_arr));
			}
		}
	}

	if (!Config::get('system', 'nodeinfo')) {
		return;
	}
	$last = Config::get('nodeinfo', 'last_calucation');

	if ($last) {
		// Calculate every 24 hours
		$next = $last + (24 * 60 * 60);
		if ($next > time()) {
			logger('calculation intervall not reached');
			return;
		}
	}
        logger('cron_start');

	$users = qu("SELECT `user`.`uid`, `user`.`login_date`, `contact`.`last-item`
			FROM `user`
			INNER JOIN `profile` ON `profile`.`uid` = `user`.`uid` AND `profile`.`is-default`
			INNER JOIN `contact` ON `contact`.`uid` = `user`.`uid` AND `contact`.`self`
			WHERE (`profile`.`publish` OR `profile`.`net-publish`) AND `user`.`verified`
				AND NOT `user`.`blocked` AND NOT `user`.`account_removed`
				AND NOT `user`.`account_expired`");
	if (is_array($users)) {
			$total_users = count($users);
			$active_users_halfyear = 0;
			$active_users_monthly = 0;

			$halfyear = time() - (180 * 24 * 60 * 60);
			$month = time() - (30 * 24 * 60 * 60);

			foreach ($users AS $user) {
				if ((strtotime($user['login_date']) > $halfyear) ||
					(strtotime($user['last-item']) > $halfyear)) {
					++$active_users_halfyear;
				}
				if ((strtotime($user['login_date']) > $month) ||
					(strtotime($user['last-item']) > $month)) {
					++$active_users_monthly;
				}
			}
			Config::set('nodeinfo', 'total_users', $total_users);
		        logger('total_users: '.$total_users, LOGGER_DEBUG);

			Config::set('nodeinfo', 'active_users_halfyear', $active_users_halfyear);
			Config::set('nodeinfo', 'active_users_monthly', $active_users_monthly);
	}

	$posts = qu("SELECT COUNT(*) AS local_posts FROM `thread` WHERE `thread`.`wall` AND `thread`.`uid` != 0");

	if (!is_array($posts)) {
		$local_posts = -1;
	} else {
		$local_posts = $posts[0]['local_posts'];
	}
	Config::set('nodeinfo', 'local_posts', $local_posts);

        logger('local_posts: '.$local_posts, LOGGER_DEBUG);

	$posts = qu("SELECT COUNT(*) FROM `contact`
			INNER JOIN `item` ON `item`.`contact-id` = `contact`.`id` AND `item`.`uid` = `contact`.`uid` AND
				`item`.`id` != `item`.`parent` AND `item`.`network` IN ('%s', '%s', '%s')
			WHERE `contact`.`self`",
			dbesc(NETWORK_OSTATUS), dbesc(NETWORK_DIASPORA), dbesc(NETWORK_DFRN));

	if (!is_array($posts)) {
		$local_comments = -1;
	} else {
		$local_comments = $posts[0]['local_comments'];
	}
	Config::set('nodeinfo', 'local_comments', $local_comments);

	// Now trying to register
	$url = 'http://the-federation.info/register/'.$a->get_hostname();
        logger('registering url: '.$url, LOGGER_DEBUG);
	$ret = fetch_url($url);
        logger('registering answer: '.$ret, LOGGER_DEBUG);

        logger('cron_end');
	Config::set('nodeinfo', 'last_calucation', time());
}
