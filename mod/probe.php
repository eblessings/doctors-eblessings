<?php

use Friendica\App;

require_once 'include/probe.php';

function probe_content(App $a) {

	if (!local_user()) {
		http_status_exit(403, array("title" => t("Public access denied."),
			"description" => t("Only logged in users are permitted to perform a probing.")));
		killme();
	}

	$o .= '<h3>Probe Diagnostic</h3>';

	$o .= '<form action="probe" method="get">';
	$o .= 'Lookup address: <input type="text" style="width: 250px;" name="addr" value="' . $_GET['addr'] . '" />';
	$o .= '<input type="submit" name="submit" value="Submit" /></form>';

	$o .= '<br /><br />';

	if (x($_GET, 'addr')) {
		$addr = trim($_GET['addr']);
		$res = probe_url($addr);
		$o .= '<pre>';
		$o .= str_replace("\n", '<br />', print_r($res, true));
		$o .= '</pre>';
	}

	return $o;
}
