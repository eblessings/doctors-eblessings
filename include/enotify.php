<?php

function notification($params) {

	logger('notification: entry', LOGGER_DEBUG);

	$a = get_app();
	$banner = t('Friendica Notification');
	$product = FRIENDICA_PLATFORM;
	$siteurl = z_path();
	$thanks = t('Thank You,');
	$sitename = get_config('config','sitename');
	$site_admin = sprintf( t('%s Administrator'), $sitename);

	$sender_name = $product;
	$hostname = $a->get_hostname();
	$sender_email = t('noreply') . '@' . $hostname;
	$additional_mail_header = "";

	if(array_key_exists('item',$params)) {
		$title = $params['item']['title'];
		$body = $params['item']['body'];
	}
	else {
		$title = $body = '';
	}

	if($params['otype'] === 'item')
		$possess_desc = t('%s post');
	if($params['otype'] == 'photo')
		$possess_desc = t('%s photo');


	if($params['type'] == NOTIFY_MAIL) {

		$subject = 	sprintf( t('[Friendica:Notify] New mail received at %s'),$sitename);

		$preamble = sprintf( t('%s sent you a new private message at %s.'),$params['source_name'],$sitename);
		$epreamble = sprintf( t('%s sent you %s.'),'[url=' . $params['source_link'] . ']' . $params['source_name'] . '[/url]', '[url=$itemlink]' . t('a private message') . '[/url]');
		$sitelink = t('Please visit %s to view and/or reply to your private messages.');
		$tsitelink = sprintf( $sitelink, $siteurl . '/message/' . $params['item']['id'] );
		$hsitelink = sprintf( $sitelink, '<a href="' . $siteurl . '/message/' . $params['item']['id'] . '">' . $sitename . '</a>');
		$itemlink = $siteurl . '/message/' . $params['item']['id'];
	}

	if($params['type'] == NOTIFY_COMMENT) {
//		logger("notification: params = " . print_r($params, true), LOGGER_DEBUG);

		$parent_id = $params['parent'];


		// if it's a post figure out who's post it is.

		$p = null;

		if($params['otype'] === 'item' && $parent_id) {
			$p = q("select * from item where id = %d and uid = %d limit 1",
				intval($parent_id),
				intval($params['uid'])
			);
		}

		$dest_str = sprintf($possess_desc,'a');
		if($p)
			$dest_str = sprintf($possess_desc,sprintf( t("%s's"),$p[0]['author-name']));
		
		if($p[0]['owner-name'] == $p[0]['author-name'] && $p[0]['wall'])
			$dest_str = sprintf($possess_desc, t('your') );

		// Some mail softwares relies on subject field for threading.
		// So, we cannot have different subjects for notifications of the same thread.
		// Before this we have the name of the replier on the subject rendering 
		// differents subjects for messages on the same thread.

		$subject = sprintf( t('[Friendica:Notify] Comment to conversation #%d by %s'), $parent_id, $params['source_name']);
		$preamble = sprintf( t('%s commented on an item/conversation you have been following.'), $params['source_name']); 
		$epreamble = sprintf( t('%s commented on %s.'), '[url=' . $params['source_link'] . ']' . $params['source_name'] . '[/url]', '[url=$itemlink]' . $dest_str . '[/url]'); 

		$sitelink = t('Please visit %s to view and/or reply to the conversation.');
		$tsitelink = sprintf( $sitelink, $siteurl );
		$hsitelink = sprintf( $sitelink, '<a href="' . $siteurl . '">' . $sitename . '</a>');
		$itemlink =  $params['link'];
	}

	if($params['type'] == NOTIFY_WALL) {
		$subject = sprintf( t('[Friendica:Notify] %s posted to your profile wall') , $params['source_name']);

		$preamble = sprintf( t('%s posted to your profile wall at %s') , $params['source_name'], $sitename);
		
		$epreamble = sprintf( t('%s posted to %s') , '[url=' . $params['source_link'] . ']' . $params['source_name'] . '[/url]', '[url=$itemlink]' . t('your profile wall.') . '[/url]'); 
		
		$sitelink = t('Please visit %s to view and/or reply to the conversation.');
		$tsitelink = sprintf( $sitelink, $siteurl );
		$hsitelink = sprintf( $sitelink, '<a href="' . $siteurl . '">' . $sitename . '</a>');
		$itemlink =  $params['link'];
	}

	if($params['type'] == NOTIFY_TAGSELF) {
		$subject =	sprintf( t('[Friendica:Notify] %s tagged you') , $params['source_name']);
		$preamble = sprintf( t('%s tagged you at %s') , $params['source_name'], $sitename);
		$epreamble = sprintf( t('%s %s.') , '[url=' . $params['source_link'] . ']' . $params['source_name'] . '[/url]', '[url=' . $params['link'] . ']' . t('tagged you') . '[/url]'); 

		$sitelink = t('Please visit %s to view and/or reply to the conversation.');
		$tsitelink = sprintf( $sitelink, $siteurl );
		$hsitelink = sprintf( $sitelink, '<a href="' . $siteurl . '">' . $sitename . '</a>');
		$itemlink =  $params['link'];
	}

	if($params['type'] == NOTIFY_TAGSHARE) {
		$subject =	sprintf( t('[Friendica:Notify] %s tagged your post') , $params['source_name']);
		$preamble = sprintf( t('%s tagged your post at %s') , $params['source_name'], $sitename);
		$epreamble = sprintf( t('%s tagged %s') , '[url=' . $params['source_link'] . ']' . $params['source_name'] . '[/url]', '[url=$itemlink]' . t('your post') . '[/url]' ); 

		$sitelink = t('Please visit %s to view and/or reply to the conversation.');
		$tsitelink = sprintf( $sitelink, $siteurl );
		$hsitelink = sprintf( $sitelink, '<a href="' . $siteurl . '">' . $sitename . '</a>');
		$itemlink =  $params['link'];
	}

	if($params['type'] == NOTIFY_INTRO) {
		$subject = sprintf( t('[Friendica:Notify] Introduction received'));
		$preamble = sprintf( t('You\'ve received an introduction from \'%s\' at %s'), $params['source_name'], $sitename); 
		$epreamble = sprintf( t('You\'ve received %s from %s.'), '[url=$itemlink]' . t('an introduction') . '[/url]' , '[url=' . $params['source_link'] . ']' . $params['source_name'] . '[/url]'); 
		$body = sprintf( t('You may visit their profile at %s'),$params['source_link']);

		$sitelink = t('Please visit %s to approve or reject the introduction.');
		$tsitelink = sprintf( $sitelink, $siteurl );
		$hsitelink = sprintf( $sitelink, '<a href="' . $siteurl . '">' . $sitename . '</a>');
		$itemlink =  $params['link'];
	}

	if($params['type'] == NOTIFY_SUGGEST) {
		$subject = sprintf( t('[Friendica:Notify] Friend suggestion received'));
		$preamble = sprintf( t('You\'ve received a friend suggestion from \'%s\' at %s'), $params['source_name'], $sitename); 
		$epreamble = sprintf( t('You\'ve received %s for %s from %s.'),
			'[url=$itemlink]' . t('a friend suggestion') . '[/url]',
			'[url=' . $params['item']['url'] . ']' . $params['item']['name'] . '[/url]', 
			'[url=' . $params['source_link'] . ']' . $params['source_name'] . '[/url]'); 
		$body = t('Name:') . ' ' . $params['item']['name'] . "\n";
		$body .= t('Photo:') . ' ' . $params['item']['photo'] . "\n";
		$body .= sprintf( t('You may visit their profile at %s'),$params['item']['url']);

		$sitelink = t('Please visit %s to approve or reject the suggestion.');
		$tsitelink = sprintf( $sitelink, $siteurl );
		$hsitelink = sprintf( $sitelink, '<a href="' . $siteurl . '">' . $sitename . '</a>');
		$itemlink =  $params['link'];
	}

	if($params['type'] == NOTIFY_CONFIRM) {

	}

	// from here on everything is in the recipients language

	push_lang($params['language']);

	require_once('include/html2bbcode.php');	

	do {
		$dups = false;
		$hash = random_string();
        $r = q("SELECT `id` FROM `notify` WHERE `hash` = '%s' LIMIT 1",
			dbesc($hash));
		if(count($r))
			$dups = true;
	} while($dups == true);


	$datarray = array();
	$datarray['hash']  = $hash;
	$datarray['name']  = $params['source_name'];
	$datarray['url']   = $params['source_link'];
	$datarray['photo'] = $params['source_photo'];
	$datarray['date']  = datetime_convert();
	$datarray['uid']   = $params['uid'];
	$datarray['link']  = $itemlink;
	$datarray['parent'] = $parent_id;
	$datarray['type']  = $params['type'];
	$datarray['verb']  = $params['verb'];
	$datarray['otype'] = $params['otype'];
 
	call_hooks('enotify_store', $datarray);

	// create notification entry in DB

	$r = q("insert into notify (hash,name,url,photo,date,uid,link,parent,type,verb,otype)
		values('%s','%s','%s','%s','%s',%d,'%s',%d,%d,'%s','%s')",
		dbesc($datarray['hash']),
		dbesc($datarray['name']),
		dbesc($datarray['url']),
		dbesc($datarray['photo']),
		dbesc($datarray['date']),
		intval($datarray['uid']),
		dbesc($datarray['link']),
		intval($datarray['parent']),
		intval($datarray['type']),
		dbesc($datarray['verb']),
		dbesc($datarray['otype'])
	);

	$r = q("select id from notify where hash = '%s' and uid = %d limit 1",
		dbesc($hash),
		intval($params['uid'])
	);
	if($r)
		$notify_id = $r[0]['id'];
	else
		return;

	$itemlink = $a->get_baseurl() . '/notify/view/' . $notify_id;
	$msg = replace_macros($epreamble,array('$itemlink' => $itemlink));
	$r = q("update notify set msg = '%s' where id = %d and uid = %d limit 1",
		dbesc($msg),
		intval($notify_id),
		intval($params['uid'])
	);
		


	// send email notification if notification preferences permit

	require_once('bbcode.php');
	if(intval($params['notify_flags']) & intval($params['type'])) {

		logger('notification: sending notification email');

		$id_for_parent = "${params['parent']}@${hostname}";

		// Is this the first email notification for this parent item and user?
		
		$r = q("select `id` from `notify-threads` where `master-parent-item` = %d and `receiver-uid` = %d limit 1", 
			intval($params['parent']),
			intval($params['uid']) );

		// If so, create the record of it and use a message-id smtp header.

		if(!$r) {
			logger("norify_id:" . intval($notify_id). ", parent: " . intval($params['parent']) . "uid: " . 
intval($params['uid']), LOGGER_DEBUG);
			$r = q("insert into `notify-threads` (`notify-id`, `master-parent-item`, `receiver-uid`, `parent-item`)
				values(%d,%d,%d,%d)",
				intval($notify_id),
				intval($params['parent']),
				intval($params['uid']), 
				0 );

			$additional_mail_header .= "Message-ID: <${id_for_parent}>\n";
			$log_msg = "include/enotify: No previous notification found for this parent:\n" . 
					"  parent: ${params['parent']}\n" . "  uid   : ${params['uid']}\n";
			logger($log_msg, LOGGER_DEBUG);
		}

		// If not, just "follow" the thread.

		else {
			$additional_mail_header = "References: <${id_for_parent}>\nIn-Reply-To: <${id_for_parent}>\n";
			logger("include/enotify: There's already a notification for this parent:\n" . print_r($r, true), LOGGER_DEBUG);
		}



		$textversion = strip_tags(html_entity_decode(bbcode(stripslashes(str_replace(array("\\r\\n", "\\r", "\\n"), "\n",
			$body))),ENT_QUOTES,'UTF-8'));
		$htmlversion = html_entity_decode(bbcode(stripslashes(str_replace(array("\\r\\n", "\\r","\\n\\n" ,"\\n"), 
			"<br />\n",$body))));

		$datarray = array();
		$datarray['banner'] = $banner;
		$datarray['product'] = $product;
		$datarray['preamble'] = $preamble;
		$datarray['sitename'] = $sitename;
		$datarray['siteurl'] = $siteurl;
		$datarray['type'] = $params['type'];
		$datarray['parent'] = $params['parent'];
		$datarray['source_name'] = $params['source_name'];
		$datarray['source_link'] = $params['source_link'];
		$datarray['source_photo'] = $params['source_photo'];
		$datarray['uid'] = $params['uid'];
		$datarray['username'] = $params['to_name'];
		$datarray['hsitelink'] = $hsitelink;
		$datarray['tsitelink'] = $tsitelink;
		$datarray['hitemlink'] = '<a href="' . $itemlink . '">' . $itemlink . '</a>';
		$datarray['titemlink'] = $itemlink;
		$datarray['thanks'] = $thanks;
		$datarray['site_admin'] = $site_admin;
		$datarray['title'] = stripslashes($title);
		$datarray['htmlversion'] = $htmlversion;
		$datarray['textversion'] = $textversion;
		$datarray['subject'] = $subject;
		$datarray['headers'] = $additional_mail_header;

		call_hooks('enotify_mail', $datarray);

		// load the template for private message notifications
		$tpl = get_markup_template('email_notify_html.tpl');
		$email_html_body = replace_macros($tpl,array(
			'$banner'       => $datarray['banner'],
			'$product'      => $datarray['product'],
			'$preamble'     => $datarray['preamble'],
			'$sitename'     => $datarray['sitename'],
			'$siteurl'      => $datarray['siteurl'],
			'$source_name'  => $datarray['source_name'],
			'$source_link'  => $datarray['source_link'],
			'$source_photo' => $datarray['source_photo'],
			'$username'     => $datarray['to_name'],
			'$hsitelink'    => $datarray['hsitelink'],
			'$hitemlink'    => $datarray['hitemlink'],
			'$thanks'       => $datarray['thanks'],
			'$site_admin'   => $datarray['site_admin'],
			'$title'		=> $datarray['title'],
			'$htmlversion'	=> $datarray['htmlversion'],	
		));
		
		// load the template for private message notifications
		$tpl = get_markup_template('email_notify_text.tpl');
		$email_text_body = replace_macros($tpl,array(
			'$banner'       => $datarray['banner'],
			'$product'      => $datarray['product'],
			'$preamble'     => $datarray['preamble'],
			'$sitename'     => $datarray['sitename'],
			'$siteurl'      => $datarray['siteurl'],
			'$source_name'  => $datarray['source_name'],
			'$source_link'  => $datarray['source_link'],
			'$source_photo' => $datarray['source_photo'],
			'$username'     => $datarray['to_name'],
			'$tsitelink'    => $datarray['tsitelink'],
			'$titemlink'    => $datarray['titemlink'],
			'$thanks'       => $datarray['thanks'],
			'$site_admin'   => $datarray['site_admin'],
			'$title'		=> $datarray['title'],
			'$textversion'	=> $datarray['textversion'],	
		));

//		logger('text: ' . $email_text_body);

		// use the EmailNotification library to send the message

		enotify::send(array(
			'fromName' => $sender_name,
			'fromEmail' => $sender_email,
			'replyTo' => $sender_email,
			'toEmail' => $params['to_email'],
			'messageSubject' => $datarray['subject'],
			'htmlVersion' => $email_html_body,
			'textVersion' => $email_text_body,
			'additionalMailHeader' => $datarray['headers'],
		));
	}

	pop_lang();

}

