<?php

require_once('library/HTML5/Parser.php');

if(! function_exists('scrape_dfrn')) {
function scrape_dfrn($url) {

	$a = get_app();

	$ret = array();

	logger('scrape_dfrn: url=' . $url);

	$s = fetch_url($url);

	if(! $s) 
		return $ret;

	$headers = $a->get_curl_headers();
	logger('scrape_dfrn: headers=' . $headers, LOGGER_DEBUG);


	$lines = explode("\n",$headers);
	if(count($lines)) {
		foreach($lines as $line) {				
			// don't try and run feeds through the html5 parser
			if(stristr($line,'content-type:') && ((stristr($line,'application/atom+xml')) || (stristr($line,'application/rss+xml'))))
				return ret;
		}
	}


	$dom = HTML5_Parser::parse($s);

	if(! $dom)
		return $ret;

	$items = $dom->getElementsByTagName('link');

	// get DFRN link elements

	foreach($items as $item) {
		$x = $item->getAttribute('rel');
		if(($x === 'alternate') && ($item->getAttribute('type') === 'application/atom+xml'))
			$ret['feed_atom'] = $item->getAttribute('href');
		if(substr($x,0,5) == "dfrn-")
			$ret[$x] = $item->getAttribute('href');
		if($x === 'lrdd') {
			$decoded = urldecode($item->getAttribute('href'));
			if(preg_match('/acct:([^@]*)@/',$decoded,$matches))
				$ret['nick'] = $matches[1];
		}
	}

	// Pull out hCard profile elements

	$items = $dom->getElementsByTagName('*');
	foreach($items as $item) {
		if(attribute_contains($item->getAttribute('class'), 'vcard')) {
			$level2 = $item->getElementsByTagName('*');
			foreach($level2 as $x) {
				if(attribute_contains($x->getAttribute('class'),'fn'))
					$ret['fn'] = $x->textContent;
				if(attribute_contains($x->getAttribute('class'),'photo'))
					$ret['photo'] = $x->getAttribute('src');
				if(attribute_contains($x->getAttribute('class'),'key'))
					$ret['key'] = $x->textContent;
			}
		}
	}

	return $ret;
}}






if(! function_exists('validate_dfrn')) {
function validate_dfrn($a) {
	$errors = 0;
	if(! x($a,'key'))
		$errors ++;
	if(! x($a,'dfrn-request'))
		$errors ++;
	if(! x($a,'dfrn-confirm'))
		$errors ++;
	if(! x($a,'dfrn-notify'))
		$errors ++;
	if(! x($a,'dfrn-poll'))
		$errors ++;
	return $errors;
}}

if(! function_exists('scrape_meta')) {
function scrape_meta($url) {

	$a = get_app();

	$ret = array();

	logger('scrape_meta: url=' . $url);

	$s = fetch_url($url);

	if(! $s) 
		return $ret;

	$headers = $a->get_curl_headers();
	logger('scrape_meta: headers=' . $headers, LOGGER_DEBUG);

	$lines = explode("\n",$headers);
	if(count($lines)) {
		foreach($lines as $line) {				
			// don't try and run feeds through the html5 parser
			if(stristr($line,'content-type:') && ((stristr($line,'application/atom+xml')) || (stristr($line,'application/rss+xml'))))
				return ret;
		}
	}



	$dom = HTML5_Parser::parse($s);

	if(! $dom)
		return $ret;

	$items = $dom->getElementsByTagName('meta');

	// get DFRN link elements

	foreach($items as $item) {
		$x = $item->getAttribute('name');
		if(substr($x,0,5) == "dfrn-")
			$ret[$x] = $item->getAttribute('content');
	}

	return $ret;
}}


