<?php


function group_add($uid,$name) {

	$ret = false;
	if(x($uid) && x($name)) {
		$r = group_byname($uid,$name); // check for dups
		if($r !== false) {

			// This could be a problem. 
			// Let's assume we've just created a group which we once deleted
			// all the old members are gone, but the group remains so we don't break any security
			// access lists. What we're doing here is reviving the dead group, but old content which
			// was restricted to this group may now be seen by the new group members. 

			$z = q("SELECT * FROM `group` WHERE `id` = %d LIMIT 1",
				intval($r)
			);
			if(count($z) && $z[0]['deleted']) {
				$r = q("UPDATE `group` SET `deleted` = 0 WHERE `uid` = %d AND `name` = '%s' LIMIT 1",
					intval($uid),
					dbesc($name)
				);
				notice( t('A deleted group with this name was revived. Existing item permissions <strong>may</strong> apply to this group and any future members. If this is not what you intended, please create another group with a different name.') . EOL); 
			}
			return true;
		}
		$r = q("INSERT INTO `group` ( `uid`, `name` )
			VALUES( %d, '%s' ) ",
			intval($uid),
			dbesc($name)
		);
		$ret = $r;
	}	
	return $ret;
}


function group_rmv($uid,$name) {
	$ret = false;
	if(x($uid) && x($name)) {
		$r = q("SELECT * FROM `group` WHERE `uid` = %d AND `name` = '%s' LIMIT 1",
			intval($uid),
			dbesc($name)
		);
		if(count($r))
			$group_id = $r[0]['id'];
		if(! $group_id)
			return false;

		// remove all members
		$r = q("DELETE FROM `group_member` WHERE `uid` = %d AND `gid` = %d ",
			intval($uid),
			intval($group_id)
		);

		// remove group
		$r = q("UPDATE `group` SET `deleted` = 1 WHERE `uid` = %d AND `name` = '%s' LIMIT 1",
			intval($uid),
			dbesc($name)
		);

		$ret = $r;

	}

	return $ret;
}

function group_byname($uid,$name) {
	if((! $uid) || (! strlen($name)))
		return false;
	$r = q("SELECT * FROM `group` WHERE `uid` = %d AND `name` = '%s' LIMIT 1",
		intval($uid),
		dbesc($name)
	);
	if(count($r))
		return $r[0]['id'];
	return false;
}

function group_rmv_member($uid,$name,$member) {
	$gid = group_byname($uid,$name);
	if(! $gid)
		return false;
	if(! ( $uid && $gid && $member))
		return false;
	$r = q("DELETE FROM `group_member` WHERE `uid` = %d AND `gid` = %d AND `contact-id` = %d LIMIT 1 ",
		intval($uid),
		intval($gid),
		intval($member)
	);
	return $r;
	

}


function group_add_member($uid,$name,$member) {
	$gid = group_byname($uid,$name);
	if((! $gid) || (! $uid) || (! $member))
		return false;

	$r = q("SELECT * FROM `group_member` WHERE `uid` = %d AND `id` = %d AND `contact-id` = %d LIMIT 1",	
		intval($uid),
		intval($gid),
		intval($member)
	);
	if(count($r))
		return true;	// You might question this, but 
				// we indicate success because the group was in fact created
				// -- It was just created at another time
 	if(! count($r))
		$r = q("INSERT INTO `group_member` (`uid`, `gid`, `contact-id`)
			VALUES( %d, %d, %d ) ",
			intval($uid),
			intval($gid),
			intval($member)
	);
	return $r;
}

function group_get_members($gid) {
	$ret = array();
	if(intval($gid)) {
		$r = q("SELECT `group_member`.`contact-id`, `contact`.* FROM `group_member` 
			LEFT JOIN `contact` ON `contact`.`id` = `group_member`.`contact-id` 
			WHERE `gid` = %d AND `group_member`.`uid` = %d ORDER BY `contact`.`name` ASC ",
			intval($gid),
			intval(local_user())
		);
		if(count($r))
			$ret = $r;
	}
	return $ret;
}

