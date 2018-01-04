<?php
/**
 * @file include/acl_selectors.php
 */
use Friendica\App;
use Friendica\Content\Feature;
use Friendica\Core\Config;
use Friendica\Database\DBM;
use Friendica\Model\Contact;
use Friendica\Model\GContact;

require_once "include/contact_selectors.php";
require_once "include/contact_widgets.php";
require_once "mod/proxy.php";

/**
 * @package acl_selectors
 */
function group_select($selname,$selclass,$preselected = false,$size = 4) {

	$a = get_app();

	$o = '';

	$o .= "<select name=\"{$selname}[]\" id=\"$selclass\" class=\"$selclass\" multiple=\"multiple\" size=\"$size\" >\r\n";

	$r = q("SELECT `id`, `name` FROM `group` WHERE NOT `deleted` AND `uid` = %d ORDER BY `name` ASC",
		intval(local_user())
	);


	$arr = array('group' => $r, 'entry' => $o);

	// e.g. 'network_pre_group_deny', 'profile_pre_group_allow'

	call_hooks($a->module . '_pre_' . $selname, $arr);

	if (DBM::is_result($r)) {
		foreach ($r as $rr) {
			if ((is_array($preselected)) && in_array($rr['id'], $preselected)) {
				$selected = " selected=\"selected\" ";
			} else {
				$selected = '';
			}

			$trimmed = mb_substr($rr['name'],0,12);

			$o .= "<option value=\"{$rr['id']}\" $selected title=\"{$rr['name']}\" >$trimmed</option>\r\n";
		}

	}
	$o .= "</select>\r\n";

	call_hooks($a->module . '_post_' . $selname, $o);


	return $o;
}

/// @TODO find proper type-hints
function contact_selector($selname, $selclass, $options, $preselected = false)
{
	$a = get_app();

	$mutual = false;
	$networks = null;
	$single = false;
	$exclude = false;
	$size = 4;

	if (is_array($options)) {
		if (x($options, 'size'))
			$size = $options['size'];

		if (x($options, 'mutual_friends')) {
			$mutual = true;
		}
		if (x($options, 'single')) {
			$single = true;
		}
		if (x($options, 'multiple')) {
			$single = false;
		}
		if (x($options, 'exclude')) {
			$exclude = $options['exclude'];
		}

		if (x($options, 'networks')) {
			switch ($options['networks']) {
				case 'DFRN_ONLY':
					$networks = array(NETWORK_DFRN);
					break;
				case 'PRIVATE':
					if (is_array($a->user) && $a->user['prvnets']) {
						$networks = array(NETWORK_DFRN, NETWORK_MAIL, NETWORK_DIASPORA);
					} else {
						$networks = array(NETWORK_DFRN, NETWORK_FACEBOOK, NETWORK_MAIL, NETWORK_DIASPORA);
					}
					break;
				case 'TWO_WAY':
					if (is_array($a->user) && $a->user['prvnets']) {
						$networks = array(NETWORK_DFRN, NETWORK_MAIL, NETWORK_DIASPORA);
					} else {
						$networks = array(NETWORK_DFRN, NETWORK_FACEBOOK, NETWORK_MAIL, NETWORK_DIASPORA, NETWORK_OSTATUS);
					}
					break;
				default: /// @TODO Maybe log this call?
					break;
			}
		}
	}

	$x = array('options' => $options, 'size' => $size, 'single' => $single, 'mutual' => $mutual, 'exclude' => $exclude, 'networks' => $networks);

	call_hooks('contact_select_options', $x);

	$o = '';

	$sql_extra = '';

	if (x($x, 'mutual')) {
		$sql_extra .= sprintf(" AND `rel` = %d ", intval(CONTACT_IS_FRIEND));
	}

	if (x($x, 'exclude')) {
		$sql_extra .= sprintf(" AND `id` != %d ", intval($x['exclude']));
	}

	if (is_array($x['networks']) && count($x['networks'])) {
		/// @TODO rewrite to foreach()
		for ($y = 0; $y < count($x['networks']) ; $y ++) {
			$x['networks'][$y] = "'" . dbesc($x['networks'][$y]) . "'";
		}
		$str_nets = implode(',', $x['networks']);
		$sql_extra .= " AND `network` IN ( $str_nets ) ";
	}

	$tabindex = (x($options, 'tabindex') ? "tabindex=\"" . $options["tabindex"] . "\"" : "");

	if ($x['single']) {
		$o .= "<select name=\"$selname\" id=\"$selclass\" class=\"$selclass\" size=\"" . $x['size'] . "\" $tabindex >\r\n";
	} else {
		$o .= "<select name=\"{$selname}[]\" id=\"$selclass\" class=\"$selclass\" multiple=\"multiple\" size=\"" . $x['size'] . "$\" $tabindex >\r\n";
	}

	$r = q("SELECT `id`, `name`, `url`, `network` FROM `contact`
		WHERE `uid` = %d AND NOT `self` AND NOT `blocked` AND NOT `pending` AND NOT `archive` AND `notify` != ''
		$sql_extra
		ORDER BY `name` ASC ",
		intval(local_user())
	);


	$arr = array('contact' => $r, 'entry' => $o);

	// e.g. 'network_pre_contact_deny', 'profile_pre_contact_allow'

	call_hooks($a->module . '_pre_' . $selname, $arr);

	if (DBM::is_result($r)) {
		foreach ($r as $rr) {
			if ((is_array($preselected)) && in_array($rr['id'], $preselected)) {
				$selected = " selected=\"selected\" ";
			} else {
				$selected = '';
			}

			$trimmed = mb_substr($rr['name'],0,20);

			$o .= "<option value=\"{$rr['id']}\" $selected title=\"{$rr['name']}|{$rr['url']}\" >$trimmed</option>\r\n";
		}

	}

	$o .= "</select>\r\n";

	call_hooks($a->module . '_post_' . $selname, $o);

	return $o;
}



