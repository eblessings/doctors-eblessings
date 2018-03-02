<?php
/**
 * @file include/acl_selectors.php
 */

use Friendica\App;
use Friendica\Core\Acl;
use Friendica\Model\Contact;

require_once "mod/proxy.php";

function navbar_complete(App $a) {
	$search = notags(trim($_REQUEST['search']));
	$mode = $_REQUEST['smode'];

	return Acl::contactAutocomplete($search, $mode);
}
