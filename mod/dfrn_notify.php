<?php

/**
 * @file mod/dfrn_notify.php
 * @brief The dfrn notify endpoint
 * @see PDF with dfrn specs: https://github.com/friendica/friendica/blob/master/spec/dfrn2.pdf
 */

use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Protocol\DFRN;
use Friendica\Protocol\Diaspora;

require_once 'include/items.php';

function dfrn_notify_post(App $a) {
	logger(__function__, LOGGER_TRACE);

	$postdata = file_get_contents('php://input');

	if (empty($_POST) || !empty($postdata)) {
		$data = json_decode($postdata);
		if (is_object($data)) {
			$nick = defaults($a->argv, 1, '');

			$user = DBA::selectFirst('user', [], ['nickname' => $nick, 'account_expired' => false, 'account_removed' => false]);
			if (!DBA::isResult($user)) {
				System::httpExit(500);
			}
			dfrn_dispatch_private($user, $postdata);
		} elseif (!dfrn_dispatch_public($postdata)) {
			require_once 'mod/salmon.php';
			salmon_post($a, $postdata);
		}
	}

	$dfrn_id      = ((x($_POST,'dfrn_id'))      ? notags(trim($_POST['dfrn_id']))   : '');
	$dfrn_version = ((x($_POST,'dfrn_version')) ? (float) $_POST['dfrn_version']    : 2.0);
	$challenge    = ((x($_POST,'challenge'))    ? notags(trim($_POST['challenge'])) : '');
	$data         = ((x($_POST,'data'))         ? $_POST['data']                    : '');
	$key          = ((x($_POST,'key'))          ? $_POST['key']                     : '');
	$rino_remote  = ((x($_POST,'rino'))         ? intval($_POST['rino'])            :  0);
	$dissolve     = ((x($_POST,'dissolve'))     ? intval($_POST['dissolve'])        :  0);
	$perm         = ((x($_POST,'perm'))         ? notags(trim($_POST['perm']))      : 'r');
	$ssl_policy   = ((x($_POST,'ssl_policy'))   ? notags(trim($_POST['ssl_policy'])): 'none');
	$page         = ((x($_POST,'page'))         ? intval($_POST['page'])            :  0);

	$forum = (($page == 1) ? 1 : 0);
	$prv   = (($page == 2) ? 1 : 0);

	$writable = (-1);
	if ($dfrn_version >= 2.21) {
		$writable = (($perm === 'rw') ? 1 : 0);
	}

	$direction = (-1);
	if (strpos($dfrn_id, ':') == 1) {
		$direction = intval(substr($dfrn_id, 0, 1));
		$dfrn_id = substr($dfrn_id, 2);
	}

	if (!DBA::exists('challenge', ['dfrn-id' => $dfrn_id, 'challenge' => $challenge])) {
		logger('could not match challenge to dfrn_id ' . $dfrn_id . ' challenge=' . $challenge);
		System::xmlExit(3, 'Could not match challenge');
	}

	DBA::delete('challenge', ['dfrn-id' => $dfrn_id, 'challenge' => $challenge]);

	// find the local user who owns this relationship.

	$sql_extra = '';
	switch ($direction) {
		case (-1):
			$sql_extra = sprintf(" AND ( `issued-id` = '%s' OR `dfrn-id` = '%s' ) ", DBA::escape($dfrn_id), DBA::escape($dfrn_id));
			break;
		case 0:
			$sql_extra = sprintf(" AND `issued-id` = '%s' AND `duplex` = 1 ", DBA::escape($dfrn_id));
			break;
		case 1:
			$sql_extra = sprintf(" AND `dfrn-id` = '%s' AND `duplex` = 1 ", DBA::escape($dfrn_id));
			break;
		default:
			System::xmlExit(3, 'Invalid direction');
			break; // NOTREACHED
	}

	/*
	 * be careful - $importer will contain both the contact information for the contact
	 * sending us the post, and also the user information for the person receiving it.
	 * since they are mixed together, it is easy to get them confused.
	 */

	$r = q("SELECT	`contact`.*, `contact`.`uid` AS `importer_uid`,
					`contact`.`pubkey` AS `cpubkey`,
					`contact`.`prvkey` AS `cprvkey`,
					`contact`.`thumb` AS `thumb`,
					`contact`.`url` as `url`,
					`contact`.`name` as `senderName`,
					`user`.*
			FROM `contact`
			LEFT JOIN `user` ON `contact`.`uid` = `user`.`uid`
			WHERE `contact`.`blocked` = 0 AND `contact`.`pending` = 0
				AND `user`.`nickname` = '%s' AND `user`.`account_expired` = 0 AND `user`.`account_removed` = 0 $sql_extra LIMIT 1",
		DBA::escape($a->argv[1])
	);

	if (!DBA::isResult($r)) {
		logger('contact not found for dfrn_id ' . $dfrn_id);
		System::xmlExit(3, 'Contact not found');
		//NOTREACHED
	}

	// $importer in this case contains the contact record for the remote contact joined with the user record of our user.

	$importer = $r[0];

	if ((($writable != (-1)) && ($writable != $importer['writable'])) || ($importer['forum'] != $forum) || ($importer['prv'] != $prv)) {
		$fields = ['writable' => ($writable == (-1)) ? $importer['writable'] : $writable,
			'forum' => $forum, 'prv' => $prv];
		DBA::update('contact', $fields, ['id' => $importer['id']]);

		if ($writable != (-1)) {
			$importer['writable'] = $writable;
		}
		$importer['forum'] = $page;
	}


	// if contact's ssl policy changed, update our links

	$importer = Contact::updateSslPolicy($importer, $ssl_policy);

	logger('data: ' . $data, LOGGER_DATA);

	if ($dissolve == 1) {
		// Relationship is dissolved permanently
		Contact::remove($importer['id']);
		logger('relationship dissolved : ' . $importer['name'] . ' dissolved ' . $importer['username']);
		System::xmlExit(0, 'relationship dissolved');
	}

	$rino = Config::get('system', 'rino_encrypt');
	$rino = intval($rino);

	if (strlen($key)) {

		// if local rino is lower than remote rino, abort: should not happen!
		// but only for $remote_rino > 1, because old code did't send rino version
		if ($rino_remote > 1 && $rino < $rino_remote) {
			logger("rino version '$rino_remote' is lower than supported '$rino'");
			System::xmlExit(0, "rino version '$rino_remote' is lower than supported '$rino'");
		}

		$rawkey = hex2bin(trim($key));
		logger('rino: md5 raw key: ' . md5($rawkey), LOGGER_DATA);

		$final_key = '';

		if ($dfrn_version >= 2.1) {
			if (($importer['duplex'] && strlen($importer['cprvkey'])) || !strlen($importer['cpubkey'])) {
				openssl_private_decrypt($rawkey, $final_key, $importer['cprvkey']);
			} else {
				openssl_public_decrypt($rawkey, $final_key, $importer['cpubkey']);
			}
		} else {
			if (($importer['duplex'] && strlen($importer['cpubkey'])) || !strlen($importer['cprvkey'])) {
				openssl_public_decrypt($rawkey, $final_key, $importer['cpubkey']);
			} else {
				openssl_private_decrypt($rawkey, $final_key, $importer['cprvkey']);
			}
		}

		switch ($rino_remote) {
			case 0:
			case 1:
				// we got a key. old code send only the key, without RINO version.
				// we assume RINO 1 if key and no RINO version
				$data = DFRN::aesDecrypt(hex2bin($data), $final_key);
				break;
			default:
				logger("rino: invalid sent version '$rino_remote'");
				System::xmlExit(0, "Invalid sent version '$rino_remote'");
		}

		logger('rino: decrypted data: ' . $data, LOGGER_DATA);
	}

	logger('Importing post from ' . $importer['addr'] . ' to ' . $importer['nickname'] . ' with the RINO ' . $rino_remote . ' encryption.', LOGGER_DEBUG);

	$ret = DFRN::import($data, $importer);
	System::xmlExit($ret, 'Processed');

	// NOTREACHED
}

