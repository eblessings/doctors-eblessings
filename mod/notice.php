<?php
/**
 * @file mod/notice.php
 * GNU Social -> friendica items permanent-url compatibility
 */

use Friendica\App;
use Friendica\Core\L10n;
use Friendica\Database\DBA;

function notice_init(App $a)
{
	$id = $a->argv[1];
	$r = q("SELECT `user`.`nickname` FROM `user` LEFT JOIN `item` ON `item`.`uid` = `user`.`uid` WHERE `item`.`id` = %d", intval($id));
	if (DBA::isResult($r)) {
		$nick = $r[0]['nickname'];
		$a->internalRedirect('display/' . $nick . '/' . $id);
	} else {
		throw new \Friendica\Network\HTTPException\NotFoundException(L10n::t('Item not found.'));
	}

	return;
}
