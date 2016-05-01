<?php
require_once('include/contact_widgets.php');
require_once('include/socgraph.php');
require_once('include/Contact.php');
require_once('include/contact_selectors.php');
require_once('mod/contacts.php');

function dirfind_init(&$a) {

	if(! local_user()) {
		notice( t('Permission denied.') . EOL );
		return;
	}

	if(! x($a->page,'aside'))
		$a->page['aside'] = '';

	$a->page['aside'] .= findpeople_widget();

	$a->page['aside'] .= follow_widget();
}



function dirfind_content(&$a, $prefix = "") {

	$community = false;
	$discover_user = false;

	$local = get_config('system','poco_local_search');

	$search = $prefix.notags(trim($_REQUEST['search']));

	if(strpos($search,'@') === 0) {
		$search = substr($search,1);
		if ((valid_email($search) AND validate_email($search)) OR
			(substr(normalise_link($search), 0, 7) == "http://")) {
			$user_data = probe_url($search);
			$discover_user = (in_array($user_data["network"], array(NETWORK_DFRN, NETWORK_OSTATUS, NETWORK_DIASPORA)));
		}
	}

	if(strpos($search,'!') === 0) {
		$search = substr($search,1);
		$community = true;
	}

	$o = '';

	if($search) {

		if ($discover_user) {
			$j = new stdClass();
			$j->total = 1;
			$j->items_page = 1;
			$j->page = $a->pager['page'];

			$objresult = new stdClass();
			$objresult->cid = 0;
			$objresult->name = $user_data["name"];
			$objresult->addr = $user_data["addr"];
			$objresult->url = $user_data["url"];
			$objresult->photo = $user_data["photo"];
			$objresult->tags = "";
			$objresult->network = $user_data["network"];

			$contact = q("SELECT `id` FROM `contact` WHERE `nurl` = '%s' AND `uid` = %d LIMIT 1",
					dbesc(normalise_link($user_data["url"])), intval(local_user()));
			if ($contact)
				$objresult->cid = $contact[0]["id"];


			$j->results[] = $objresult;

			poco_check($user_data["url"], $user_data["name"], $user_data["network"], $user_data["photo"],
				"", "", "", "", "", datetime_convert(), 0);
		} elseif ($local) {

			if ($community)
				$extra_sql = " AND `community`";
			else
				$extra_sql = "";

			$perpage = 80;
			$startrec = (($a->pager['page']) * $perpage) - $perpage;

			if (get_config('system','diaspora_enabled'))
				$diaspora = NETWORK_DIASPORA;
			else
				$diaspora = NETWORK_DFRN;

			if (!get_config('system','ostatus_disabled'))
				$ostatus = NETWORK_OSTATUS;
			else
				$ostatus = NETWORK_DFRN;

			$count = q("SELECT count(*) AS `total` FROM `gcontact`
					LEFT JOIN `contact` ON `contact`.`nurl` = `gcontact`.`nurl`
						AND `contact`.`uid` = %d AND NOT `contact`.`blocked`
						AND NOT `contact`.`pending` AND `contact`.`rel` IN ('%s', '%s')
					WHERE (`contact`.`id` > 0 OR (NOT `gcontact`.`hide` AND `gcontact`.`network` IN ('%s', '%s', '%s') AND
					((`gcontact`.`last_contact` >= `gcontact`.`last_failure`) OR (`gcontact`.`updated` >= `gcontact`.`last_failure`)))) AND
					(`gcontact`.`url` REGEXP '%s' OR `gcontact`.`name` REGEXP '%s' OR `gcontact`.`location` REGEXP '%s' OR
						`gcontact`.`addr` REGEXP '%s' OR `gcontact`.`about` REGEXP '%s' OR `gcontact`.`keywords` REGEXP '%s') $extra_sql",
					intval(local_user()), dbesc(CONTACT_IS_SHARING), dbesc(CONTACT_IS_FRIEND),
					dbesc(NETWORK_DFRN), dbesc($ostatus), dbesc($diaspora),
					dbesc(escape_tags($search)), dbesc(escape_tags($search)), dbesc(escape_tags($search)),
					dbesc(escape_tags($search)), dbesc(escape_tags($search)), dbesc(escape_tags($search)));

			$results = q("SELECT `contact`.`id` AS `cid`, `gcontact`.`url`, `gcontact`.`name`, `gcontact`.`photo`, `gcontact`.`network`, `gcontact`.`keywords`, `gcontact`.`addr`
					FROM `gcontact`
					LEFT JOIN `contact` ON `contact`.`nurl` = `gcontact`.`nurl`
						AND `contact`.`uid` = %d AND NOT `contact`.`blocked`
						AND NOT `contact`.`pending` AND `contact`.`rel` IN ('%s', '%s')
					WHERE (`contact`.`id` > 0 OR (NOT `gcontact`.`hide` AND `gcontact`.`network` IN ('%s', '%s', '%s') AND
					((`gcontact`.`last_contact` >= `gcontact`.`last_failure`) OR (`gcontact`.`updated` >= `gcontact`.`last_failure`)))) AND
					(`gcontact`.`url` REGEXP '%s' OR `gcontact`.`name` REGEXP '%s' OR `gcontact`.`location` REGEXP '%s' OR
						`gcontact`.`addr` REGEXP '%s' OR `gcontact`.`about` REGEXP '%s' OR `gcontact`.`keywords` REGEXP '%s') $extra_sql
						GROUP BY `gcontact`.`nurl`
						ORDER BY `gcontact`.`updated` DESC LIMIT %d, %d",
					intval(local_user()), dbesc(CONTACT_IS_SHARING), dbesc(CONTACT_IS_FRIEND),
					dbesc(NETWORK_DFRN), dbesc($ostatus), dbesc($diaspora),
					dbesc(escape_tags($search)), dbesc(escape_tags($search)), dbesc(escape_tags($search)),
					dbesc(escape_tags($search)), dbesc(escape_tags($search)), dbesc(escape_tags($search)),
					intval($startrec), intval($perpage));
			$j = new stdClass();
			$j->total = $count[0]["total"];
			$j->items_page = $perpage;
			$j->page = $a->pager['page'];
			foreach ($results AS $result) {
				if (poco_alternate_ostatus_url($result["url"]))
					 continue;

				if ($result["name"] == "") {
					$urlparts = parse_url($result["url"]);
					$result["name"] = end(explode("/", $urlparts["path"]));
				}

				$objresult = new stdClass();
				$objresult->cid = $result["cid"];
				$objresult->name = $result["name"];
				$objresult->addr = $result["addr"];
				$objresult->url = $result["url"];
				$objresult->photo = $result["photo"];
				$objresult->tags = $result["keywords"];
				$objresult->network = $result["network"];

				$j->results[] = $objresult;
			}

			// Add found profiles from the global directory to the local directory
			proc_run('php','include/discover_poco.php', "dirsearch", urlencode($search));
		} else {

			$p = (($a->pager['page'] != 1) ? '&p=' . $a->pager['page'] : '');

			if(strlen(get_config('system','directory')))
				$x = fetch_url(get_server().'/lsearch?f=' . $p .  '&search=' . urlencode($search));

			$j = json_decode($x);
		}

		if($j->total) {
			$a->set_pager_total($j->total);
			$a->set_pager_itemspage($j->items_page);
		}

		if(count($j->results)) {

			$id = 0;

			foreach($j->results as $jj) {

				$alt_text = "";

				$contact_details = get_contact_details_by_url($jj->url, local_user());

				$itemurl = (($contact_details["addr"] != "") ? $contact_details["addr"] : $jj->url);

				// If We already know this contact then don't show the "connect" button
				if ($jj->cid > 0) {
					$connlnk = "";
					$conntxt = "";
					$contact = q("SELECT * FROM `contact` WHERE `id` = %d",
							intval($jj->cid));
					if ($contact) {
						$photo_menu = contact_photo_menu($contact[0]);
						$details = _contact_detail_for_template($contact[0]);
						$alt_text = $details['alt_text'];
					} else
						$photo_menu = array();
				} else {
					$connlnk = $a->get_baseurl().'/follow/?url='.(($jj->connect) ? $jj->connect : $jj->url);
					$conntxt = t('Connect');
					$photo_menu = array(array(t("View Profile"), zrl($jj->url)));
					$photo_menu[] = array(t("Connect/Follow"), $connlnk);
				}

				$jj->photo = str_replace("http:///photo/", get_server()."/photo/", $jj->photo);

				$entry = array(
					'alt_text' => $alt_text,
					'url' => zrl($jj->url),
					'itemurl' => $itemurl,
					'name' => htmlentities($jj->name),
					'thumb' => proxy_url($jj->photo, false, PROXY_SIZE_THUMB),
					'img_hover' => $jj->tags,
					'conntxt' => $conntxt,
					'connlnk' => $connlnk,
					'photo_menu' => $photo_menu,
					'details'       => $contact_details['location'],
					'tags'          => $contact_details['keywords'],
					'about'         => $contact_details['about'],
					'account_type'  => (($contact_details['community']) ? t('Forum') : ''),
					'network' => network_to_name($jj->network, $jj->url),
					'id' => ++$id,
				);
				$entries[] = $entry;
			}

		$tpl = get_markup_template('viewcontact_template.tpl');

		$o .= replace_macros($tpl,array(
			'title' => sprintf( t('People Search - %s'), $search),
			'$contacts' => $entries,
			'$paginate' => paginate($a),
		));

		}
		else {
			info( t('No matches') . EOL);
		}

	}

	return $o;
}