function dfrn_dispatch_public($postdata)
{
	$msg = Diaspora::decodeRaw([], $postdata);
	if (!$msg) {
		// We have to fail silently to be able to hand it over to the salmon parser
		return false;
	}

	// Fetch the corresponding public contact
	$contact = Contact::getDetailsByAddr($msg['author'], 0);
	if (!$contact) {
		logger('Contact not found for address ' . $msg['author']);
		System::xmlExit(3, 'Contact ' . $msg['author'] . ' not found');
	}

	// We now have some contact, so we fetch it
	$importer = DBA::fetchFirst("SELECT *, `name` as `senderName`,
					0 AS `importer_uid`,
					'' AS `uprvkey`,
					'UTC' AS `timezone`,
					'' AS `nickname`,
					'' AS `sprvkey`,
					'' AS `spubkey`,
					0 AS `page-flags`,
					0 AS `account-type`,
					0 AS `prvnets`
					FROM `contact`
					WHERE NOT `blocked` AND `id` = ? LIMIT 1",
					$contact['id']);

	// This should never fail
	if (!DBA::isResult($importer)) {
		logger('Contact not found for address ' . $msg['author']);
		System::xmlExit(3, 'Contact ' . $msg['author'] . ' not found');
	}

	logger('Importing post from ' . $msg['author'] . ' with the public envelope.', LOGGER_DEBUG);

	// Now we should be able to import it
	$ret = DFRN::import($msg['message'], $importer);
	System::xmlExit($ret, 'Done');
}

