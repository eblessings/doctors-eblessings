<?php
/**
 * @file include/enotify.php
 */

use Friendica\Content\Text\BBCode;
use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\Renderer;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Item;
use Friendica\Model\ItemContent;
use Friendica\Model\Notify;
use Friendica\Model\User;
use Friendica\Model\UserItem;
use Friendica\Protocol\Activity;

/**
 * Creates a notification entry and possibly sends a mail
 *
 * @param array $params Array with the elements:
 *                      uid, item, parent, type, otype, verb, event,
 *                      link, subject, body, to_name, to_email, source_name,
 *                      source_link, activity, preamble, notify_flags,
 *                      language, show_in_notification_page
 * @return bool
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function notification($params)
{
	// Temporary logging for finding the origin
	if (!isset($params['uid'])) {
		Logger::notice('Missing parameters "uid".', ['params' => $params, 'callstack' => System::callstack()]);
	}

	// Ensure that the important fields are set at any time
	$fields = ['notify-flags', 'language', 'username', 'email'];
	$user = DBA::selectFirst('user', $fields, ['uid' => $params['uid']]);

	if (!DBA::isResult($user)) {
		Logger::error('Unknown user', ['uid' =>  $params['uid']]);
		return false;
	}

	$params['notify_flags'] = ($params['notify_flags'] ?? '') ?: $user['notify-flags'];
	$params['language']     = ($params['language']     ?? '') ?: $user['language'];
	$params['to_name']      = ($params['to_name']      ?? '') ?: $user['username'];
	$params['to_email']     = ($params['to_email']     ?? '') ?: $user['email'];

	// from here on everything is in the recipients language
	$l10n = DI::l10n()->withLang($params['language']);

	$siteurl = DI::baseUrl()->get(true);
	$sitename = DI::config()->get('config', 'sitename');

	$hostname = DI::baseUrl()->getHostname();
	if (strpos($hostname, ':')) {
		$hostname = substr($hostname, 0, strpos($hostname, ':'));
	}

	$user = User::getById($params['uid'], ['nickname', 'page-flags']);

	// There is no need to create notifications for forum accounts
	if (!DBA::isResult($user) || in_array($user["page-flags"], [User::PAGE_FLAGS_COMMUNITY, User::PAGE_FLAGS_PRVGROUP])) {
		return false;
	}
	$nickname = $user["nickname"];

	// with $params['show_in_notification_page'] == false, the notification isn't inserted into
	// the database, and an email is sent if applicable.
	// default, if not specified: true
	$show_in_notification_page = isset($params['show_in_notification_page']) ? $params['show_in_notification_page'] : true;

	$additional_mail_header = "X-Friendica-Account: <".$nickname."@".$hostname.">\n";

	if (array_key_exists('item', $params)) {
		$title = $params['item']['title'];
		$body = $params['item']['body'];
	} else {
		$title = $body = '';
	}

	if (isset($params['item']['id'])) {
		$item_id = $params['item']['id'];
	} else {
		$item_id = 0;
	}

	if (isset($params['parent'])) {
		$parent_id = $params['parent'];
	} else {
		$parent_id = 0;
	}

	$epreamble = '';
	$preamble  = '';
	$subject   = '';
	$sitelink  = '';
	$tsitelink = '';
	$hsitelink = '';
	$itemlink  = '';

	if ($params['type'] == Notify\Type::MAIL) {
		$itemlink = $siteurl.'/message/'.$params['item']['id'];
		$params["link"] = $itemlink;

		$subject = $l10n->t('[Friendica:Notify] New mail received at %s', $sitename);

		$preamble = $l10n->t('%1$s sent you a new private message at %2$s.', $params['source_name'], $sitename);
		$epreamble = $l10n->t('%1$s sent you %2$s.', '[url='.$params['source_link'].']'.$params['source_name'].'[/url]', '[url=' . $itemlink . ']' . $l10n->t('a private message').'[/url]');

		$sitelink = $l10n->t('Please visit %s to view and/or reply to your private messages.');
		$tsitelink = sprintf($sitelink, $siteurl.'/message/'.$params['item']['id']);
		$hsitelink = sprintf($sitelink, '<a href="'.$siteurl.'/message/'.$params['item']['id'].'">'.$sitename.'</a>');
	}

	if ($params['type'] == Notify\Type::COMMENT || $params['type'] == NOTIFY_TAGSELF) {
		$thread = Item::selectFirstThreadForUser($params['uid'], ['ignored'], ['iid' => $parent_id, 'deleted' => false]);
		if (DBA::isResult($thread) && $thread['ignored']) {
			Logger::log('Thread ' . $parent_id . ' will be ignored', Logger::DEBUG);
			return false;
		}

		// Check to see if there was already a tag notify or comment notify for this post.
		// If so don't create a second notification
		/// @todo In the future we should store the notification with the highest "value" and replace notifications
		$condition = ['type' => [NOTIFY_TAGSELF, Notify\Type::COMMENT, NOTIFY_SHARE],
			'link' => $params['link'], 'uid' => $params['uid']];
		if (DBA::exists('notify', $condition)) {
			return false;
		}

		// if it's a post figure out who's post it is.
		$item = null;
		if ($params['otype'] === Notify\ObjectType::ITEM && $parent_id) {
			$item = Item::selectFirstForUser($params['uid'], Item::ITEM_FIELDLIST, ['id' => $parent_id, 'deleted' => false]);
		}

		if (empty($item)) {
			return false;
		}

		$item_post_type = Item::postType($item);

		$content = ItemContent::getPlaintextPost($item, 70);
		if (!empty($content['text'])) {
			$title = '"' . trim(str_replace("\n", " ", $content['text'])) . '"';
		} else {
			$title = '';
		}

		// First go for the general message

		// "George Bull's post"
		if ($params['activity']['origin_comment']) {
			$message = '%1$s replied to you on %2$s\'s %3$s %4$s';
		} elseif ($params['activity']['explicit_tagged']) {
			$message = '%1$s tagged you on %2$s\'s %3$s %4$s';
		} else {
			$message = '%1$s commented on %2$s\'s %3$s %4$s';
		}

		$dest_str = $l10n->t($message, $params['source_name'], $item['author-name'], $item_post_type, $title);

		// Then look for the special cases

		// "your post"
		if ($params['activity']['origin_thread']) {
			if ($params['activity']['origin_comment']) {
				$message = '%1$s replied to you on your %2$s %3$s';
			} elseif ($params['activity']['explicit_tagged']) {
				$message = '%1$s tagged you on your %2$s %3$s';
			} else {
				$message = '%1$s commented on your %2$s %3$s';
			}

			$dest_str = $l10n->t($message, $params['source_name'], $item_post_type, $title);
		// "their post"
		} elseif ($item['author-link'] == $params['source_link']) {
			if ($params['activity']['origin_comment']) {
				$message = '%1$s replied to you on their %2$s %3$s';
			} elseif ($params['activity']['explicit_tagged']) {
				$message = '%1$s tagged you on their %2$s %3$s';
			} else {
				$message = '%1$s commented on their %2$s %3$s';
			}

			$dest_str = $l10n->t($message, $params['source_name'], $item_post_type, $title);
		}

		// Some mail software relies on subject field for threading.
		// So, we cannot have different subjects for notifications of the same thread.
		// Before this we have the name of the replier on the subject rendering
		// different subjects for messages on the same thread.
		if ($params['activity']['explicit_tagged']) {
			$subject = $l10n->t('[Friendica:Notify] %s tagged you', $params['source_name']);

			$preamble = $l10n->t('%1$s tagged you at %2$s', $params['source_name'], $sitename);
		} else {
			$subject = $l10n->t('[Friendica:Notify] Comment to conversation #%1$d by %2$s', $parent_id, $params['source_name']);

			$preamble = $l10n->t('%s commented on an item/conversation you have been following.', $params['source_name']);
		}

		$epreamble = $dest_str;

		$sitelink = $l10n->t('Please visit %s to view and/or reply to the conversation.');
		$tsitelink = sprintf($sitelink, $siteurl);
		$hsitelink = sprintf($sitelink, '<a href="' . $siteurl . '">' . $sitename . '</a>');
		$itemlink =  $params['link'];
	}

	if ($params['type'] == Notify\Type::WALL) {
		$subject = $l10n->t('[Friendica:Notify] %s posted to your profile wall', $params['source_name']);

		$preamble = $l10n->t('%1$s posted to your profile wall at %2$s', $params['source_name'], $sitename);
		$epreamble = $l10n->t('%1$s posted to [url=%2$s]your wall[/url]',
			'[url='.$params['source_link'].']'.$params['source_name'].'[/url]',
			$params['link']
		);

		$sitelink = $l10n->t('Please visit %s to view and/or reply to the conversation.');
		$tsitelink = sprintf($sitelink, $siteurl);
		$hsitelink = sprintf($sitelink, '<a href="'.$siteurl.'">'.$sitename.'</a>');
		$itemlink =  $params['link'];
	}

	if ($params['type'] == NOTIFY_SHARE) {
		$subject = $l10n->t('[Friendica:Notify] %s shared a new post', $params['source_name']);

		$preamble = $l10n->t('%1$s shared a new post at %2$s', $params['source_name'], $sitename);
		$epreamble = $l10n->t('%1$s [url=%2$s]shared a post[/url].',
			'[url='.$params['source_link'].']'.$params['source_name'].'[/url]',
			$params['link']
		);

		$sitelink = $l10n->t('Please visit %s to view and/or reply to the conversation.');
		$tsitelink = sprintf($sitelink, $siteurl);
		$hsitelink = sprintf($sitelink, '<a href="'.$siteurl.'">'.$sitename.'</a>');
		$itemlink =  $params['link'];
	}

	if ($params['type'] == NOTIFY_POKE) {
		$subject = $l10n->t('[Friendica:Notify] %1$s poked you', $params['source_name']);

		$preamble = $l10n->t('%1$s poked you at %2$s', $params['source_name'], $sitename);
		$epreamble = $l10n->t('%1$s [url=%2$s]poked you[/url].',
			'[url='.$params['source_link'].']'.$params['source_name'].'[/url]',
			$params['link']
		);

		$subject = str_replace('poked', $l10n->t($params['activity']), $subject);
		$preamble = str_replace('poked', $l10n->t($params['activity']), $preamble);
		$epreamble = str_replace('poked', $l10n->t($params['activity']), $epreamble);

		$sitelink = $l10n->t('Please visit %s to view and/or reply to the conversation.');
		$tsitelink = sprintf($sitelink, $siteurl);
		$hsitelink = sprintf($sitelink, '<a href="'.$siteurl.'">'.$sitename.'</a>');
		$itemlink =  $params['link'];
	}

	if ($params['type'] == NOTIFY_TAGSHARE) {
		$itemlink =  $params['link'];
		$subject = $l10n->t('[Friendica:Notify] %s tagged your post', $params['source_name']);

		$preamble = $l10n->t('%1$s tagged your post at %2$s', $params['source_name'], $sitename);
		$epreamble = $l10n->t('%1$s tagged [url=%2$s]your post[/url]',
			'[url='.$params['source_link'].']'.$params['source_name'].'[/url]',
			$itemlink
		);

		$sitelink = $l10n->t('Please visit %s to view and/or reply to the conversation.');
		$tsitelink = sprintf($sitelink, $siteurl);
		$hsitelink = sprintf($sitelink, '<a href="'.$siteurl.'">'.$sitename.'</a>');
	}

	if ($params['type'] == Notify\Type::INTRO) {
		$itemlink = $params['link'];
		$subject = $l10n->t('[Friendica:Notify] Introduction received');

		$preamble = $l10n->t('You\'ve received an introduction from \'%1$s\' at %2$s', $params['source_name'], $sitename);
		$epreamble = $l10n->t('You\'ve received [url=%1$s]an introduction[/url] from %2$s.',
			$itemlink,
			'[url='.$params['source_link'].']'.$params['source_name'].'[/url]'
		);

		$body = $l10n->t('You may visit their profile at %s', $params['source_link']);

		$sitelink = $l10n->t('Please visit %s to approve or reject the introduction.');
		$tsitelink = sprintf($sitelink, $siteurl);
		$hsitelink = sprintf($sitelink, '<a href="'.$siteurl.'">'.$sitename.'</a>');

		switch ($params['verb']) {
			case Activity::FRIEND:
				// someone started to share with user (mostly OStatus)
				$subject = $l10n->t('[Friendica:Notify] A new person is sharing with you');

				$preamble = $l10n->t('%1$s is sharing with you at %2$s', $params['source_name'], $sitename);
				$epreamble = $l10n->t('%1$s is sharing with you at %2$s',
					'[url='.$params['source_link'].']'.$params['source_name'].'[/url]',
					$sitename
				);
				break;
			case Activity::FOLLOW:
				// someone started to follow the user (mostly OStatus)
				$subject = $l10n->t('[Friendica:Notify] You have a new follower');

				$preamble = $l10n->t('You have a new follower at %2$s : %1$s', $params['source_name'], $sitename);
				$epreamble = $l10n->t('You have a new follower at %2$s : %1$s',
					'[url='.$params['source_link'].']'.$params['source_name'].'[/url]',
					$sitename
				);
				break;
			default:
				// ACTIVITY_REQ_FRIEND is default activity for notifications
				break;
		}
	}

	if ($params['type'] == NOTIFY_SUGGEST) {
		$itemlink =  $params['link'];
		$subject = $l10n->t('[Friendica:Notify] Friend suggestion received');

		$preamble = $l10n->t('You\'ve received a friend suggestion from \'%1$s\' at %2$s', $params['source_name'], $sitename);
		$epreamble = $l10n->t('You\'ve received [url=%1$s]a friend suggestion[/url] for %2$s from %3$s.',
			$itemlink,
			'[url='.$params['item']['url'].']'.$params['item']['name'].'[/url]',
			'[url='.$params['source_link'].']'.$params['source_name'].'[/url]'
		);

		$body = $l10n->t('Name:').' '.$params['item']['name']."\n";
		$body .= $l10n->t('Photo:').' '.$params['item']['photo']."\n";
		$body .= $l10n->t('You may visit their profile at %s', $params['item']['url']);

		$sitelink = $l10n->t('Please visit %s to approve or reject the suggestion.');
		$tsitelink = sprintf($sitelink, $siteurl);
		$hsitelink = sprintf($sitelink, '<a href="'.$siteurl.'">'.$sitename.'</a>');
	}

	if ($params['type'] == Notify\Type::CONFIRM) {
		if ($params['verb'] == Activity::FRIEND) { // mutual connection
			$itemlink =  $params['link'];
			$subject = $l10n->t('[Friendica:Notify] Connection accepted');

			$preamble = $l10n->t('\'%1$s\' has accepted your connection request at %2$s', $params['source_name'], $sitename);
			$epreamble = $l10n->t('%2$s has accepted your [url=%1$s]connection request[/url].',
				$itemlink,
				'[url='.$params['source_link'].']'.$params['source_name'].'[/url]'
			);

			$body =  $l10n->t('You are now mutual friends and may exchange status updates, photos, and email without restriction.');

			$sitelink = $l10n->t('Please visit %s if you wish to make any changes to this relationship.');
			$tsitelink = sprintf($sitelink, $siteurl);
			$hsitelink = sprintf($sitelink, '<a href="'.$siteurl.'">'.$sitename.'</a>');
		} else { // ACTIVITY_FOLLOW
			$itemlink =  $params['link'];
			$subject = $l10n->t('[Friendica:Notify] Connection accepted');

			$preamble = $l10n->t('\'%1$s\' has accepted your connection request at %2$s', $params['source_name'], $sitename);
			$epreamble = $l10n->t('%2$s has accepted your [url=%1$s]connection request[/url].',
				$itemlink,
				'[url='.$params['source_link'].']'.$params['source_name'].'[/url]'
			);

			$body =  $l10n->t('\'%1$s\' has chosen to accept you a fan, which restricts some forms of communication - such as private messaging and some profile interactions. If this is a celebrity or community page, these settings were applied automatically.', $params['source_name']);
			$body .= "\n\n";
			$body .= $l10n->t('\'%1$s\' may choose to extend this into a two-way or more permissive relationship in the future.', $params['source_name']);

			$sitelink = $l10n->t('Please visit %s  if you wish to make any changes to this relationship.');
			$tsitelink = sprintf($sitelink, $siteurl);
			$hsitelink = sprintf($sitelink, '<a href="'.$siteurl.'">'.$sitename.'</a>');
		}
	}

	if ($params['type'] == NOTIFY_SYSTEM) {
		switch($params['event']) {
			case "SYSTEM_REGISTER_REQUEST":
				$itemlink =  $params['link'];
				$subject = $l10n->t('[Friendica System Notify]') . ' ' . $l10n->t('registration request');

				$preamble = $l10n->t('You\'ve received a registration request from \'%1$s\' at %2$s', $params['source_name'], $sitename);
				$epreamble = $l10n->t('You\'ve received a [url=%1$s]registration request[/url] from %2$s.',
					$itemlink,
					'[url='.$params['source_link'].']'.$params['source_name'].'[/url]'
				);

				$body = $l10n->t("Full Name:	%s\nSite Location:	%s\nLogin Name:	%s (%s)",
					$params['source_name'],
					$siteurl, $params['source_mail'],
					$params['source_nick']
				);

				$sitelink = $l10n->t('Please visit %s to approve or reject the request.');
				$tsitelink = sprintf($sitelink, $params['link']);
				$hsitelink = sprintf($sitelink, '<a href="'.$params['link'].'">'.$sitename.'</a><br><br>');
				break;
			case "SYSTEM_DB_UPDATE_FAIL":
				break;
		}
	}

	$subject .= " (".$nickname."@".$hostname.")";

	$h = [
		'params'    => $params,
		'subject'   => $subject,
		'preamble'  => $preamble,
		'epreamble' => $epreamble,
		'body'      => $body,
		'sitelink'  => $sitelink,
		'tsitelink' => $tsitelink,
		'hsitelink' => $hsitelink,
		'itemlink'  => $itemlink
	];

	Hook::callAll('enotify', $h);

	$subject   = $h['subject'];

	$preamble  = $h['preamble'];
	$epreamble = $h['epreamble'];

	$body      = $h['body'];

	$tsitelink = $h['tsitelink'];
	$hsitelink = $h['hsitelink'];
	$itemlink  = $h['itemlink'];

	$notify_id = 0;

	if ($show_in_notification_page) {
		$notification = DI::notify()->insert([
			'name'       => $params['source_name'] ?? '',
			'name_cache' => strip_tags(BBCode::convert($params['source_name'] ?? '')),
			'url'        => $params['source_link'] ?? '',
			'photo'      => $params['source_photo'] ?? '',
			'link'       => $itemlink ?? '',
			'uid'        => $params['uid'] ?? 0,
			'iid'        => $item_id ?? 0,
			'parent'     => $parent_id ?? 0,
			'type'       => $params['type'] ?? '',
			'verb'       => $params['verb'] ?? '',
			'otype'      => $params['otype'] ?? '',
		]);

		$notification->msg = Renderer::replaceMacros($epreamble, ['$itemlink' => $notification->link]);

		DI::notify()->update($notification);

		$itemlink  = DI::baseUrl() . '/notification/' . $notification->id;
		$notify_id = $notification->id;
	}

	// send email notification if notification preferences permit
	if ((intval($params['notify_flags']) & intval($params['type']))
		|| $params['type'] == NOTIFY_SYSTEM) {

		Logger::log('sending notification email');

		if (isset($params['parent']) && (intval($params['parent']) != 0)) {
			$id_for_parent = $params['parent'] . "@" . $hostname;

			// Is this the first email notification for this parent item and user?
			if (!DBA::exists('notify-threads', ['master-parent-item' => $params['parent'], 'receiver-uid' => $params['uid']])) {
				Logger::log("notify_id:" . intval($notify_id) . ", parent: " . intval($params['parent']) . "uid: " . intval($params['uid']), Logger::DEBUG);

				$fields = ['notify-id'    => $notify_id, 'master-parent-item' => $params['parent'],
				           'receiver-uid' => $params['uid'], 'parent-item' => 0];
				DBA::insert('notify-threads', $fields);

				$additional_mail_header .= "Message-ID: <${id_for_parent}>\n";
				$log_msg                = "include/enotify: No previous notification found for this parent:\n" .
				                          "  parent: ${params['parent']}\n" . "  uid   : ${params['uid']}\n";
				Logger::log($log_msg, Logger::DEBUG);
			} else {
				// If not, just "follow" the thread.
				$additional_mail_header .= "References: <${id_for_parent}>\nIn-Reply-To: <${id_for_parent}>\n";
				Logger::log("There's already a notification for this parent.", Logger::DEBUG);
			}
		}

		$datarray = [
			'preamble'     => $preamble,
			'type'         => $params['type'],
			'parent'       => $parent_id,
			'source_name'  => $params['source_name'] ?? null,
			'source_link'  => $params['source_link'] ?? null,
			'source_photo' => $params['source_photo'] ?? null,
			'uid'          => $params['uid'],
			'hsitelink'    => $hsitelink,
			'tsitelink'    => $tsitelink,
			'itemlink'     => $itemlink,
			'title'        => $title,
			'body'         => $body,
			'subject'      => $subject,
			'headers'      => $additional_mail_header,
		];

		Hook::callAll('enotify_mail', $datarray);

		$builder = DI::emailer()
			->newNotifyMail()
			->addHeaders($datarray['headers'])
			->withRecipient($params['to_email'])
			->forUser([
				'uid' => $datarray['uid'],
				'language' => $params['language'],
			])
			->withNotification($datarray['subject'], $datarray['preamble'], $datarray['title'], $datarray['body'])
			->withSiteLink($datarray['tsitelink'], $datarray['hsitelink'])
			->withItemLink($datarray['itemlink']);

		// If a photo is present, add it to the email
		if (!empty($datarray['source_photo'])) {
			$builder->withPhoto(
				$datarray['source_photo'],
				$datarray['source_link'] ?? $sitelink,
				$datarray['source_name'] ?? $sitename);
		}

		$email = $builder->build();

		// use the Emailer class to send the message
		return DI::emailer()->send($email);
	}

	return false;
}

/**
 * Checks for users who should be notified
 *
 * @param int $itemid ID of the item for which the check should be done
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function check_user_notification($itemid) {
	// fetch all users with notifications
	$useritems = DBA::select('user-item', ['uid', 'notification-type'], ['iid' => $itemid]);
	while ($useritem = DBA::fetch($useritems)) {
		check_item_notification($itemid, $useritem['uid'], $useritem['notification-type']);
	}
	DBA::close($useritems);
}

/**
 * Checks for item related notifications and sends them
 *
 * @param int    $itemid            ID of the item for which the check should be done
 * @param int    $uid               User ID
 * @param int    $notification_type Notification bits
 * @return bool
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function check_item_notification($itemid, $uid, $notification_type) {
	$fields = ['id', 'mention', 'tag', 'parent', 'title', 'body',
		'author-link', 'author-name', 'author-avatar', 'author-id',
		'guid', 'parent-uri', 'uri', 'contact-id', 'network'];
	$condition = ['id' => $itemid, 'gravity' => [GRAVITY_PARENT, GRAVITY_COMMENT], 'deleted' => false];
	$item = Item::selectFirstForUser($uid, $fields, $condition);
	if (!DBA::isResult($item)) {
		return false;
	}

	// Generate the notification array
	$params = [];
	$params['uid'] = $uid;
	$params['item'] = $item;
	$params['parent'] = $item['parent'];
	$params['link'] = DI::baseUrl() . '/display/' . urlencode($item['guid']);
	$params['otype'] = 'item';
	$params['source_name'] = $item['author-name'];
	$params['source_link'] = $item['author-link'];
	$params['source_photo'] = $item['author-avatar'];

	// Set the activity flags
	$params['activity']['explicit_tagged'] = ($notification_type & UserItem::NOTIF_EXPLICIT_TAGGED);
	$params['activity']['implicit_tagged'] = ($notification_type & UserItem::NOTIF_IMPLICIT_TAGGED);
	$params['activity']['origin_comment'] = ($notification_type & UserItem::NOTIF_DIRECT_COMMENT);
	$params['activity']['origin_thread'] = ($notification_type & UserItem::NOTIF_THREAD_COMMENT);
	$params['activity']['thread_comment'] = ($notification_type & UserItem::NOTIF_COMMENT_PARTICIPATION);
	$params['activity']['thread_activity'] = ($notification_type & UserItem::NOTIF_ACTIVITY_PARTICIPATION);

	// Tagging a user in a direct post (first comment level) means a direct comment
	if ($params['activity']['explicit_tagged'] && ($notification_type & UserItem::NOTIF_DIRECT_THREAD_COMMENT)) {
		$params['activity']['origin_comment'] = true;
	}

	if ($notification_type & UserItem::NOTIF_SHARED) {
		$params['type'] = NOTIFY_SHARE;
		$params['verb'] = Activity::POST;
	} elseif ($notification_type & UserItem::NOTIF_EXPLICIT_TAGGED) {
		$params['type'] = NOTIFY_TAGSELF;
		$params['verb'] = Activity::TAG;
	} elseif ($notification_type & UserItem::NOTIF_IMPLICIT_TAGGED) {
		$params['type'] = Notify\Type::COMMENT;
		$params['verb'] = Activity::POST;
	} elseif ($notification_type & UserItem::NOTIF_THREAD_COMMENT) {
		$params['type'] = Notify\Type::COMMENT;
		$params['verb'] = Activity::POST;
	} elseif ($notification_type & UserItem::NOTIF_DIRECT_COMMENT) {
		$params['type'] = Notify\Type::COMMENT;
		$params['verb'] = Activity::POST;
	} elseif ($notification_type & UserItem::NOTIF_COMMENT_PARTICIPATION) {
		$params['type'] = Notify\Type::COMMENT;
		$params['verb'] = Activity::POST;
	} elseif ($notification_type & UserItem::NOTIF_ACTIVITY_PARTICIPATION) {
		$params['type'] = Notify\Type::COMMENT;
		$params['verb'] = Activity::POST;
	} else {
		return false;
	}

	notification($params);
}
