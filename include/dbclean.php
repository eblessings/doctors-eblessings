<?php
require_once("boot.php");

global $a, $db;

if(is_null($a))
	$a = new App;

if(is_null($db)) {
	@include(".htconfig.php");
	require_once("include/dba.php");
	$db = new dba($db_host, $db_user, $db_pass, $db_data);
	unset($db_host, $db_user, $db_pass, $db_data);
}

load_config('config');
load_config('system');

remove_orphans();
killme();

function remove_orphans() {
	 global $db;

	logger("Deleting orphaned data from thread table");
	if ($db->q("SELECT `iid` FROM `thread` WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`parent` = `thread`.`iid`)", true)) {
		logger("found thread orphans: ".$db->num_rows());
		while ($orphan = $db->qfetch())
			q("DELETE FROM `thread` WHERE `iid` = %d", intval($orphan["iid"]));
	}
	$db->qclose();


	logger("Deleting orphaned data from notify table");
	if ($db->q("SELECT `iid` FROM `notify` WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`id` = `notify`.`iid`)", true)) {
		logger("found notify orphans: ".$db->num_rows());
		while ($orphan = $db->qfetch())
			q("DELETE FROM `notify` WHERE `iid` = %d", intval($orphan["iid"]));
	}
	$db->qclose();


	logger("Deleting orphaned data from sign table");
	if ($db->q("SELECT `iid` FROM `sign` WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`id` = `sign`.`iid`)", true)) {
		logger("found sign orphans: ".$db->num_rows());
		while ($orphan = $db->qfetch())
			q("DELETE FROM `sign` WHERE `iid` = %d", intval($orphan["iid"]));
	}
	$db->qclose();


	logger("Deleting orphaned data from term table");
	if ($db->q("SELECT `oid` FROM `term` WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`id` = `term`.`oid`)", true)) {
		logger("found term orphans: ".$db->num_rows());
		while ($orphan = $db->qfetch())
			q("DELETE FROM `term` WHERE `oid` = %d", intval($orphan["oid"]));
	}
	$db->qclose();

// SELECT `id`, `received`, `created`, `guid` FROM `item` WHERE `uid` = 0 AND NOT EXISTS (SELECT `guid` FROM `item` AS `i` WHERE `item`.`guid` = `i`.`guid` AND `i`.`uid` != 0) LIMIT 1;

	logger("Done deleting orphaned data from tables");
}
?>
