<?php
if (!file_exists("boot.php") AND (sizeof($_SERVER["argv"]) != 0)) {
	$directory = dirname($_SERVER["argv"][0]);

	if (substr($directory, 0, 1) != "/")
		$directory = $_SERVER["PWD"]."/".$directory;

	$directory = realpath($directory."/..");

	chdir($directory);
}

require_once("boot.php");


function cron_run(&$argv, &$argc){
	global $a, $db;

	if(is_null($a)) {
		$a = new App;
	}

	if(is_null($db)) {
		@include(".htconfig.php");
		require_once("include/dba.php");
		$db = new dba($db_host, $db_user, $db_pass, $db_data);
		unset($db_host, $db_user, $db_pass, $db_data);
	};


	require_once('include/session.php');
	require_once('include/datetime.php');
	require_once('library/simplepie/simplepie.inc');
	require_once('include/items.php');
	require_once('include/Contact.php');
	require_once('include/email.php');
	require_once('include/socgraph.php');
	require_once('include/pidfile.php');
	require_once('mod/nodeinfo.php');

	load_config('config');
	load_config('system');

	$maxsysload = intval(get_config('system','maxloadavg'));
	if($maxsysload < 1)
		$maxsysload = 50;

	$load = current_load();
	if($load) {
		if(intval($load) > $maxsysload) {
			logger('system: load ' . $load . ' too high. cron deferred to next scheduled run.');
			return;
		}
	}

	$last = get_config('system','last_cron');

	$poll_interval = intval(get_config('system','cron_interval'));
	if(! $poll_interval)
		$poll_interval = 10;

	if($last) {
		$next = $last + ($poll_interval * 60);
		if($next > time()) {
			logger('cron intervall not reached');
			return;
		}
	}

	$lockpath = get_lockpath();
	if ($lockpath != '') {
		$pidfile = new pidfile($lockpath, 'cron');
		if($pidfile->is_already_running()) {
			logger("cron: Already running");
			if ($pidfile->running_time() > 9*60) {
				$pidfile->kill();
				logger("cron: killed stale process");
				// Calling a new instance
				proc_run('php','include/cron.php');
			}
			exit;
		}
	}



	$a->set_baseurl(get_config('system','url'));

	load_hooks();

	logger('cron: start');

	// run queue delivery process in the background

	proc_run('php',"include/queue.php");

	// run diaspora photo queue process in the background

	proc_run('php',"include/dsprphotoq.php");

	// run the process to discover global contacts in the background

	proc_run('php',"include/discover_poco.php");

	// run the process to update locally stored global contacts in the background

	proc_run('php',"include/discover_poco.php", "checkcontact");

	// expire any expired accounts

	q("UPDATE user SET `account_expired` = 1 where `account_expired` = 0
		AND `account_expires_on` != '0000-00-00 00:00:00'
		AND `account_expires_on` < UTC_TIMESTAMP() ");

	// delete user and contact records for recently removed accounts

	$r = q("SELECT * FROM `user` WHERE `account_removed` = 1 AND `account_expires_on` < UTC_TIMESTAMP() - INTERVAL 3 DAY");
	if ($r) {
		foreach($r as $user) {
			q("DELETE FROM `contact` WHERE `uid` = %d", intval($user['uid']));
			q("DELETE FROM `user` WHERE `uid` = %d", intval($user['uid']));
		}
	}

	$abandon_days = intval(get_config('system','account_abandon_days'));
	if($abandon_days < 1)
		$abandon_days = 0;

	// Check OStatus conversations
	// Check only conversations with mentions (for a longer time)
	check_conversations(true);

	// Check every conversation
	check_conversations(false);

	// Set the gcontact-id in the item table if missing
	item_set_gcontact();

	// update nodeinfo data
	nodeinfo_cron();

	/// @TODO Regenerate usage statistics
	// q("ANALYZE TABLE `item`");

	// once daily run birthday_updates and then expire in background

	$d1 = get_config('system','last_expire_day');
	$d2 = intval(datetime_convert('UTC','UTC','now','d'));

	if($d2 != intval($d1)) {

		update_contact_birthdays();

		proc_run('php',"include/discover_poco.php", "suggestions");

		set_config('system','last_expire_day',$d2);

		proc_run('php','include/expire.php');
	}

	// Clear cache entries
	cron_clear_cache($a);

	// Repair missing Diaspora values in contacts
	cron_repair_diaspora($a);

	// Repair entries in the database
	cron_repair_database();

	$manual_id  = 0;
	$generation = 0;
	$force      = false;
	$restart    = false;

	if(($argc > 1) && ($argv[1] == 'force'))
		$force = true;

	if(($argc > 1) && ($argv[1] == 'restart')) {
		$restart = true;
		$generation = intval($argv[2]);
		if(! $generation)
			killme();
	}

	if(($argc > 1) && intval($argv[1])) {
		$manual_id = intval($argv[1]);
		$force     = true;
	}

	$interval = intval(get_config('system','poll_interval'));
	if(! $interval)
		$interval = ((get_config('system','delivery_interval') === false) ? 3 : intval(get_config('system','delivery_interval')));

	// If we are using the worker we don't need a delivery interval
	if (get_config("system", "worker"))
		$interval = false;

	$sql_extra = (($manual_id) ? " AND `id` = $manual_id " : "");

	reload_plugins();

	$d = datetime_convert();

	// Only poll from those with suitable relationships,
	// and which have a polling address and ignore Diaspora since
	// we are unable to match those posts with a Diaspora GUID and prevent duplicates.

	$abandon_sql = (($abandon_days)
		? sprintf(" AND `user`.`login_date` > UTC_TIMESTAMP() - INTERVAL %d DAY ", intval($abandon_days))
		: ''
	);

	$contacts = q("SELECT `contact`.`id` FROM `contact` INNER JOIN `user` ON `user`.`uid` = `contact`.`uid`
		WHERE `rel` IN (%d, %d) AND `poll` != '' AND `network` IN ('%s', '%s', '%s', '%s', '%s', '%s')
		$sql_extra
		AND NOT `self` AND NOT `contact`.`blocked` AND NOT `contact`.`readonly` AND NOT `contact`.`archive`
		AND NOT `user`.`account_expired` AND NOT `user`.`account_removed` $abandon_sql ORDER BY RAND()",
		intval(CONTACT_IS_SHARING),
		intval(CONTACT_IS_FRIEND),
		dbesc(NETWORK_DFRN),
		dbesc(NETWORK_ZOT),
		dbesc(NETWORK_OSTATUS),
		dbesc(NETWORK_FEED),
		dbesc(NETWORK_MAIL),
		dbesc(NETWORK_MAIL2)
	);

	if(! count($contacts)) {
		return;
	}

	foreach($contacts as $c) {

		$res = q("SELECT * FROM `contact` WHERE `id` = %d LIMIT 1",
			intval($c['id'])
		);

		if((! $res) || (! count($res)))
			continue;

		foreach($res as $contact) {

			$xml = false;

			if($manual_id)
				$contact['last-update'] = '0000-00-00 00:00:00';

			if(in_array($contact['network'], array(NETWORK_DFRN, NETWORK_ZOT, NETWORK_OSTATUS)))
				$contact['priority'] = 2;

			if($contact['subhub'] AND in_array($contact['network'], array(NETWORK_DFRN, NETWORK_ZOT, NETWORK_OSTATUS))) {
				// We should be getting everything via a hub. But just to be sure, let's check once a day.
				// (You can make this more or less frequent if desired by setting 'pushpoll_frequency' appropriately)
				// This also lets us update our subscription to the hub, and add or replace hubs in case it
				// changed. We will only update hubs once a day, regardless of 'pushpoll_frequency'.

				$poll_interval = get_config('system','pushpoll_frequency');
				$contact['priority'] = (($poll_interval !== false) ? intval($poll_interval) : 3);
			}

			if($contact['priority'] AND !$force) {

				$update     = false;

				$t = $contact['last-update'];

				/**
				 * Based on $contact['priority'], should we poll this site now? Or later?
				 */

				switch ($contact['priority']) {
					case 5:
						if(datetime_convert('UTC','UTC', 'now') > datetime_convert('UTC','UTC', $t . " + 1 month"))
							$update = true;
						break;
					case 4:
						if(datetime_convert('UTC','UTC', 'now') > datetime_convert('UTC','UTC', $t . " + 1 week"))
							$update = true;
						break;
					case 3:
						if(datetime_convert('UTC','UTC', 'now') > datetime_convert('UTC','UTC', $t . " + 1 day"))
							$update = true;
						break;
					case 2:
						if(datetime_convert('UTC','UTC', 'now') > datetime_convert('UTC','UTC', $t . " + 12 hour"))
							$update = true;
						break;
					case 1:
					default:
						if(datetime_convert('UTC','UTC', 'now') > datetime_convert('UTC','UTC', $t . " + 1 hour"))
							$update = true;
						break;
				}
				if(!$update)
					continue;
			}

			logger("Polling ".$contact["network"]." ".$contact["id"]." ".$contact["nick"]." ".$contact["name"]);

			proc_run('php','include/onepoll.php',$contact['id']);

			if($interval)
				@time_sleep_until(microtime(true) + (float) $interval);
		}
	}

	logger('cron: end');

	set_config('system','last_cron', time());

	return;
}

/**
 * @brief Clear cache entries
 *
 * @param App $a
 */
function cron_clear_cache(&$a) {

	$last = get_config('system','cache_last_cleared');

	if($last) {
		$next = $last + (3600); // Once per hour
		$clear_cache = ($next <= time());
	} else
		$clear_cache = true;

	if (!$clear_cache)
		return;

	// clear old cache
	Cache::clear();

	// clear old item cache files
	clear_cache();

	// clear cache for photos
	clear_cache($a->get_basepath(), $a->get_basepath()."/photo");

	// clear smarty cache
	clear_cache($a->get_basepath()."/view/smarty3/compiled", $a->get_basepath()."/view/smarty3/compiled");

	// clear cache for image proxy
	if (!get_config("system", "proxy_disabled")) {
		clear_cache($a->get_basepath(), $a->get_basepath()."/proxy");

		$cachetime = get_config('system','proxy_cache_time');
		if (!$cachetime) $cachetime = PROXY_DEFAULT_TIME;

		q('DELETE FROM `photo` WHERE `uid` = 0 AND `resource-id` LIKE "pic:%%" AND `created` < NOW() - INTERVAL %d SECOND', $cachetime);
	}

	// Delete the cached OEmbed entries that are older than one year
	q("DELETE FROM `oembed` WHERE `created` < NOW() - INTERVAL 1 YEAR");

	// Delete the cached "parse_url" entries that are older than one year
	q("DELETE FROM `parsed_url` WHERE `created` < NOW() - INTERVAL 1 YEAR");

	// Maximum table size in megabyte
	$max_tablesize = intval(get_config('system','optimize_max_tablesize')) * 1000000;
	if ($max_tablesize == 0)
		$max_tablesize = 100 * 1000000; // Default are 100 MB

	// Minimum fragmentation level in percent
	$fragmentation_level = intval(get_config('system','optimize_fragmentation')) / 100;
	if ($fragmentation_level == 0)
		$fragmentation_level = 0.3; // Default value is 30%

	// Optimize some tables that need to be optimized
	$r = q("SHOW TABLE STATUS");
	foreach($r as $table) {

		// Don't optimize tables that are too large
		if ($table["Data_length"] > $max_tablesize)
			continue;

		// Don't optimize empty tables
		if ($table["Data_length"] == 0)
			continue;

		// Calculate fragmentation
		$fragmentation = $table["Data_free"] / $table["Data_length"];

		logger("Table ".$table["Name"]." - Fragmentation level: ".round($fragmentation * 100, 2), LOGGER_DEBUG);

		// Don't optimize tables that needn't to be optimized
		if ($fragmentation < $fragmentation_level)
			continue;

		// So optimize it
		logger("Optimize Table ".$table["Name"], LOGGER_DEBUG);
		q("OPTIMIZE TABLE `%s`", dbesc($table["Name"]));
	}

	set_config('system','cache_last_cleared', time());
}

/**
 * @brief Repair missing values in Diaspora contacts
 *
 * @param App $a
 */
function cron_repair_diaspora(&$a) {
	$r = q("SELECT `id`, `url` FROM `contact`
		WHERE `network` = '%s' AND (`batch` = '' OR `notify` = '' OR `poll` = '' OR pubkey = '')
			ORDER BY RAND() LIMIT 50", dbesc(NETWORK_DIASPORA));
	if ($r) {
		foreach ($r AS $contact) {
			if (poco_reachable($contact["url"])) {
				$data = probe_url($contact["url"]);
				if ($data["network"] == NETWORK_DIASPORA) {
					logger("Repair contact ".$contact["id"]." ".$contact["url"], LOGGER_DEBUG);
					q("UPDATE `contact` SET `batch` = '%s', `notify` = '%s', `poll` = '%s', pubkey = '%s' WHERE `id` = %d",
						dbesc($data["batch"]), dbesc($data["notify"]), dbesc($data["poll"]), dbesc($data["pubkey"]),
						intval($contact["id"]));
				}
			}
		}
	}
}

/**
 * @brief Do some repairs in database entries
 *
 */
function cron_repair_database() {

	// Set the parent if it wasn't set. (Shouldn't happen - but does sometimes)
	// This call is very "cheap" so we can do it at any time without a problem
	q("UPDATE `item` INNER JOIN `item` AS `parent` ON `parent`.`uri` = `item`.`parent-uri` AND `parent`.`uid` = `item`.`uid` SET `item`.`parent` = `parent`.`id` WHERE `item`.`parent` = 0");

	/// @todo
	// - remove duplicated contacts with uid=0 (We could do this at the place where the contacts are stored)
	// - remove thread entries without item
	// - remove sign entries without item
	// - remove children when parent got lost
	// - set contact-id in item when not present
}

if (array_search(__file__,get_included_files())===0){
	cron_run($_SERVER["argv"],$_SERVER["argc"]);
	killme();
}
