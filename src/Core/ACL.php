<?php

/**
 * @file src/Core/Acl.php
 */

namespace Friendica\Core;

use Friendica\BaseObject;
use Friendica\Content\Feature;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\GContact;
use Friendica\Core\Session;
use Friendica\Util\Network;
use Friendica\Model\Group;

/**
 * Handle ACL management and display
 *
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
class ACL extends BaseObject
{
	/**
	 * Returns a select input tag with all the contact of the local user
	 *
	 * @param string $selname     Name attribute of the select input tag
	 * @param string $selclass    Class attribute of the select input tag
	 * @param array  $options     Available options:
	 *                            - size: length of the select box
	 *                            - mutual_friends: Only used for the hook
	 *                            - single: Only used for the hook
	 *                            - exclude: Only used for the hook
	 * @param array  $preselected Contact ID that should be already selected
	 * @return string
	 * @throws \Exception
	 */
	public static function getSuggestContactSelectHTML($selname, $selclass, array $options = [], array $preselected = [])
	{
		$a = self::getApp();

		$networks = null;

		$size = ($options['size'] ?? 0) ?: 4;
		$mutual = !empty($options['mutual_friends']);
		$single = !empty($options['single']) && empty($options['multiple']);
		$exclude = $options['exclude'] ?? false;

		switch (($options['networks'] ?? '') ?: Protocol::PHANTOM) {
			case 'DFRN_ONLY':
				$networks = [Protocol::DFRN];
				break;

			case 'PRIVATE':
				$networks = [Protocol::ACTIVITYPUB, Protocol::DFRN, Protocol::MAIL, Protocol::DIASPORA];
				break;

			case 'TWO_WAY':
				if (!empty($a->user['prvnets'])) {
					$networks = [Protocol::ACTIVITYPUB, Protocol::DFRN, Protocol::MAIL, Protocol::DIASPORA];
				} else {
					$networks = [Protocol::ACTIVITYPUB, Protocol::DFRN, Protocol::MAIL, Protocol::DIASPORA, Protocol::OSTATUS];
				}
				break;

			default: /// @TODO Maybe log this call?
				break;
		}

		$x = ['options' => $options, 'size' => $size, 'single' => $single, 'mutual' => $mutual, 'exclude' => $exclude, 'networks' => $networks];

		Hook::callAll('contact_select_options', $x);

		$o = '';

		$sql_extra = '';

		if (!empty($x['mutual'])) {
			$sql_extra .= sprintf(" AND `rel` = %d ", intval(Contact::FRIEND));
		}

		if (!empty($x['exclude'])) {
			$sql_extra .= sprintf(" AND `id` != %d ", intval($x['exclude']));
		}

		if (!empty($x['networks'])) {
			/// @TODO rewrite to foreach()
			array_walk($x['networks'], function (&$value) {
				$value = "'" . DBA::escape($value) . "'";
			});
			$str_nets = implode(',', $x['networks']);
			$sql_extra .= " AND `network` IN ( $str_nets ) ";
		}

		$tabindex = (!empty($options['tabindex']) ? 'tabindex="' . $options["tabindex"] . '"' : '');

		if (!empty($x['single'])) {
			$o .= "<select name=\"$selname\" id=\"$selclass\" class=\"$selclass\" size=\"" . $x['size'] . "\" $tabindex >\r\n";
		} else {
			$o .= "<select name=\"{$selname}[]\" id=\"$selclass\" class=\"$selclass\" multiple=\"multiple\" size=\"" . $x['size'] . "$\" $tabindex >\r\n";
		}

		$stmt = DBA::p("SELECT `id`, `name`, `url`, `network` FROM `contact`
			WHERE `uid` = ? AND NOT `self` AND NOT `blocked` AND NOT `pending` AND NOT `archive` AND NOT `deleted` AND `notify` != ''
			$sql_extra
			ORDER BY `name` ASC ", intval(local_user())
		);

		$contacts = DBA::toArray($stmt);

		$arr = ['contact' => $contacts, 'entry' => $o];

		// e.g. 'network_pre_contact_deny', 'profile_pre_contact_allow'
		Hook::callAll($a->module . '_pre_' . $selname, $arr);

		if (DBA::isResult($contacts)) {
			foreach ($contacts as $contact) {
				if (in_array($contact['id'], $preselected)) {
					$selected = ' selected="selected" ';
				} else {
					$selected = '';
				}

				$trimmed = mb_substr($contact['name'], 0, 20);

				$o .= "<option value=\"{$contact['id']}\" $selected title=\"{$contact['name']}|{$contact['url']}\" >$trimmed</option>\r\n";
			}
		}

		$o .= '</select>' . PHP_EOL;

		Hook::callAll($a->module . '_post_' . $selname, $o);

		return $o;
	}

	/**
	 * Returns a select input tag with all the contact of the local user
	 *
	 * @param string $selname     Name attribute of the select input tag
	 * @param string $selclass    Class attribute of the select input tag
	 * @param array  $preselected Contact IDs that should be already selected
	 * @param int    $size        Length of the select box
	 * @param int    $tabindex    Select input tag tabindex attribute
	 * @return string
	 * @throws \Exception
	 */
	public static function getMessageContactSelectHTML($selname, $selclass, array $preselected = [], $size = 4, $tabindex = null)
	{
		$a = self::getApp();

		$o = '';

		// When used for private messages, we limit correspondence to mutual DFRN/Friendica friends and the selector
		// to one recipient. By default our selector allows multiple selects amongst all contacts.
		$sql_extra = sprintf(" AND `rel` = %d ", intval(Contact::FRIEND));
		$sql_extra .= sprintf(" AND `network` IN ('%s' , '%s') ", Protocol::DFRN, Protocol::DIASPORA);

		$tabindex_attr = !empty($tabindex) ? ' tabindex="' . intval($tabindex) . '"' : '';

		$hidepreselected = '';
		if ($preselected) {
			$sql_extra .= " AND `id` IN (" . implode(",", $preselected) . ")";
			$hidepreselected = ' style="display: none;"';
		}

		$o .= "<select name=\"$selname\" id=\"$selclass\" class=\"$selclass\" size=\"$size\"$tabindex_attr$hidepreselected>\r\n";

		$stmt = DBA::p("SELECT `id`, `name`, `url`, `network` FROM `contact`
			WHERE `uid` = ? AND NOT `self` AND NOT `blocked` AND NOT `pending` AND NOT `archive` AND NOT `deleted` AND `notify` != ''
			$sql_extra
			ORDER BY `name` ASC ", intval(local_user())
		);

		$contacts = DBA::toArray($stmt);

		$arr = ['contact' => $contacts, 'entry' => $o];

		// e.g. 'network_pre_contact_deny', 'profile_pre_contact_allow'
		Hook::callAll($a->module . '_pre_' . $selname, $arr);

		$receiverlist = [];

		if (DBA::isResult($contacts)) {
			foreach ($contacts as $contact) {
				if (in_array($contact['id'], $preselected)) {
					$selected = ' selected="selected"';
				} else {
					$selected = '';
				}

				$trimmed = Protocol::formatMention($contact['url'], $contact['name']);

				$receiverlist[] = $trimmed;

				$o .= "<option value=\"{$contact['id']}\"$selected title=\"{$contact['name']}|{$contact['url']}\" >$trimmed</option>\r\n";
			}
		}

		$o .= '</select>' . PHP_EOL;

		if ($preselected) {
			$o .= implode(', ', $receiverlist);
		}

		Hook::callAll($a->module . '_post_' . $selname, $o);

		return $o;
	}

	private static function fixACL(&$item)
	{
		$item = intval(str_replace(['<', '>'], ['', ''], $item));
	}

	/**
	 * Return the default permission of the provided user array
	 *
	 * @param array $user
	 * @return array Hash of contact id lists
	 * @throws \Exception
	 */
	public static function getDefaultUserPermissions(array $user = null)
	{
		$matches = [];

		$acl_regex = '/<([0-9]+)>/i';

		preg_match_all($acl_regex, $user['allow_cid'] ?? '', $matches);
		$allow_cid = $matches[1];
		preg_match_all($acl_regex, $user['allow_gid'] ?? '', $matches);
		$allow_gid = $matches[1];
		preg_match_all($acl_regex, $user['deny_cid'] ?? '', $matches);
		$deny_cid = $matches[1];
		preg_match_all($acl_regex, $user['deny_gid'] ?? '', $matches);
		$deny_gid = $matches[1];

		// Reformats the ACL data so that it is accepted by the JS frontend
		array_walk($allow_cid, 'self::fixACL');
		array_walk($allow_gid, 'self::fixACL');
		array_walk($deny_cid, 'self::fixACL');
		array_walk($deny_gid, 'self::fixACL');

		Contact::pruneUnavailable($allow_cid);

		return [
			'allow_cid' => $allow_cid,
			'allow_gid' => $allow_gid,
			'deny_cid' => $deny_cid,
			'deny_gid' => $deny_gid,
		];
	}

	/**
	 * Returns the ACL list of contacts for a given user id
	 *
	 * @param int $user_id
	 * @return array
	 * @throws \Exception
	 */
	public static function getContactListByUserId(int $user_id)
	{
		$acl_contacts = Contact::selectToArray(
			['id', 'name', 'addr', 'micro'],
			['uid' => $user_id, 'pending' => false, 'rel' => [Contact::FOLLOWER, Contact::FRIEND]]
		);
		array_walk($acl_contacts, function (&$value) {
			$value['type'] = 'contact';
		});

		return $acl_contacts;
	}

	/**
	 * Returns the ACL list of groups (including meta-groups) for a given user id
	 *
	 * @param int $user_id
	 * @return array
	 */
	public static function getGroupListByUserId(int $user_id)
	{
		$acl_groups = [
			[
				'id' => Group::FOLLOWERS,
				'name' => L10n::t('Followers'),
				'addr' => '',
				'micro' => 'images/twopeople.png',
				'type' => 'group',
			],
			[
				'id' => Group::MUTUALS,
				'name' => L10n::t('Mutuals'),
				'addr' => '',
				'micro' => 'images/twopeople.png',
				'type' => 'group',
			]
		];
		foreach (Group::getByUserId($user_id) as $group) {
			$acl_groups[] = [
				'id' => $group['id'],
				'name' => $group['name'],
				'addr' => '',
				'micro' => 'images/twopeople.png',
				'type' => 'group',
			];
		}

		return $acl_groups;
	}

	/**
	 * Return the full jot ACL selector HTML
	 *
	 * @param array $user                User array
	 * @param bool  $show_jotnets
	 * @param array $default_permissions Static defaults permission array: ['allow_cid' => '', 'allow_gid' => '', 'deny_cid' => '', 'deny_gid' => '']
	 * @return string
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function getFullSelectorHTML(array $user = null, $show_jotnets = false, array $default_permissions = [])
	{
		// Defaults user permissions
		if (empty($default_permissions)) {
			$default_permissions = self::getDefaultUserPermissions($user);
		}

		$jotnets_fields = [];
		if ($show_jotnets) {
			$mail_enabled = false;
			$pubmail_enabled = false;

			if (function_exists('imap_open') && !Config::get('system', 'imap_disabled')) {
				$mailacct = DBA::selectFirst('mailacct', ['pubmail'], ['`uid` = ? AND `server` != ""', local_user()]);
				if (DBA::isResult($mailacct)) {
					$mail_enabled = true;
					$pubmail_enabled = !empty($mailacct['pubmail']);
				}
			}

			if (empty($default_permissions['hidewall'])) {
				if ($mail_enabled) {
					$jotnets_fields[] = [
						'type' => 'checkbox',
						'field' => [
							'pubmail_enable',
							L10n::t('Post to Email'),
							$pubmail_enabled
						]
					];
				}

				Hook::callAll('jot_networks', $jotnets_fields);
			}
		}

		$tpl = Renderer::getMarkupTemplate('acl_selector.tpl');
		$o = Renderer::replaceMacros($tpl, [
			'$showall' => L10n::t('Visible to everybody'),
			'$show' => L10n::t('show'),
			'$hide' => L10n::t('don\'t show'),
			'$allowcid' => json_encode(($default_permissions['allow_cid'] ?? '') ?: []), // We need arrays for
			'$allowgid' => json_encode(($default_permissions['allow_gid'] ?? '') ?: []), // Javascript since we
			'$denycid'  => json_encode(($default_permissions['deny_cid']  ?? '') ?: []), // call .remove() and
			'$denygid'  => json_encode(($default_permissions['deny_gid']  ?? '') ?: []), // .push() on these values
			'$networks' => $show_jotnets,
			'$emailcc' => L10n::t('CC: email addresses'),
			'$emtitle' => L10n::t('Example: bob@example.com, mary@example.com'),
			'$jotnets_enabled' => empty($default_permissions['hidewall']),
			'$jotnets_summary' => L10n::t('Connectors'),
			'$jotnets_fields' => $jotnets_fields,
			'$jotnets_disabled_label' => L10n::t('Connectors disabled, since "%s" is enabled.', L10n::t('Hide your profile details from unknown viewers?')),
			'$aclModalTitle' => L10n::t('Permissions'),
			'$aclModalDismiss' => L10n::t('Close'),
			'$features' => [
				'aclautomention' => !empty($user['uid']) && Feature::isEnabled($user['uid'], 'aclautomention') ? 'true' : 'false'
			],
		]);

		return $o;
	}
}
