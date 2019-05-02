<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Core\Addon;
use Friendica\Core\System;

class Statistics extends BaseModule
{
	public static function init()
	{
		$config = self::getApp()->getConfig();

		if (!$config->get("system", "nodeinfo")) {
			System::httpExit(404);
		}
	}

	public static function rawContent()
	{
		$config = self::getApp()->getConfig();
		$logger = self::getApp()->getLogger();

		$registration_open =
			intval($config->get('config', 'register_policy')) !== Register::CLOSED
			&& !$config->get('config', 'invitation_only');

		/// @todo mark the "service" addons and load them dynamically here
		$services = [
			'appnet'      => Addon::isEnabled('appnet'),
			'buffer'      => Addon::isEnabled('buffer'),
			'dreamwidth'  => Addon::isEnabled('dreamwidth'),
			'gnusocial'   => Addon::isEnabled('gnusocial'),
			'libertree'   => Addon::isEnabled('libertree'),
			'livejournal' => Addon::isEnabled('livejournal'),
			'pumpio'      => Addon::isEnabled('pumpio'),
			'twitter'     => Addon::isEnabled('twitter'),
			'tumblr'      => Addon::isEnabled('tumblr'),
			'wordpress'   => Addon::isEnabled('wordpress'),
		];

		$statistics = array_merge([
			'name'                  => $config->get('config', 'sitename'),
			'network'               => FRIENDICA_PLATFORM,
			'version'               => FRIENDICA_VERSION . '-' . DB_UPDATE_VERSION,
			'registrations_open'    => $registration_open,
			'total_users'           => $config->get('nodeinfo', 'total_users'),
			'active_users_halfyear' => $config->get('nodeinfo', 'active_users_halfyear'),
			'active_users_monthly'  => $config->get('nodeinfo', 'active_users_monthly'),
			'local_posts'           => $config->get('nodeinfo', 'local_posts'),
			'services'              => $services,
		], $services);

		header("Content-Type: application/json");
		echo json_encode($statistics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$logger->debug("statistics.", ['statistics' => $statistics]);
		exit();
	}
}
