<?php

require_once("boot.php");
require_once("include/socgraph.php");


function discover_poco_run(&$argv, &$argc){
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
	require_once('include/pidfile.php');

	load_config('config');
	load_config('system');

	$maxsysload = intval(get_config('system','maxloadavg'));
	if($maxsysload < 1)
		$maxsysload = 50;
	if(function_exists('sys_getloadavg')) {
		$load = sys_getloadavg();
		if(intval($load[0]) > $maxsysload) {
			logger('system: load ' . $load[0] . ' too high. discover_poco deferred to next scheduled run.');
			return;
		}
	}

	if(($argc > 2) && ($argv[1] == "dirsearch")) {
		$search = urldecode($argv[2]);
		$mode = 1;
	} elseif(($argc == 2) && ($argv[1] == "checkcontact")) {
		$mode = 2;
	} elseif ($argc == 1) {
		$search = "";
		$mode = 0;
	} else
		die("Unknown or missing parameter ".$argv[1]."\n");

	$lockpath = get_lockpath();
	if ($lockpath != '') {
		$pidfile = new pidfile($lockpath, 'discover_poco'.$mode.urlencode($search));
		if($pidfile->is_already_running()) {
			logger("discover_poco: Already running");
			if ($pidfile->running_time() > 19*60) {
                                $pidfile->kill();
                                logger("discover_poco: killed stale process");
				// Calling a new instance
				if ($mode == 0)
					proc_run('php','include/discover_poco.php');
                        }
			exit;
		}
	}

	$a->set_baseurl(get_config('system','url'));

	load_hooks();

	logger('start '.$search);

	if (($mode == 2) AND get_config('system','poco_completion'))
		discover_users();
	elseif (($mode == 1) AND ($search != "") and get_config('system','poco_local_search'))
		discover_directory($search);
	elseif (($mode == 0) AND ($search == "") and (get_config('system','poco_discovery') > 0))
		poco_discover();

	logger('end '.$search);

	return;
}

function discover_users() {
	logger("Discover users", LOGGER_DEBUG);
	// To-Do: Maybe we should check old contact as well.
	$users = q("SELECT `url`, `created`, `updated`, `last_failure`, `last_contact`, `server_url` FROM `gcontact`
			WHERE `last_contact` = '0000-00-00 00:00:00' AND `last_failure` = '0000-00-00 00:00:00' AND
				`network` IN ('%s', '%s', '%s') ORDER BY rand()",
			dbesc(NETWORK_DFRN), dbesc(NETWORK_DIASPORA), dbesc(NETWORK_OSTATUS));

	if (!$users)
		return;

	$checked = 0;

	foreach ($users AS $user) {
		if (poco_do_update($user["created"], $user["updated"], $user["last_failure"], $user["last_contact"])) {

			if ($user[0]["server_url"] != "")
                		$server_url = $user[0]["server_url"];
        		else
                		$server_url = poco_detect_server($user["url"]);

			if (poco_check_server($server_url, $gcontacts[0]["network"])) {
				logger('Check user '.$user["url"]);
				poco_last_updated($user["url"]);

				if (++$checked > 100)
					return;
			}
		}
	}
}

function discover_directory($search) {

	$data = Cache::get("dirsearch:".$search);
	if (!is_null($data)){
		// Only search for the same item every 24 hours
		if (time() < $data + (60 * 60 * 24)) {
			logger("Already searched for ".$search." in the last 24 hours", LOGGER_DEBUG);
			return;
		}
	}

	$x = fetch_url("http://dir.friendica.com/lsearch?p=1&n=500&search=".urlencode($search));
	$j = json_decode($x);

	if(count($j->results))
		foreach($j->results as $jj) {
			// Check if the contact already exists
			$exists = q("SELECT `id`, `last_contact`, `last_failure`, `updated` FROM `gcontact` WHERE `nurl` = '%s'", normalise_link($jj->url));
			if ($exists) {
				logger("Profile ".$jj->url." already exists (".$search.")", LOGGER_DEBUG);

				if (($exists[0]["last_contact"] < $exists[0]["last_failure"]) AND
					($exists[0]["updated"] < $exists[0]["last_failure"]))
					continue;

				// Update the contact
				poco_last_updated($jj->url);
				continue;
			}

			// Harcoded paths aren't so good. But in this case it is okay.
			// First: We only will get Friendica contacts (which always are using this url schema)
			// Second: There will be no further problems if we are doing a mistake
			$server_url = preg_replace("=(https?://)(.*)/profile/(.*)=ism", "$1$2", $jj->url);
			if ($server_url != $jj->url)
				if (!poco_check_server($server_url)) {
					logger("Friendica server ".$server_url." doesn't answer.", LOGGER_DEBUG);
					continue;
				}
					logger("Friendica server ".$server_url." seems to be okay.", LOGGER_DEBUG);

			logger("Check if profile ".$jj->url." is reachable (".$search.")", LOGGER_DEBUG);
			$data = probe_url($jj->url);
			if ($data["network"] == NETWORK_DFRN) {
				logger("Add profile ".$jj->url." to local directory (".$search.")", LOGGER_DEBUG);
				poco_check($data["url"], $data["name"], $data["network"], $data["photo"], "", "", "", $jj->tags, $data["addr"], "", 0);
			}
		}
	Cache::set("dirsearch:".$search, time());
}

if (array_search(__file__,get_included_files())===0){
  discover_poco_run($_SERVER["argv"],$_SERVER["argc"]);
  killme();
}
