<?php
/**
 * @file mod/notifications.php
 * @brief The notifications module
 */

use Friendica\App;
use Friendica\Content\ContactSelector;
use Friendica\Content\Nav;
use Friendica\Core\L10n;
use Friendica\Core\NotificationsManager;
use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\Database\DBA;

function notifications_post(App $a)
{
	if (!local_user()) {
		goaway(System::baseUrl());
	}

	$request_id = (($a->argc > 1) ? $a->argv[1] : 0);

	if ($request_id === "all") {
		return;
	}

	if ($request_id) {
		$intro = DBA::selectFirst('intro', ['id', 'contact-id', 'fid'], ['id' => $request_id, 'uid' => local_user()]);

		if (DBA::isResult($intro)) {
			$intro_id = $intro['id'];
			$contact_id = $intro['contact-id'];
		} else {
			notice(L10n::t('Invalid request identifier.') . EOL);
			return;
		}

		// If it is a friend suggestion, the contact is not a new friend but an existing friend
		// that should not be deleted.

		$fid = $intro['fid'];

		if ($_POST['submit'] == L10n::t('Discard')) {
			DBA::delete('intro', ['id' => $intro_id]);

			if (!$fid) {
				// The check for blocked and pending is in case the friendship was already approved
				// and we just want to get rid of the now pointless notification
				$condition = ['id' => $contact_id, 'uid' => local_user(),
					'self' => false, 'blocked' => true, 'pending' => true];
				DBA::delete('contact', $condition);
			}
			goaway('notifications/intros');
		}

		if ($_POST['submit'] == L10n::t('Ignore')) {
			DBA::update('intro', ['ignore' => true], ['id' => $intro_id]);
			goaway('notifications/intros');
		}
	}
}

