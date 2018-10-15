<?php
/**
 * @file include/common.php
 */

use Friendica\App;
use Friendica\Content\ContactSelector;
use Friendica\Core\L10n;
use Friendica\Database\DBA;
use Friendica\Model;
use Friendica\Module;
use Friendica\Util\Proxy as ProxyUtils;


require_once 'include/dba.php';

function common_content(App $a)
{
	$o = '';

	$cmd = $a->argv[1];
	$uid = intval($a->argv[2]);
	$cid = intval($a->argv[3]);
	$zcid = 0;

	if (!local_user()) {
		notice(L10n::t('Permission denied.') . EOL);
		return;
	}

	if ($cmd !== 'loc' && $cmd != 'rem') {
		return;
	}

	if (!$uid) {
		return;
	}

	if ($cmd === 'loc' && $cid) {
		$contact = DBA::selectFirst('contact', ['name', 'url', 'photo', 'uid', 'id'], ['id' => $cid, 'uid' => $uid]);

		if (DBA::isResult($contact)) {
			$a->page['aside'] = "";
			Model\Profile::load($a, "", 0, Model\Contact::getDetailsByURL($contact["url"]));
		}
	} else {
		$contact = DBA::selectFirst('contact', ['name', 'url', 'photo', 'uid', 'id'], ['self' => true, 'uid' => $uid]);

		if (DBA::isResult($contact)) {
			$vcard_widget = replace_macros(get_markup_template("vcard-widget.tpl"), [
				'$name' => htmlentities($contact['name']),
				'$photo' => $contact['photo'],
				'url' => 'contacts/' . $cid
			]);

			if (!x($a->page, 'aside')) {
				$a->page['aside'] = '';
			}
			$a->page['aside'] .= $vcard_widget;
		}
	}

	if (!DBA::isResult($contact)) {
		return;
	}

	if (!$cid && Model\Profile::getMyURL()) {
		$contact = DBA::selectFirst('contact', ['id'], ['nurl' => normalise_link(Model\Profile::getMyURL()), 'uid' => $uid]);
		if (DBA::isResult($contact)) {
			$cid = $contact['id'];
		} else {
			$gcontact = DBA::selectFirst('gcontact', ['id'], ['nurl' => normalise_link(Model\Profile::getMyURL())]);
			if (DBA::isResult($gcontact)) {
				$zcid = $gcontact['id'];
			}
		}
	}

	if ($cid == 0 && $zcid == 0) {
		return;
	}

	if ($cid) {
		$t = Model\GContact::countCommonFriends($uid, $cid);
	} else {
		$t = Model\GContact::countCommonFriendsZcid($uid, $zcid);
	}

	if ($t > 0) {
		$a->setPagerTotal($t);
	} else {
		notice(L10n::t('No contacts in common.') . EOL);
		return $o;
	}

	if ($cid) {
		$r = Model\GContact::commonFriends($uid, $cid, $a->pager['start'], $a->pager['itemspage']);
	} else {
		$r = Model\GContact::commonFriendsZcid($uid, $zcid, $a->pager['start'], $a->pager['itemspage']);
	}

	if (!DBA::isResult($r)) {
		return $o;
	}

	$id = 0;

	$entries = [];
	foreach ($r as $rr) {
		//get further details of the contact
		$contact_details = Model\Contact::getDetailsByURL($rr['url'], $uid);

		// $rr['id'] is needed to use contact_photo_menu()
		/// @TODO Adding '/" here avoids E_NOTICE on missing constants
		$rr['id'] = $rr['cid'];

		$photo_menu = Model\Contact::photoMenu($rr);

		$entry = [
			'url'          => $rr['url'],
			'itemurl'      => defaults($contact_details, 'addr', $rr['url']),
			'name'         => $contact_details['name'],
			'thumb'        => ProxyUtils::proxifyUrl($contact_details['thumb'], false, ProxyUtils::SIZE_THUMB),
			'img_hover'    => htmlentities($contact_details['name']),
			'details'      => $contact_details['location'],
			'tags'         => $contact_details['keywords'],
			'about'        => $contact_details['about'],
			'account_type' => Model\Contact::getAccountType($contact_details),
			'network'      => ContactSelector::networkToName($contact_details['network'], $contact_details['url']),
			'photo_menu'   => $photo_menu,
			'id'           => ++$id,
		];
		$entries[] = $entry;
	}

	$title = '';
	$tab_str = '';
	if ($cmd === 'loc' && $cid && local_user() == $uid) {
		$tab_str = Module\Contact::getTabsHTML($a, $contact, 4);
	} else {
		$title = L10n::t('Common Friends');
	}

	$tpl = get_markup_template('viewcontact_template.tpl');

	$o .= replace_macros($tpl, [
		'$title'    => $title,
		'$tab_str'  => $tab_str,
		'$contacts' => $entries,
		'$paginate' => paginate($a),
	]);

	return $o;
}
