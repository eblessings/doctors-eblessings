<?php

require_once('include/security.php');
require_once('include/bbcode.php');
require_once('include/items.php');


function like_content(&$a) {

	if(! local_user() && ! remote_user()) {
		return;
	}

	$verb = notags(trim($_GET['verb']));

	if(! $verb)
		$verb = 'like';


	switch($verb) {
		case 'like':
		case 'unlike':
			$activity = ACTIVITY_LIKE;
			break;
		case 'dislike':
		case 'undislike':
			$activity = ACTIVITY_DISLIKE;
			break;
		default:
			return;
			break;
	}


	$item_id = (($a->argc > 1) ? notags(trim($a->argv[1])) : 0);

	logger('like: verb ' . $verb . ' item ' . $item_id);


	$r = q("SELECT * FROM `item` WHERE ( `id` = '%s' OR `uri` = '%s') AND `id` = `parent` LIMIT 1",
		dbesc($item_id),
		dbesc($item_id)
	);

	if(! $item_id || (! count($r))) {
		logger('like: no item ' . $item_id);
		return;
	}

	$item = $r[0];

	$owner_uid = $item['uid'];

	if(! can_write_wall($a,$owner_uid)) {
		return;
	}

	$remote_owner = null;

	if(! $item['wall']) {
		// The top level post may have been written by somebody on another system
		$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($item['contact-id']),
			intval($item['uid'])
		);
		if(! count($r))
			return;
		if(! $r[0]['self'])
			$remote_owner = $r[0];
	}

	// this represents the post owner on this system. 

	$r = q("SELECT `contact`.*, `user`.`nickname` FROM `contact` LEFT JOIN `user` ON `contact`.`uid` = `user`.`uid`
		WHERE `contact`.`self` = 1 AND `contact`.`uid` = %d LIMIT 1",
		intval($owner_uid)
	);
	if(count($r))
		$owner = $r[0];

	if(! $owner) {
		logger('like: no owner');
		return;
	}

	if(! $remote_owner)
		$remote_owner = $owner;


	// This represents the person posting

	if((local_user()) && (local_user() == $owner_uid)) {
		$contact = $owner;
	}
	else {
		$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($_SESSION['visitor_id']),
			intval($owner_uid)
		);
		if(count($r))
			$contact = $r[0];
	}
	if(! $contact) {
		return;
	}


	$r = q("SELECT `id` FROM `item` WHERE `verb` = '%s' AND `deleted` = 0 
		AND `contact-id` = %d AND ( `parent` = '%s' OR `parent-uri` = '%s') LIMIT 1",
		dbesc($activity),
		intval($contact['id']),
		dbesc($item_id),
		dbesc($item_id)
	);
	if(count($r)) {
		// Already voted, undo it
		$r = q("UPDATE `item` SET `deleted` = 1, `changed` = '%s' WHERE `id` = %d LIMIT 1",
			dbesc(datetime_convert()),
			intval($r[0]['id'])
		);

		// Clean up the `sign` table
		$r2 = q("DELETE FROM `sign` WHERE `iid` = %d",
			intval($r[0]['id'])
		);

		// Save the author information for the unlike in case we need to relay to Diaspora
		// Note that we can only create a signature for a user of the local server. We don't have
		// a key for remote users. That is ok, because if a remote user is "unlike"ing a post, it 
		// means we are the relay, and for relayable_retractions, Diaspora
		// only checks the parent_author_signature if it doesn't have to relay further
		//
		// If $item['resource-id'] exists, it means the item is a photo. Diaspora doesn't support
		// likes on photos, so don't bother.

		if(($activity === ACTIVITY_LIKE) && (! $item['resource-id'])) {
			$signed_text = $r[0]['guid'] . ';' . 'Like';

			if( contact['network'] === NETWORK_DIASPORA)
				$diaspora_handle = $contact['addr'];
			else { // Only works for NETWORK_DFRN
				$contact_baseurl = substr($contact['url'], strpos($contact['url'],'://') + 3, strpos($contact['url'],'/profile') - 1);
				$diaspora_handle = $contact['nick'] . '@' . $contact_baseurl;

				// Get contact's private key if he's a user of the local Friendica server
				$r2 = q("SELECT `contact`.`uid` FROM `contact` WHERE `url` = '%s' AND `self` = 1 LIMIT 1",
					dbesc(contact['url'])
				);

				if( $r2) {
					$contact_uid = $r2['uid'];
					$r2 = q("SELECT prvkey FROM user WHERE id = %d LIMIT 1",
						intval($contact_uid)
					);

					if( $r2)
						$authorsig = base64_encode(rsa_sign($signed_text,$r2['prvkey'],'sha256'));
				}
			}

			if(! isset($authorsig))
				$authorsig = '';

			q("insert into sign (`retract_iid`,`signed_text`,`signature`,`signer`) values (%d,'%s','%s','%s') ",
				intval($r[0]['id']),
				dbesc($signed_text),
				dbesc($authorsig),
				dbesc($diaspora_handle)
			);
		}

//		proc_run('php',"include/notifier.php","like","$post_id"); // $post_id isn't defined here!
		proc_run('php',"include/notifier.php","like","$r[0]['id']");
		return;
	}

	$uri = item_new_uri($a->get_hostname(),$owner_uid);

	$post_type = (($item['resource-id']) ? t('photo') : t('status'));
	$objtype = (($item['resource-id']) ? ACTIVITY_OBJ_PHOTO : ACTIVITY_OBJ_NOTE ); 
	$link = xmlify('<link rel="alternate" type="text/html" href="' . $a->get_baseurl() . '/display/' . $owner['nickname'] . '/' . $item['id'] . '" />' . "\n") ;
	$body = $item['body'];

	$obj = <<< EOT

	<object>
		<type>$objtype</type>
		<local>1</local>
		<id>{$item['uri']}</id>
		<link>$link</link>
		<title></title>
		<content>$body</content>
	</object>
