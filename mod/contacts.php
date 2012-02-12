<?php

require_once('include/Contact.php');
require_once('include/socgraph.php');

function contacts_init(&$a) {
	if(! local_user())
		return;

	$contact_id = 0;

	if(($a->argc == 2) && intval($a->argv[1])) {
		$contact_id = intval($a->argv[1]);
		$r = q("SELECT * FROM `contact` WHERE `uid` = %d and `id` = %d LIMIT 1",
			intval(local_user()),
			intval($contact_id)
		);
		if(! count($r)) {
			$contact_id = 0;
		}
	}

	require_once('include/group.php');
	require_once('include/contact_widgets.php');

	if(! x($a->page,'aside'))
		$a->page['aside'] = '';

	if($contact_id) {
			$a->data['contact'] = $r[0];
			$o .= '<div class="vcard">';
			$o .= '<div class="fn">' . $a->data['contact']['name'] . '</div>';
			$o .= '<div id="profile-photo-wrapper"><img class="photo" style="width: 175px; height: 175px;" src="' . $a->data['contact']['photo'] . '" alt="' . $a->data['contact']['name'] . '" /></div>';
			$o .= '</div>';
			$a->page['aside'] .= $o;

	}	
	else
		$a->page['aside'] .= follow_widget();

	$a->page['aside'] .= group_side('contacts','group',false,0,$contact_id);

	$a->page['aside'] .= findpeople_widget();

	$a->page['aside'] .= networks_widget('contacts',$_GET['nets']);
}

function contacts_post(&$a) {
	
	if(! local_user())
		return;

	$contact_id = intval($a->argv[1]);
	if(! $contact_id)
		return;

	$orig_record = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
		intval($contact_id),
		intval(local_user())
	);

	if(! count($orig_record)) {
		notice( t('Could not access contact record.') . EOL);
		goaway($a->get_baseurl() . '/contacts');
		return; // NOTREACHED
	}

	call_hooks('contact_edit_post', $_POST);

	$profile_id = intval($_POST['profile-assign']);
	if($profile_id) {
		$r = q("SELECT `id` FROM `profile` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($profile_id),
			intval(local_user())
		);
		if(! count($r)) {
			notice( t('Could not locate selected profile.') . EOL);
			return;
		}
	}

	$hidden = intval($_POST['hidden']);

	$priority = intval($_POST['poll']);
	if($priority > 5 || $priority < 0)
		$priority = 0;

	$info = escape_tags(trim($_POST['info']));

	$r = q("UPDATE `contact` SET `profile-id` = %d, `priority` = %d , `info` = '%s',
		`hidden` = %d WHERE `id` = %d AND `uid` = %d LIMIT 1",
		intval($profile_id),
		intval($priority),
		dbesc($info),
		intval($hidden),
		intval($contact_id),
		intval(local_user())
	);
	if($r)
		info( t('Contact updated.') . EOL);
	else
		notice( t('Failed to update contact record.') . EOL);
	return;

}



