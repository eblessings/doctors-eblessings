<?php
/**
 * @file src/Worker/Delivery.php
 */
namespace Friendica\Worker;

use Friendica\BaseObject;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\System;
use Friendica\Database\DBM;
use Friendica\Model\Contact;
use Friendica\Model\Item;
use Friendica\Model\Queue;
use Friendica\Model\User;
use Friendica\Protocol\DFRN;
use Friendica\Protocol\Diaspora;
use Friendica\Protocol\Email;
use dba;

require_once 'include/items.php';

class Delivery extends BaseObject {
	const MAIL =       'mail';
	const SUGGESTION = 'suggest';
	const RELOCATION = 'relocate';
	const DELETION =   'drop';
	const POST =       'wall-new';
	const COMMENT =    'comment-new';

	public static function execute($cmd, $item_id, $contact_id) {
		logger('Invoked: ' . $cmd . ': ' . $item_id . ' to ' . $contact_id, LOGGER_DEBUG);

		$top_level = false;
		$followup = false;
		$public_message = false;

		if ($cmd == self::MAIL) {
			$target_item = dba::selectFirst('mail', [], ['id' => $item_id]);
			if (!DBM::is_result($message)) {
				return;
			}
			$uid = $target_item['uid'];
		} elseif ($cmd == self::SUGGESTION) {
			$target_item = dba::selectFirst('fsuggest', [], ['id' => $item_id]);
			if (!DBM::is_result($message)) {
				return;
			}
			$uid = $target_item['uid'];
		} elseif ($cmd == self::RELOCATION) {
			$uid = $item_id;
		} else {
			$item = dba::selectFirst('item', ['parent'], ['id' => $item_id]);
			if (!DBM::is_result($item) || empty($item['parent'])) {
				return;
			}
			$parent_id = intval($item['parent']);

			$itemdata = dba::p("SELECT `item`.*, `contact`.`uid` AS `cuid`,
							`sign`.`signed_text`,`sign`.`signature`,`sign`.`signer`
						FROM `item`
						INNER JOIN `contact` ON `contact`.`id` = `item`.`contact-id`
						LEFT JOIN `sign` ON `sign`.`iid` = `item`.`id`
						WHERE `item`.`id` IN (?, ?) AND `visible` AND NOT `moderated`
						ORDER BY `item`.`id`",
					$item_id, $parent_id);
			$items = [];
			while ($item = dba::fetch($itemdata)) {
				if ($item['id'] == $parent_id) {
					$parent = $item;
				}
				if ($item['id'] == $item_id) {
					$target_item = $item;
				}
				$items[] = $item;
			}
			dba::close($itemdata);

			$uid = $target_item['cuid'];

			// avoid race condition with deleting entries
			if ($items[0]['deleted']) {
				foreach ($items as $item) {
					$item['deleted'] = 1;
				}
			}

			// When commenting too fast after delivery, a post wasn't recognized as top level post.
			// The count then showed more than one entry. The additional check should help.
			// The check for the "count" should be superfluous, but I'm not totally sure by now, so we keep it.
			if ((($parent['id'] == $item_id) || (count($items) == 1)) && ($parent['uri'] === $parent['parent-uri'])) {
				logger('Top level post');
				$top_level = true;
			}

			// This is IMPORTANT!!!!

			// We will only send a "notify owner to relay" or followup message if the referenced post
			// originated on our system by virtue of having our hostname somewhere
			// in the URI, AND it was a comment (not top_level) AND the parent originated elsewhere.
			// if $parent['wall'] == 1 we will already have the parent message in our array
			// and we will relay the whole lot.

			$localhost = self::getApp()->get_hostname();
			if (strpos($localhost, ':')) {
				$localhost = substr($localhost, 0, strpos($localhost, ':'));
			}
			/**
			 *
			 * Be VERY CAREFUL if you make any changes to the following line. Seemingly innocuous changes
			 * have been known to cause runaway conditions which affected several servers, along with
			 * permissions issues.
			 *
			 */

			if (!$top_level && ($parent['wall'] == 0) && stristr($target_item['uri'], $localhost)) {
				logger('Followup ' . $target_item["guid"], LOGGER_DEBUG);
				// local followup to remote post
				$followup = true;
			}

			if (empty($parent['allow_cid'])
				&& empty($parent['allow_gid'])
				&& empty($parent['deny_cid'])
				&& empty($parent['deny_gid'])
				&& !$parent["private"]) {
				$public_message = true;
			}
		}

		$owner = User::getOwnerDataById($uid);
		if (!DBM::is_result($owner)) {
			return;
		}

		// We don't deliver our items to blocked or pending contacts, and not to ourselves either
		$contact = dba::selectFirst('contact', [],
			['id' => $contact_id, 'blocked' => false, 'pending' => false, 'self' => false]
		);
		if (!DBM::is_result($contact)) {
			return;
		}

		// Transmit via Diaspora if the thread had started as Diaspora post
		// This is done since the uri wouldn't match (Diaspora doesn't transmit it)
		if (isset($parent) && ($parent['network'] == NETWORK_DIASPORA) && ($contact['network'] == NETWORK_DFRN)) {
			$contact['network'] = NETWORK_DIASPORA;
		}

		logger("Delivering " . $cmd . " followup=$followup - via network " . $contact['network']);

		switch ($contact['network']) {

			case NETWORK_DFRN:
				self::deliverDFRN($cmd, $contact, $owner, $items, $target_item, $public_message, $top_level, $followup);
				break;

			case NETWORK_DIASPORA:
				self::deliverDiaspora($cmd, $contact, $owner, $items, $target_item, $public_message, $top_level, $followup);
				break;

			case NETWORK_OSTATUS:
				// Do not send to otatus if we are not configured to send to public networks
				if ($owner['prvnets']) {
					break;
				}
				if (Config::get('system','ostatus_disabled') || Config::get('system','dfrn_only')) {
					break;
				}

				// There is currently no code here to distribute anything to OStatus.
				// This is done in "notifier.php" (See "url_recipients" and "push_notify")
				break;

			case NETWORK_MAIL:
				self::deliverMail($cmd, $contact, $owner, $target_item);
				break;

			default:
				break;
		}

		return;
	}

