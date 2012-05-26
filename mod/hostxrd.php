<?php

require_once('include/crypto.php');

function hostxrd_init(&$a) {
	header('Access-Control-Allow-Origin: *');
	header("Content-type: text/xml");
	$pubkey = get_config('system','site_pubkey');

	if(! $pubkey) {
		$res = new_keypair(1024);

		set_config('system','site_prvkey', $res['prvkey']);
		set_config('system','site_pubkey', $res['pubkey']);
	}

	$tpl = file_get_contents('view/xrd_host.tpl');
	echo str_replace(array(
		'$zhost','$zroot','$domain','$zot_post','$bigkey'),array($a->get_hostname(),z_root(),z_path(),z_root() . '/post', salmon_key(get_config('system','site_pubkey'))),$tpl);
	session_write_close();
	exit();

}