<?php

use Friendica\App;
use Friendica\Core\System;
use Friendica\Model\GlobalContact;

function randprof_init(App $a) {
	require_once('include/Contact.php');

	$x = random_profile();

	if ($x) {
		goaway(zrl($x));
	}

	goaway(System::baseUrl() . '/profile');
}