if(! function_exists('scrape_vcard')) {
function scrape_vcard($url) {

	$a = get_app();

	$ret = array();

	logger('scrape_vcard: url=' . $url);

	$s = fetch_url($url);

	if(! $s) 
		return $ret;

	$headers = $a->get_curl_headers();
	$lines = explode("\n",$headers);
	if(count($lines)) {
		foreach($lines as $line) {				
			// don't try and run feeds through the html5 parser
			if(stristr($line,'content-type:') && ((stristr($line,'application/atom+xml')) || (stristr($line,'application/rss+xml'))))
				return ret;
		}
	}

	$dom = HTML5_Parser::parse($s);

	if(! $dom)
		return $ret;

	// Pull out hCard profile elements

	$items = $dom->getElementsByTagName('*');
	foreach($items as $item) {
		if(attribute_contains($item->getAttribute('class'), 'vcard')) {
			$level2 = $item->getElementsByTagName('*');
			foreach($level2 as $x) {
				if(attribute_contains($x->getAttribute('class'),'fn'))
					$ret['fn'] = $x->textContent;
				if((attribute_contains($x->getAttribute('class'),'photo'))
					|| (attribute_contains($x->getAttribute('class'),'avatar')))
					$ret['photo'] = $x->getAttribute('src');
				if((attribute_contains($x->getAttribute('class'),'nickname'))
					|| (attribute_contains($x->getAttribute('class'),'uid')))
					$ret['nick'] = $x->textContent;
			}
		}
	}

	return $ret;
}}


if(! function_exists('scrape_feed')) {
function scrape_feed($url) {

	$a = get_app();

	$ret = array();
	$s = fetch_url($url);

	if(! $s) 
		return $ret;

	$headers = $a->get_curl_headers();
	logger('scrape_feed: headers=' . $headers, LOGGER_DEBUG);

	$lines = explode("\n",$headers);
	if(count($lines)) {
		foreach($lines as $line) {				
			if(stristr($line,'content-type:')) {
				if(stristr($line,'application/atom+xml') || stristr($s,'<feed')) {
					$ret['feed_atom'] = $url;
					return $ret;
				}
 				if(stristr($line,'application/rss+xml') || stristr($s,'<rss')) {
					$ret['feed_rss'] = $url;
					return $ret;
				}
			}
		}
	}

	$dom = HTML5_Parser::parse($s);

	if(! $dom)
		return $ret;


	$items = $dom->getElementsByTagName('img');

	// get img elements (twitter)

	if($items) {
		foreach($items as $item) {
			$x = $item->getAttribute('id');
			if($x === 'profile-image') {
				$ret['photo'] = $item->getAttribute('src');
			}
		}
	}


	$head = $dom->getElementsByTagName('base');
	if($head) {
		foreach($head as $head0) {
			$basename = $head0->getAttribute('href');
			break;
		}
	}
	if(! $basename)
		$basename = substr($url,0,strrpos($url,'/')) . '/';

	$items = $dom->getElementsByTagName('link');

	// get Atom/RSS link elements, take the first one of either.

	if($items) {
		foreach($items as $item) {
			$x = $item->getAttribute('rel');
			if(($x === 'alternate') && ($item->getAttribute('type') === 'application/atom+xml')) {
				if(! x($ret,'feed_atom'))
					$ret['feed_atom'] = $item->getAttribute('href');
			}
			if(($x === 'alternate') && ($item->getAttribute('type') === 'application/rss+xml')) {
				if(! x($ret,'feed_rss'))
					$ret['feed_rss'] = $item->getAttribute('href');
			}
		}	
	}

	// Drupal and perhaps others only provide relative URL's. Turn them into absolute.

	if(x($ret,'feed_atom') && (! strstr($ret['feed_atom'],'://')))
		$ret['feed_atom'] = $basename . $ret['feed_atom'];
	if(x($ret,'feed_rss') && (! strstr($ret['feed_rss'],'://')))
		$ret['feed_rss'] = $basename . $ret['feed_rss'];

	return $ret;
}}