require_once('include/email.php');

class enotify {
	/**
	 * Send a multipart/alternative message with Text and HTML versions
	 *
	 * @param fromName			name of the sender
	 * @param fromEmail			email fo the sender
	 * @param replyTo			replyTo address to direct responses
	 * @param toEmail			destination email address
	 * @param messageSubject	subject of the message
	 * @param htmlVersion		html version of the message
	 * @param textVersion		text only version of the message
	 * @param additionalMailHeader	additions to the smtp mail header
	 */
	static public function send($params) {

		$fromName = email_header_encode($params['fromName'],'UTF-8'); 
		$messageSubject = email_header_encode($params['messageSubject'],'UTF-8');
		
		// generate a mime boundary
		$mimeBoundary   =rand(0,9)."-"
				.rand(10000000000,9999999999)."-"
				.rand(10000000000,9999999999)."=:"
				.rand(10000,99999);

		// generate a multipart/alternative message header
		$messageHeader =
			$params['additionalMailHeader'] .
			"From: {$params['fromName']} <{$params['fromEmail']}>\n" . 
			"Reply-To: {$params['fromName']} <{$params['replyTo']}>\n" .
			"MIME-Version: 1.0\n" .
			"Content-Type: multipart/alternative; boundary=\"{$mimeBoundary}\"";

		// assemble the final multipart message body with the text and html types included
		$textBody	=	chunk_split(base64_encode($params['textVersion']));
		$htmlBody	=	chunk_split(base64_encode($params['htmlVersion']));
		$multipartMessageBody =
			"--" . $mimeBoundary . "\n" .					// plain text section
			"Content-Type: text/plain; charset=UTF-8\n" .
			"Content-Transfer-Encoding: base64\n\n" .
			$textBody . "\n" .
			"--" . $mimeBoundary . "\n" .					// text/html section
			"Content-Type: text/html; charset=UTF-8\n" .
			"Content-Transfer-Encoding: base64\n\n" .
			$htmlBody . "\n" .
			"--" . $mimeBoundary . "--\n";					// message ending

		// send the message
		$res = mail(
			$params['toEmail'],	 									// send to address
			$params['messageSubject'],								// subject
			$multipartMessageBody,	 						// message body
			$messageHeader									// message headers
		);
		logger("notification: enotify::send returns " . $res, LOGGER_DEBUG);
	}
}
?>
