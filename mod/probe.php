<?php
/**
 * @file mod/probe.php
 */
use Friendica\App;
use Friendica\Core\L10n;
use Friendica\Core\System;
use Friendica\Network\Probe;

function probe_content(App $a)
{
	if (!local_user()) {
		System::httpExit(403, ["title" => L10n::t("Public access denied."),
			"description" => L10n::t("Only logged in users are permitted to perform a probing.")]);
		exit();
	}

	$o = '<div class="generic-page-wrapper">';
	$o .= '<h3>Probe Diagnostic</h3>';

	$o .= '<form action="probe" method="get">';
	$o .= 'Lookup address: <input type="text" style="width: 250px;" name="addr" value="' . defaults($_GET, 'addr', '') . '" />';
	$o .= '<input type="submit" name="submit" value="Submit" /></form>';

	$o .= '<br /><br />';

	if (!empty($_GET['addr'])) {
		$addr = trim($_GET['addr']);
		$res = Probe::uri($addr, "", 0, false);
		$o .= '<pre>';
		$o .= str_replace("\n", '<br />', print_r($res, true));
		$o .= '</pre>';
	}
	$o .= '</div>';

	return $o;
}