	private static function deliverDFRN($cmd, $contact, $owner, $items, $target_item, $public_message, $top_level, $followup)
	{
		logger('Deliver ' . $target_item["guid"] . ' via DFRN to ' . $contact['addr']);

		if ($cmd == self::MAIL) {
			$item = $target_item;
			$item['body'] = Item::fixPrivatePhotos($item['body'], $owner['uid'], null, $item['contact-id']);
			$atom = DFRN::mail($item, $owner);
		} elseif ($cmd == self::SUGGESTION) {
			$item = $target_item;
			$atom = DFRN::fsuggest($item, $owner);
			dba::delete('fsuggest', ['id' => $item['id']]);
		} elseif ($cmd == self::RELOCATION) {
			$atom = DFRN::relocate($owner, $owner['uid']);
		} elseif ($followup) {
			$msgitems = [$target_item];
			$atom = DFRN::entries($msgitems, $owner);
		} else {
			$msgitems = [];
			foreach ($items as $item) {
				// Only add the parent when we don't delete other items.
				if (($target_item['id'] == $item['id']) || ($cmd != self::DELETION)) {
					$item["entry:comment-allow"] = true;
					$item["entry:cid"] = ($top_level ? $contact['id'] : 0);
					$msgitems[] = $item;
				}
			}
			$atom = DFRN::entries($msgitems, $owner);
		}

		logger('Notifier entry: ' . $contact["url"] . ' ' . $target_item["guid"] . ' entry: ' . $atom, LOGGER_DATA);

		$basepath =  implode('/', array_slice(explode('/', $contact['url']), 0, 3));

		// perform local delivery if we are on the same site

		if (link_compare($basepath, System::baseUrl())) {
			$condition = ['nurl' => normalise_link($contact['url']), 'self' => true];
			$target_self = dba::selectFirst('contact', ['uid'], $condition);
			if (!DBM::is_result($target_self)) {
				return;
			}
			$target_uid = $target_self['uid'];

			// Check if the user has got this contact
			$cid = Contact::getIdForURL($owner['url'], $target_uid);
			if (!$cid) {
				// Otherwise there should be a public contact
				$cid = Contact::getIdForURL($owner['url']);
				if (!$cid) {
					return;
				}
			}

			// We now have some contact, so we fetch it
			$target_importer = dba::fetch_first("SELECT *, `name` as `senderName`
							FROM `contact`
							WHERE NOT `blocked` AND `id` = ? LIMIT 1",
							$cid);

			// This should never fail
			if (!DBM::is_result($target_importer)) {
				return;
			}

			// Set the user id. This is important if this is a public contact
			$target_importer['importer_uid']  = $target_uid;
			DFRN::import($atom, $target_importer);
			return;
		}

		// We don't have a relationship with contacts on a public post.
		// Se we transmit with the new method and via Diaspora as a fallback
		if ($items[0]['uid'] == 0) {
			$deliver_status = DFRN::transmit($owner, $contact, $atom);
			if ($deliver_status < 200) {
				// Transmit via Diaspora if not possible via Friendica
				self::deliverDiaspora($cmd, $contact, $owner, $items, $target_item, $public_message, $top_level, $followup);
				return;
			}
		} else {
			$deliver_status = DFRN::deliver($owner, $contact, $atom);
		}

		logger('Delivery to ' . $contact["url"] . ' with guid ' . $target_item["guid"] . ' returns ' . $deliver_status);

		if ($deliver_status < 0) {
			logger('Delivery failed: queuing message ' . $target_item["guid"] );
			Queue::add($contact['id'], NETWORK_DFRN, $atom, false, $target_item['guid']);
		}

		if (($deliver_status >= 200) && ($deliver_status <= 299)) {
			// We successfully delivered a message, the contact is alive
			Contact::unmarkForArchival($contact);
		} else {
			// The message could not be delivered. We mark the contact as "dead"
			Contact::markForArchival($contact);
		}
	}