EOT;
	if($verb === 'like')
		$bodyverb = t('%1$s likes %2$s\'s %3$s');
	if($verb === 'dislike')
		$bodyverb = t('%1$s doesn\'t like %2$s\'s %3$s');

	if(! isset($bodyverb))
			return; 

	$arr = array();

	$arr['uri'] = $uri;
	$arr['uid'] = $owner_uid;
	$arr['contact-id'] = $contact['id'];
	$arr['type'] = 'activity';
	$arr['wall'] = $item['wall'];
	$arr['origin'] = 1;
	$arr['gravity'] = GRAVITY_LIKE;
	$arr['parent'] = $item['id'];
	$arr['parent-uri'] = $item['uri'];
	$arr['owner-name'] = $remote_owner['name'];
	$arr['owner-link'] = $remote_owner['url'];
	$arr['owner-avatar'] = $remote_owner['thumb'];
	$arr['author-name'] = $contact['name'];
	$arr['author-link'] = $contact['url'];
	$arr['author-avatar'] = $contact['thumb'];
	
	$ulink = '[url=' . $contact['url'] . ']' . $contact['name'] . '[/url]';
	$alink = '[url=' . $item['author-link'] . ']' . $item['author-name'] . '[/url]';
	$plink = '[url=' . $a->get_baseurl() . '/display/' . $owner['nickname'] . '/' . $item['id'] . ']' . $post_type . '[/url]';
	$arr['body'] =  sprintf( $bodyverb, $ulink, $alink, $plink );

	$arr['verb'] = $activity;
	$arr['object-type'] = $objtype;
	$arr['object'] = $obj;
	$arr['allow_cid'] = $item['allow_cid'];
	$arr['allow_gid'] = $item['allow_gid'];
	$arr['deny_cid'] = $item['deny_cid'];
	$arr['deny_gid'] = $item['deny_gid'];
	$arr['visible'] = 1;
	$arr['unseen'] = 1;
	$arr['last-child'] = 0;

	$post_id = item_store($arr);	

	if(! $item['visible']) {
		$r = q("UPDATE `item` SET `visible` = 1 WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($item['id']),
			intval($owner_uid)
		);
	}			


	// Save the author information for the like in case we need to relay to Diaspora
	// Note that we can only create a signature for a user of the local server. We don't have
	// a key for remote users. That is ok, because if a remote user is "unlike"ing a post, it 
	// means we are the relay, and for relayable_retractions, Diaspora
	// only checks the parent_author_signature if it doesn't have to relay further

	if(($activity === ACTIVITY_LIKE) && ($post_type === t('status'))) {
		if( contact['network'] === NETWORK_DIASPORA)
			$diaspora_handle = $contact['addr'];
		else { // Only works for NETWORK_DFRN
			$contact_baseurl = substr($contact['url'], strpos($contact['url'],'://') + 3, strpos($contact['url'],'/profile') - 1);
			$diaspora_handle = $contact['nick'] . '@' . $contact_baseurl;

			// Get contact's private key if he's a user of the local Friendica server
			$r2 = q("SELECT `contact`.`uid` FROM `contact` WHERE `url` = '%s' AND `self` = 1 LIMIT 1",
				dbesc(contact['url'])
			);

			if( $r2) {
				$contact_uid = $r2['uid'];
				$r2 = q("SELECT prvkey FROM user WHERE id = %d LIMIT 1",
					intval($contact_uid)
				);

				if( $r2)
					$contact_uprvkey = $r2['prvkey'];
			}
		}

		$r = q("SELECT guid, parent FROM `item` WHERE id = %d LIMIT 1",
			intval($post_id)
		);
		if( $r) {
			$p = q("SELECT guid FROM `item` WHERE id = %d AND parent = %d LIMIT 1",
				intval($r[0]['parent'),
				intval($r[0]['parent')
			);
			if( $p) {
				$signed_text = $r[0]['guid'] . ';Post;' . $p[0]['guid'] . ';true;' . $diaspora_handle;

				if(isset($contact_uprvkey))
					$authorsig = base64_encode(rsa_sign($signed_text,$contact_uprvkey,'sha256'));
				else
					$authorsig = '';

				q("insert into sign (`iid`,`signed_text`,`signature`,`signer`) values (%d,'%s','%s','%s') ",
					intval($post_id),
					dbesc($signed_text),
					dbesc($authorsig),
					dbesc($diaspora_handle)
				);
			}
		}
	}


	$arr['id'] = $post_id;

	call_hooks('post_local_end', $arr);

	proc_run('php',"include/notifier.php","like","$post_id");

	killme();
//	return; // NOTREACHED
}