function contacts_content(&$a) {

	$sort_type = 0;
	$o = '';
	nav_set_selected('contacts');


	if(! local_user()) {
		notice( t('Permission denied.') . EOL);
		return;
	}

	if($a->argc == 3) {

		$contact_id = intval($a->argv[1]);
		if(! $contact_id)
			return;

		$cmd = $a->argv[2];

		$orig_record = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d AND `self` = 0 LIMIT 1",
			intval($contact_id),
			intval(local_user())
		);

		if(! count($orig_record)) {
			notice( t('Could not access contact record.') . EOL);
			goaway($a->get_baseurl() . '/contacts');
			return; // NOTREACHED
		}

		if($cmd === 'update') {

			// pull feed and consume it, which should subscribe to the hub.
			proc_run('php',"include/poller.php","$contact_id");
			goaway($a->get_baseurl() . '/contacts/' . $contact_id);
			// NOTREACHED
		}

		if($cmd === 'block') {
			$blocked = (($orig_record[0]['blocked']) ? 0 : 1);
			$r = q("UPDATE `contact` SET `blocked` = %d WHERE `id` = %d AND `uid` = %d LIMIT 1",
				intval($blocked),
				intval($contact_id),
				intval(local_user())
			);
			if($r) {
				//notice( t('Contact has been ') . (($blocked) ? t('blocked') : t('unblocked')) . EOL );
				info( (($blocked) ? t('Contact has been blocked') : t('Contact has been unblocked')) . EOL );
			}
			goaway($a->get_baseurl() . '/contacts/' . $contact_id);
			return; // NOTREACHED
		}

		if($cmd === 'ignore') {
			$readonly = (($orig_record[0]['readonly']) ? 0 : 1);
			$r = q("UPDATE `contact` SET `readonly` = %d WHERE `id` = %d AND `uid` = %d LIMIT 1",
				intval($readonly),
				intval($contact_id),
				intval(local_user())
			);
			if($r) {
				info( (($readonly) ? t('Contact has been ignored') : t('Contact has been unignored')) . EOL );
			}
			goaway($a->get_baseurl() . '/contacts/' . $contact_id);
			return; // NOTREACHED
		}

		if($cmd === 'drop') {

			// create an unfollow slap

			if($orig_record[0]['network'] === NETWORK_OSTATUS) {
				$tpl = get_markup_template('follow_slap.tpl');
				$slap = replace_macros($tpl, array(
					'$name' => $a->user['username'],
					'$profile_page' => $a->get_baseurl() . '/profile/' . $a->user['nickname'],
					'$photo' => $a->contact['photo'],
					'$thumb' => $a->contact['thumb'],
					'$published' => datetime_convert('UTC','UTC', 'now', ATOM_TIME),
					'$item_id' => 'urn:X-dfrn:' . $a->get_hostname() . ':unfollow:' . random_string(),
					'$title' => '',
					'$type' => 'text',
					'$content' => t('stopped following'),
					'$nick' => $a->user['nickname'],
					'$verb' => 'http://ostatus.org/schema/1.0/unfollow', // ACTIVITY_UNFOLLOW,
					'$ostat_follow' => '' // '<as:verb>http://ostatus.org/schema/1.0/unfollow</as:verb>' . "\r\n"
				));

				if((x($orig_record[0],'notify')) && (strlen($orig_record[0]['notify']))) {
					require_once('include/salmon.php');
					slapper($a->user,$orig_record[0]['notify'],$slap);
				}
			}
			elseif($orig_record[0]['network'] === NETWORK_DIASPORA) {
				require_once('include/diaspora.php');
				diaspora_unshare($a->user,$orig_record[0]);
			}
			elseif($orig_record[0]['network'] === NETWORK_DFRN) {
				require_once('include/items.php');
				dfrn_deliver($a->user,$orig_record[0],'placeholder', 1);
			}

			contact_remove($orig_record[0]['id']);
			info( t('Contact has been removed.') . EOL );
			if(x($_SESSION,'return_url'))
				goaway($a->get_baseurl() . '/' . $_SESSION['return_url']);
			else
				goaway($a->get_baseurl() . '/contacts');
			return; // NOTREACHED
		}
	}

	if((x($a->data,'contact')) && (is_array($a->data['contact']))) {

		$contact_id = $a->data['contact']['id'];
		$contact = $a->data['contact'];

		$tpl = get_markup_template('contact_head.tpl');
		$a->page['htmlhead'] .= replace_macros($tpl, array('$baseurl' => $a->get_baseurl()));

		require_once('include/contact_selectors.php');

		$tpl = get_markup_template("contact_edit.tpl");

		switch($contact['rel']) {
			case CONTACT_IS_FRIEND:
				$dir_icon = 'images/lrarrow.gif';
				$relation_text = t('You are mutual friends with %s');
				break;
			case CONTACT_IS_FOLLOWER;
				$dir_icon = 'images/larrow.gif';
				$relation_text = t('You are sharing with %s');
				break;
	
			case CONTACT_IS_SHARING;
				$dir_icon = 'images/rarrow.gif';
				$relation_text = t('%s is sharing with you');
				break;
			default:
				break;
		}

		$relation_text = sprintf($relation_text,$contact['name']);

		if(($contact['network'] === NETWORK_DFRN) && ($contact['rel'])) {
			$url = "redir/{$contact['id']}";
			$sparkle = ' class="sparkle" ';
		}
		else { 
			$url = $contact['url'];
			$sparkle = '';
		}

		$insecure = t('Private communications are not available for this contact.');

		$last_update = (($contact['last-update'] == '0000-00-00 00:00:00') 
				? t('Never') 
				: datetime_convert('UTC',date_default_timezone_get(),$contact['last-update'],'D, j M Y, g:i A'));

		if($contact['last-update'] !== '0000-00-00 00:00:00')
			$last_update .= ' ' . (($contact['last-update'] == $contact['success_update']) ? t("\x28Update was successful\x29") : t("\x28Update was not successful\x29"));

		$lblsuggest = (($contact['network'] === NETWORK_DFRN) ? t('Suggest friends') : '');

		$poll_enabled = (($contact['network'] !== NETWORK_DIASPORA) ? true : false);

		$nettype = sprintf( t('Network type: %s'),network_to_name($contact['network']));

		$common = count_common_friends(local_user(),$contact['id']);
		$common_text = (($common) ? sprintf( tt('%d contact in common','%d contacts in common', $common),$common) : '');

		$polling = (($contact['network'] === NETWORK_MAIL | $contact['network'] === NETWORK_FEED) ? 'polling' : ''); 

		$x = count_all_friends(local_user(), $contact['id']);
		$all_friends = (($x) ? t('View all contacts') : '');

		// tabs
		$tabs = array(
			array(
				'label' => (($contact['blocked']) ? t('Unblock') : t('Block') ),
				'url'   => $a->get_baseurl() . '/contacts/' . $contact_id . '/block',
				'sel'   => '',
			),
			array(
				'label' => (($contact['readonly']) ? t('Unignore') : t('Ignore') ),
				'url'   => $a->get_baseurl() . '/contacts/' . $contact_id . '/ignore',
				'sel'   => '',
			),
			array(
				'label' => t('Repair'),
				'url'   => $a->get_baseurl() . '/crepair/' . $contact_id,
				'sel'   => '',
			)
		);
		$tab_tpl = get_markup_template('common_tabs.tpl');
		$tab_str = replace_macros($tab_tpl, array('$tabs' => $tabs));


		$o .= replace_macros($tpl,array(
			'$header' => t('Contact Editor'),
			'$tab_str' => $tab_str,
			'$submit' => t('Submit'),
			'$lbl_vis1' => t('Profile Visibility'),
			'$lbl_vis2' => sprintf( t('Please choose the profile you would like to display to %s when viewing your profile securely.'), $contact['name']),
			'$lbl_info1' => t('Contact Information / Notes'),
			'$infedit' => t('Edit contact notes'),
			'$common_text' => $common_text,
			'$common_link' => $a->get_baseurl() . '/common/' . $contact['id'],
			'$all_friends' => $all_friends,
			'$relation_text' => $relation_text,
			'$visit' => sprintf( t('Visit %s\'s profile [%s]'),$contact['name'],$contact['url']),
			'$blockunblock' => t('Block/Unblock contact'),
			'$ignorecont' => t('Ignore contact'),
			'$lblcrepair' => t("Repair URL settings"),
			'$lblrecent' => t('View conversations'),
			'$lblsuggest' => $lblsuggest,
			'$delete' => t('Delete contact'),
			'$nettype' => $nettype,
			'$poll_interval' => contact_poll_interval($contact['priority'],(! $poll_enabled)),
			'$poll_enabled' => $poll_enabled,
			'$lastupdtext' => t('Last update:'),
			'$updpub' => t('Update public posts'),
			'$last_update' => $last_update,
			'$udnow' => t('Update now'),
			'$profile_select' => contact_profile_assign($contact['profile-id'],(($contact['network'] !== NETWORK_DFRN) ? true : false)),
			'$contact_id' => $contact['id'],
			'$block_text' => (($contact['blocked']) ? t('Unblock') : t('Block') ),
			'$ignore_text' => (($contact['readonly']) ? t('Unignore') : t('Ignore') ),
			'$insecure' => (($contact['network'] !== NETWORK_DFRN && $contact['network'] !== NETWORK_MAIL && $contact['network'] !== NETWORK_FACEBOOK && $contact['network'] !== NETWORK_DIASPORA) ? $insecure : ''),
			'$info' => $contact['info'],
			'$blocked' => (($contact['blocked']) ? t('Currently blocked') : ''),
			'$ignored' => (($contact['readonly']) ? t('Currently ignored') : ''),
			'$hidden' => array('hidden', t('Hide this contact from others'), ($contact['hidden'] == 1), t('Replies/likes to your public posts <strong>may</strong> still be visible')),
			'$photo' => $contact['photo'],
			'$name' => $contact['name'],
			'$dir_icon' => $dir_icon,
			'$alt_text' => $alt_text,
			'$sparkle' => $sparkle,
			'$url' => $url

		));

		$arr = array('contact' => $contact,'output' => $o);

		call_hooks('contact_edit', $arr);

		return $arr['output'];

	}

	$blocked = false;

	$_SESSION['return_url'] = $a->query_string;

	if(($a->argc == 2) && ($a->argv[1] === 'all'))
		$sql_extra = '';
	else {
		if(($a->argc == 2) && ($a->argv[1] === 'blocked')) {
			$sql_extra = " AND `blocked` = 1 ";
			$blocked = true;
		}
		else
			$sql_extra = " AND `blocked` = 0 ";
	}
	$search = ((x($_GET,'search')) ? notags(trim($_GET['search'])) : '');
	$nets = ((x($_GET,'nets')) ? notags(trim($_GET['nets'])) : '');

	$tpl = get_markup_template("contacts-top.tpl");
	$o .= replace_macros($tpl,array(
		'$header' => t('Contacts') . (($nets) ? ' - ' . network_to_name($nets) : ''),
		'$hide_url' => (($blocked) ? 'contacts' : 'contacts/blocked'),
		'$hide_text' => (($blocked) ? t('Show Unblocked Contacts') : t('Show Blocked Contacts')),
		'$all_url' => 'contacts/all',
		'$all_text' => t('Show All Contacts'),
		'$search' => $search,
		'$desc' => t('Search your contacts'),
		'$finding' => (strlen($search) ? '<h4>' . t('Finding: ') . "'" . $search . "'" . '</h4>' : ""),
		'$submit' => t('Find'),
		'$cmd' => $a->cmd


	)); 

	if($search)
		$search = dbesc($search.'*');
	$sql_extra .= ((strlen($search)) ? " AND MATCH `name` AGAINST ('$search' IN BOOLEAN MODE) " : "");

	if($nets)
		$sql_extra .= sprintf(" AND network = '%s' ", dbesc($nets));
 
	$sql_extra2 = ((($sort_type > 0) && ($sort_type <= CONTACT_IS_FRIEND)) ? sprintf(" AND `rel` = %d ",intval($sort_type)) : ''); 

	
	$r = q("SELECT COUNT(*) AS `total` FROM `contact` 
		WHERE `uid` = %d AND `pending` = 0 $sql_extra $sql_extra2 ",
		intval($_SESSION['uid']));
	if(count($r))
		$a->set_pager_total($r[0]['total']);

	$r = q("SELECT * FROM `contact` WHERE `uid` = %d AND `pending` = 0 $sql_extra $sql_extra2 ORDER BY `name` ASC LIMIT %d , %d ",
		intval($_SESSION['uid']),
		intval($a->pager['start']),
		intval($a->pager['itemspage'])
	);

	if(count($r)) {

		$tpl = get_markup_template("contact_template.tpl");

		foreach($r as $rr) {
			if($rr['self'])
				continue;

			switch($rr['rel']) {
				case CONTACT_IS_FRIEND:
					$dir_icon = 'images/lrarrow.gif';
					$alt_text = t('Mutual Friendship');
					break;
				case  CONTACT_IS_FOLLOWER;
					$dir_icon = 'images/larrow.gif';
					$alt_text = t('is a fan of yours');
					break;
				case CONTACT_IS_SHARING;
					$dir_icon = 'images/rarrow.gif';
					$alt_text = t('you are a fan of');
					break;
				default:
					break;
			}
			if(($rr['network'] === 'dfrn') && ($rr['rel'])) {
				$url = "redir/{$rr['id']}";
				$sparkle = ' class="sparkle" ';
			}
			else { 
				$url = $rr['url'];
				$sparkle = '';
			}


			$o .= replace_macros($tpl, array(
				'$img_hover' => sprintf( t('Visit %s\'s profile [%s]'),$rr['name'],$rr['url']),
				'$edit_hover' => t('Edit contact'),
				'$contact_photo_menu' => contact_photo_menu($rr),
				'$id' => $rr['id'],
				'$alt_text' => $alt_text,
				'$dir_icon' => $dir_icon,
				'$thumb' => $rr['thumb'], 
				'$name' => $rr['name'],
				'$username' => $rr['name'],
				'$sparkle' => $sparkle,
				'$url' => $url
			));
		}

		$o .= '<div id="contact-edit-end"></div>';

	}
	$o .= paginate($a);
	return $o;
}