	private static function deliverDiaspora($cmd, $contact, $owner, $items, $target_item, $public_message, $top_level, $followup)
	{
		// We don't treat Forum posts as "wall-to-wall" to be able to post them via Diaspora
		$walltowall = $top_level && ($owner['id'] != $items[0]['contact-id']) & ($owner['account-type'] != ACCOUNT_TYPE_COMMUNITY);

		if ($public_message) {
			$loc = 'public batch ' . $contact['batch'];
		} else {
			$loc = $contact['addr'];
		}

		logger('Deliver ' . $target_item["guid"] . ' via Diaspora to ' . $loc);

		if (Config::get('system', 'dfrn_only') || !Config::get('system', 'diaspora_enabled')) {
			return;
		}
		if ($cmd == self::MAIL) {
			Diaspora::sendMail($target_item, $owner, $contact);
			return;
		}

		if ($cmd == self::SUGGESTION) {
			return;
		}
		if (!$contact['pubkey'] && !$public_message) {
			return;
		}
		if (($target_item['deleted']) && (($target_item['uri'] === $target_item['parent-uri']) || $followup)) {
			// top-level retraction
			logger('diaspora retract: ' . $loc);
			Diaspora::sendRetraction($target_item, $owner, $contact, $public_message);
			return;
		} elseif ($cmd == self::RELOCATION) {
			Diaspora::sendAccountMigration($owner, $contact, $owner['uid']);
			return;
		} elseif ($followup) {
			// send comments and likes to owner to relay
			logger('diaspora followup: ' . $loc);
			Diaspora::sendFollowup($target_item, $owner, $contact, $public_message);
			return;
		} elseif ($target_item['uri'] !== $target_item['parent-uri']) {
			// we are the relay - send comments, likes and relayable_retractions to our conversants
			logger('diaspora relay: ' . $loc);
			Diaspora::sendRelay($target_item, $owner, $contact, $public_message);
			return;
		} elseif ($top_level && !$walltowall) {
			// currently no workable solution for sending walltowall
			logger('diaspora status: ' . $loc);
			Diaspora::sendStatus($target_item, $owner, $contact, $public_message);
			return;
		}

		logger('Unknown mode ' . $cmd . ' for ' . $loc);
	}

