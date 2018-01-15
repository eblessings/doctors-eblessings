<?php
/**
 * Module: invite.php
 *
 * Send email invitations to join social network
 *
 */
use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\PConfig;
use Friendica\Core\System;
use Friendica\Protocol\Email;

function invite_post(App $a) {

	if (! local_user()) {
		notice( t('Permission denied.') . EOL);
		return;
	}

	check_form_security_token_redirectOnErr('/', 'send_invite');

	$max_invites = intval(Config::get('system','max_invites'));
	if (! $max_invites) {
		$max_invites = 50;
	}

	$current_invites = intval(PConfig::get(local_user(),'system','sent_invites'));
	if ($current_invites > $max_invites) {
		notice( t('Total invitation limit exceeded.') . EOL);
		return;
	}


	$recips  = ((x($_POST,'recipients')) ? explode("\n",$_POST['recipients']) : []);
	$message = ((x($_POST,'message'))    ? notags(trim($_POST['message']))    : '');

	$total = 0;

	if (Config::get('system','invitation_only')) {
		$invonly = true;
		$x = PConfig::get(local_user(),'system','invites_remaining');
		if ((! $x) && (! is_site_admin())) {
			return;
		}
	}

	foreach ($recips as $recip) {

		$recip = trim($recip);

		if (! valid_email($recip)) {
			notice(  sprintf( t('%s : Not a valid email address.'), $recip) . EOL);
			continue;
		}

		if ($invonly && ($x || is_site_admin())) {
			$code = autoname(8) . srand(1000,9999);
			$nmessage = str_replace('$invite_code',$code,$message);

			$r = q("INSERT INTO `register` (`hash`,`created`) VALUES ('%s', '%s') ",
				dbesc($code),
				dbesc(datetime_convert())
			);

			if (! is_site_admin()) {
				$x --;
				if ($x >= 0) {
					PConfig::set(local_user(),'system','invites_remaining',$x);
				} else {
					return;
				}
			}
		} else {
			$nmessage = $message;
		}

		$res = mail($recip, Email::encodeHeader(t('Please join us on Friendica'),'UTF-8'),
			$nmessage,
			"From: " . $a->user['email'] . "\n"
			. 'Content-type: text/plain; charset=UTF-8' . "\n"
			. 'Content-transfer-encoding: 8bit' );

		if ($res) {
			$total ++;
			$current_invites ++;
			PConfig::set(local_user(),'system','sent_invites',$current_invites);
			if($current_invites > $max_invites) {
				notice( t('Invitation limit exceeded. Please contact your site administrator.') . EOL);
				return;
			}
		} else {
			notice( sprintf( t('%s : Message delivery failed.'), $recip) . EOL);
		}

	}
	notice( sprintf( tt("%d message sent.", "%d messages sent.", $total) , $total) . EOL);
	return;
}


function invite_content(App $a) {

	if (! local_user()) {
		notice( t('Permission denied.') . EOL);
		return;
	}

	$tpl = get_markup_template('invite.tpl');
	$invonly = false;

	if (Config::get('system','invitation_only')) {
		$invonly = true;
		$x = PConfig::get(local_user(),'system','invites_remaining');
		if ((! $x) && (! is_site_admin())) {
			notice( t('You have no more invitations available') . EOL);
			return '';
		}
	}

	$dirloc = Config::get('system','directory');
	if (strlen($dirloc)) {
		if ($a->config['register_policy'] == REGISTER_CLOSED) {
			$linktxt = sprintf( t('Visit %s for a list of public sites that you can join. Friendica members on other sites can all connect with each other, as well as with members of many other social networks.'), $dirloc . '/servers');
		} else {
			$linktxt = sprintf( t('To accept this invitation, please visit and register at %s or any other public Friendica website.'), System::baseUrl())
			. "\r\n" . "\r\n" . sprintf( t('Friendica sites all inter-connect to create a huge privacy-enhanced social web that is owned and controlled by its members. They can also connect with many traditional social networks. See %s for a list of alternate Friendica sites you can join.'),$dirloc . '/servers');
		}
	} else { // there is no global directory URL defined
		if ($a->config['register_policy'] == REGISTER_CLOSED) {
			$o = t('Our apologies. This system is not currently configured to connect with other public sites or invite members.');
			return $o;
		} else {
			$linktxt = sprintf( t('To accept this invitation, please visit and register at %s.'), System::baseUrl()
			. "\r\n" . "\r\n" . t('Friendica sites all inter-connect to create a huge privacy-enhanced social web that is owned and controlled by its members. They can also connect with many traditional social networks.'));
		}
	}

	$o = replace_macros($tpl, [
		'$form_security_token' => get_form_security_token("send_invite"),
		'$invite'              => t('Send invitations'),
		'$addr_text'           => t('Enter email addresses, one per line:'),
		'$msg_text'            => t('Your message:'),
		'$default_message'     => t('You are cordially invited to join me and other close friends on Friendica - and help us to create a better social web.') . "\r\n" . "\r\n"
			. $linktxt
			. "\r\n" . "\r\n" . (($invonly) ? t('You will need to supply this invitation code: $invite_code') . "\r\n" . "\r\n" : '') .t('Once you have registered, please connect with me via my profile page at:')
			. "\r\n" . "\r\n" . System::baseUrl() . '/profile/' . $a->user['nickname']
			. "\r\n" . "\r\n" . t('For more information about the Friendica project and why we feel it is important, please visit http://friendi.ca') . "\r\n" . "\r\n"  ,
		'$submit'              => t('Submit')
	]);

	return $o;
}
