<?php


function notify_init(&$a) {
	if(! local_user())
		return;

	if($a->argc > 2 && $a->argv[1] === 'view' && intval($a->argv[2])) {
		$r = q("select * from notify where id = %d and uid = %d limit 1",
			intval($a->argv[2]),
			intval(local_user())
		);
		if(count($r)) {
			q("update notify set seen = 1 where id = %d and uid = %d limit 1",
				intval($a->argv[2]),
				intval(local_user())
			);
			goaway($r[0]['link']);
		}

		goaway($a->get_baseurl());
	}
}


function notify_content(&$a) {
	if(! local_user())
		return login();
}