	private static function deliverMail($cmd, $contact, $owner, $target_item)
	{
		if (Config::get('system','dfrn_only')) {
			return;
		}
		// WARNING: does not currently convert to RFC2047 header encodings, etc.

		$addr = $contact['addr'];
		if (!strlen($addr)) {
			return;
		}

		if (!in_array($cmd, [self::POST, self::COMMENT])) {
			return;
		}

		$local_user = dba::selectFirst('user', [], ['uid' => $owner['uid']]);
		if (!DBM::is_result($local_user)) {
			return;
		}

		logger('Deliver ' . $target_item["guid"] . ' via mail to ' . $contact['addr']);

		$reply_to = '';
		$mailacct = dba::selectFirst('mailacct', ['reply_to'], ['uid' => $owner['uid']]);
		if (DBM::is_result($mailacct) && !empty($mailacct['reply_to'])) {
			$reply_to = $mailacct['reply_to'];
		}

		$subject  = ($target_item['title'] ? Email::encodeHeader($target_item['title'], 'UTF-8') : L10n::t("\x28no subject\x29"));

		// only expose our real email address to true friends

		if (($contact['rel'] == CONTACT_IS_FRIEND) && !$contact['blocked']) {
			if ($reply_to) {
				$headers  = 'From: ' . Email::encodeHeader($local_user['username'],'UTF-8') . ' <' . $reply_to.'>' . "\n";
				$headers .= 'Sender: ' . $local_user['email'] . "\n";
			} else {
				$headers  = 'From: ' . Email::encodeHeader($local_user['username'],'UTF-8').' <' . $local_user['email'] . '>' . "\n";
			}
		} else {
			$headers  = 'From: '. Email::encodeHeader($local_user['username'], 'UTF-8') . ' <noreply@' . self::getApp()->get_hostname() . '>' . "\n";
		}

		$headers .= 'Message-Id: <' . Email::iri2msgid($target_item['uri']) . '>' . "\n";

		if ($target_item['uri'] !== $target_item['parent-uri']) {
			$headers .= "References: <" . Email::iri2msgid($target_item["parent-uri"]) . ">";

			// If Threading is enabled, write down the correct parent
			if (($target_item["thr-parent"] != "") && ($target_item["thr-parent"] != $target_item["parent-uri"])) {
				$headers .= " <".Email::iri2msgid($target_item["thr-parent"]).">";
			}
			$headers .= "\n";

			if (empty($target_item['title'])) {
				$condition = ['uri' => $target_item['parent-uri'], 'uid' => $owner['uid']];
				$title = dba::selectFirst('item', ['title'], $condition);
				if (DBM::is_result($title) && ($title['title'] != '')) {
					$subject = $title['title'];
				} else {
					$condition = ['parent-uri' => $target_item['parent-uri'], 'uid' => $owner['uid']];
					$title = dba::selectFirst('item', ['title'], $condition);
					if (DBM::is_result($title) && ($title['title'] != '')) {
						$subject = $title['title'];
					}
				}
			}
			if (strncasecmp($subject, 'RE:', 3)) {
				$subject = 'Re: ' . $subject;
			}
		}
		Email::send($addr, $subject, $headers, $target_item);
	}
}
