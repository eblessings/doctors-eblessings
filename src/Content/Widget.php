<?php
/**
 * @file src/Content/Widget.php
 */
namespace Friendica\Content;

use Friendica\Core\Addon;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\Protocol;
use Friendica\Core\Renderer;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\FileTag;
use Friendica\Model\GContact;
use Friendica\Model\Profile;
use Friendica\Util\Proxy as ProxyUtils;
use Friendica\Util\Strings;
use Friendica\Util\XML;

class Widget
{
	/**
	 * Return the follow widget
	 *
	 * @param string $value optional, default empty
	 * @return string
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function follow($value = "")
	{
		return Renderer::replaceMacros(Renderer::getMarkupTemplate('follow.tpl'), array(
			'$connect' => L10n::t('Add New Contact'),
			'$desc' => L10n::t('Enter address or web location'),
			'$hint' => L10n::t('Example: bob@example.com, http://example.com/barbara'),
			'$value' => $value,
			'$follow' => L10n::t('Connect')
		));
	}

	/**
	 * Return Find People widget
	 */
	public static function findPeople()
	{
		$a = \get_app();
		$global_dir = Config::get('system', 'directory');

		if (Config::get('system', 'invitation_only')) {
			$x = PConfig::get(local_user(), 'system', 'invites_remaining');
			if ($x || is_site_admin()) {
				$a->page['aside'] .= '<div class="side-link widget" id="side-invite-remain">'
					. L10n::tt('%d invitation available', '%d invitations available', $x)
					. '</div>';
			}
		}

		$nv = [];
		$nv['findpeople'] = L10n::t('Find People');
		$nv['desc'] = L10n::t('Enter name or interest');
		$nv['label'] = L10n::t('Connect/Follow');
		$nv['hint'] = L10n::t('Examples: Robert Morgenstein, Fishing');
		$nv['findthem'] = L10n::t('Find');
		$nv['suggest'] = L10n::t('Friend Suggestions');
		$nv['similar'] = L10n::t('Similar Interests');
		$nv['random'] = L10n::t('Random Profile');
		$nv['inv'] = L10n::t('Invite Friends');
		$nv['directory'] = L10n::t('Global Directory');
		$nv['global_dir'] = $global_dir;
		$nv['local_directory'] = L10n::t('Local Directory');

		$aside = [];
		$aside['$nv'] = $nv;

		return Renderer::replaceMacros(Renderer::getMarkupTemplate('peoplefind.tpl'), $aside);
	}

	/**
	 * Return unavailable networks
	 */
	public static function unavailableNetworks()
	{
		// Always hide content from these networks
		$networks = ['face', 'apdn'];

		if (!Addon::isEnabled("statusnet")) {
			$networks[] = Protocol::STATUSNET;
		}

		if (!Addon::isEnabled("pumpio")) {
			$networks[] = Protocol::PUMPIO;
		}

		if (!Addon::isEnabled("twitter")) {
			$networks[] = Protocol::TWITTER;
		}

		if (Config::get("system", "ostatus_disabled")) {
			$networks[] = Protocol::OSTATUS;
		}

		if (!Config::get("system", "diaspora_enabled")) {
			$networks[] = Protocol::DIASPORA;
		}

		if (!Addon::isEnabled("pnut")) {
			$networks[] = Protocol::PNUT;
		}

		if (!sizeof($networks)) {
			return "";
		}

		$network_filter = implode("','", $networks);

		$network_filter = "AND `network` NOT IN ('$network_filter')";

		return $network_filter;
	}

