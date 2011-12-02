<?php

function search_saved_searches() {

	$o = '';

	$r = q("select `id`,`term` from `search` WHERE `uid` = %d",
		intval(local_user())
	);

	if(count($r)) {
		$o .= '<div id="saved-search-list" class="widget">';
		$o .= '<h3>' . t('Saved Searches') . '</h3>' . "\r\n";
		$o .= '<ul id="saved-search-ul">' . "\r\n";
		foreach($r as $rr) {
			$o .= '<li class="saved-search-li clear"><a href="search/?f=&remove=1&search=' . $rr['term'] . '" class="icon drophide savedsearchdrop" title="' . t('Remove term') . '" onclick="return confirmDelete();" onmouseover="imgbright(this);" onmouseout="imgdull(this);" ></a> <a href="search/?f=&search=' . $rr['term'] . '" class="savedsearchterm" >' . $rr['term'] . '</a></li>' . "\r\n";
		}
		$o .= '</ul><div class="clear"></div></div>' . "\r\n";
	}		

	return $o;

}


function search_init(&$a) {

	$search = ((x($_GET,'search')) ? notags(trim(rawurldecode($_GET['search']))) : '');

	if(local_user()) {
		if(x($_GET,'save') && $search) {
			$r = q("select * from `search` where `uid` = %d and `term` = '%s' limit 1",
				intval(local_user()),
				dbesc($search)
			);
			if(! count($r)) {
				q("insert into `search` ( `uid`,`term` ) values ( %d, '%s') ",
					intval(local_user()),
					dbesc($search)
				);
			}
		}
		if(x($_GET,'remove') && $search) {
			q("delete from `search` where `uid` = %d and `term` = '%s' limit 1",
				intval(local_user()),
				dbesc($search)
			);
		}

		$a->page['aside'] .= search_saved_searches();

	}
	else
		unset($_SESSION['theme']);



}



function search_post(&$a) {
	if(x($_POST,'search'))
		$a->data['search'] = $_POST['search'];
}


function search_content(&$a) {

	if((get_config('system','block_public')) && (! local_user()) && (! remote_user())) {
		notice( t('Public access denied.') . EOL);
		return;
	}
	
	nav_set_selected('search');

	require_once("include/bbcode.php");
	require_once('include/security.php');
	require_once('include/conversation.php');

	$o = '<div id="live-search"></div>' . "\r\n";

	$o .= '<h3>' . t('Search This Site') . '</h3>';

	if(x($a->data,'search'))
		$search = notags(trim($a->data['search']));
	else
		$search = ((x($_GET,'search')) ? notags(trim(rawurldecode($_GET['search']))) : '');

	$o .= search($search,'search-box','/search',((local_user()) ? true : false));

	if(! $search)
		return $o;

	// Here is the way permissions work in the search module...
	// Only public wall posts can be shown
	// OR your own posts if you are a logged in member

	$escaped_search = str_replace(array('[',']'),array('\\[','\\]'),$search);

//	$s_bool  = sprintf("AND MATCH (`item`.`body`) AGAINST ( '%s' IN BOOLEAN MODE )", dbesc($search));
	$s_regx  = sprintf("AND ( `item`.`body` REGEXP '%s' OR `item`.`tag` REGEXP '%s' )", 
		dbesc($escaped_search), dbesc('\\]' . $escaped_search . '\\['));

//	if(mb_strlen($search) >= 3)
//		$search_alg = $s_bool;
//	else

		$search_alg = $s_regx;

	$r = q("SELECT COUNT(*) AS `total`
		FROM `item` LEFT JOIN `contact` ON `contact`.`id` = `item`.`contact-id` LEFT JOIN `user` ON `user`.`uid` = `item`.`uid`
		WHERE `item`.`visible` = 1 AND `item`.`deleted` = 0
		AND (( `wall` = 1 AND `item`.`allow_cid` = ''  AND `item`.`allow_gid` = '' AND `item`.`deny_cid`  = '' AND `item`.`deny_gid`  = '' AND `user`.`hidewall` = 0) 
			OR `item`.`uid` = %d )
		AND `contact`.`blocked` = 0 AND `contact`.`pending` = 0
		$search_alg ",
		intval(local_user())
	);

	if(count($r))
		$a->set_pager_total($r[0]['total']);

	if(! $r[0]['total']) {
		info( t('No results.') . EOL);
		return $o;
	}

	$r = q("SELECT `item`.*, `item`.`id` AS `item_id`, 
		`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`rel`,
		`contact`.`network`, `contact`.`thumb`, `contact`.`self`, `contact`.`writable`, 
		`contact`.`id` AS `cid`, `contact`.`uid` AS `contact-uid`,
		`user`.`nickname`
		FROM `item` LEFT JOIN `contact` ON `contact`.`id` = `item`.`contact-id`
		LEFT JOIN `user` ON `user`.`uid` = `item`.`uid`
		WHERE `item`.`visible` = 1 AND `item`.`deleted` = 0
		AND (( `wall` = 1 AND `item`.`allow_cid` = ''  AND `item`.`allow_gid` = '' AND `item`.`deny_cid`  = '' AND `item`.`deny_gid`  = '' AND `user`.`hidewall` = 0 ) 
			OR `item`.`uid` = %d )
		AND `contact`.`blocked` = 0 AND `contact`.`pending` = 0
		$search_alg
		ORDER BY `received` DESC LIMIT %d , %d ",
		intval(local_user()),
		intval($a->pager['start']),
		intval($a->pager['itemspage'])

	);

	$o .= '<h2>Search results for: ' . $search . '</h2>';

	$o .= conversation($a,$r,'search',false);

	$o .= paginate($a);

	return $o;
}

