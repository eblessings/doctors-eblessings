<?php
require_once("boot.php");

function delivery_run($argv, $argc){
	global $a, $db;

	if(is_null($a)){
		$a = new App;
	}
  
	if(is_null($db)) {
		@include(".htconfig.php");
		require_once("dba.php");
		$db = new dba($db_host, $db_user, $db_pass, $db_data);
		        unset($db_host, $db_user, $db_pass, $db_data);
	}

	require_once("session.php");
	require_once("datetime.php");
	require_once('include/items.php');
	require_once('include/bbcode.php');
	require_once('include/diaspora.php');

	load_config('config');
	load_config('system');

	load_hooks();

	if($argc < 3)
		return;

	$a->set_baseurl(get_config('system','url'));

	logger('delivery: invoked: ' . print_r($argv,true), LOGGER_DEBUG);

	$cmd        = $argv[1];
	$item_id    = intval($argv[2]);
	$contact_id = intval($argv[3]);

	// Some other process may have delivered this item already.

	$r = q("select * from deliverq where cmd = '%s' and item = %d and contact = %d limit 1",
		dbesc($cmd),
		dbesc($item_id),
		dbesc($contact_id)
	);
	if(! count($r)) {
		return;
	}	

	// It's ours to deliver. Remove it from the queue.

	q("delete from deliverq where cmd = '%s' and item = %d and contact = %d limit 1",
		dbesc($cmd),
		dbesc($item_id),
		dbesc($contact_id)
	);

	if((! $item_id) || (! $contact_id))
		return;

	$expire = false;
	$top_level = false;
	$recipients = array();
	$url_recipients = array();

	$normal_mode = true;

	$recipients[] = $contact_id;

	if($cmd === 'expire') {
		$normal_mode = false;
		$expire = true;
		$items = q("SELECT * FROM `item` WHERE `uid` = %d AND `wall` = 1 
			AND `deleted` = 1 AND `changed` > UTC_TIMESTAMP() - INTERVAL 30 MINUTE",
			intval($item_id)
		);
		$uid = $item_id;
		$item_id = 0;
		if(! count($items))
			return;
	}
	else {

		// find ancestors
		$r = q("SELECT * FROM `item` WHERE `id` = %d LIMIT 1",
			intval($item_id)
		);

		if((! count($r)) || (! intval($r[0]['parent']))) {
			return;
		}

		$target_item = $r[0];
		$parent_id = intval($r[0]['parent']);
		$uid = $r[0]['uid'];
		$updated = $r[0]['edited'];

		if(! $parent_id)
			return;


		$items = q("SELECT `item`.*, `sign`.`signed_text`,`sign`.`signature`,`sign`.`signer` 
			FROM `item` LEFT JOIN `sign` ON `sign`.`iid` = `item`.`id` WHERE `parent` = %d ORDER BY `id` ASC",
			intval($parent_id)
		);

		if(! count($items)) {
			return;
		}

		$icontacts = null;
		$contacts_arr = array();
		foreach($items as $item)
			if(! in_array($item['contact-id'],$contacts_arr))
				$contacts_arr[] = intval($item['contact-id']);
		if(count($contacts_arr)) {
			$str_contacts = implode(',',$contacts_arr); 
			$icontacts = q("SELECT * FROM `contact` 
				WHERE `id` IN ( $str_contacts ) "
			);
		}
		if( ! ($icontacts && count($icontacts)))
			return;

		// avoid race condition with deleting entries

		if($items[0]['deleted']) {
			foreach($items as $item)
				$item['deleted'] = 1;
		}

		if((count($items) == 1) && ($items[0]['uri'] === $items[0]['parent-uri'])) {
			logger('delivery: top level post');
			$top_level = true;
		}
	}

	$r = q("SELECT `contact`.*, `user`.`pubkey` AS `upubkey`, `user`.`prvkey` AS `uprvkey`, 
		`user`.`timezone`, `user`.`nickname`, `user`.`sprvkey`, `user`.`spubkey`, 
		`user`.`page-flags`, `user`.`prvnets`
		FROM `contact` LEFT JOIN `user` ON `user`.`uid` = `contact`.`uid` 
		WHERE `contact`.`uid` = %d AND `contact`.`self` = 1 LIMIT 1",
		intval($uid)
	);

	if(! count($r))
		return;

	$owner = $r[0];

	$walltowall = ((($top_level) && ($owner['id'] != $items[0]['contact-id'])) ? true : false);

	$public_message = true;

	// fill this in with a single salmon slap if applicable

	$slap = '';

	require_once('include/group.php');

	$parent = $items[0];

		// This is IMPORTANT!!!!

		// We will only send a "notify owner to relay" or followup message if the referenced post
		// originated on our system by virtue of having our hostname somewhere
		// in the URI, AND it was a comment (not top_level) AND the parent originated elsewhere.
		// if $parent['wall'] == 1 we will already have the parent message in our array
		// and we will relay the whole lot.
 
		// expire sends an entire group of expire messages and cannot be forwarded.
		// However the conversation owner will be a part of the conversation and will 
		// be notified during this run.
		// Other DFRN conversation members will be alerted during polled updates.

		// Diaspora members currently are not notified of expirations, and other networks have
		// either limited or no ability to process deletions. We should at least fix Diaspora 
		// by stringing togther an array of retractions and sending them onward.
		 
  	
	$localhost = $a->get_hostname();
	if(strpos($localhost,':'))
		$localhost = substr($localhost,0,strpos($localhost,':'));

		/**
		 *
		 * Be VERY CAREFUL if you make any changes to the following line. Seemingly innocuous changes 
		 * have been known to cause runaway conditions which affected several servers, along with 
		 * permissions issues. 
		 *
		 */
 
	if((! $top_level) && ($parent['wall'] == 0) && (! $expire) && (stristr($target_item['uri'],$localhost))) {
		logger('relay denied for delivery agent.');

		/* no relay allowed for direct contact delivery */
		return;
	}

	if((strlen($parent['allow_cid'])) 
		|| (strlen($parent['allow_gid'])) 
		|| (strlen($parent['deny_cid'])) 
		|| (strlen($parent['deny_gid']))) {
		$public_message = false; // private recipients, not public
	}

	$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `blocked` = 0 AND `pending` = 0",
		intval($contact_id)
	);

	if(count($r))
		$contact = $r[0];
	
	$hubxml = feed_hublinks();

	logger('notifier: slaps: ' . print_r($slaps,true), LOGGER_DATA);

	require_once('include/salmon.php');

	if($contact['self'])
		return;

	$deliver_status = 0;

	switch($contact['network']) {

		case NETWORK_DFRN :
			logger('notifier: dfrndelivery: ' . $contact['name']);

			$feed_template = get_markup_template('atom_feed.tpl');
			$mail_template = get_markup_template('atom_mail.tpl');

			$atom = '';


			$birthday = feed_birthday($owner['uid'],$owner['timezone']);

			if(strlen($birthday))
				$birthday = '<dfrn:birthday>' . xmlify($birthday) . '</dfrn:birthday>';

			$atom .= replace_macros($feed_template, array(
					'$version'      => xmlify(FRIENDIKA_VERSION),
					'$feed_id'      => xmlify($a->get_baseurl() . '/profile/' . $owner['nickname'] ),
					'$feed_title'   => xmlify($owner['name']),
					'$feed_updated' => xmlify(datetime_convert('UTC', 'UTC', $updated . '+00:00' , ATOM_TIME)) ,
					'$hub'          => $hubxml,
					'$salmon'       => '',  // private feed, we don't use salmon here
					'$name'         => xmlify($owner['name']),
					'$profile_page' => xmlify($owner['url']),
					'$photo'        => xmlify($owner['photo']),
					'$thumb'        => xmlify($owner['thumb']),
					'$picdate'      => xmlify(datetime_convert('UTC','UTC',$owner['avatar-date'] . '+00:00' , ATOM_TIME)) ,
					'$uridate'      => xmlify(datetime_convert('UTC','UTC',$owner['uri-date']    . '+00:00' , ATOM_TIME)) ,
					'$namdate'      => xmlify(datetime_convert('UTC','UTC',$owner['name-date']   . '+00:00' , ATOM_TIME)) ,
					'$birthday'     => $birthday
			));

			foreach($items as $item) {
				if(! $item['parent'])
					continue;

				// private emails may be in included in public conversations. Filter them.
				if(($public_message) && $item['private'])
					continue;

				$item_contact = get_item_contact($item,$icontacts);
				if(! $item_contact)
					continue;

				if($normal_mode) {
					if($item_id == $item['id'])
						$atom .= atom_entry($item,'text',$item_contact,$owner,true);
				}
				else
					$atom .= atom_entry($item,'text',$item_contact,$owner,true);

			}

			$atom .= '</feed>' . "\r\n";

			logger('notifier: ' . $atom, LOGGER_DATA);

			$deliver_status = dfrn_deliver($owner,$contact,$atom);

			logger('notifier: dfrn_delivery returns ' . $deliver_status);
	
			if($deliver_status == (-1)) {
				logger('notifier: delivery failed: queuing message');
				// queue message for redelivery
				q("INSERT INTO `queue` ( `cid`, `created`, `last`, `content`)
					VALUES ( %d, '%s', '%s', '%s') ",
					intval($contact['id']),
					dbesc(datetime_convert()),
					dbesc(datetime_convert()),
					dbesc($atom)
				);
			}
			break;

		case NETWORK_OSTATUS :

			// Do not send to otatus if we are not configured to send to public networks
			if($owner['prvnets'])
				break;
			if(get_config('system','ostatus_disabled') || get_config('system','dfrn_only'))
				break;

			// only send salmon if public - e.g. if it's ok to notify
			// a public hub, it's ok to send a salmon

			if(($public_message) && (! $expire)) {
				$slaps = array();

				foreach($items as $item) {
					if(! $item['parent'])
						continue;

					// private emails may be in included in public conversations. Filter them.
					if(($public_message) && $item['private'])
						continue;

					$item_contact = get_item_contact($item,$icontacts);
					if(! $item_contact)
						continue;

					if(($top_level) && ($public_message) && ($item['author-link'] === $item['owner-link']) && (! $expire)) 
						$slaps[] = atom_entry($item,'html',$item_contact,$owner,true);
				}

				logger('notifier: slapdelivery: ' . $contact['name']);
				foreach($slaps as $slappy) {
					if($contact['notify']) {
						$deliver_status = slapper($owner,$contact['notify'],$slappy);
						if($deliver_status == (-1)) {
							// queue message for redelivery
							q("INSERT INTO `queue` ( `cid`, `created`, `last`, `content`)
								VALUES ( %d, '%s', '%s', '%s') ",
								intval($contact['id']),
								dbesc(datetime_convert()),
								dbesc(datetime_convert()),
								dbesc($slappy)
							);								
						}
					}
				}
			}

			break;

		case NETWORK_MAIL :
						
			if(get_config('system','dfrn_only'))
				break;
			// WARNING: does not currently convert to RFC2047 header encodings, etc.

			$addr = $contact['addr'];
			if(! strlen($addr))
				break;

			if($cmd === 'wall-new' || $cmd === 'comment-new') {

				$it = null;
				if($cmd === 'wall-new') 
					$it = $items[0];
				else {
					$r = q("SELECT * FROM `item` WHERE `id` = %d AND `uid` = %d LIMIT 1", 
						intval($argv[2]),
						intval($uid)
					);
					if(count($r))
						$it = $r[0];
				}
				if(! $it)
					break;
					

				$local_user = q("SELECT * FROM `user` WHERE `uid` = %d LIMIT 1",
					intval($uid)
				);
				if(! count($local_user))
					break;
					
				$reply_to = '';
				$r1 = q("SELECT * FROM `mailacct` WHERE `uid` = %d LIMIT 1",
					intval($uid)
				);
				if($r1 && $r1[0]['reply_to'])
					$reply_to = $r1[0]['reply_to'];

				$subject  = (($it['title']) ? $it['title'] : t("\x28no subject\x29")) ;
				$headers  = 'From: ' . $local_user[0]['username'] . ' <' . $local_user[0]['email'] . '>' . "\n";
				if($reply_to)
					$headers .= 'Reply-to: ' . $reply_to . "\n";
				$headers .= 'Message-id: <' . $it['uri'] . '>' . "\n";
				if($it['uri'] !== $it['parent-uri']) {
					$header .= 'References: <' . $it['parent-uri'] . '>' . "\n";
					if(! strlen($it['title'])) {
						$r = q("SELECT `title` FROM `item` WHERE `parent-uri` = '%s' LIMIT 1",
							dbesc($it['parent-uri'])
						);
						if(count($r)) {
							$subtitle = $r[0]['title'];
							if($subtitle) {
								if(strncasecmp($subtitle,'RE:',3))
									$subject = $subtitle;
								else
									$subject = 'Re: ' . $subtitle;
							}
						}
					}
				}
				$headers .= 'MIME-Version: 1.0' . "\n";
				$headers .= 'Content-Type: text/html; charset=UTF-8' . "\n";
				$headers .= 'Content-Transfer-Encoding: 8bit' . "\n\n";
				$html    = prepare_body($it);
				$message = '<html><body>' . $html . '</body></html>';
				logger('notifier: email delivery to ' . $addr);
				mail($addr, $subject, $message, $headers);
			}
			break;

		case NETWORK_DIASPORA :
			if($public_message)
				$loc = 'public batch ' . $contact['batch'];
			else 
				$loc = $contact['name'];

			logger('delivery: diaspora batch deliver: ' . $loc);

			if(get_config('system','dfrn_only') || (! get_config('system','diaspora_enabled')) || (! $normal_mode))
				break;

			if((! $contact['pubkey']) && (! $public_message))
				break;
					
			if($target_item['verb'] === ACTIVITY_DISLIKE) {
				// unsupported
				break;
			}
			elseif(($target_item['deleted']) && ($target_item['verb'] !== ACTIVITY_LIKE)) {
			logger('delivery: diaspora retract: ' . $loc);
				// diaspora delete, 
				diaspora_send_retraction($target_item,$owner,$contact,$public_message);
				break;
			}
			elseif($target_item['parent'] != $target_item['id']) {

			logger('delivery: diaspora relay: ' . $loc);

				// we are the relay - send comments, likes and unlikes to our conversants
				diaspora_send_relay($target_item,$owner,$contact,$public_message);
				break;
			}		
			elseif(($top_level) && (! $walltowall)) {
				// currently no workable solution for sending walltowall
				logger('delivery: diaspora status: ' . $loc);
				diaspora_send_status($target_item,$owner,$contact,$public_message);
				break;
			}

			logger('delivery: diaspora unknown mode: ' . $contact['name']);

			break;

		case NETWORK_FEED :
		case NETWORK_FACEBOOK :
			if(get_config('system','dfrn_only'))
				break;
		default:
			break;
	}

	return;
}

if (array_search(__file__,get_included_files())===0){
  delivery_run($argv,$argc);
  killme();
}
