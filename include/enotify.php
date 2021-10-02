<?php
/**
 * @copyright Copyright (C) 2010-2021, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

use Friendica\Content\Text\Plaintext;
use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Contact;
use Friendica\Model\Item;
use Friendica\Model\Notification;
use Friendica\Model\Post;
use Friendica\Model\User;
use Friendica\Model\Verb;
use Friendica\Navigation\Notifications;
use Friendica\Protocol\Activity;

/**
 * Creates a notification entry and possibly sends a mail
 *
 * @param array $params Array with the elements:
 *                      type, event, otype, activity, verb, uid, cid, item, link,
 *                      source_name, source_mail, source_nick, source_link, source_photo,
 *                      show_in_notification_page
 *
 * @return bool
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function notification($params)
{
	/** @var string the common prefix of a notification subject */
	$subjectPrefix = DI::l10n()->t('[Friendica:Notify]');

	// Temporary logging for finding the origin
	if (!isset($params['uid'])) {
		Logger::notice('Missing parameters "uid".', ['params' => $params, 'callstack' => System::callstack()]);
	}

	// Ensure that the important fields are set at any time
	$fields = ['nickname', 'page-flags', 'notify-flags', 'language', 'username', 'email'];
	$user = DBA::selectFirst('user', $fields, ['uid' => $params['uid']]);

	if (!DBA::isResult($user)) {
		Logger::error('Unknown user', ['uid' =>  $params['uid']]);
		return false;
	}

	// There is no need to create notifications for forum accounts
	if (in_array($user['page-flags'], [User::PAGE_FLAGS_COMMUNITY, User::PAGE_FLAGS_PRVGROUP])) {
		return false;
	}

	$params['notify_flags'] = $user['notify-flags'];
	$params['language']     = $user['language'];
	$params['to_name']      = $user['username'];
	$params['to_email']     = $user['email'];

	// from here on everything is in the recipients language
	$l10n = DI::l10n()->withLang($params['language']);

	if (!empty($params['cid'])) {
		$contact = Contact::getById($params['cid'], ['url', 'name', 'photo']);
		if (DBA::isResult($contact)) {
			$params['source_link'] = $contact['url'];
			$params['source_name'] = $contact['name'];
			$params['source_photo'] = $contact['photo'];
		}
	}

	$siteurl = DI::baseUrl()->get(true);
	$sitename = DI::config()->get('config', 'sitename');

	// with $params['show_in_notification_page'] == false, the notification isn't inserted into
	// the database, and an email is sent if applicable.
	// default, if not specified: true
	$show_in_notification_page = isset($params['show_in_notification_page']) ? $params['show_in_notification_page'] : true;

	$title = $params['item']['title'] ?? '';
	$body = $params['item']['body'] ?? '';

	$parent_id = $params['item']['parent'] ?? 0;
	$parent_uri_id = $params['item']['parent-uri-id'] ?? 0;

	$epreamble = '';
	$preamble  = '';
	$subject   = '';
	$sitelink  = '';
	$tsitelink = '';
	$hsitelink = '';
	$itemlink  = '';

	switch ($params['type']) {
		case Notification\Type::MAIL:
			$itemlink = $params['link'];

			$subject = $l10n->t('%s New mail received at %s', $subjectPrefix, $sitename);

			$preamble = $l10n->t('%1$s sent you a new private message at %2$s.', $params['source_name'], $sitename);
			$epreamble = $l10n->t('%1$s sent you %2$s.', '[url='.$params['source_link'].']'.$params['source_name'].'[/url]', '[url=' . $itemlink . ']' . $l10n->t('a private message').'[/url]');

			$sitelink = $l10n->t('Please visit %s to view and/or reply to your private messages.');
			$tsitelink = sprintf($sitelink, $itemlink);
			$hsitelink = sprintf($sitelink, '<a href="' . $itemlink . '">' . $sitename . '</a>');

			// Mail notifications aren't using the "notify" table entry
			$show_in_notification_page = false;
			break;

		case Notification\Type::COMMENT:
			if (Post\ThreadUser::getIgnored($parent_uri_id, $params['uid'])) {
				Logger::info('Thread is ignored', ['parent' => $parent_id, 'parent-uri-id' => $parent_uri_id]);
				return false;
			}

			$item = Post::selectFirstForUser($params['uid'], Item::ITEM_FIELDLIST, ['id' => $parent_id, 'deleted' => false]);
			if (empty($item)) {
				return false;
			}

			$item_post_type = Item::postType($item, $l10n);

			$content = Plaintext::getPost($item, 70);
			if (!empty($content['text'])) {
				$title = '"' . trim(str_replace("\n", " ", $content['text'])) . '"';
			} else {
				$title = '';
			}

			// First go for the general message

			// "George Bull's post"
			$message = $l10n->t('%1$s commented on %2$s\'s %3$s %4$s');
			$dest_str = sprintf($message, $params['source_name'], $item['author-name'], $item_post_type, $title);

			// "your post"
			if ($item['wall']) {
				$message = $l10n->t('%1$s commented on your %2$s %3$s');
				$dest_str = sprintf($message, $params['source_name'], $item_post_type, $title);
			// "their post"
			} elseif ($item['author-link'] == $params['source_link']) {
				$message = $l10n->t('%1$s commented on their %2$s %3$s');
				$dest_str = sprintf($message, $params['source_name'], $item_post_type, $title);
			}

			$subject = $l10n->t('%1$s Comment to conversation #%2$d by %3$s', $subjectPrefix, $parent_id, $params['source_name']);

			$preamble = $l10n->t('%s commented on an item/conversation you have been following.', $params['source_name']);

			$epreamble = $dest_str;

			$sitelink = $l10n->t('Please visit %s to view and/or reply to the conversation.');
			$tsitelink = sprintf($sitelink, $siteurl);
			$hsitelink = sprintf($sitelink, '<a href="' . $siteurl . '">' . $sitename . '</a>');
			$itemlink =  $params['link'];
			break;

		case Notification\Type::WALL:
			$subject = $l10n->t('%s %s posted to your profile wall', $subjectPrefix, $params['source_name']);

			$preamble = $l10n->t('%1$s posted to your profile wall at %2$s', $params['source_name'], $sitename);
			$epreamble = $l10n->t('%1$s posted to [url=%2$s]your wall[/url]',
				'[url='.$params['source_link'].']'.$params['source_name'].'[/url]',
				$params['link']
			);

			$sitelink = $l10n->t('Please visit %s to view and/or reply to the conversation.');
			$tsitelink = sprintf($sitelink, $siteurl);
			$hsitelink = sprintf($sitelink, '<a href="'.$siteurl.'">'.$sitename.'</a>');
			$itemlink =  $params['link'];
			break;

		case Notification\Type::POKE:
			$subject = $l10n->t('%1$s %2$s poked you', $subjectPrefix, $params['source_name']);

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
			break;

		case Notification\Type::INTRO:
			$itemlink = $params['link'];
			$subject = $l10n->t('%s Introduction received', $subjectPrefix);

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
					$subject = $l10n->t('%s A new person is sharing with you', $subjectPrefix);

					$preamble = $l10n->t('%1$s is sharing with you at %2$s', $params['source_name'], $sitename);
					$epreamble = $l10n->t('%1$s is sharing with you at %2$s',
						'[url='.$params['source_link'].']'.$params['source_name'].'[/url]',
						$sitename
					);
					break;
				case Activity::FOLLOW:
					// someone started to follow the user (mostly OStatus)
					$subject = $l10n->t('%s You have a new follower', $subjectPrefix);

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
			break;

		case Notification\Type::SUGGEST:
			$itemlink =  $params['link'];
			$subject = $l10n->t('%s Friend suggestion received', $subjectPrefix);

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
			break;

		case Notification\Type::CONFIRM:
			if ($params['verb'] == Activity::FRIEND) { // mutual connection
				$itemlink =  $params['link'];
				$subject = $l10n->t('%s Connection accepted', $subjectPrefix);

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
				$subject = $l10n->t('%s Connection accepted', $subjectPrefix);

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
			break;

		case Notification\Type::SYSTEM:
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
			break;

		default:
			Logger::notice('Unhandled type', ['type' => $params['type']]);
			return false;
	}

	return notification_store_and_send($params, $sitelink, $tsitelink, $hsitelink, $title, $subject, $preamble, $epreamble, $body, $itemlink, $show_in_notification_page);
}

