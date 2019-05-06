<?php
namespace Friendica\Module;

use Friendica\App;
use Friendica\BaseModule;
use Friendica\Core\L10n;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Protocol\Diaspora;
use Friendica\Protocol\ActivityPub;
use Friendica\Util\DateTimeFormat;

class FollowConfirm extends BaseModule
{
	public static function post()
	{
		$a = self::getApp();

		$uid = local_user();
		if (!$uid) {
			notice(L10n::t('Permission denied.') . EOL);
			return;
		}

		$intro_id = intval(defaults($_POST, 'intro_id'  , 0));
		$duplex   = intval(defaults($_POST, 'duplex'    , 0));
		$cid      = intval(defaults($_POST, 'contact_id', 0));
		$hidden   = intval(defaults($_POST, 'hidden'    , 0));

		if (empty($cid)) {
			notice(L10n::t('No given contact.') . EOL);
			return;
		}

		Logger::log('Confirming follower with contact_id: ' . $cid);

		$contact = DBA::selectFirst('contact', [], ['id' => $cid, 'uid' => $uid]);
		if (!DBA::isResult($contact)) {
			Logger::log('Contact not found in DB.');
			notice(L10n::t('Contact not found.') . EOL);
			return;
		}

		$relation = $contact['rel'];
		$new_relation = $contact['rel'];
		$writable = $contact['writable'];

		if (!empty($contact['protocol'])) {
			$protocol = $contact['protocol'];
		} else {
			$protocol = $contact['network'];
		}

		if ($protocol == Protocol::ACTIVITYPUB) {
			ActivityPub\Transmitter::sendContactAccept($contact['url'], $contact['hub-verify'], $uid);
		}

		if (in_array($protocol, [Protocol::DIASPORA, Protocol::ACTIVITYPUB])) {
			if ($duplex) {
				$new_relation = Contact::FRIEND;
			} else {
				$new_relation = Contact::FOLLOWER;
			}

			if ($new_relation != Contact::FOLLOWER) {
				$writable = 1;
			}
		}

		$fields = ['name-date' => DateTimeFormat::utcNow(),
			'uri-date' => DateTimeFormat::utcNow(),
			'blocked' => false, 'pending' => false, 'protocol' => $protocol,
			'writable' => $writable, 'hidden' => $hidden, 'rel' => $new_relation];
		DBA::update('contact', $fields, ['id' => $cid]);

		if ($new_relation == Contact::FRIEND) {
			if ($protocol == Protocol::DIASPORA) {
				$user = DBA::selectFirst('user', [], ['uid' => $uid]);
				$contact = DBA::select('contact', [], ['id' => $cid]);
				$ret = Diaspora::sendShare($user, $contact);
				Logger::log('share returns: ' . $ret);
			} elseif ($protocol == Protocol::ACTIVITYPUB) {
				ActivityPub\Transmitter::sendActivity('Follow', $contact['url'], $uid);
			}
		}

		DBA::delete('intro', ['id' => $intro_id]);

		$a->internalRedirect('contact/' . intval($cid));
	}
}
