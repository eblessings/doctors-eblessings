<?php

require_once('include/datetime.php');

/*
 * poco_load
 *
 * Given a contact-id (minimum), load the PortableContacts friend list for that contact,
 * and add the entries to the gcontact (Global Contact) table, or update existing entries
 * if anything (name or photo) has changed.
 * We use normalised urls for comparison which ignore http vs https and www.domain vs domain
 *
 * Once the global contact is stored add (if necessary) the contact linkage which associates
 * the given uid, cid to the global contact entry. There can be many uid/cid combinations
 * pointing to the same global contact id. 
 *
 */
 



function poco_load($cid,$uid = 0,$zcid = 0,$url = null) {
	$a = get_app();

	if($cid) {
		if((! $url) || (! $uid)) {
			$r = q("select `poco`, `uid` from `contact` where `id` = %d limit 1",
				intval($cid)
			);
			if(count($r)) {
				$url = $r[0]['poco'];
				$uid = $r[0]['uid'];
			}
		}
		if(! $uid)
			return;
	}

	if(! $url)
		return;

	$url = $url . (($uid) ? '/@me/@all?fields=displayName,urls,photos' : '?fields=displayName,urls,photos,updated') ;

	logger('poco_load: ' . $url, LOGGER_DEBUG);

	$s = fetch_url($url);

	logger('poco_load: returns ' . $s, LOGGER_DATA);

	logger('poco_load: return code: ' . $a->get_curl_code(), LOGGER_DEBUG);

	if(($a->get_curl_code() > 299) || (! $s))
		return;

	$j = json_decode($s);

	logger('poco_load: json: ' . print_r($j,true),LOGGER_DATA);

	if(! isset($j->entry))
		return;

	$total = 0;
	foreach($j->entry as $entry) {

		$total ++;
		$profile_url = '';
		$profile_photo = '';
		$connect_url = '';
		$name = '';
		$updated = '0000-00-00 00:00:00';

		$name = $entry->displayName;

		if(isset($entry->urls)) {
			foreach($entry->urls as $url) {
				if($url->type == 'profile') {
					$profile_url = $url->value;
					continue;
				}
				if($url->type == 'webfinger') {
					$connect_url = str_replace('acct:' , '', $url->value);
					continue;
				}
			}
		}
		if(isset($entry->photos)) {
			foreach($entry->photos as $photo) {
				if($photo->type == 'profile') {
					$profile_photo = $photo->value;
					continue;
				}
			}
		}

		if(isset($entry->updated))
			$updated = date("Y-m-d H:i:s", strtotime($entry->updated));

		if((! $name) || (! $profile_url) || (! $profile_photo))
			continue;

		$x = q("SELECT * FROM `gcontact` WHERE `nurl` = '%s' LIMIT 1",
			dbesc(normalise_link($profile_url))
		);

		if(count($x)) {
			$gcid = $x[0]['id'];

			if($x[0]['name'] != $name || $x[0]['photo'] != $profile_photo || $x[0]['updated'] < $updated) {
				q("update gcontact set `name` = '%s', `photo` = '%s', `connect` = '%s', `url` = '%s', `updated` = '%s'
					where `nurl` = '%s'",
					dbesc($name),
					dbesc($profile_photo),
					dbesc($connect_url),
					dbesc($profile_url),
					dbesc($updated),
					dbesc(normalise_link($profile_url))
				);
			}
		} else {
			q("insert into `gcontact` (`name`,`url`,`nurl`,`photo`,`connect`, `updated`)
				values ( '%s', '%s', '%s', '%s','%s') ",
				dbesc($name),
				dbesc($profile_url),
				dbesc(normalise_link($profile_url)),
				dbesc($profile_photo),
				dbesc($connect_url),
				dbesc($updated)
			);
			$x = q("SELECT * FROM `gcontact` WHERE `nurl` = '%s' LIMIT 1",
				dbesc(normalise_link($profile_url))
			);
			if(count($x))
				$gcid = $x[0]['id'];
		}
		if(! $gcid)
			return;

		$r = q("SELECT * FROM `glink` WHERE `cid` = %d AND `uid` = %d AND `gcid` = %d AND `zcid` = %d LIMIT 1",
			intval($cid),
			intval($uid),
			intval($gcid),
			intval($zcid)
		);
		if(! count($r)) {
			q("INSERT INTO `glink` (`cid`,`uid`,`gcid`,`zcid`, `updated`) VALUES (%d,%d,%d,%d, '%s') ",
				intval($cid),
				intval($uid),
				intval($gcid),
				intval($zcid),
				dbesc(datetime_convert())
			);
		}
		else {
			q("UPDATE `glink` SET `updated` = '%s' WHERE `cid` = %d AND `uid` = %d AND `gcid` = %d AND `zcid` = %d",
				dbesc(datetime_convert()),
				intval($cid),
				intval($uid),
				intval($gcid),
				intval($zcid)
			);
		}

		// For unknown reasons there are sometimes duplicates
		q("DELETE FROM `gcontact` WHERE `nurl` = '%s' AND `id` != %d AND
			NOT EXISTS (SELECT `gcid` FROM `glink` WHERE `gcid` = `gcontact`.`id`)",
			dbesc(normalise_link($profile_url)),
			intval($gcid)
		);

	}
	logger("poco_load: loaded $total entries",LOGGER_DEBUG);

	q("DELETE FROM `glink` WHERE `cid` = %d AND `uid` = %d AND `zcid` = %d AND `updated` < UTC_TIMESTAMP - INTERVAL 2 DAY",
		intval($cid),
		intval($uid),
		intval($zcid)
	);

}