function dfrn_dispatch_private($user, $postdata)
{
	$msg = Diaspora::decodeRaw($user, $postdata);
	if (!$msg) {
		System::xmlExit(4, 'Unable to parse message');
	}

	// Check if the user has got this contact
	$cid = Contact::getIdForURL($msg['author'], $user['uid']);
	if (!$cid) {
		// Otherwise there should be a public contact
		$cid = Contact::getIdForURL($msg['author']);
		if (!$cid) {
			logger('Contact not found for address ' . $msg['author']);
			System::xmlExit(3, 'Contact ' . $msg['author'] . ' not found');
		}
	}

	// We now have some contact, so we fetch it
	$importer = DBA::fetchFirst("SELECT *, `name` as `senderName`
					FROM `contact`
					WHERE NOT `blocked` AND `id` = ? LIMIT 1",
					$cid);

	// This should never fail
	if (!DBA::isResult($importer)) {
		logger('Contact not found for address ' . $msg['author']);
		System::xmlExit(3, 'Contact ' . $msg['author'] . ' not found');
	}

	// Set the user id. This is important if this is a public contact
	$importer['importer_uid']  = $user['uid'];

	$importer = array_merge($importer, $user);

	logger('Importing post from ' . $msg['author'] . ' to ' . $user['nickname'] . ' with the private envelope.', LOGGER_DEBUG);

	// Now we should be able to import it
	$ret = DFRN::import($msg['message'], $importer);
	System::xmlExit($ret, 'Done');
}

