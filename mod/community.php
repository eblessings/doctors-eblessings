<?php

function community_init(&$a) {
	if(! local_user()) {
		unset($_SESSION['theme']);
		unset($_SESSION['mobile-theme']);
	}


}


function community_content(&$a, $update = 0) {

	$o = '';

	if((get_config('system','block_public')) && (! local_user()) && (! remote_user())) {
		notice( t('Public access denied.') . EOL);
		return;
	}

	if(get_config('system','community_page_style') == CP_NO_COMMUNITY_PAGE) {
		notice( t('Not available.') . EOL);
		return;
	}

	require_once("include/bbcode.php");
	require_once('include/security.php');
	require_once('include/conversation.php');


	$o .= '<h3>' . t('Community') . '</h3>';
	if(! $update) {
		nav_set_selected('community');
	}

	if(x($a->data,'search'))
		$search = notags(trim($a->data['search']));
	else
		$search = ((x($_GET,'search')) ? notags(trim(rawurldecode($_GET['search']))) : '');


	// Here is the way permissions work in this module...
	// Only public posts can be shown
	// OR your own posts if you are a logged in member

	if(get_config('system', 'old_pager')) {
		$r = q("SELECT COUNT(distinct(`item`.`uri`)) AS `total`
			FROM `item` INNER JOIN `contact` ON `contact`.`id` = `item`.`contact-id`
			AND `contact`.`blocked` = 0 AND `contact`.`pending` = 0
			INNER JOIN `user` ON `user`.`uid` = `item`.`uid` AND `user`.`hidewall` = 0
			WHERE `item`.`visible` = 1 AND `item`.`deleted` = 0 and `item`.`moderated` = 0
			AND `item`.`allow_cid` = ''  AND `item`.`allow_gid` = ''
			AND `item`.`deny_cid`  = '' AND `item`.`deny_gid`  = ''
			AND `item`.`private` = 0 AND `item`.`wall` = 1"
		);

		if(count($r))
			$a->set_pager_total($r[0]['total']);

		if(! $r[0]['total']) {
			info( t('No results.') . EOL);
			return $o;
		}

	}

	$r = community_getitems($a->pager['start'], $a->pager['itemspage']);

	if(! count($r)) {
		info( t('No results.') . EOL);
		return $o;
	}

	$maxpostperauthor = get_config('system','max_author_posts_community_page');

	if ($maxpostperauthor != 0) {
		$count = 1;
		$previousauthor = "";
		$numposts = 0;
		$s = array();

		do {
			foreach ($r AS $row=>$item) {
				if ($previousauthor == $item["author-link"])
					++$numposts;
				else
					$numposts = 0;

				$previousauthor = $item["author-link"];

				if (($numposts < $maxpostperauthor) AND (sizeof($s) < $a->pager['itemspage']))
					$s[] = $item;
			}
			if ((sizeof($s) < $a->pager['itemspage']))
				$r = community_getitems($a->pager['start'] + ($count * $a->pager['itemspage']), $a->pager['itemspage']);

		} while ((sizeof($s) < $a->pager['itemspage']) AND (++$count < 50) AND (sizeof($r) > 0));
	} else
		$s = $r;

	// we behave the same in message lists as the search module

	$o .= conversation($a,$s,'community',$update);

	if(!get_config('system', 'old_pager')) {
	        $o .= alt_pager($a,count($r));
	} else {
	        $o .= paginate($a);
	}

	return $o;
}

function community_getitems($start, $itemspage) {
	// Work in progress
	if (get_config('system', 'global_community'))
		return(community_getpublicitems($start, $itemspage));

	$r = q("SELECT `item`.`uri`, `item`.*, `item`.`id` AS `item_id`,
		`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`alias`, `contact`.`rel`,
		`contact`.`network`, `contact`.`thumb`, `contact`.`self`, `contact`.`writable`,
		`contact`.`id` AS `cid`, `contact`.`uid` AS `contact-uid`,
		`user`.`nickname`, `user`.`hidewall`
		FROM `thread` FORCE INDEX (`wall_private_received`)
		INNER JOIN `user` ON `user`.`uid` = `thread`.`uid` AND `user`.`hidewall` = 0
		INNER JOIN `item` ON `item`.`id` = `thread`.`iid`
		AND `item`.`allow_cid` = ''  AND `item`.`allow_gid` = ''
		AND `item`.`deny_cid`  = '' AND `item`.`deny_gid`  = ''
		INNER JOIN `contact` ON `contact`.`id` = `thread`.`contact-id`
		AND `contact`.`blocked` = 0 AND `contact`.`pending` = 0 AND `contact`.`self`
		WHERE `thread`.`visible` = 1 AND `thread`.`deleted` = 0 and `thread`.`moderated` = 0
		AND `thread`.`private` = 0 AND `thread`.`wall` = 1
		ORDER BY `thread`.`received` DESC LIMIT %d, %d ",
		intval($start),
		intval($itemspage)
	);

	return($r);

}

function community_getpublicitems($start, $itemspage) {
	$r = q("SELECT `item`.`uri`, `item`.*, `item`.`id` AS `item_id`,
			`author-name` AS `name`, `owner-avatar` AS `photo`,
			`owner-link` AS `url`, `owner-avatar` AS `thumb`
		FROM `item` WHERE `item`.`uid` = 0
		AND `item`.`allow_cid` = '' AND `item`.`allow_gid` = ''
		AND `item`.`deny_cid` = '' AND `item`.`deny_gid` = ''
		ORDER BY `item`.`received` DESC LIMIT %d, %d",
		intval($start),
		intval($itemspage)
	);

	return($r);

}