function probe_url($url) {
	require_once('include/email.php');

	$result = array();

	if(! $url)
		return $result;

	$diaspora = false;	
	$email_conversant = false;

	$twitter = ((strpos($url,'twitter.com') !== false) ? true : false);

	if(! $twitter) {
		$links = lrdd($url);

		if(count($links)) {
			logger('probe_url: found lrdd links: ' . print_r($links,true), LOGGER_DATA);
			foreach($links as $link) {
				if($link['@attributes']['rel'] === NAMESPACE_DFRN)
					$dfrn = unamp($link['@attributes']['href']);
				if($link['@attributes']['rel'] === 'salmon')
					$notify = unamp($link['@attributes']['href']);
				if($link['@attributes']['rel'] === NAMESPACE_FEED)
					$poll = unamp($link['@attributes']['href']);
				if($link['@attributes']['rel'] === 'http://microformats.org/profile/hcard')
					$hcard = unamp($link['@attributes']['href']);
				if($link['@attributes']['rel'] === 'http://webfinger.net/rel/profile-page')
					$profile = unamp($link['@attributes']['href']);
				if($link['@attributes']['rel'] === 'http://joindiaspora.com/seed_location')
					$diaspora = true;
			}

			// Status.Net can have more than one profile URL. We need to match the profile URL
			// to a contact on incoming messages to prevent spam, and we won't know which one
			// to match. So in case of two, one of them is stored as an alias. Only store URL's
			// and not webfinger user@host aliases. If they've got more than two non-email style
			// aliases, let's hope we're lucky and get one that matches the feed author-uri because 
			// otherwise we're screwed.

			foreach($links as $link) {
				if($link['@attributes']['rel'] === 'alias') {
					if(strpos($link['@attributes']['href'],'@') === false) {
						if(isset($profile)) {
							if($link['@attributes']['href'] !== $profile)
								$alias = unamp($link['@attributes']['href']);
						}
						else
							$profile = unamp($link['@attributes']['href']);
					}
				}
			}
		}
		else {

			// Check email

			$orig_url = $url;
			if((strpos($orig_url,'@')) && validate_email($orig_url)) {
				$x = q("SELECT `prvkey` FROM `user` WHERE `uid` = %d LIMIT 1",
					intval(local_user())
				);
				$r = q("SELECT * FROM `mailacct` WHERE `uid` = %d AND `server` != '' LIMIT 1",
					intval(local_user())
				);
				if(count($x) && count($r)) {
				    $mailbox = construct_mailbox_name($r[0]);
					$password = '';
					openssl_private_decrypt(hex2bin($r[0]['pass']),$password,$x[0]['prvkey']);
					$mbox = email_connect($mailbox,$r[0]['user'],$password);
					unset($password);
				}
				if($mbox) {
					$msgs = email_poll($mbox,$orig_url);
					if(count($msgs)) {
						$addr = $orig_url;
						$network = NETWORK_MAIL;
						$name = substr($url,0,strpos($url,'@'));
						$profile = 'http://' . substr($url,strpos($url,'@')+1);
						// fix nick character range
						$vcard = array('fn' => $name, 'nick' => $name, 'photo' => gravatar_img($url));
						$notify = 'smtp ' . random_string();
						$poll = 'email ' . random_string();
						$priority = 0;
						$x = email_msg_meta($mbox,$msgs[0]);
						if(stristr($x->from,$orig_url))
							$adr = imap_rfc822_parse_adrlist($x->from,'');
						elseif(stristr($x->to,$orig_url))
							$adr = imap_rfc822_parse_adrlist($x->to,'');
						if(isset($adr) && strlen($adr[0]->personal))
							$vcard['fn'] = notags($adr[0]->personal);
					}
					imap_close($mbox);
				}
			}
		}
	}	

	if(strlen($dfrn)) {
		$ret = scrape_dfrn($dfrn);
		if(is_array($ret) && x($ret,'dfrn-request')) {
			$network = NETWORK_DFRN;
			$request = $ret['dfrn-request'];
			$confirm = $ret['dfrn-confirm'];
			$notify  = $ret['dfrn-notify'];
			$poll    = $ret['dfrn-poll'];
		}
	}

	if($network !== NETWORK_DFRN && $network !== NETWORK_MAIL) {
		$network  = NETWORK_OSTATUS;
		$priority = 0;

		if($hcard) {
			$vcard = scrape_vcard($hcard);

			// Google doesn't use absolute url in profile photos
	
			if((x($vcard,'photo')) && substr($vcard['photo'],0,1) == '/') {
				$h = @parse_url($hcard);
				if($h)
					$vcard['photo'] = $h['scheme'] . '://' . $h['host'] . $vcard['photo'];
			}
		
			logger('probe_url: scrape_vcard: ' . print_r($vcard,true), LOGGER_DATA);
		}

		if(! $profile) {
			if($diaspora)
				$profile = $hcard;
			else
				$profile = $url;
		}

		if($twitter) {		
			logger('twitter: setup');
			$tid = basename($url);
			$tapi = 'https://api.twitter.com/1/statuses/user_timeline.rss';
			if(intval($tid))
				$poll = $tapi . '?user_id=' . $tid;
			else
				$poll = $tapi . '?screen_name=' . $tid;
			$profile = 'http://twitter.com/#!/' . $tid;
		}

		if(! x($vcard,'fn'))
			if(x($vcard,'nick'))
				$vcard['fn'] = $vcard['nick'];

	
		if(((! isset($vcard)) && (! $poll)) || ($twitter)) {

			$feedret = scrape_feed($url);
			logger('probe_url: scrape_feed returns: ' . print_r($feedret,true), LOGGER_DATA);
			if(count($feedret) && ($feedret['feed_atom'] || $feedret['feed_rss'])) {
				$poll = ((x($feedret,'feed_atom')) ? unamp($feedret['feed_atom']) : unamp($feedret['feed_rss']));
				$vcard = array();
			}

			if(x($feedret,'photo'))
				$vcard['photo'] = $feedret['photo'];
			require_once('library/simplepie/simplepie.inc');
		    $feed = new SimplePie();
			$xml = fetch_url($poll);

			logger('probe_url: fetch feed: ' . $poll . ' returns: ' . $xml, LOGGER_DATA);
			$a = get_app();

			logger('probe_url: scrape_feed: headers: ' . $a->get_curl_headers(), $LOGGER_DATA);

   			$feed->set_raw_data($xml);

		    $feed->init();
			if($feed->error())
				logger('probe_url: scrape_feed: Error parsing XML: ' . $feed->error());

			if(! x($vcard,'photo'))
				$vcard['photo'] = $feed->get_image_url();
			$author = $feed->get_author();
			if($author) {			
				$vcard['fn'] = unxmlify(trim($author->get_name()));
				if(! $vcard['fn'])
					$vcard['fn'] = trim(unxmlify($author->get_email()));
				if(strpos($vcard['fn'],'@') !== false)
					$vcard['fn'] = substr($vcard['fn'],0,strpos($vcard['fn'],'@'));
				$email = unxmlify($author->get_email());
			}
			else {
				$item = $feed->get_item(0);
				if($item) {
					$author = $item->get_author();
					if($author) {			
						$vcard['fn'] = trim(unxmlify($author->get_name()));
						if(! $vcard['fn'])
							$vcard['fn'] = trim(unxmlify($author->get_email()));
						if(strpos($vcard['fn'],'@') !== false)
							$vcard['fn'] = substr($vcard['fn'],0,strpos($vcard['fn'],'@'));
						$email = unxmlify($author->get_email());
					}
					if(! $vcard['photo']) {
						$rawmedia = $item->get_item_tags('http://search.yahoo.com/mrss/','thumbnail');
						if($rawmedia && $rawmedia[0]['attribs']['']['url'])
							$vcard['photo'] = unxmlify($rawmedia[0]['attribs']['']['url']);
					}
				}
			}
			if((! $vcard['photo']) && strlen($email))
				$vcard['photo'] = gravatar_img($email);
			if($poll === $profile)
				$lnk = $feed->get_permalink();
			if(isset($lnk) && strlen($lnk))
				$profile = $lnk;	

			if(! (x($vcard,'fn')))
				$vcard['fn'] = notags($feed->get_title());
			if(! (x($vcard,'fn')))
				$vcard['fn'] = notags($feed->get_description());

			if(strpos($vcard['fn'],'Twitter / ') !== false) {
				$vcard['fn'] = substr($vcard['fn'],strpos($vcard['fn'],'/')+1);
				$vcard['fn'] = trim($vcard['fn']);
			}
			if(! x($vcard,'nick')) {
				$vcard['nick'] = strtolower(notags(unxmlify($vcard['fn'])));
				if(strpos($vcard['nick'],' '))
					$vcard['nick'] = trim(substr($vcard['nick'],0,strpos($vcard['nick'],' ')));
			}
			$network = 'feed';
			$priority = 2;
		}
	}

	if(! x($vcard,'photo')) {
		$a = get_app();
		$vcard['photo'] = $a->get_baseurl() . '/images/default-profile.jpg' ; 
	}
	$vcard['fn'] = notags($vcard['fn']);
	$vcard['nick'] = notags($vcard['nick']);


	$result['name'] = $vcard['fn'];
	$result['nick'] = $vcard['nick'];
	$result['url'] = $profile;
	$result['addr'] = $addr;
	$result['notify'] = $notify;
	$result['poll'] = $poll;
	$result['request'] = $request;
	$result['confirm'] = $confirm;
	$result['photo'] = $vcard['photo'];
	$result['priority'] = $priority;
	$result['network'] = $network;
	$result['alias'] = $alias;

	logger('probe_url: ' . print_r($result,true), LOGGER_DEBUG);

	return $result;
}
