<?php

require_once('Scrape.php');
require_once('include/follow.php');

function follow_init(&$a) {

	if(! local_user()) {
		notice( t('Permission denied.') . EOL);
		goaway($_SESSION['return_url']);
		// NOTREACHED
	}

	$uid = local_user();
	$url = $orig_url = notags(trim($_REQUEST['url']));
	$return_url = $_SESSION['return_url'];


	$result = new_contact($uid,$url,true);

	if($result['success'] == false) {
		if($result['message'])
			notice($result['message']);
		goaway($return_url);
	}

	if(strstr($return_url,'contacts'))
		goaway($a->get_baseurl() . '/contacts/' . $contact_id);

	goaway($return_url);
	// NOTREACHED
}
