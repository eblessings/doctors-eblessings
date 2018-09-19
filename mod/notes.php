<?php
/**
 * @file mod/notes.php
 */

use Friendica\App;
use Friendica\Content\Nav;
use Friendica\Core\L10n;
use Friendica\Database\DBA;
use Friendica\Model\Item;
use Friendica\Model\Profile;

function notes_init(App $a)
{
	if (! local_user()) {
		return;
	}

	$profile = 0;

	$which = $a->user['nickname'];

	Nav::setSelected('home');

	//Profile::load($a, $which, $profile);
}


function notes_content(App $a, $update = false)
{
	if (!local_user()) {
		notice(L10n::t('Permission denied.') . EOL);
		return;
	}

	require_once 'include/security.php';
	require_once 'include/conversation.php';

	$o = Profile::getTabs($a, true);

	if (!$update) {
		$o .= '<h3>' . L10n::t('Personal Notes') . '</h3>';

		$x = [
			'is_owner' => true,
			'allow_location' => (($a->user['allow_location']) ? true : false),
			'default_location' => $a->user['default-location'],
			'nickname' => $a->user['nickname'],
			'lockstate' => 'lock',
			'acl' => '',
			'bang' => '',
			'visitor' => 'block',
			'profile_uid' => local_user(),
			'button' => L10n::t('Save'),
			'acl_data' => '',
		];

		$o .= status_editor($a, $x, $a->contact['id']);
	}

	$condition = ['uid' => local_user(), 'post-type' => Item::PT_PERSONAL_NOTE, 'gravity' => GRAVITY_PARENT,
		'wall' => false, 'contact-id'=> $a->contact['id']];

	$a->set_pager_itemspage(40);

	$params = ['order' => ['created' => true],
		'limit' => [$a->pager['start'], $a->pager['itemspage']]];
	$r = Item::selectThreadForUser(local_user(), ['uri'], $condition, $params);

	$count = 0;

	if (DBA::isResult($r)) {
		$notes = DBA::toArray($r);

		$count = count($notes);

		$o .= conversation($a, $notes, 'notes', $update);
	}

	$o .= alt_pager($a, $count);

	return $o;
}