function notification_store_and_send($params, $sitelink, $tsitelink, $hsitelink, $title, $subject, $preamble, $epreamble, $body, $itemlink, $show_in_notification_page)
{
	$item_id = $params['item']['id'] ?? 0;
	$uri_id = $params['item']['uri-id'] ?? 0;
	$parent_id = $params['item']['parent'] ?? 0;
	$parent_uri_id = $params['item']['parent-uri-id'] ?? 0;

	// Ensure that the important fields are set at any time
	$fields = ['nickname'];
	$user = User::getById($params['uid'], $fields);

	$sitename = DI::config()->get('config', 'sitename');

	$nickname = $user['nickname'];

	$hostname = DI::baseUrl()->getHostname();
	if (strpos($hostname, ':')) {
		$hostname = substr($hostname, 0, strpos($hostname, ':'));
	}

	// Creates a new email builder for the notification email
	$emailBuilder = DI::emailer()->newNotifyMail();

	$emailBuilder->setHeader('X-Friendica-Account', '<' . $nickname . '@' . $hostname . '>');

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
		/** @var $factory Notifications\Factory\Notify */
		$factory = DI::getDice()->create(Notifications\Factory\Notify::class);

		$Notify = $factory->createFromParams($params, $itemlink, $item_id, $uri_id, $parent_id, $parent_uri_id);
		try {
			$Notify = DI::notify()->save($Notify);
		} catch (Notifications\Exception\NotificationCreationInterceptedException $e) {
			// Notification insertion can be intercepted by an addon registering the 'enotify_store' hook
			return false;
		}

		$Notify->updateMsgFromPreamble($epreamble);
		$Notify = DI::notify()->save($Notify);

		$itemlink  = DI::baseUrl() . '/notification/' . $Notify->id;
		$notify_id = $Notify->id;
	}

	// send email notification if notification preferences permit
	if ((intval($params['notify_flags']) & intval($params['type']))
		|| $params['type'] == Notification\Type::SYSTEM) {

		Logger::log('sending notification email');

		if (isset($params['parent']) && (intval($params['parent']) != 0)) {
			$parent = Post::selectFirst(['guid'], ['id' => $params['parent']]);
			$message_id = "<" . $parent['guid'] . "@" . gethostname() . ">";

			// Is this the first email notification for this parent item and user?
			if (!DBA::exists('notify-threads', ['master-parent-uri-id' => $parent_uri_id, 'receiver-uid' => $params['uid']])) {
				Logger::log("notify_id:" . intval($notify_id) . ", parent: " . intval($params['parent']) . "uid: " . intval($params['uid']), Logger::DEBUG);

				$fields = ['notify-id' => $notify_id, 'master-parent-uri-id' => $parent_uri_id,
					'receiver-uid' => $params['uid'], 'parent-item' => 0];
				DBA::insert('notify-threads', $fields);

				$emailBuilder->setHeader('Message-ID', $message_id);
				$log_msg = "include/enotify: No previous notification found for this parent:\n" .
					"  parent: ${params['parent']}\n" . "  uid   : ${params['uid']}\n";
				Logger::log($log_msg, Logger::DEBUG);
			} else {
				// If not, just "follow" the thread.
				$emailBuilder->setHeader('References', $message_id);
				$emailBuilder->setHeader('In-Reply-To', $message_id);
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
			'headers'      => $emailBuilder->getHeaders(),
		];

		Hook::callAll('enotify_mail', $datarray);

		$emailBuilder
			->withHeaders($datarray['headers'])
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
			$emailBuilder->withPhoto(
				$datarray['source_photo'],
				$datarray['source_link'] ?? $sitelink,
				$datarray['source_name'] ?? $sitename);
		}

		$email = $emailBuilder->build();

		// use the Emailer class to send the message
		return DI::emailer()->send($email);
	}

	return false;
}

