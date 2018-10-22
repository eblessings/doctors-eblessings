<?php

use Friendica\App;
use Friendica\Core\System;

function toggle_mobile_init(App $a) {

	if (isset($_GET['off'])) {
		$_SESSION['show-mobile'] = false;
	} else {
		$_SESSION['show-mobile'] = true;
	}

	if (isset($_GET['address'])) {
		$address = $_GET['address'];
	} else {
		$address = '';
	}

	$a->redirect($address);
}