function contact_select($selname, $selclass, $preselected = false, $size = 4, $privmail = false, $celeb = false, $privatenet = false, $tabindex = null) {

	require_once "include/bbcode.php";

	$a = get_app();

	$o = '';

	// When used for private messages, we limit correspondence to mutual DFRN/Friendica friends and the selector
	// to one recipient. By default our selector allows multiple selects amongst all contacts.

	$sql_extra = '';

	if ($privmail || $celeb) {
		$sql_extra .= sprintf(" AND `rel` = %d ", intval(CONTACT_IS_FRIEND));
	}

	if ($privmail) {
		$sql_extra .= sprintf(" AND `network` IN ('%s' , '%s') ",
					NETWORK_DFRN, NETWORK_DIASPORA);
	} elseif ($privatenet) {
		$sql_extra .= sprintf(" AND `network` IN ('%s' , '%s', '%s', '%s') ",
					NETWORK_DFRN, NETWORK_MAIL, NETWORK_FACEBOOK, NETWORK_DIASPORA);
	}

	$tabindex = ($tabindex > 0 ? "tabindex=\"$tabindex\"" : "");

	if ($privmail && $preselected) {
		$sql_extra .= " AND `id` IN (".implode(",", $preselected).")";
		$hidepreselected = ' style="display: none;"';
	} else {
		$hidepreselected = "";
	}

	if ($privmail) {
		$o .= "<select name=\"$selname\" id=\"$selclass\" class=\"$selclass\" size=\"$size\" $tabindex $hidepreselected>\r\n";
	} else {
		$o .= "<select name=\"{$selname}[]\" id=\"$selclass\" class=\"$selclass\" multiple=\"multiple\" size=\"$size\" $tabindex >\r\n";
	}

	$r = q("SELECT `id`, `name`, `url`, `network` FROM `contact`
		WHERE `uid` = %d AND NOT `self` AND NOT `blocked` AND NOT `pending` AND NOT `archive` AND `notify` != ''
		$sql_extra
		ORDER BY `name` ASC ",
		intval(local_user())
	);


	$arr = array('contact' => $r, 'entry' => $o);

	// e.g. 'network_pre_contact_deny', 'profile_pre_contact_allow'

	call_hooks($a->module . '_pre_' . $selname, $arr);

	$receiverlist = array();

	if (DBM::is_result($r)) {
		foreach ($r as $rr) {
			if ((is_array($preselected)) && in_array($rr['id'], $preselected)) {
				$selected = " selected=\"selected\" ";
			} else {
				$selected = '';
			}

			if ($privmail) {
				$trimmed = GetProfileUsername($rr['url'], $rr['name'], false);
			} else {
				$trimmed = mb_substr($rr['name'],0,20);
			}

			$receiverlist[] = $trimmed;

			$o .= "<option value=\"{$rr['id']}\" $selected title=\"{$rr['name']}|{$rr['url']}\" >$trimmed</option>\r\n";
		}

	}

	$o .= "</select>\r\n";

	if ($privmail && $preselected) {
		$o .= implode(", ", $receiverlist);
	}

	call_hooks($a->module . '_post_' . $selname, $o);

	return $o;
}


