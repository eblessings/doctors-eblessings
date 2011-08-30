<?php

/**
 * Diaspora endpoint
 */


require_once('include/salmon.php');
require_once('include/crypto.php');
require_once('include/diaspora.php');

	
function receive_post(&$a) {

	if($a->argc != 3 || $a->argv[1] !== 'users')
		http_status_exit(500);

	$guid = $a->argv[2];

	$r = q("SELECT * FROM `user` WHERE `guid` = '%s' LIMIT 1",
		dbesc($guid)
	);
	if(! count($r))
		http_status_exit(500);

	$importer = $r[0];

	// It is an application/x-www-form-urlencoded

	$xml = urldecode($_POST['xml']);

	logger('mod-diaspora: new salmon ' . $xml, LOGGER_DATA);

	if(! $xml)
		http_status_exit(500);

	$msg = diaspora_decode($importer,$xml);

	logger('mod-diaspora: decoded msg: ' . print_r($msg,true), LOGGER_DATA);

	if(! is_array($msg))
		http_status_exit(500);

	diaspora_dispatch($importer,$msg);

	http_status_exit(200);
	// NOTREACHED
}