function dfrn_notify_content(App $a) {

	if (x($_GET,'dfrn_id')) {

		/*
		 * initial communication from external contact, $direction is their direction.
		 * If this is a duplex communication, ours will be the opposite.
		 */

		$dfrn_id = notags(trim($_GET['dfrn_id']));
		$dfrn_version = (float) $_GET['dfrn_version'];
		$rino_remote = ((x($_GET,'rino')) ? intval($_GET['rino']) : 0);
		$type = "";
		$last_update = "";

		logger('new notification dfrn_id=' . $dfrn_id);

		$direction = (-1);
		if (strpos($dfrn_id,':') == 1) {
			$direction = intval(substr($dfrn_id,0,1));
			$dfrn_id = substr($dfrn_id,2);
		}

		$hash = random_string();

		$status = 0;

		DBA::delete('challenge', ["`expire` < ?", time()]);

		$fields = ['challenge' => $hash, 'dfrn-id' => $dfrn_id, 'expire' => time() + 90,
			'type' => $type, 'last_update' => $last_update];
		DBA::insert('challenge', $fields);

		logger('challenge=' . $hash, LOGGER_DATA);

		$sql_extra = '';
		switch($direction) {
			case (-1):
				$sql_extra = sprintf(" AND (`issued-id` = '%s' OR `dfrn-id` = '%s') ", DBA::escape($dfrn_id), DBA::escape($dfrn_id));
				$my_id = $dfrn_id;
				break;
			case 0:
				$sql_extra = sprintf(" AND `issued-id` = '%s' AND `duplex` = 1 ", DBA::escape($dfrn_id));
				$my_id = '1:' . $dfrn_id;
				break;
			case 1:
				$sql_extra = sprintf(" AND `dfrn-id` = '%s' AND `duplex` = 1 ", DBA::escape($dfrn_id));
				$my_id = '0:' . $dfrn_id;
				break;
			default:
				$status = 1;
				break; // NOTREACHED
		}

		$r = q("SELECT `contact`.*, `user`.`nickname`, `user`.`page-flags` FROM `contact` LEFT JOIN `user` ON `user`.`uid` = `contact`.`uid`
				WHERE `contact`.`blocked` = 0 AND `contact`.`pending` = 0 AND `user`.`nickname` = '%s'
				AND `user`.`account_expired` = 0 AND `user`.`account_removed` = 0 $sql_extra LIMIT 1",
				DBA::escape($a->argv[1])
		);

		if (!DBA::isResult($r)) {
			logger('No user data found for ' . $a->argv[1] . ' - SQL: ' . $sql_extra);
			killme();
		}

		logger("Remote rino version: ".$rino_remote." for ".$r[0]["url"], LOGGER_DATA);

		$challenge    = '';
		$encrypted_id = '';
		$id_str       = $my_id . '.' . mt_rand(1000,9999);

		$prv_key = trim($r[0]['prvkey']);
		$pub_key = trim($r[0]['pubkey']);
		$dplx    = intval($r[0]['duplex']);

		if (($dplx && strlen($prv_key)) || (strlen($prv_key) && !strlen($pub_key))) {
			openssl_private_encrypt($hash, $challenge, $prv_key);
			openssl_private_encrypt($id_str, $encrypted_id, $prv_key);
		} elseif (strlen($pub_key)) {
			openssl_public_encrypt($hash, $challenge, $pub_key);
			openssl_public_encrypt($id_str, $encrypted_id, $pub_key);
		} else {
			/// @TODO these kind of else-blocks are making the code harder to understand
			$status = 1;
		}

		$challenge    = bin2hex($challenge);
		$encrypted_id = bin2hex($encrypted_id);


		$rino = Config::get('system', 'rino_encrypt');
		$rino = intval($rino);

		logger("Local rino version: ". $rino, LOGGER_DATA);

		// if requested rino is lower than enabled local rino, lower local rino version
		// if requested rino is higher than enabled local rino, reply with local rino
		if ($rino_remote < $rino) {
			$rino = $rino_remote;
		}

		if (($r[0]['rel'] && ($r[0]['rel'] != Contact::SHARING)) || ($r[0]['page-flags'] == Contact::PAGE_COMMUNITY)) {
			$perm = 'rw';
		} else {
			$perm = 'r';
		}

		header("Content-type: text/xml");

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n"
			. '<dfrn_notify>' . "\r\n"
			. "\t" . '<status>' . $status . '</status>' . "\r\n"
			. "\t" . '<dfrn_version>' . DFRN_PROTOCOL_VERSION . '</dfrn_version>' . "\r\n"
			. "\t" . '<rino>' . $rino . '</rino>' . "\r\n"
			. "\t" . '<perm>' . $perm . '</perm>' . "\r\n"
			. "\t" . '<dfrn_id>' . $encrypted_id . '</dfrn_id>' . "\r\n"
			. "\t" . '<challenge>' . $challenge . '</challenge>' . "\r\n"
			. '</dfrn_notify>' . "\r\n" ;

		killme();
	}
}
