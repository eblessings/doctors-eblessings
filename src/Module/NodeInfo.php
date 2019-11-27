<?php

namespace Friendica\Module;

use Friendica\App;
use Friendica\BaseModule;
use Friendica\Core\Addon;
use Friendica\Core\System;

/**
 * Standardized way of exposing metadata about a server running one of the distributed social networks.
 * @see https://github.com/jhass/nodeinfo/blob/master/PROTOCOL.md
 */
class NodeInfo extends BaseModule
{
	public static function rawContent(array $parameters = [])
	{
		$app = self::getApp();

		// @TODO: Replace with parameter from router
		// if the first argument is ".well-known", print the well-known text
		if (($app->argc > 1) && ($app->argv[0] == '.well-known')) {
			self::printWellKnown($app);
		// otherwise print the nodeinfo
		} elseif ($parameters['version'] == '1.0') {
			self::printNodeInfo1($app);
		} elseif ($parameters['version'] == '2.0') {
			self::printNodeInfo2($app);
		} else {
			throw new \Friendica\Network\HTTPException\NotFoundException();
		}
	}

	/**
	 * Prints the well-known nodeinfo redirect
	 *
	 * @param App $app
	 *
	 * @throws \Friendica\Network\HTTPException\NotFoundException
	 */
	private static function printWellKnown(App $app)
	{
		$nodeinfo = [
			'links' => [
				['rel'  => 'http://nodeinfo.diaspora.software/ns/schema/1.0',
				'href' => $app->getBaseURL() . '/nodeinfo/1.0'],
				['rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
				'href' => $app->getBaseURL() . '/nodeinfo/2.0'],
			]
		];

		header('Content-type: application/json; charset=utf-8');
		echo json_encode($nodeinfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		exit;
	}

	/**
	 * Return the supported services
	 *
	 * @param App $app
	 *
	 * @return array with supported services
	*/
	private static function getUsage(App $app)
	{
		$config = $app->getConfig();

		$usage = [];

		if (!empty($config->get('system', 'nodeinfo'))) {
			$usage['users'] = [
				'total'          => intval($config->get('nodeinfo', 'total_users')),
				'activeHalfyear' => intval($config->get('nodeinfo', 'active_users_halfyear')),
				'activeMonth'    => intval($config->get('nodeinfo', 'active_users_monthly'))
			];
			$usage['localPosts'] = intval($config->get('nodeinfo', 'local_posts'));
			$usage['localComments'] = intval($config->get('nodeinfo', 'local_comments'));
		}

		return $usage;
	}

	/**
	 * Return the supported services
	 *
	 * @param App $app
	 *
	 * @return array with supported services
	*/
	private static function getServices(App $app)
	{
		$config = $app->getConfig();

		$services = [
			'inbound'  => [],
			'outbound' => [],
		];

		$smtp = (function_exists('imap_open') && !$config->get('system', 'imap_disabled') && !$config->get('system', 'dfrn_only'));

		if (Addon::isEnabled('blogger')) {
			$services['outbound'][] = 'blogger';
		}
		if (Addon::isEnabled('dwpost')) {
			$services['outbound'][] = 'dreamwidth';
		}
		if (Addon::isEnabled('statusnet')) {
			$services['inbound'][] = 'gnusocial';
			$services['outbound'][] = 'gnusocial';
		}
		if (Addon::isEnabled('ijpost')) {
			$services['outbound'][] = 'insanejournal';
		}
		if (Addon::isEnabled('libertree')) {
			$services['outbound'][] = 'libertree';
		}
		if (Addon::isEnabled('buffer')) {
			$services['outbound'][] = 'linkedin';
		}
		if (Addon::isEnabled('ljpost')) {
			$services['outbound'][] = 'livejournal';
		}
		if (Addon::isEnabled('buffer')) {
			$services['outbound'][] = 'pinterest';
		}
		if (Addon::isEnabled('posterous')) {
			$services['outbound'][] = 'posterous';
		}
		if (Addon::isEnabled('pumpio')) {
			$services['inbound'][] = 'pumpio';
			$services['outbound'][] = 'pumpio';
		}
		if ($smtp) {
			$services['outbound'][] = 'smtp';
		}
		if (Addon::isEnabled('tumblr')) {
			$services['outbound'][] = 'tumblr';
		}
		if (Addon::isEnabled('twitter') || Addon::isEnabled('buffer')) {
			$services['outbound'][] = 'twitter';
		}
		if (Addon::isEnabled('wppost')) {
			$services['outbound'][] = 'wordpress';
		}

		return $services;
	}

	/**
	 * Print the nodeinfo version 1
	 *
	 * @param App $app
	 */
	private static function printNodeInfo1(App $app)
	{
		$config = $app->getConfig();

		$nodeinfo = [
			'version'           => '1.0',
			'software'          => [
				'name'    => 'friendica',
				'version' => FRIENDICA_VERSION . '-' . DB_UPDATE_VERSION,
			],
			'protocols'         => [
				'inbound'  => [
					'friendica', 'activitypub'
				],
				'outbound' => [
					'friendica', 'activitypub'
				],
			],
			'services'          => [],
			'usage'             => [],
			'openRegistrations' => intval($config->get('config', 'register_policy')) !== Register::CLOSED,
			'metadata'          => [
				'nodeName' => $config->get('config', 'sitename'),
			],
		];

		if (!empty($config->get('system', 'diaspora_enabled'))) {
			$nodeinfo['protocols']['inbound'][] = 'diaspora';
			$nodeinfo['protocols']['outbound'][] = 'diaspora';
		}

		if (empty($config->get('system', 'ostatus_disabled'))) {
			$nodeinfo['protocols']['inbound'][] = 'gnusocial';
			$nodeinfo['protocols']['outbound'][] = 'gnusocial';
		}

		$nodeinfo['usage'] = self::getUsage($app);

		$nodeinfo['services'] = self::getServices($app);

		$nodeinfo['metadata']['protocols'] = $nodeinfo['protocols'];
		$nodeinfo['metadata']['protocols']['outbound'][] = 'atom1.0';
		$nodeinfo['metadata']['protocols']['inbound'][] = 'atom1.0';
		$nodeinfo['metadata']['protocols']['inbound'][] = 'rss2.0';

		$nodeinfo['metadata']['services'] = $nodeinfo['services'];

		if (Addon::isEnabled('twitter')) {
			$nodeinfo['metadata']['services']['inbound'][] = 'twitter';
		}

		$nodeinfo['metadata']['explicitContent'] = $config->get('system', 'explicit_content', false) == true;

		header('Content-type: application/json; charset=utf-8');
		echo json_encode($nodeinfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		exit;
	}

	/**
	 * Print the nodeinfo version 2
	 *
	 * @param App $app
	 */
	private static function printNodeInfo2(App $app)
	{
		$config = $app->getConfig();

		$smtp = (function_exists('imap_open') && !$config->get('system', 'imap_disabled') && !$config->get('system', 'dfrn_only'));

		$nodeinfo = [
			'version'           => '2.0',
			'software'          => [
				'name'    => 'friendica',
				'version' => FRIENDICA_VERSION . '-' . DB_UPDATE_VERSION,
			],
			'protocols'         => ['dfrn', 'activitypub'],
			'services'          => [],
			'usage'             => [],
			'openRegistrations' => intval($config->get('config', 'register_policy')) !== Register::CLOSED,
			'metadata'          => [
				'nodeName' => $config->get('config', 'sitename'),
			],
		];

		if (!empty($config->get('system', 'diaspora_enabled'))) {
			$nodeinfo['protocols'][] = 'diaspora';
		}

		if (empty($config->get('system', 'ostatus_disabled'))) {
			$nodeinfo['protocols'][] = 'ostatus';
		}

		$nodeinfo['usage'] = self::getUsage($app);

		$nodeinfo['services'] = self::getServices($app);

		$nodeinfo['services']['inbound'][]  = 'atom1.0';
		$nodeinfo['services']['outbound'][] = 'atom1.0';
		$nodeinfo['services']['outbound'][] = 'rss2.0';

		$nodeinfo['metadata']['explicitContent'] = $config->get('system', 'explicit_content', false) == true;

		header('Content-type: application/json; charset=utf-8');
		echo json_encode($nodeinfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		exit;
	}
}