function fixacl(&$item) {
	$item = intval(str_replace(array('<', '>'), array('', ''), $item));
}

function prune_deadguys($arr) {

	if (! $arr) {
		return $arr;
	}

	$str = dbesc(implode(',', $arr));

	$r = q("SELECT `id` FROM `contact` WHERE `id` IN ( " . $str . ") AND `blocked` = 0 AND `pending` = 0 AND `archive` = 0 ");

	if (DBM::is_result($r)) {
		$ret = array();
		foreach ($r as $rr) {
			$ret[] = intval($rr['id']);
		}
		return $ret;
	}

	return array();
}


function get_acl_permissions($user = null) {
	$allow_cid = $allow_gid = $deny_cid = $deny_gid = false;

	if (is_array($user)) {
		$allow_cid = ((strlen($user['allow_cid']))
			? explode('><', $user['allow_cid']) : array() );
		$allow_gid = ((strlen($user['allow_gid']))
			? explode('><', $user['allow_gid']) : array() );
		$deny_cid  = ((strlen($user['deny_cid']))
			? explode('><', $user['deny_cid']) : array() );
		$deny_gid  = ((strlen($user['deny_gid']))
			? explode('><', $user['deny_gid']) : array() );
		array_walk($allow_cid,'fixacl');
		array_walk($allow_gid,'fixacl');
		array_walk($deny_cid,'fixacl');
		array_walk($deny_gid,'fixacl');
	}

	$allow_cid = prune_deadguys($allow_cid);

	return array(
		'allow_cid' => $allow_cid,
		'allow_gid' => $allow_gid,
		'deny_cid' => $deny_cid,
		'deny_gid' => $deny_gid,
	);
}


function populate_acl($user = null, $show_jotnets = false) {

	$perms = get_acl_permissions($user);

	$jotnets = '';
	if ($show_jotnets) {
		$mail_disabled = ((function_exists('imap_open') && (! Config::get('system','imap_disabled'))) ? 0 : 1);

		$mail_enabled = false;
		$pubmail_enabled = false;

		if (! $mail_disabled) {
			$r = q("SELECT `pubmail` FROM `mailacct` WHERE `uid` = %d AND `server` != '' LIMIT 1",
				intval(local_user())
			);
			if (DBM::is_result($r)) {
				$mail_enabled = true;
				if (intval($r[0]['pubmail'])) {
					$pubmail_enabled = true;
				}
			}
		}

		if (!$user['hidewall']) {
			if ($mail_enabled) {
				$selected = (($pubmail_enabled) ? ' checked="checked" ' : '');
				$jotnets .= '<div class="profile-jot-net"><input type="checkbox" name="pubmail_enable"' . $selected . ' value="1" /> ' . t("Post to Email") . '</div>';
			}

			call_hooks('jot_networks', $jotnets);
		} else {
			$jotnets .= sprintf(t('Connectors disabled, since "%s" is enabled.'),
					    t('Hide your profile details from unknown viewers?'));
		}
	}

	$tpl = get_markup_template("acl_selector.tpl");
	$o = replace_macros($tpl, array(
		'$showall'=> t("Visible to everybody"),
		'$show'	=> t("show"),
		'$hide'	 => t("don't show"),
		'$allowcid' => json_encode($perms['allow_cid']),
		'$allowgid' => json_encode($perms['allow_gid']),
		'$denycid' => json_encode($perms['deny_cid']),
		'$denygid' => json_encode($perms['deny_gid']),
		'$networks' => $show_jotnets,
		'$emailcc' => t('CC: email addresses'),
		'$emtitle' => t('Example: bob@example.com, mary@example.com'),
		'$jotnets' => $jotnets,
		'$aclModalTitle' => t('Permissions'),
		'$aclModalDismiss' => t('Close'),
		'$features' => array(
		'aclautomention' => (Feature::isEnabled($user['uid'], "aclautomention") ? "true" : "false")
		),
	));


	return $o;

}