function count_common_friends($uid,$cid) {

	$r = q("SELECT count(*) as `total`
		FROM `glink` INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		where `glink`.`cid` = %d and `glink`.`uid` = %d
		and `gcontact`.`nurl` in (select nurl from contact where uid = %d and self = 0 and blocked = 0 and hidden = 0 and id != %d ) ",
		intval($cid),
		intval($uid),
		intval($uid),
		intval($cid)
	);

//	logger("count_common_friends: $uid $cid {$r[0]['total']}"); 
	if(count($r))
		return $r[0]['total'];
	return 0;

}


function common_friends($uid,$cid,$start = 0,$limit=9999,$shuffle = false) {

	if($shuffle)
		$sql_extra = " order by rand() ";
	else
		$sql_extra = " order by `gcontact`.`name` asc "; 

	$r = q("SELECT `gcontact`.* 
		FROM `glink` INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		where `glink`.`cid` = %d and `glink`.`uid` = %d
		and `gcontact`.`nurl` in (select nurl from contact where uid = %d and self = 0 and blocked = 0 and hidden = 0 and id != %d ) 
		$sql_extra limit %d, %d",
		intval($cid),
		intval($uid),
		intval($uid),
		intval($cid),
		intval($start),
		intval($limit)
	);

	return $r;

}


function count_common_friends_zcid($uid,$zcid) {

	$r = q("SELECT count(*) as `total` 
		FROM `glink` INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		where `glink`.`zcid` = %d
		and `gcontact`.`nurl` in (select nurl from contact where uid = %d and self = 0 and blocked = 0 and hidden = 0 ) ",
		intval($zcid),
		intval($uid)
	);

	if(count($r))
		return $r[0]['total'];
	return 0;

}

function common_friends_zcid($uid,$zcid,$start = 0, $limit = 9999,$shuffle = false) {

	if($shuffle)
		$sql_extra = " order by rand() ";
	else
		$sql_extra = " order by `gcontact`.`name` asc "; 

	$r = q("SELECT `gcontact`.* 
		FROM `glink` INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		where `glink`.`zcid` = %d
		and `gcontact`.`nurl` in (select nurl from contact where uid = %d and self = 0 and blocked = 0 and hidden = 0 ) 
		$sql_extra limit %d, %d",
		intval($zcid),
		intval($uid),
		intval($start),
		intval($limit)
	);

	return $r;

}


function count_all_friends($uid,$cid) {

	$r = q("SELECT count(*) as `total`
		FROM `glink` INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		where `glink`.`cid` = %d and `glink`.`uid` = %d ",
		intval($cid),
		intval($uid)
	);

	if(count($r))
		return $r[0]['total'];
	return 0;

}


function all_friends($uid,$cid,$start = 0, $limit = 80) {

	$r = q("SELECT `gcontact`.* 
		FROM `glink` INNER JOIN `gcontact` on `glink`.`gcid` = `gcontact`.`id`
		where `glink`.`cid` = %d and `glink`.`uid` = %d 
		order by `gcontact`.`name` asc LIMIT %d, %d ",
		intval($cid),
		intval($uid),
		intval($start),
		intval($limit)
	);

	return $r;
}



function suggestion_query($uid, $start = 0, $limit = 80) {

	if(! $uid)
		return array();

	$r = q("SELECT count(glink.gcid) as `total`, gcontact.* from gcontact
		INNER JOIN glink on glink.gcid = gcontact.id
		where uid = %d and not gcontact.nurl in ( select nurl from contact where uid = %d )
		and not gcontact.name in ( select name from contact where uid = %d )
		and not gcontact.id in ( select gcid from gcign where uid = %d )
		AND `gcontact`.`updated` != '0000-00-00 00:00:00'
		group by glink.gcid order by gcontact.updated desc,total desc limit %d, %d ",
		intval($uid),
		intval($uid),
		intval($uid),
		intval($uid),
		intval($start),
		intval($limit)
	);

	if(count($r) && count($r) >= ($limit -1))
		return $r;

	$r2 = q("SELECT gcontact.* from gcontact
		INNER JOIN glink on glink.gcid = gcontact.id
		where glink.uid = 0 and glink.cid = 0 and glink.zcid = 0 and not gcontact.nurl in ( select nurl from contact where uid = %d )
		and not gcontact.name in ( select name from contact where uid = %d )
		and not gcontact.id in ( select gcid from gcign where uid = %d )
		AND `gcontact`.`updated` != '0000-00-00 00:00:00'
		order by rand() limit %d, %d ",
		intval($uid),
		intval($uid),
		intval($uid),
		intval($start),
		intval($limit)
	);

	$list = array();
	foreach ($r2 AS $suggestion)
		$list[$suggestion["nurl"]] = $suggestion;

	foreach ($r AS $suggestion)
		$list[$suggestion["nurl"]] = $suggestion;

	return $list;
}

function update_suggestions() {

	$a = get_app();

	$done = array();

	poco_load(0,0,0,$a->get_baseurl() . '/poco');

	$done[] = $a->get_baseurl() . '/poco';

	if(strlen(get_config('system','directory_submit_url'))) {
		$x = fetch_url('http://dir.friendica.com/pubsites');
		if($x) {
			$j = json_decode($x);
			if($j->entries) {
				foreach($j->entries as $entry) {
					$url = $entry->url . '/poco';
					if(! in_array($url,$done))
						poco_load(0,0,0,$entry->url . '/poco');
				}
			}
		}
	}

	$r = q("select distinct(poco) as poco from contact where network = '%s'",
		dbesc(NETWORK_DFRN)
	);

	if(count($r)) {
		foreach($r as $rr) {
			$base = substr($rr['poco'],0,strrpos($rr['poco'],'/'));
			if(! in_array($base,$done))
				poco_load(0,0,0,$base);
		}
	}
}
