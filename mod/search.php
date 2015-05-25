<?php

function search_saved_searches() {

	$o = '';

	if(! feature_enabled(local_user(),'savedsearch'))
		return $o;

	$r = q("SELECT `id`,`term` FROM `search` WHERE `uid` = %d",
		intval(local_user())
	);

	if(count($r)) {
		$saved = array();
		foreach($r as $rr) {
			$saved[] = array(
				'id'            => $rr['id'],
				'term'			=> $rr['term'],
				'encodedterm' 	=> urlencode($rr['term']),
				'delete'		=> t('Remove term'),
				'selected'		=> ($search==$rr['term']),
			);
		}


		$tpl = get_markup_template("saved_searches_aside.tpl");

		$o .= replace_macros($tpl, array(
			'$title'	 => t('Saved Searches'),
			'$add'		 => '',
			'$searchbox' => '',
			'$saved' 	 => $saved,
		));
	}

	return $o;

}


function search_init(&$a) {

	$search = ((x($_GET,'search')) ? notags(trim(rawurldecode($_GET['search']))) : '');

	if(local_user()) {
		if(x($_GET,'save') && $search) {
			$r = q("SELECT * FROM `search` WHERE `uid` = %d AND `term` = '%s' LIMIT 1",
				intval(local_user()),
				dbesc($search)
			);
			if(! count($r)) {
				q("INSERT INTO `search` (`uid`,`term`) VALUES ( %d, '%s')",
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
	else {
		unset($_SESSION['theme']);
		unset($_SESSION['mobile-theme']);
	}



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

	$o = '<h3>' . t('Search') . '</h3>';

	if(x($a->data,'search'))
		$search = notags(trim($a->data['search']));
	else
		$search = ((x($_GET,'search')) ? notags(trim(rawurldecode($_GET['search']))) : '');

	$tag = false;
	if(x($_GET,'tag')) {
		$tag = true;
		$search = ((x($_GET,'tag')) ? notags(trim(rawurldecode($_GET['tag']))) : '');
	}


	$o .= search($search,'search-box','/search',((local_user()) ? true : false));

	if(strpos($search,'#') === 0) {
		$tag = true;
		$search = substr($search,1);
	}
	if(strpos($search,'@') === 0) {
		require_once('mod/dirfind.php');
		return dirfind_content($a);
	}

	if(! $search)
		return $o;

	if (get_config('system','only_tag_search'))
		$tag = true;

	// Here is the way permissions work in the search module...
	// Only public posts can be shown
	// OR your own posts if you are a logged in member
	// No items will be shown if the member has a blocked profile wall.

	if($tag) {
		logger("Start tag search for '".$search."'", LOGGER_DEBUG);

		$r = q("SELECT STRAIGHT_JOIN `item`.`uri`, `item`.*, `item`.`id` AS `item_id`,
				`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`alias`, `contact`.`rel`,
				`contact`.`network`, `contact`.`thumb`, `contact`.`self`, `contact`.`writable`,
				`contact`.`id` AS `cid`, `contact`.`uid` AS `contact-uid`
			FROM `term`
				INNER JOIN `item` ON `item`.`id`=`term`.`oid`
				INNER JOIN `contact` ON `contact`.`id` = `item`.`contact-id` AND NOT `contact`.`blocked` AND NOT `contact`.`pending`
			WHERE `item`.`visible` AND NOT `item`.`deleted` AND NOT `item`.`moderated`
				AND (`term`.`uid` = 0 OR (`term`.`uid` = %d AND NOT `term`.`global`)) AND `term`.`otype` = %d AND `term`.`type` = %d AND `term`.`term` = '%s'
			ORDER BY term.created DESC LIMIT %d , %d ",
				intval(local_user()), intval(TERM_OBJ_POST), intval(TERM_HASHTAG), dbesc(protect_sprintf($search)),
				intval($a->pager['start']), intval($a->pager['itemspage']));
	} else {
		logger("Start fulltext search for '".$search."'", LOGGER_DEBUG);

		if (get_config('system','use_fulltext_engine')) {
			$sql_extra = sprintf(" AND MATCH (`item`.`body`, `item`.`title`) AGAINST ('%s' in boolean mode) ", dbesc(protect_sprintf($search)));
		} else {
			$sql_extra = sprintf(" AND `item`.`body` REGEXP '%s' ", dbesc(protect_sprintf(preg_quote($search))));
		}

		$r = q("SELECT STRAIGHT_JOIN `item`.`uri`, `item`.*, `item`.`id` AS `item_id`,
				`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`alias`, `contact`.`rel`,
				`contact`.`network`, `contact`.`thumb`, `contact`.`self`, `contact`.`writable`,
				`contact`.`id` AS `cid`, `contact`.`uid` AS `contact-uid`
			FROM `item`
				INNER JOIN `contact` ON `contact`.`id` = `item`.`contact-id` AND NOT `contact`.`blocked` AND NOT `contact`.`pending`
			WHERE `item`.`visible` AND NOT `item`.`deleted` AND NOT `item`.`moderated`
				AND (`item`.`uid` = 0 OR (`item`.`uid` = %s AND (`item`.`private` OR NOT `item`.`network` IN ('%s', '%s', '%s'))))
				$sql_extra
			GROUP BY `item`.`uri` ORDER BY `item`.`id` DESC LIMIT %d , %d ",
				intval(local_user()), dbesc(NETWORK_DFRN), dbesc(NETWORK_OSTATUS), dbesc(NETWORK_DIASPORA),
				intval($a->pager['start']), intval($a->pager['itemspage']));
	}

	if(! count($r)) {
		info( t('No results.') . EOL);
		return $o;
	}


	if($tag)
		$o .= '<h2>' . t('Items tagged with:')  . ' ' . $search . '</h2>';
	else
		$o .= '<h2>' . t('Search results for:') . ' ' . $search . '</h2>';

	logger("Start Conversation for '".$search."'", LOGGER_DEBUG);
	$o .= conversation($a,$r,'search',false);

	$o .= alt_pager($a,count($r));

	logger("Done '".$search."'", LOGGER_DEBUG);

	return $o;
}