	/**
	 * Return networks widget
	 *
	 * @param string $baseurl  baseurl
	 * @param string $selected optional, default empty
	 * @return string
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function networks($baseurl, $selected = '')
	{
		if (!local_user()) {
			return '';
		}

		if (!Feature::isEnabled(local_user(), 'networks')) {
			return '';
		}

		$extra_sql = self::unavailableNetworks();

		$r = DBA::p("SELECT DISTINCT(`network`) FROM `contact` WHERE `uid` = ? AND NOT `deleted` AND `network` != '' $extra_sql ORDER BY `network`",
			local_user()
		);

		$nets = array();
		while ($rr = DBA::fetch($r)) {
			$nets[] = array('ref' => $rr['network'], 'name' => ContactSelector::networkToName($rr['network']), 'selected' => (($selected == $rr['network']) ? 'selected' : '' ));
		}
		DBA::close($r);

		if (count($nets) < 2) {
			return '';
		}

		return Renderer::replaceMacros(Renderer::getMarkupTemplate('nets.tpl'), array(
			'$title' => L10n::t('Protocols'),
			'$desc' => '',
			'$sel_all' => (($selected == '') ? 'selected' : ''),
			'$all' => L10n::t('All Protocols'),
			'$nets' => $nets,
			'$base' => $baseurl,
		));
	}

	/**
	 * Return file as widget
	 *
	 * @param string $baseurl  baseurl
	 * @param string $selected optional, default empty
	 * @return string|void
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function fileAs($baseurl, $selected = '')
	{
		if (!local_user()) {
			return '';
		}

		$saved = PConfig::get(local_user(), 'system', 'filetags');
		if (!strlen($saved)) {
			return;
		}

		$matches = false;
		$terms = array();
		$cnt = preg_match_all('/\[(.*?)\]/', $saved, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch)
			{
				$unescaped = XML::escape(FileTag::decode($mtch[1]));
				$terms[] = array('name' => $unescaped, 'selected' => (($selected == $unescaped) ? 'selected' : ''));
			}
		}

		return Renderer::replaceMacros(Renderer::getMarkupTemplate('fileas_widget.tpl'), array(
			'$title' => L10n::t('Saved Folders'),
			'$desc' => '',
			'$sel_all' => (($selected == '') ? 'selected' : ''),
			'$all' => L10n::t('Everything'),
			'$terms' => $terms,
			'$base' => $baseurl,
		));
	}

	/**
	 * Return categories widget
	 *
	 * @param string $baseurl  baseurl
	 * @param string $selected optional, default empty
	 * @return string|void
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function categories($baseurl, $selected = '')
	{
		$a = \get_app();

		if (!Feature::isEnabled($a->profile['profile_uid'], 'categories')) {
			return '';
		}

		$saved = PConfig::get($a->profile['profile_uid'], 'system', 'filetags');
		if (!strlen($saved)) {
			return;
		}

		$matches = false;
		$terms = array();
		$cnt = preg_match_all('/<(.*?)>/', $saved, $matches, PREG_SET_ORDER);

		if ($cnt) {
			foreach ($matches as $mtch) {
				$unescaped = XML::escape(FileTag::decode($mtch[1]));
				$terms[] = array('name' => $unescaped, 'selected' => (($selected == $unescaped) ? 'selected' : ''));
			}
		}

		return Renderer::replaceMacros(Renderer::getMarkupTemplate('categories_widget.tpl'), array(
			'$title' => L10n::t('Categories'),
			'$desc' => '',
			'$sel_all' => (($selected == '') ? 'selected' : ''),
			'$all' => L10n::t('Everything'),
			'$terms' => $terms,
			'$base' => $baseurl,
		));
	}

	/**
	 * Return common friends visitor widget
	 *
	 * @param string $profile_uid uid
	 * @return string|void
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function commonFriendsVisitor($profile_uid)
	{
		if (local_user() == $profile_uid) {
			return;
		}

		$cid = $zcid = 0;

		if (!empty($_SESSION['remote'])) {
			foreach ($_SESSION['remote'] as $visitor) {
				if ($visitor['uid'] == $profile_uid) {
					$cid = $visitor['cid'];
					break;
				}
			}
		}

		if (!$cid) {
			if (Profile::getMyURL()) {
				$contact = DBA::selectFirst('contact', ['id'],
						['nurl' => Strings::normaliseLink(Profile::getMyURL()), 'uid' => $profile_uid]);
				if (DBA::isResult($contact)) {
					$cid = $contact['id'];
				} else {
					$gcontact = DBA::selectFirst('gcontact', ['id'], ['nurl' => Strings::normaliseLink(Profile::getMyURL())]);
					if (DBA::isResult($gcontact)) {
						$zcid = $gcontact['id'];
					}
				}
			}
		}

		if ($cid == 0 && $zcid == 0) {
			return;
		}

		if ($cid) {
			$t = GContact::countCommonFriends($profile_uid, $cid);
		} else {
			$t = GContact::countCommonFriendsZcid($profile_uid, $zcid);
		}

		if (!$t) {
			return;
		}

		if ($cid) {
			$r = GContact::commonFriends($profile_uid, $cid, 0, 5, true);
		} else {
			$r = GContact::commonFriendsZcid($profile_uid, $zcid, 0, 5, true);
		}

		if (!DBA::isResult($r)) {
			return;
		}

		$entries = [];
		foreach ($r as $rr) {
			$entry = [
				'url'   => Contact::magicLink($rr['url']),
				'name'  => $rr['name'],
				'photo' => ProxyUtils::proxifyUrl($rr['photo'], false, ProxyUtils::SIZE_THUMB),
			];
			$entries[] = $entry;
		}

		$tpl = Renderer::getMarkupTemplate('remote_friends_common.tpl');
		return Renderer::replaceMacros($tpl, [
			'$desc'     => L10n::tt("%d contact in common", "%d contacts in common", $t),
			'$base'     => System::baseUrl(),
			'$uid'      => $profile_uid,
			'$cid'      => (($cid) ? $cid : '0'),
			'$linkmore' => (($t > 5) ? 'true' : ''),
			'$more'     => L10n::t('show more'),
			'$items'    => $entries
		]);
	}

	/**
	 * Insert a tag cloud widget for the present profile.
	 *
	 * @brief Insert a tag cloud widget for the present profile.
	 * @param int $limit Max number of displayed tags.
	 * @return string HTML formatted output.
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function tagCloud($limit = 50)
	{
		$a = \get_app();

		if (!$a->profile['profile_uid'] || !$a->profile['url']) {
			return '';
		}

		if (Feature::isEnabled($a->profile['profile_uid'], 'tagadelic')) {
			$owner_id = Contact::getIdForURL($a->profile['url'], 0, true);

			if (!$owner_id) {
				return '';
			}
			return Widget\TagCloud::getHTML($a->profile['profile_uid'], $limit, $owner_id, 'wall');
		}

		return '';
	}
}