function notification_from_array(Notifications\Entity\Notification $Notification)
{
	Logger::info('Start', ['uid' => $Notification->uid, 'id' => $Notification->id, 'type' => $Notification->type]);

	if ($Notification->type === Post\UserNotification::TYPE_NONE) {
		Logger::info('Not an item based notification, quitting', ['uid' => $Notification->uid, 'id' => $Notification->id, 'type' => $Notification->type]);
		return false;
	}

	$params = [];
	$params['verb']  = $Notification->verb;
	$params['uid']   = $Notification->uid;
	$params['otype'] = Notification\ObjectType::ITEM;

	$user = User::getById($Notification->uid);

	$params['notify_flags'] = $user['notify-flags'];
	$params['language']     = $user['language'];
	$params['to_name']      = $user['username'];
	$params['to_email']     = $user['email'];

	// from here on everything is in the recipients language
	$l10n = DI::l10n()->withLang($user['language']);

	$contact = Contact::getById($Notification->actorId, ['url', 'name', 'photo']);
	if (DBA::isResult($contact)) {
		$params['source_link']  = $contact['url'];
		$params['source_name']  = $contact['name'];
		$params['source_photo'] = $contact['photo'];
	}

	$item = Post::selectFirstForUser($Notification->uid, Item::ITEM_FIELDLIST,
		['uid' => [0, $Notification->uid], 'uri-id' => $Notification->targetUriId, 'deleted' => false],
		['order' => ['uid' => true]]);
	if (empty($item)) {
		Logger::info('Item not found', ['uri-id' => $Notification->targetUriId, 'type' => $Notification->type]);
		return false;
	}

	$params['item']   = $item;
	$params['parent'] = $item['parent'];
	$params['link']   = DI::baseUrl() . '/display/' . urlencode($item['guid']);

	$subjectPrefix = $l10n->t('[Friendica:Notify]');

	if (Post\ThreadUser::getIgnored($Notification->parentUriId, $Notification->uid)) {
		Logger::info('Thread is ignored', ['parent-uri-id' => $Notification->parentUriId, 'type' => $Notification->type]);
		return false;
	}

	// Check to see if there was already a tag notify or comment notify for this post.
	// If so don't create a second notification
	$condition = ['type' => [Notification\Type::TAG_SELF, Notification\Type::COMMENT, Notification\Type::SHARE],
		'link' => $params['link'], 'verb' => Activity::POST];
	if (DI::notify()->existsForUser($Notification->uid, $condition)) {
		Logger::info('Duplicate found, quitting', $condition + ['uid' => $Notification->uid]);
		return false;
	}

	$content = Plaintext::getPost($item, 70);
	if (!empty($content['text'])) {
		$title = '"' . trim(str_replace("\n", " ", $content['text'])) . '"';
	} else {
		$title = $item['title'];
	}

	// Some mail software relies on subject field for threading.
	// So, we cannot have different subjects for notifications of the same thread.
	// Before this we have the name of the replier on the subject rendering
	// different subjects for messages on the same thread.
	if ($Notification->type === Post\UserNotification::TYPE_EXPLICIT_TAGGED) {
		$params['type'] = Notification\Type::TAG_SELF;
		$subject        = $l10n->t('%s %s tagged you', $subjectPrefix, $contact['name']);
	} elseif ($Notification->type === Post\UserNotification::TYPE_SHARED) {
		$params['type'] = Notification\Type::SHARE;
		$subject        = $l10n->t('%s %s shared a new post', $subjectPrefix, $contact['name']);
	} else {
		$params['type'] = Notification\Type::COMMENT;
		$subject        = $l10n->t('%1$s Comment to conversation #%2$d by %3$s', $subjectPrefix, $item['parent'], $contact['name']);
	}

	$msg = (new Notifications\Factory\Notification(DI::logger()))->getMessageFromNotification($Notification, DI::baseUrl(), $l10n);
	if (empty($msg)) {
		Logger::info('No notification message, quitting', ['uid' => $Notification->uid, 'id' => $Notification->id, 'type' => $Notification->type]);
		return false;
	}

	$preamble  = $msg['plain'];
	$epreamble = $msg['rich'];

	$sitename = DI::config()->get('config', 'sitename');
	$siteurl  = DI::baseUrl()->get(true);

	$sitelink  = $l10n->t('Please visit %s to view and/or reply to the conversation.');
	$tsitelink = sprintf($sitelink, $siteurl);
	$hsitelink = sprintf($sitelink, '<a href="' . $siteurl . '">' . $sitename . '</a>');
	$itemlink  = $params['link'];

	Logger::info('Perform notification', ['uid' => $Notification->uid, 'id' => $Notification->id, 'type' => $Notification->type]);

	return notification_store_and_send($params, $sitelink, $tsitelink, $hsitelink, $title, $subject, $preamble, $epreamble, $item['body'], $itemlink, true);
}
