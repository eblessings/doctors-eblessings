<?php

require_once("boot.php");

function update_gcontact_run(&$argv, &$argc){
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
	require_once('include/queue_fn.php');

	load_config('config');
	load_config('system');

	$a->set_baseurl(get_config('system','url'));

	load_hooks();

	logger('update_gcontact: start');

	$manual_id  = 0;
	$generation = 0;
	$hub_update = false;
	$force      = false;
	$restart    = false;

	if(($argc > 1) && (intval($argv[1])))
		$contact_id = intval($argv[1]);

	if(($argc > 2) && ($argv[2] == "force"))
		$force = true;

	if(!$contact_id) {
		logger('update_gcontact: no contact');
		return;
	}

	$lockpath = get_lockpath();
	if ($lockpath != '') {
		$pidfile = new pidfile($lockpath, 'update_gcontact'.$contact_id);
		if ($pidfile->is_already_running()) {
			logger("update_gcontact: Already running for contact ".$contact_id);
			if ($pidfile->running_time() > 9*60) {
				$pidfile->kill();
				logger("killed stale process");
			}
			exit;
		}
	}

	$r = q("SELECT * FROM `gcontact` WHERE `id` = %d", intval($contact_id));

	if (!$r)
		return;

	$data = probe_url($r[0]["url"]);

	if (!in_array($data["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS)))
		return;

	if (($data["name"] == "") AND ($r[0]['name'] != ""))
		$data["name"] = $r[0]['name'];

	if (($data["nick"] == "") AND ($r[0]['nick'] != ""))
		$data["nick"] = $r[0]['nick'];

	if (($data["addr"] == "") AND ($r[0]['addr'] != ""))
		$data["addr"] = $r[0]['addr'];

	if (($data["photo"] == "") AND ($r[0]['photo'] != ""))
		$data["photo"] = $r[0]['photo'];


	q("UPDATE `gcontact` SET `name` = '%s', `nick` = '%s', `addr` = '%s', `photo` = '%s'
				WHERE `id` = %d",
				dbesc($data["name"]),
				dbesc($data["nick"]),
				dbesc($data["addr"]),
				dbesc($data["photo"]),
				intval($contact_id)
			);

	q("UPDATE `contact` SET `name` = '%s', `nick` = '%s', `addr` = '%s', `photo` = '%s'
				WHERE `uid` = 0 AND `nurl` = '%s'",
				dbesc($data["name"]),
				dbesc($data["nick"]),
				dbesc($data["addr"]),
				dbesc($data["photo"]),
				dbesc(normalise_link($data["url"]))
			);
}

if (array_search(__file__,get_included_files())===0){
  update_gcontact_run($_SERVER["argv"],$_SERVER["argc"]);
  killme();
}