function notifications_content(App $a)
{
	if (!local_user()) {
		notice(L10n::t('Permission denied.') . EOL);
		return;
	}

	$page	=	(x($_REQUEST,'page')		? $_REQUEST['page']		: 1);
	$show	=	(x($_REQUEST,'show')		? $_REQUEST['show']		: 0);

	Nav::setSelected('notifications');

	$json = (($a->argc > 1 && $a->argv[$a->argc - 1] === 'json') ? true : false);

	$nm = new NotificationsManager();

	$o = '';
	// Get the nav tabs for the notification pages
	$tabs = $nm->getTabs();
	$notif_content = [];
	$notif_nocontent = '';

	// Notification results per page
	$perpage = 20;
	$startrec = ($page * $perpage) - $perpage;

	// Get introductions
	if ((($a->argc > 1) && ($a->argv[1] == 'intros')) || (($a->argc == 1))) {
		Nav::setSelected('introductions');
		$notif_header = L10n::t('Notifications');

		$all = (($a->argc > 2) && ($a->argv[2] == 'all'));

		$notifs = $nm->introNotifs($all, $startrec, $perpage);

	// Get the network notifications
	} elseif (($a->argc > 1) && ($a->argv[1] == 'network')) {
		$notif_header = L10n::t('Network Notifications');
		$notifs = $nm->networkNotifs($show, $startrec, $perpage);

	// Get the system notifications
	} elseif (($a->argc > 1) && ($a->argv[1] == 'system')) {
		$notif_header = L10n::t('System Notifications');
		$notifs = $nm->systemNotifs($show, $startrec, $perpage);

	// Get the personal notifications
	} elseif (($a->argc > 1) && ($a->argv[1] == 'personal')) {
		$notif_header = L10n::t('Personal Notifications');
		$notifs = $nm->personalNotifs($show, $startrec, $perpage);

	// Get the home notifications
	} elseif (($a->argc > 1) && ($a->argv[1] == 'home')) {
		$notif_header = L10n::t('Home Notifications');
		$notifs = $nm->homeNotifs($show, $startrec, $perpage);

	}


	// Set the pager
	$a->set_pager_itemspage($perpage);

	// Add additional informations (needed for json output)
	$notifs['items_page'] = $a->pager['itemspage'];
	$notifs['page'] = $a->pager['page'];

	// Json output
	if (intval($json) === 1) {
		System::jsonExit($notifs);
	}

	$notif_tpl = get_markup_template('notifications.tpl');

	// Process the data for template creation
	if ($notifs['ident'] === 'introductions') {
		$sugg = get_markup_template('suggestions.tpl');
		$tpl = get_markup_template("intros.tpl");

		// The link to switch between ignored and normal connection requests
		$notif_show_lnk = [
			'href' => (!$all ? 'notifications/intros/all' : 'notifications/intros' ),
			'text' => (!$all ? L10n::t('Show Ignored Requests') : L10n::t('Hide Ignored Requests'))
		];

		// Loop through all introduction notifications.This creates an array with the output html for each
		// introduction
		foreach ($notifs['notifications'] as $it) {

			// There are two kind of introduction. Contacts suggested by other contacts and normal connection requests.
			// We have to distinguish between these two because they use different data.
			switch ($it['label']) {
				case 'friend_suggestion':
					$notif_content[] = replace_macros($sugg, [
						'$type' => $it['label'],
						'$str_notifytype' => L10n::t('Notification type:'),
						'$notify_type' => $it['notify_type'],
						'$intro_id' => $it['intro_id'],
						'$lbl_madeby' => L10n::t('Suggested by:'),
						'$madeby' => $it['madeby'],
						'$madeby_url' => $it['madeby_url'],
						'$madeby_zrl' => $it['madeby_zrl'],
						'$madeby_addr' => $it['madeby_addr'],
						'$contact_id' => $it['contact_id'],
						'$photo' => $it['photo'],
						'$fullname' => $it['name'],
						'$url' => $it['url'],
						'$zrl' => $it['zrl'],
						'$lbl_url' => L10n::t('Profile URL'),
						'$addr' => $it['addr'],
						'$hidden' => ['hidden', L10n::t('Hide this contact from others'), ($it['hidden'] == 1), ''],

						'$knowyou' => $it['knowyou'],
						'$approve' => L10n::t('Approve'),
						'$note' => $it['note'],
						'$request' => $it['request'],
						'$ignore' => L10n::t('Ignore'),
						'$discard' => L10n::t('Discard'),
					]);
					break;

				// Normal connection requests
				default:
					$friend_selected = (($it['network'] !== Protocol::OSTATUS) ? ' checked="checked" ' : ' disabled ');
					$fan_selected = (($it['network'] === Protocol::OSTATUS) ? ' checked="checked" disabled ' : '');
					$dfrn_tpl = get_markup_template('netfriend.tpl');

					$knowyou   = '';
					$lbl_knowyou = '';
					$dfrn_text = '';
					$helptext = '';
					$helptext2 = '';
					$helptext3 = '';

					if ($it['network'] === Protocol::DFRN || $it['network'] === Protocol::DIASPORA) {
						if ($it['network'] === Protocol::DFRN) {
							$lbl_knowyou = L10n::t('Claims to be known to you: ');
							$knowyou = (($it['knowyou']) ? L10n::t('yes') : L10n::t('no'));
							$helptext = L10n::t('Shall your connection be bidirectional or not?');
							$helptext2 = L10n::t('Accepting %s as a friend allows %s to subscribe to your posts, and you will also receive updates from them in your news feed.', $it['name'], $it['name']);
							$helptext3 = L10n::t('Accepting %s as a subscriber allows them to subscribe to your posts, but you will not receive updates from them in your news feed.', $it['name']);
						} else {
							$knowyou = '';
							$helptext = L10n::t('Shall your connection be bidirectional or not?');
							$helptext2 = L10n::t('Accepting %s as a friend allows %s to subscribe to your posts, and you will also receive updates from them in your news feed.', $it['name'], $it['name']);
							$helptext3 = L10n::t('Accepting %s as a sharer allows them to subscribe to your posts, but you will not receive updates from them in your news feed.', $it['name']);
						}
					}

					$dfrn_text = replace_macros($dfrn_tpl,[
						'$intro_id' => $it['intro_id'],
						'$friend_selected' => $friend_selected,
						'$fan_selected' => $fan_selected,
						'$approve_as1' => $helptext,
						'$approve_as2' => $helptext2,
						'$approve_as3' => $helptext3,
						'$as_friend' => L10n::t('Friend'),
						'$as_fan' => (($it['network'] == Protocol::DIASPORA) ? L10n::t('Sharer') : L10n::t('Subscriber'))
					]);

					$header = $it["name"];

					if ($it["addr"] != "") {
						$header .= " <".$it["addr"].">";
					}

					$header .= " (".ContactSelector::networkToName($it['network'], $it['url']).")";

					if ($it['network'] != Protocol::DIASPORA) {
						$discard = L10n::t('Discard');
					} else {
						$discard = '';
					}

					$notif_content[] = replace_macros($tpl, [
						'$type' => $it['label'],
						'$header' => htmlentities($header),
						'$str_notifytype' => L10n::t('Notification type:'),
						'$notify_type' => $it['notify_type'],
						'$dfrn_text' => $dfrn_text,
						'$dfrn_id' => $it['dfrn_id'],
						'$uid' => $it['uid'],
						'$intro_id' => $it['intro_id'],
						'$contact_id' => $it['contact_id'],
						'$photo' => $it['photo'],
						'$fullname' => $it['name'],
						'$location' => $it['location'],
						'$lbl_location' => L10n::t('Location:'),
						'$about' => $it['about'],
						'$lbl_about' => L10n::t('About:'),
						'$keywords' => $it['keywords'],
						'$lbl_keywords' => L10n::t('Tags:'),
						'$gender' => $it['gender'],
						'$lbl_gender' => L10n::t('Gender:'),
						'$hidden' => ['hidden', L10n::t('Hide this contact from others'), ($it['hidden'] == 1), ''],
						'$url' => $it['url'],
						'$zrl' => $it['zrl'],
						'$lbl_url' => L10n::t('Profile URL'),
						'$addr' => $it['addr'],
						'$lbl_knowyou' => $lbl_knowyou,
						'$lbl_network' => L10n::t('Network:'),
						'$network' => ContactSelector::networkToName($it['network'], $it['url']),
						'$knowyou' => $knowyou,
						'$approve' => L10n::t('Approve'),
						'$note' => $it['note'],
						'$ignore' => L10n::t('Ignore'),
						'$discard' => $discard,

					]);
					break;
			}
		}

		if (count($notifs['notifications']) == 0) {
			info(L10n::t('No introductions.') . EOL);
		}

	// Normal notifications (no introductions)
	} else {
		// The template files we need in different cases for formatting the content
		$tpl_item_like = 'notifications_likes_item.tpl';
		$tpl_item_dislike = 'notifications_dislikes_item.tpl';
		$tpl_item_attend = 'notifications_attend_item.tpl';
		$tpl_item_attendno = 'notifications_attend_item.tpl';
		$tpl_item_attendmaybe = 'notifications_attend_item.tpl';
		$tpl_item_friend = 'notifications_friends_item.tpl';
		$tpl_item_comment = 'notifications_comments_item.tpl';
		$tpl_item_post = 'notifications_posts_item.tpl';
		$tpl_item_notify = 'notify.tpl';

		// Loop trough ever notification This creates an array with the output html for each
		// notification and apply the correct template according to the notificationtype (label).
		foreach ($notifs['notifications'] as $it) {

			// We use the notification label to get the correct template file
			$tpl_var_name = 'tpl_item_'.$it['label'];
			$tpl_notif = get_markup_template($$tpl_var_name);

			$notif_content[] = replace_macros($tpl_notif,[
				'$item_label' => $it['label'],
				'$item_link' => $it['link'],
				'$item_image' => $it['image'],
				'$item_url' => $it['url'],
				'$item_text' => $it['text'],
				'$item_when' => $it['when'],
				'$item_ago' => $it['ago'],
				'$item_seen' => $it['seen'],
			]);
		}

		$notif_show_lnk = [
			'href' => ($show ? 'notifications/'.$notifs['ident'] : 'notifications/'.$notifs['ident'].'?show=all' ),
			'text' => ($show ? L10n::t('Show unread') : L10n::t('Show all')),
		];

		// Output if there aren't any notifications available
		if (count($notifs['notifications']) == 0) {
			$notif_nocontent = L10n::t('No more %s notifications.', $notifs['ident']);
		}
	}

	$o .= replace_macros($notif_tpl, [
		'$notif_header' => $notif_header,
		'$tabs' => $tabs,
		'$notif_content' => $notif_content,
		'$notif_nocontent' => $notif_nocontent,
		'$notif_show_lnk' => $notif_show_lnk,
		'$notif_paginate' => alt_pager($a, count($notif_content))
	]);

	return $o;
}