function group_public_members($gid) {
	$ret = 0;
	if(intval($gid)) {
		$r = q("SELECT `contact`.`id` AS `contact-id` FROM `group_member` 
			LEFT JOIN `contact` ON `contact`.`id` = `group_member`.`contact-id` 
			WHERE `gid` = %d AND `group_member`.`uid` = %d 
			AND  `contact`.`network` = '%s' AND `contact`.`notify` != '' ",
			intval($gid),
			intval(local_user()),
			dbesc(NETWORK_OSTATUS)
		);		
		if(count($r))
			$ret = count($r);
	}
	return $ret;
}



function group_side($every="contacts",$each="group",$edit = false, $group_id = 0, $cid = 0) {

	$o = '';

	if(! local_user())
		return '';

	$createtext = t('Create a new group');
	$linktext= t('Everybody');
	$selected = (($group_id == 0) ? ' group-selected' : '');
$o .= <<< EOT

<div id="group-sidebar" class="widget">
<h3>Groups</h3>

<div id="sidebar-group-list">
	<ul id="sidebar-group-ul">
	<li class="sidebar-group-li" ><a href="$every" class="sidebar-group-element$selected" >$linktext</a></li>

EOT;

	$r = q("SELECT * FROM `group` WHERE `deleted` = 0 AND `uid` = %d ORDER BY `name` ASC",
		intval($_SESSION['uid'])
	);
	if($cid) {
		$member_of = groups_containing(local_user(),$cid);
	} 

	if(count($r)) {
		foreach($r as $rr) {
			$selected = (($group_id == $rr['id']) ? ' group-selected' : '');
			$o .= '	<li class="sidebar-group-li">' 
			. (($edit) ? "<a href=\"group/{$rr['id']}\" title=\"" . t('Edit') 
				. "\" class=\"groupsideedit\" ><img src=\"images/spencil.gif\" alt=\"" . t('Edit') . "\"></a> " : "") 
			. (($cid) ? '<input type="checkbox" class="' . (($selected) ? 'ticked' : 'unticked') . '" onclick="contactgroupChangeMember(' . $rr['id'] . ',' . $cid . ');return true;" '
				. ((in_array($rr['id'],$member_of)) ? ' checked="checked" ' : '') . '/>' : '')
			. "<a href=\"$each/{$rr['id']}\" class=\"sidebar-group-element" . $selected ."\"  >{$rr['name']}</a></li>\r\n";
		}
	}
	$o .= "	</ul>\r\n	</div>";

	$o .= <<< EOT

  <div id="sidebar-new-group">
  <a href="group/new">$createtext</a>
  </div>
</div>
	
EOT;

	return $o;
}

function expand_groups($a) {
	if(! (is_array($a) && count($a)))
		return array();
	$groups = implode(',', $a);
	$groups = dbesc($groups);
	$r = q("SELECT `contact-id` FROM `group_member` WHERE `gid` IN ( $groups )");
	$ret = array();
	if(count($r))
		foreach($r as $rr)
			$ret[] = $rr['contact-id'];
	return $ret;
}


function member_of($c) {

	$r = q("SELECT `group`.`name`, `group`.`id` FROM `group` LEFT JOIN `group_member` ON `group_member`.`gid` = `group`.`id` WHERE `group_member`.`contact-id` = %d AND `group`.`deleted` = 0 ORDER BY `group`.`name`  ASC ",
		intval($c)
	);

	return $r;

}

function groups_containing($uid,$c) {

	$r = q("SELECT `gid` FROM `group_member` WHERE `uid` = %d AND `group_member`.`contact-id` = %d ",
		intval($uid),
		intval($c)
	);

	$ret = array();
	if(count($r)) {
		foreach($r as $rr)
			$ret[] = $rr['gid'];
	}

	return $ret;
}