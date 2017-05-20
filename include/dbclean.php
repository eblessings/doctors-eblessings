<?php
/**
 * @file include/dbclean.php
 * @brief The script is called from time to time to clean the database entries and remove orphaned data.
 */

use Friendica\Core\Config;

function dbclean_run(&$argv, &$argc) {
	if (!Config::get('system', 'dbclean', false)) {
		return;
	}

	if ($argc == 2) {
		$stage = intval($argv[1]);
	} else {
		$stage = 0;
	}

	// Get the expire days for step 8 and 9
	$days = Config::get('system', 'dbclean-expire-days', 0);

	if ($stage == 0) {
		for ($i = 1; $i <= 9; $i++) {
			// Execute the background script for a step when it isn't finished.
			// Execute step 8 and 9 only when $days is defined.
			if (!Config::get('system', 'finished-dbclean-'.$i, false) AND (($i < 8) OR ($days > 0))) {
				proc_run(PRIORITY_LOW, 'include/dbclean.php', $i);
			}
		}
	} else {
		remove_orphans($stage);
	}
}

/**
 * @brief Remove orphaned database entries
 */
function remove_orphans($stage = 0) {
	global $db;

	$count = 0;

	// We split the deletion in many small tasks
	$limit = 1000;

	// Get the expire days for step 8 and 9
	$days = Config::get('system', 'dbclean-expire-days', 0);

	if ($stage == 1) {
		$last_id = Config::get('system', 'dbclean-last-id-1', 0);

		logger("Deleting old global item entries from item table without user copy. Last ID: ".$last_id);
		$r = dba::p("SELECT `id` FROM `item` WHERE `uid` = 0 AND
					NOT EXISTS (SELECT `guid` FROM `item` AS `i` WHERE `item`.`guid` = `i`.`guid` AND `i`.`uid` != 0) AND
					`received` < UTC_TIMESTAMP() - INTERVAL 90 DAY AND `id` >= ?
				ORDER BY `id` LIMIT ".intval($limit), $last_id);
		$count = dba::num_rows($r);
		if ($count > 0) {
			logger("found global item orphans: ".$count);
			while ($orphan = dba::fetch($r)) {
				$last_id = $orphan["id"];
				dba::delete('item', array('id' => $orphan["id"]));
			}
		} else {
			logger("No global item orphans found");
		}
		dba::close($r);
		logger("Done deleting ".$count." old global item entries from item table without user copy. Last ID: ".$last_id);

		Config::set('system', 'dbclean-last-id-1', $last_id);
	} elseif ($stage == 2) {
		$last_id = Config::get('system', 'dbclean-last-id-2', 0);

		logger("Deleting items without parents. Last ID: ".$last_id);
		$r = dba::p("SELECT `id` FROM `item`
				WHERE NOT EXISTS (SELECT `id` FROM `item` AS `i` WHERE `item`.`parent` = `i`.`id`)
				AND `id` >= ? ORDER BY `id` LIMIT ".intval($limit), $last_id);
		$count = dba::num_rows($r);
		if ($count > 0) {
			logger("found item orphans without parents: ".$count);
			while ($orphan = dba::fetch($r)) {
				$last_id = $orphan["id"];
				dba::delete('item', array('id' => $orphan["id"]));
			}
		} else {
			logger("No item orphans without parents found");
		}
		dba::close($r);
		logger("Done deleting ".$count." items without parents. Last ID: ".$last_id);

		Config::set('system', 'dbclean-last-id-2', $last_id);

		if ($count < $limit) {
			Config::set('system', 'finished-dbclean-2', true);
		}
	} elseif ($stage == 3) {
		$last_id = Config::get('system', 'dbclean-last-id-3', 0);

		logger("Deleting orphaned data from thread table. Last ID: ".$last_id);
		$r = dba::p("SELECT `iid` FROM `thread`
				WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`parent` = `thread`.`iid`) AND `iid` >= ?
				ORDER BY `iid` LIMIT ".intval($limit), $last_id);
		$count = dba::num_rows($r);
		if ($count > 0) {
			logger("found thread orphans: ".$count);
			while ($orphan = dba::fetch($r)) {
				$last_id = $orphan["iid"];
				dba::delete('thread', array('iid' => $orphan["iid"]));
			}
		} else {
			logger("No thread orphans found");
		}
		dba::close($r);
		logger("Done deleting ".$count." orphaned data from thread table. Last ID: ".$last_id);

		Config::set('system', 'dbclean-last-id-3', $last_id);

		if ($count < $limit) {
			Config::set('system', 'finished-dbclean-3', true);
		}
	} elseif ($stage == 4) {
		$last_id = Config::get('system', 'dbclean-last-id-4', 0);

		logger("Deleting orphaned data from notify table. Last ID: ".$last_id);
		$r = dba::p("SELECT `iid`, `id` FROM `notify`
				WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`id` = `notify`.`iid`) AND `id` >= ?
				ORDER BY `id` LIMIT ".intval($limit), $last_id);
		$count = dba::num_rows($r);
		if ($count > 0) {
			logger("found notify orphans: ".$count);
			while ($orphan = dba::fetch($r)) {
				$last_id = $orphan["id"];
				dba::delete('notify', array('iid' => $orphan["iid"]));
			}
		} else {
			logger("No notify orphans found");
		}
		dba::close($r);
		logger("Done deleting ".$count." orphaned data from notify table. Last ID: ".$last_id);

		Config::set('system', 'dbclean-last-id-4', $last_id);

		if ($count < $limit) {
			Config::set('system', 'finished-dbclean-4', true);
		}
	} elseif ($stage == 5) {
		$last_id = Config::get('system', 'dbclean-last-id-5', 0);

		logger("Deleting orphaned data from notify-threads table. Last ID: ".$last_id);
		$r = dba::p("SELECT `id` FROM `notify-threads`
				WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`parent` = `notify-threads`.`master-parent-item`) AND `id` >= ?
				ORDER BY `id` LIMIT ".intval($limit), $last_id);
		$count = dba::num_rows($r);
		if ($count > 0) {
			logger("found notify-threads orphans: ".$count);
			while ($orphan = dba::fetch($r)) {
				$last_id = $orphan["id"];
				dba::delete('notify-threads', array('id' => $orphan["id"]));
			}
		} else {
			logger("No notify-threads orphans found");
		}
		dba::close($r);
		logger("Done deleting ".$count." orphaned data from notify-threads table. Last ID: ".$last_id);

		Config::set('system', 'dbclean-last-id-5', $last_id);

		if ($count < $limit) {
			Config::set('system', 'finished-dbclean-5', true);
		}
	} elseif ($stage == 6) {
		$last_id = Config::get('system', 'dbclean-last-id-6', 0);

		logger("Deleting orphaned data from sign table. Last ID: ".$last_id);
		$r = dba::p("SELECT `iid`, `id` FROM `sign`
				WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`id` = `sign`.`iid`) AND `id` >= ?
				ORDER BY `id` LIMIT ".intval($limit), $last_id);
		$count = dba::num_rows($r);
		if ($count > 0) {
			logger("found sign orphans: ".$count);
			while ($orphan = dba::fetch($r)) {
				$last_id = $orphan["id"];
				dba::delete('sign', array('iid' => $orphan["iid"]));
			}
		} else {
			logger("No sign orphans found");
		}
		dba::close($r);
		logger("Done deleting ".$count." orphaned data from sign table. Last ID: ".$last_id);

		Config::set('system', 'dbclean-last-id-6', $last_id);

		if ($count < $limit) {
			Config::set('system', 'finished-dbclean-6', true);
		}
	} elseif ($stage == 7) {
		$last_id = Config::get('system', 'dbclean-last-id-7', 0);

		logger("Deleting orphaned data from term table. Last ID: ".$last_id);
		$r = dba::p("SELECT `oid`, `tid` FROM `term`
				WHERE NOT EXISTS (SELECT `id` FROM `item` WHERE `item`.`id` = `term`.`oid`) AND `tid` >= ?
				ORDER BY `tid` LIMIT ".intval($limit), $last_id);
		$count = dba::num_rows($r);
		if ($count > 0) {
			logger("found term orphans: ".$count);
			while ($orphan = dba::fetch($r)) {
				$last_id = $orphan["tid"];
				dba::delete('term', array('oid' => $orphan["oid"]));
			}
		} else {
			logger("No term orphans found");
		}
		dba::close($r);
		logger("Done deleting ".$count." orphaned data from term table. Last ID: ".$last_id);

		Config::set('system', 'dbclean-last-id-7', $last_id);

		if ($count < $limit) {
			Config::set('system', 'finished-dbclean-7', true);
		}
	} elseif ($stage == 8) {
		if ($days <= 0) {
			return;
		}

		$last_id = Config::get('system', 'dbclean-last-id-8', 0);

		logger("Deleting expired threads. Last ID: ".$last_id);
		$r = dba::p("SELECT `thread`.`iid` FROM `thread`
                                INNER JOIN `contact` ON `thread`.`contact-id` = `contact`.`id` AND NOT `notify_new_posts`
                                WHERE `thread`.`received` < UTC_TIMESTAMP() - INTERVAL ? DAY
                                        AND NOT `thread`.`mention` AND NOT `thread`.`starred`
                                        AND NOT `thread`.`wall` AND NOT `thread`.`origin`
                                        AND `thread`.`uid` != 0 AND `thread`.`iid` >= ?
                                        AND NOT `thread`.`iid` IN (SELECT `parent` FROM `item`
                                                        WHERE (`item`.`starred` OR (`item`.`resource-id` != '')
                                                                OR (`item`.`file` != '') OR (`item`.`event-id` != '')
                                                                OR (`item`.`attach` != '') OR `item`.`wall` OR `item`.`origin`)
                                                                AND `item`.`parent` = `thread`.`iid`)
                                ORDER BY `thread`.`iid` LIMIT 1000", $days, $last_id);
		$count = dba::num_rows($r);
		if ($count > 0) {
			logger("found expired threads: ".$count);
			while ($thread = dba::fetch($r)) {
				$last_id = $thread["iid"];
				dba::delete('thread', array('iid' => $thread["iid"]));
			}
		} else {
			logger("No expired threads found");
		}
		dba::close($r);
		logger("Done deleting ".$count." expired threads. Last ID: ".$last_id);

		Config::set('system', 'dbclean-last-id-8', $last_id);
	} elseif ($stage == 9) {
		if ($days <= 0) {
			return;
		}

		$last_id = Config::get('system', 'dbclean-last-id-9', 0);
		$till_id = Config::get('system', 'dbclean-last-id-8', 0);

		logger("Deleting old global item entries from expired threads from ID ".$last_id." to ID ".$till_id);
		$r = dba::p("SELECT `id` FROM `item` WHERE `uid` = 0 AND
					NOT EXISTS (SELECT `guid` FROM `item` AS `i` WHERE `item`.`guid` = `i`.`guid` AND `i`.`uid` != 0) AND
					`received` < UTC_TIMESTAMP() - INTERVAL 90 DAY AND `id` >= ? AND `id` <= ?
				ORDER BY `id` LIMIT ".intval($limit), $last_id, $till_id);
		$count = dba::num_rows($r);
		if ($count > 0) {
			logger("found global item entries from expired threads: ".$count);
			while ($orphan = dba::fetch($r)) {
				$last_id = $orphan["id"];
				dba::delete('item', array('id' => $orphan["id"]));
			}
		} else {
			logger("No global item entries from expired threads");
		}
		dba::close($r);
		logger("Done deleting ".$count." old global item entries from expired threads. Last ID: ".$last_id);

		Config::set('system', 'dbclean-last-id-9', $last_id);
	}

	// Call it again if not all entries were purged
	if (($stage != 0) AND ($count > 0)) {
		proc_run(PRIORITY_MEDIUM, 'include/dbclean.php');
	}
}
