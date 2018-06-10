<?php
/**
 * @file mod/notes.php
 */
use Friendica\App;
use Friendica\Content\Nav;
use Friendica\Core\L10n;
use Friendica\Database\DBM;
use Friendica\Model\Profile;
use Friendica\Model\Item;

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
	if (! local_user()) {
		notice(L10n::t('Permission denied.') . EOL);
		return;
	}

	require_once 'include/security.php';
	require_once 'include/conversation.php';
	$groups = [];


	$o = '';

	$remote_contact = false;

	$contact_id = $_SESSION['cid'];
	$contact = $a->contact;

	$is_owner = true;

	$o ="";
	$o .= Profile::getTabs($a, true);

	if (!$update) {
		$o .= '<h3>' . L10n::t('Personal Notes') . '</h3>';

		$commpage = false;
		$commvisitor = false;

		$x = [
			'is_owner' => $is_owner,
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

	// Construct permissions

	// default permissions - anonymous user

	$sql_extra = " AND `item`.`allow_cid` = '<" . $a->contact['id'] . ">' ";

	/// @todo We seem to need "Item::count" as function as well
	$r = q("SELECT COUNT(*) AS `total`
		FROM `item` %s
		WHERE %s AND `item`.`uid` = %d AND `item`.`type` = 'note'
		AND `contact`.`self` AND `item`.`id` = `item`.`parent` AND NOT `item`.`wall`
		$sql_extra ",
		item_joins(local_user()),
		item_condition(),
		intval(local_user())
	);

	if (DBM::is_result($r)) {
		$a->set_pager_total($r[0]['total']);
		$a->set_pager_itemspage(40);
	}

	$condition = ["`uid` = ? AND `type` = 'note' AND NOT `wall`
		AND `id` = `parent` AND `allow_cid` = ?",
		local_user(), '<' . $a->contact['id'] . '>'];
	$params = ['order' => ['created' => true],
		'limit' => [$a->pager['start'], $a->pager['itemspage']]];

	$r = Item::select(local_user(), ['item_id'], $condition, $params);

	if (DBM::is_result($r)) {
		$parents_arr = [];

		while ($rr = dba::fetch($r)) {
			$parents_arr[] = $rr['item_id'];
		}
		dba::close($r);

		$condition = ['uid' => local_user(), 'parent' => $parents_arr];
		$result = Item::select(local_user(), [], $condition);
		if (DBM::is_result($result)) {
			$items = conv_sort(dba::inArray($result), 'commented');
			$o .= conversation($a, $items, 'notes', $update);
		}
	}

	$o .= paginate($a);
	return $o;
}