function acl_lookup(App $a, $out_type = 'json') {

	if (!local_user()) {
		return '';
	}

	$start	=	(x($_REQUEST,'start')		? $_REQUEST['start']		: 0);
	$count	=	(x($_REQUEST,'count')		? $_REQUEST['count']		: 100);
	$search	 =	(x($_REQUEST,'search')		? $_REQUEST['search']		: "");
	$type	=	(x($_REQUEST,'type')		? $_REQUEST['type']		: "");
	$mode	=	(x($_REQUEST,'smode')		? $_REQUEST['smode']		: "");
	$conv_id =	(x($_REQUEST,'conversation')	? $_REQUEST['conversation']	: null);

	// For use with jquery.textcomplete for private mail completion

	if (x($_REQUEST, 'query') && strlen($_REQUEST['query'])) {
		if (! $type) {
			$type = 'm';
		}
		$search = $_REQUEST['query'];
	}

	logger("Searching for ".$search." - type ".$type, LOGGER_DEBUG);

	if ($search != "") {
		$sql_extra = "AND `name` LIKE '%%".dbesc($search)."%%'";
		$sql_extra2 = "AND (`attag` LIKE '%%".dbesc($search)."%%' OR `name` LIKE '%%".dbesc($search)."%%' OR `nick` LIKE '%%".dbesc($search)."%%')";
	} else {
		/// @TODO Avoid these needless else blocks by putting variable-initialization atop of if()
		$sql_extra = $sql_extra2 = "";
	}

	// count groups and contacts
	if ($type == '' || $type == 'g') {
		$r = q("SELECT COUNT(*) AS g FROM `group` WHERE `deleted` = 0 AND `uid` = %d $sql_extra",
			intval(local_user())
		);
		$group_count = (int)$r[0]['g'];
	} else {
		$group_count = 0;
	}

	$sql_extra2 .= " ".unavailable_networks();

	if ($type == '' || $type == 'c') {
		// autocomplete for editor mentions
		$r = q("SELECT COUNT(*) AS c FROM `contact`
				WHERE `uid` = %d AND NOT `self`
				AND NOT `blocked` AND NOT `pending` AND NOT `archive`
				AND `success_update` >= `failure_update`
				AND `notify` != '' $sql_extra2" ,
			intval(local_user())
		);
		$contact_count = (int)$r[0]['c'];
	} elseif ($type == 'f') {
		// autocomplete for editor mentions of forums
		$r = q("SELECT COUNT(*) AS c FROM `contact`
				WHERE `uid` = %d AND NOT `self`
				AND NOT `blocked` AND NOT `pending` AND NOT `archive`
				AND (`forum` OR `prv`)
				AND `success_update` >= `failure_update`
				AND `notify` != '' $sql_extra2" ,
			intval(local_user())
		);
		$contact_count = (int)$r[0]['c'];
	} elseif ($type == 'm') {
		// autocomplete for Private Messages

		$r = q("SELECT COUNT(*) AS c FROM `contact`
				WHERE `uid` = %d AND NOT `self`
				AND NOT `blocked` AND NOT `pending` AND NOT `archive`
				AND `success_update` >= `failure_update`
				AND `network` IN ('%s', '%s') $sql_extra2" ,
			intval(local_user()),
			dbesc(NETWORK_DFRN),
			dbesc(NETWORK_DIASPORA)
		);
		$contact_count = (int)$r[0]['c'];

	} elseif ($type == 'a') {

		// autocomplete for Contacts

		$r = q("SELECT COUNT(*) AS c FROM `contact`
				WHERE `uid` = %d AND NOT `self`
				AND NOT `pending` $sql_extra2" ,
			intval(local_user())
		);
		$contact_count = (int)$r[0]['c'];

	} else {
		$contact_count = 0;
	}


	$tot = $group_count+$contact_count;

	$groups = array();
	$contacts = array();

	if ($type == '' || $type == 'g') {

		/// @todo We should cache this query.
		// This can be done when we can delete cache entries via wildcard
		$r = q("SELECT `group`.`id`, `group`.`name`, GROUP_CONCAT(DISTINCT `group_member`.`contact-id` SEPARATOR ',') AS uids
				FROM `group`
				INNER JOIN `group_member` ON `group_member`.`gid`=`group`.`id`
				WHERE NOT `group`.`deleted` AND `group`.`uid` = %d
					$sql_extra
				GROUP BY `group`.`name`, `group`.`id`
				ORDER BY `group`.`name`
				LIMIT %d,%d",
			intval(local_user()),
			intval($start),
			intval($count)
		);

		foreach ($r as $g) {
//		logger('acl: group: ' . $g['name'] . ' members: ' . $g['uids']);
			$groups[] = array(
				"type"  => "g",
				"photo" => "images/twopeople.png",
				"name"  => htmlentities($g['name']),
				"id"	=> intval($g['id']),
				"uids"  => array_map("intval", explode(",",$g['uids'])),
				"link"  => '',
				"forum" => '0'
			);
		}
		if ((count($groups) > 0) && ($search == "")) {
			$groups[] = array("separator" => true);
		}
	}

	if ($type == '') {

		$r = q("SELECT `id`, `name`, `nick`, `micro`, `network`, `url`, `attag`, `addr`, `forum`, `prv`, (`prv` OR `forum`) AS `frm` FROM `contact`
			WHERE `uid` = %d AND NOT `self` AND NOT `blocked` AND NOT `pending` AND NOT `archive` AND `notify` != ''
			AND `success_update` >= `failure_update` AND NOT (`network` IN ('%s', '%s'))
			$sql_extra2
			ORDER BY `name` ASC ",
			intval(local_user()),
			dbesc(NETWORK_OSTATUS), dbesc(NETWORK_STATUSNET)
		);
	} elseif ($type == 'c') {
		$r = q("SELECT `id`, `name`, `nick`, `micro`, `network`, `url`, `attag`, `addr`, `forum`, `prv` FROM `contact`
			WHERE `uid` = %d AND NOT `self` AND NOT `blocked` AND NOT `pending` AND NOT `archive` AND `notify` != ''
			AND `success_update` >= `failure_update` AND NOT (`network` IN ('%s'))
			$sql_extra2
			ORDER BY `name` ASC ",
			intval(local_user()),
			dbesc(NETWORK_STATUSNET)
		);
	} elseif ($type == 'f') {
		$r = q("SELECT `id`, `name`, `nick`, `micro`, `network`, `url`, `attag`, `addr`, `forum`, `prv` FROM `contact`
			WHERE `uid` = %d AND NOT `self` AND NOT `blocked` AND NOT `pending` AND NOT `archive` AND `notify` != ''
			AND `success_update` >= `failure_update` AND NOT (`network` IN ('%s'))
			AND (`forum` OR `prv`)
			$sql_extra2
			ORDER BY `name` ASC ",
			intval(local_user()),
			dbesc(NETWORK_STATUSNET)
		);
	} elseif ($type == 'm') {
		$r = q("SELECT `id`, `name`, `nick`, `micro`, `network`, `url`, `attag`, `addr` FROM `contact`
			WHERE `uid` = %d AND NOT `self` AND NOT `blocked` AND NOT `pending` AND NOT `archive`
			AND `success_update` >= `failure_update` AND `network` IN ('%s', '%s')
			$sql_extra2
			ORDER BY `name` ASC ",
			intval(local_user()),
			dbesc(NETWORK_DFRN),
			dbesc(NETWORK_DIASPORA)
		);
	} elseif ($type == 'a') {
		$r = q("SELECT `id`, `name`, `nick`, `micro`, `network`, `url`, `attag`, `addr`, `forum`, `prv` FROM `contact`
			WHERE `uid` = %d AND `pending` = 0 AND `success_update` >= `failure_update`
			$sql_extra2
			ORDER BY `name` ASC ",
			intval(local_user())
		);
	} elseif ($type == 'x') {
		// autocomplete for global contact search (e.g. navbar search)
		$r = navbar_complete($a);
		$contacts = array();
		if ($r) {
			foreach ($r as $g) {
				$contacts[] = array(
					'photo'   => proxy_url($g['photo'], false, PROXY_SIZE_MICRO),
					'name'    => $g['name'],
					'nick'    => (x($g['addr']) ? $g['addr'] : $g['url']),
					'network' => $g['network'],
					'link'    => $g['url'],
					'forum'   => (x($g['community']) ? 1 : 0),
				);
			}
		}
		$o = array(
			'start' => $start,
			'count' => $count,
			'items' => $contacts,
		);
		echo json_encode($o);
		killme();
	} else {
		$r = array();
	}


	if (DBM::is_result($r)) {
		$forums = array();
		foreach ($r as $g) {
			$entry = array(
				'type'    => 'c',
				'photo'   => proxy_url($g['micro'], false, PROXY_SIZE_MICRO),
				'name'    => htmlentities($g['name']),
				'id'      => intval($g['id']),
				'network' => $g['network'],
				'link'    => $g['url'],
				'nick'    => htmlentities(($g['attag']) ? $g['attag'] : $g['nick']),
				'addr'    => htmlentities(($g['addr']) ? $g['addr'] : $g['url']),
				'forum'   => ((x($g, 'forum') || x($g, 'prv')) ? 1 : 0),
			);
			if ($entry['forum']) {
				$forums[] = $entry;
			} else {
				$contacts[] = $entry;
			}
		}
		if (count($forums) > 0) {
			if ($search == "") {
				$forums[] = array("separator" => true);
			}
			$contacts = array_merge($forums, $contacts);
		}
	}

	$items = array_merge($groups, $contacts);

	if ($conv_id) {
		/*
		 * if $conv_id is set, get unknown contacts in thread
		 * but first get known contacts url to filter them out
		 */
		$known_contacts = array_map(
			function ($i) {
				return dbesc($i['link']);
			}
		, $contacts);

		$unknown_contacts = array();
		$r = q("SELECT `author-link`
				FROM `item` WHERE `parent` = %d
					AND (`author-name` LIKE '%%%s%%' OR `author-link` LIKE '%%%s%%')
					AND `author-link` NOT IN ('%s')
				GROUP BY `author-link`, `author-avatar`, `author-name`
				ORDER BY `author-name` ASC
				",
				intval($conv_id),
				dbesc($search),
				dbesc($search),
				implode("', '", $known_contacts)
		);
		if (DBM::is_result($r)) {
			foreach ($r as $row) {
				$contact = Contact::getDetailsByURL($row['author-link']);

				if (count($contact) > 0) {
					$unknown_contacts[] = array(
						'type'    => 'c',
						'photo'   => proxy_url($contact['micro'], false, PROXY_SIZE_MICRO),
						'name'    => htmlentities($contact['name']),
						'id'      => intval($contact['cid']),
						'network' => $contact['network'],
						'link'    => $contact['url'],
						'nick'    => htmlentities($contact['nick'] ? : $contact['addr']),
						'addr'    => htmlentities(($contact['addr']) ? $contact['addr'] : $contact['url']),
						'forum'   => $contact['forum']
					);
				}
			}
		}

		$items = array_merge($items, $unknown_contacts);
		$tot += count($unknown_contacts);
	}

	$results = array(
		'tot'      => $tot,
		'start'    => $start,
		'count'    => $count,
		'groups'   => $groups,
		'contacts' => $contacts,
		'items'    => $items,
		'type'     => $type,
		'search'   => $search,
	);

	call_hooks('acl_lookup_end', $results);

	if ($out_type === 'html') {
		$o = array(
			'tot'      => $results['tot'],
			'start'    => $results['start'],
			'count'    => $results['count'],
			'groups'   => $results['groups'],
			'contacts' => $results['contacts'],
		);
		return $o;
	}

	$o = array(
		'tot'   => $results['tot'],
		'start' => $results['start'],
		'count' => $results['count'],
		'items' => $results['items'],
	);

	echo json_encode($o);

	killme();
}
/**
 * @brief Searching for global contacts for autocompletion
 *
 * @param App $a
 * @return array with the search results
 */
function navbar_complete(App $a) {

//	logger('navbar_complete');

	if ((Config::get('system','block_public')) && (! local_user()) && (! remote_user())) {
		return;
	}

	// check if searching in the local global contact table is enabled
	$localsearch = Config::get('system','poco_local_search');

	$search = $prefix.notags(trim($_REQUEST['search']));
	$mode = $_REQUEST['smode'];

	// don't search if search term has less than 2 characters
	if (! $search || mb_strlen($search) < 2) {
		return array();
	}

	if (substr($search,0,1) === '@') {
		$search = substr($search,1);
	}

	if ($localsearch) {
		$x = GContact::searchByName($search, $mode);
		return $x;
	}

	if (! $localsearch) {
		$p = (($a->pager['page'] != 1) ? '&p=' . $a->pager['page'] : '');

		$x = z_fetch_url(get_server() . '/lsearch?f=' . $p .  '&search=' . urlencode($search));
		if ($x['success']) {
			$j = json_decode($x['body'],true);
			if ($j && isset($j['results'])) {
				return $j['results'];
			}
		}
	}

	/// @TODO Not needed here?
	return;
}
