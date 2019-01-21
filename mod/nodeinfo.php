<?php
/**
 * @file mod/nodeinfo.php
 *
 * Documentation: http://nodeinfo.diaspora.software/schema.html
*/

use Friendica\App;
use Friendica\Core\Addon;
use Friendica\Core\Config;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Util\Network;
function nodeinfo_wellknown(App $a) {
	$nodeinfo = ['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/1.0',
					'href' => System::baseUrl().'/nodeinfo/1.0']]];

	header('Content-type: application/json; charset=utf-8');
	echo json_encode($nodeinfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	exit;
}

function nodeinfo_init(App $a) {
	if (!Config::get('system', 'nodeinfo')) {
		System::httpExit(404);
	}

	if (($a->argc != 2) || ($a->argv[1] != '1.0')) {
		System::httpExit(404);
	}

	$smtp = (function_exists('imap_open') && !Config::get('system', 'imap_disabled') && !Config::get('system', 'dfrn_only'));

	$nodeinfo = [];
	$nodeinfo['version'] = '1.0';
	$nodeinfo['software'] = ['name' => 'friendica', 'version' => FRIENDICA_VERSION.'-'.DB_UPDATE_VERSION];

	$nodeinfo['protocols'] = [];
	$nodeinfo['protocols']['inbound'] = [];
	$nodeinfo['protocols']['outbound'] = [];

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

	$nodeinfo['services'] = [];
	$nodeinfo['services']['inbound'] = [];
	$nodeinfo['services']['outbound'] = [];

	$nodeinfo['usage'] = [];

	$nodeinfo['openRegistrations'] = intval(Config::get('config', 'register_policy')) !== REGISTER_CLOSED;

	$nodeinfo['metadata'] = ['nodeName' => Config::get('config', 'sitename')];

	if (Config::get('system', 'nodeinfo')) {

		$nodeinfo['usage']['users'] = ['total' => (int)Config::get('nodeinfo', 'total_users'),
					'activeHalfyear' => (int)Config::get('nodeinfo', 'active_users_halfyear'),
					'activeMonth' => (int)Config::get('nodeinfo', 'active_users_monthly')];
		$nodeinfo['usage']['localPosts'] = (int)Config::get('nodeinfo', 'local_posts');
		$nodeinfo['usage']['localComments'] = (int)Config::get('nodeinfo', 'local_comments');

		if (Addon::isEnabled('blogger')) {
			$nodeinfo['services']['outbound'][] = 'blogger';
		}
		if (Addon::isEnabled('dwpost')) {
			$nodeinfo['services']['outbound'][] = 'dreamwidth';
		}
		if (Addon::isEnabled('statusnet')) {
			$nodeinfo['services']['inbound'][] = 'gnusocial';
			$nodeinfo['services']['outbound'][] = 'gnusocial';
		}
		if (Addon::isEnabled('ijpost')) {
			$nodeinfo['services']['outbound'][] = 'insanejournal';
		}
		if (Addon::isEnabled('libertree')) {
			$nodeinfo['services']['outbound'][] = 'libertree';
		}
		if (Addon::isEnabled('buffer')) {
			$nodeinfo['services']['outbound'][] = 'linkedin';
		}
		if (Addon::isEnabled('ljpost')) {
			$nodeinfo['services']['outbound'][] = 'livejournal';
		}
		if (Addon::isEnabled('buffer')) {
			$nodeinfo['services']['outbound'][] = 'pinterest';
		}
		if (Addon::isEnabled('posterous')) {
			$nodeinfo['services']['outbound'][] = 'posterous';
		}
		if (Addon::isEnabled('pumpio')) {
			$nodeinfo['services']['inbound'][] = 'pumpio';
			$nodeinfo['services']['outbound'][] = 'pumpio';
		}

		if ($smtp) {
			$nodeinfo['services']['outbound'][] = 'smtp';
		}
		if (Addon::isEnabled('tumblr')) {
			$nodeinfo['services']['outbound'][] = 'tumblr';
		}
		if (Addon::isEnabled('twitter') || Addon::isEnabled('buffer')) {
			$nodeinfo['services']['outbound'][] = 'twitter';
		}
		if (Addon::isEnabled('wppost')) {
			$nodeinfo['services']['outbound'][] = 'wordpress';
		}
		$nodeinfo['metadata']['protocols'] = $nodeinfo['protocols'];
		$nodeinfo['metadata']['protocols']['outbound'][] = 'atom1.0';
		$nodeinfo['metadata']['protocols']['inbound'][] = 'atom1.0';
		$nodeinfo['metadata']['protocols']['inbound'][] = 'rss2.0';

		$nodeinfo['metadata']['services'] = $nodeinfo['services'];

		if (Addon::isEnabled('twitter')) {
			$nodeinfo['metadata']['services']['inbound'][] = 'twitter';
		}

		$nodeinfo['metadata']['explicitContent'] = Config::get('system', 'explicit_content', false) == true;
	}

	header('Content-type: application/json; charset=utf-8');
	echo json_encode($nodeinfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	exit;
}



function nodeinfo_cron() {

	$a = \get_app();

	// If the addon 'statistics_json' is enabled then disable it and activate nodeinfo.
	if (Addon::isEnabled('statistics_json')) {
		Config::set('system', 'nodeinfo', true);

		$addon = 'statistics_json';
		$addons = Config::get('system', 'addon');

		if ($addons) {
			$addons_arr = explode(',',str_replace(' ', '',$addons));

			$idx = array_search($addon, $addons_arr);
			if ($idx !== false) {
				unset($addons_arr[$idx]);
				Addon::uninstall($addon);
				Config::set('system', 'addon', implode(', ',$addons_arr));
			}
		}
	}

	if (!Config::get('system', 'nodeinfo')) {
		return;
	}

	Logger::log('cron_start');

	$users = q("SELECT `user`.`uid`, `user`.`login_date`, `contact`.`last-item`
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
		Config::set('nodeinfo', 'active_users_halfyear', $active_users_halfyear);
		Config::set('nodeinfo', 'active_users_monthly', $active_users_monthly);

		Logger::log('total_users: ' . $total_users . '/' . $active_users_halfyear. '/' . $active_users_monthly, Logger::DEBUG);
	}

	$local_posts = DBA::count('thread', ["`wall` AND NOT `deleted` AND `uid` != 0"]);
	Config::set('nodeinfo', 'local_posts', $local_posts);
	Logger::log('local_posts: ' . $local_posts, Logger::DEBUG);

	$local_comments = DBA::count('item', ["`origin` AND `id` != `parent` AND NOT `deleted` AND `uid` != 0"]);
	Config::set('nodeinfo', 'local_comments', $local_comments);
	Logger::log('local_comments: ' . $local_comments, Logger::DEBUG);

	// Now trying to register
	$url = 'http://the-federation.info/register/'.$a->getHostName();
	Logger::log('registering url: '.$url, Logger::DEBUG);
	$ret = Network::fetchUrl($url);
	Logger::log('registering answer: '.$ret, Logger::DEBUG);

	Logger::log('cron_end');
}
