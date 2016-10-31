<?php
/**
 * @file include/dbclean.php
 * @brief The script is called from time to time to clean the database entries and remove orphaned data.
 */
require_once("boot.php");

function dbclean_run(&$argv, &$argc) {
	global $a, $db;

	if (is_null($a))
		$a = new App;

	if (is_null($db)) {
		@include(".htconfig.php");
		require_once("include/dba.php");
		$db = new dba($db_host, $db_user, $db_pass, $db_data);
		unset($db_host, $db_user, $db_pass, $db_data);
	}

	load_config('config');
	load_config('system');

	if ($argc == 2) {
		$stage = intval($argv[1]);
	} else {
		$stage = 0;
	}
	remove_orphans($stage);
}

/**
 * @brief Remove orphaned database entries
 */
function remove_orphans($stage = 0) {
	global $db;

	if (($stage == 1) OR ($stage == 0)) {
		logger("Deleting old global item entries from item table without user copy");
		if ($db->q("SELECT `id` FROM `item` WHERE `uid` = 0
				AND NOT EXISTS (SELECT `guid` FROM `item` AS `i` WHERE `item`.`guid` = `i`.`guid` AND `i`.`uid` != 0)
				AND `received` < UTC_TIMESTAMP() - INTERVAL 90 DAY LIMIT 10000", true)) {
			$count = $db->num_rows();
			logger("found global item orphans: ".$count);
			while ($orphan = $db->qfetch()) {
				q("DELETE FROM `item` WHERE `id` = %d", intval($orphan["id"]));
			}

			// Call it again if not all entries were purged
			if (($count > 0) AND get_config("system", "worker")) {
				proc_run(PRIORITY_LOW, 'include/dbclean.php', 1);
			}
		}
		$db->qclose();
		logger("Done deleting old global item entries from item table without user copy");
	}

	if (($stage == 2) OR ($stage == 0)) {
		logger("Deleting items without parents");
		if ($db->q("SELECT `id` FROM `item` WHERE NOT EXISTS (SELECT `id` FROM `item` AS `i` WHERE `item`.`parent` = `i`.`id`) LIMIT 10000", true)) {
			$count = $db->num_rows();
			logger("found item orphans without parents: ".$count);
			while ($orphan = $db->qfetch()) {
				q("DELETE FROM `item` WHERE `id` = %d", intval($orphan["id"]));
			}

			// Call it again if not all entries were purged
			if (($count > 0) AND get_config("system", "worker")) {
				proc_run(PRIORITY_LOW, 'include/dbclean.php', 2);
			}
		}
		$db->qclose();
		logger("Done deleting items without parents");
	}

	if (($stage == 3) OR ($stage == 0)) {
		logger("Deleting orphaned data from thread table");
		if ($db->q("SELECT `iid` FROM `thread` WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`parent` = `thread`.`iid`)", true)) {
			$count = $db->num_rows();
			logger("found thread orphans: ".$count);
			while ($orphan = $db->qfetch()) {
				q("DELETE FROM `thread` WHERE `iid` = %d", intval($orphan["iid"]));
			}

			// Call it again if not all entries were purged
			if (($count > 0) AND get_config("system", "worker")) {
				proc_run(PRIORITY_LOW, 'include/dbclean.php', 3);
			}
		}
		$db->qclose();
		logger("Done deleting orphaned data from thread table");
	}

	if (($stage == 4) OR ($stage == 0)) {
		logger("Deleting orphaned data from notify table");
		if ($db->q("SELECT `iid` FROM `notify` WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`id` = `notify`.`iid`)", true)) {
			$count = $db->num_rows();
			logger("found notify orphans: ".$count);
			while ($orphan = $db->qfetch()) {
				q("DELETE FROM `notify` WHERE `iid` = %d", intval($orphan["iid"]));
			}

			// Call it again if not all entries were purged
			if (($count > 0) AND get_config("system", "worker")) {
				proc_run(PRIORITY_LOW, 'include/dbclean.php', 4);
			}
		}
		$db->qclose();
		logger("Done deleting orphaned data from notify table");
	}

	if (($stage == 5) OR ($stage == 0)) {
		logger("Deleting orphaned data from notify-threads table");
		if ($db->q("SELECT `id` FROM `notify-threads` WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`parent` = `notify-threads`.`master-parent-item`)", true)) {
			$count = $db->num_rows();
			logger("found notify-threads orphans: ".$count);
			while ($orphan = $db->qfetch()) {
				q("DELETE FROM `notify-threads` WHERE `id` = %d", intval($orphan["id"]));
			}

			// Call it again if not all entries were purged
			if (($count > 0) AND get_config("system", "worker")) {
				proc_run(PRIORITY_LOW, 'include/dbclean.php', 5);
			}
		}
		$db->qclose();
		logger("Done deleting orphaned data from notify-threads table");
	}


	if (($stage == 6) OR ($stage == 0)) {
		logger("Deleting orphaned data from sign table");
		if ($db->q("SELECT `iid` FROM `sign` WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`id` = `sign`.`iid`)", true)) {
			$count = $db->num_rows();
			logger("found sign orphans: ".$count);
			while ($orphan = $db->qfetch()) {
				q("DELETE FROM `sign` WHERE `iid` = %d", intval($orphan["iid"]));
			}

			// Call it again if not all entries were purged
			if (($count > 0) AND get_config("system", "worker")) {
				proc_run(PRIORITY_LOW, 'include/dbclean.php', 6);
			}
		}
		$db->qclose();
		logger("Done deleting orphaned data from sign table");
	}


	if (($stage == 7) OR ($stage == 0)) {
		logger("Deleting orphaned data from term table");
		if ($db->q("SELECT `oid` FROM `term` WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`id` = `term`.`oid`)", true)) {
			$count = $db->num_rows();
			logger("found term orphans: ".$count);
			while ($orphan = $db->qfetch()) {
				q("DELETE FROM `term` WHERE `oid` = %d", intval($orphan["oid"]));
			}

			// Call it again if not all entries were purged
			if (($count > 0) AND get_config("system", "worker")) {
				proc_run(PRIORITY_LOW, 'include/dbclean.php', 7);
			}
		}
		$db->qclose();
		logger("Done deleting orphaned data from term table");
	}
}

if (array_search(__file__,get_included_files())===0){
  dbclean_run($_SERVER["argv"],$_SERVER["argc"]);
  killme();
}
?>
