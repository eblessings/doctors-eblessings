<?php

/**
 * @file mod/dfrn_notify.php
 * @brief The dfrn notify endpoint
 * @see PDF with dfrn specs: https://github.com/friendica/friendica/blob/master/spec/dfrn2.pdf
 */
require_once('include/items.php');
require_once('include/dfrn.php');
require_once('include/event.php');

require_once('library/defuse/php-encryption-1.2.1/Crypto.php');

function dfrn_notify_post(App $a) {
	logger(__function__, LOGGER_TRACE);
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
	if (strpos($dfrn_id,':') == 1) {
		$direction = intval(substr($dfrn_id,0,1));
		$dfrn_id = substr($dfrn_id,2);
	}

	$r = q("SELECT * FROM `challenge` WHERE `dfrn-id` = '%s' AND `challenge` = '%s' LIMIT 1",
		dbesc($dfrn_id),
		dbesc($challenge)
	);
	if (! dbm::is_result($r)) {
		logger('dfrn_notify: could not match challenge to dfrn_id ' . $dfrn_id . ' challenge=' . $challenge);
		xml_status(3);
	}

	$r = q("DELETE FROM `challenge` WHERE `dfrn-id` = '%s' AND `challenge` = '%s'",
		dbesc($dfrn_id),
		dbesc($challenge)
	);

	// find the local user who owns this relationship.

	$sql_extra = '';
	switch($direction) {
		case (-1):
			$sql_extra = sprintf(" AND ( `issued-id` = '%s' OR `dfrn-id` = '%s' ) ", dbesc($dfrn_id), dbesc($dfrn_id));
			break;
		case 0:
			$sql_extra = sprintf(" AND `issued-id` = '%s' AND `duplex` = 1 ", dbesc($dfrn_id));
			break;
		case 1:
			$sql_extra = sprintf(" AND `dfrn-id` = '%s' AND `duplex` = 1 ", dbesc($dfrn_id));
			break;
		default:
			xml_status(3);
			break; // NOTREACHED
	}

	// be careful - $importer will contain both the contact information for the contact
	// sending us the post, and also the user information for the person receiving it.
	// since they are mixed together, it is easy to get them confused.

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
		dbesc($a->argv[1])
	);

	if (! dbm::is_result($r)) {
		logger('dfrn_notify: contact not found for dfrn_id ' . $dfrn_id);
		xml_status(3);
		//NOTREACHED
	}

	// $importer in this case contains the contact record for the remote contact joined with the user record of our user.

	$importer = $r[0];

	logger("Remote rino version: ".$rino_remote." for ".$importer["url"], LOGGER_DEBUG);

	if ((($writable != (-1)) && ($writable != $importer['writable'])) || ($importer['forum'] != $forum) || ($importer['prv'] != $prv)) {
		q("UPDATE `contact` SET `writable` = %d, forum = %d, prv = %d WHERE `id` = %d",
			intval(($writable == (-1)) ? $importer['writable'] : $writable),
			intval($forum),
			intval($prv),
			intval($importer['id'])
		);
		if ($writable != (-1))
			$importer['writable'] = $writable;
		$importer['forum'] = $page;
	}


	// if contact's ssl policy changed, update our links

	fix_contact_ssl_policy($importer,$ssl_policy);

	logger('dfrn_notify: received notify from ' . $importer['name'] . ' for ' . $importer['username']);
	logger('dfrn_notify: data: ' . $data, LOGGER_DATA);

	if ($dissolve == 1) {

		/*
		 * Relationship is dissolved permanently
		 */

		require_once('include/Contact.php');
		contact_remove($importer['id']);
		logger('relationship dissolved : ' . $importer['name'] . ' dissolved ' . $importer['username']);
		xml_status(0);

	}


	// If we are setup as a soapbox we aren't accepting input from this person
	// This behaviour is deactivated since it really doesn't make sense to even disallow comments
	// The check if someone is a friend or simply a follower is done in a later place so it needn't to be done here
	//if ($importer['page-flags'] == PAGE_SOAPBOX)
	//	xml_status(0);

	$rino = get_config('system','rino_encrypt');
	$rino = intval($rino);
	// use RINO1 if mcrypt isn't installed and RINO2 was selected
	if ($rino == 2 and !function_exists('mcrypt_create_iv')) {
		$rino=1;
	}

	logger("Local rino version: ". $rino, LOGGER_DEBUG);

	if (strlen($key)) {

		// if local rino is lower than remote rino, abort: should not happen!
		// but only for $remote_rino > 1, because old code did't send rino version
		if ($rino_remote_version > 1 && $rino < $rino_remote) {
			logger("rino version '$rino_remote' is lower than supported '$rino'");
			xml_status(0,"rino version '$rino_remote' is lower than supported '$rino'");
		}

		$rawkey = hex2bin(trim($key));
		logger('rino: md5 raw key: ' . md5($rawkey));
		$final_key = '';

		if ($dfrn_version >= 2.1) {
			if ((($importer['duplex']) && strlen($importer['cprvkey'])) || (! strlen($importer['cpubkey']))) {
				openssl_private_decrypt($rawkey,$final_key,$importer['cprvkey']);
			}
			else {
				openssl_public_decrypt($rawkey,$final_key,$importer['cpubkey']);
			}
		}
		else {
			if ((($importer['duplex']) && strlen($importer['cpubkey'])) || (! strlen($importer['cprvkey']))) {
				openssl_public_decrypt($rawkey,$final_key,$importer['cpubkey']);
			}
			else {
				openssl_private_decrypt($rawkey,$final_key,$importer['cprvkey']);
			}
		}

		#logger('rino: received key : ' . $final_key);

		switch($rino_remote) {
			case 0:
			case 1:
				// we got a key. old code send only the key, without RINO version.
				// we assume RINO 1 if key and no RINO version
				$data = aes_decrypt(hex2bin($data),$final_key);
				break;
			case 2:
				try {
					$data = Crypto::decrypt(hex2bin($data),$final_key);
				} catch (InvalidCiphertext $ex) { // VERY IMPORTANT
					// Either:
					//   1. The ciphertext was modified by the attacker,
					//   2. The key is wrong, or
					//   3. $ciphertext is not a valid ciphertext or was corrupted.
					// Assume the worst.
					logger('The ciphertext has been tampered with!');
					xml_status(0,'The ciphertext has been tampered with!');
				} catch (Ex\CryptoTestFailed $ex) {
					logger('Cannot safely perform dencryption');
					xml_status(0,'CryptoTestFailed');
				} catch (Ex\CannotPerformOperation $ex) {
					logger('Cannot safely perform decryption');
					xml_status(0,'Cannot safely perform decryption');
				}
				break;
			default:
				logger("rino: invalid sent verision '$rino_remote'");
				xml_status(0);
		}


		logger('rino: decrypted data: ' . $data, LOGGER_DATA);
	}

	$ret = dfrn::import($data, $importer);
	xml_status($ret);

	// NOTREACHED
}


function dfrn_notify_content(App $a) {

	if (x($_GET,'dfrn_id')) {

		// initial communication from external contact, $direction is their direction.
		// If this is a duplex communication, ours will be the opposite.

		$dfrn_id = notags(trim($_GET['dfrn_id']));
		$dfrn_version = (float) $_GET['dfrn_version'];
		$rino_remote = ((x($_GET,'rino')) ? intval($_GET['rino']) : 0);
		$type = "";
		$last_update = "";

		logger('dfrn_notify: new notification dfrn_id=' . $dfrn_id);

		$direction = (-1);
		if (strpos($dfrn_id,':') == 1) {
			$direction = intval(substr($dfrn_id,0,1));
			$dfrn_id = substr($dfrn_id,2);
		}

		$hash = random_string();

		$status = 0;

		$r = q("DELETE FROM `challenge` WHERE `expire` < " . intval(time()));

		$r = q("INSERT INTO `challenge` ( `challenge`, `dfrn-id`, `expire` , `type`, `last_update` )
			VALUES( '%s', '%s', %d, '%s', '%s' ) ",
			dbesc($hash),
			dbesc($dfrn_id),
			intval(time() + 90 ),
			dbesc($type),
			dbesc($last_update)
		);

		logger('dfrn_notify: challenge=' . $hash, LOGGER_DEBUG);

		$sql_extra = '';
		switch($direction) {
			case (-1):
				$sql_extra = sprintf(" AND ( `issued-id` = '%s' OR `dfrn-id` = '%s' ) ", dbesc($dfrn_id), dbesc($dfrn_id));
				$my_id = $dfrn_id;
				break;
			case 0:
				$sql_extra = sprintf(" AND `issued-id` = '%s' AND `duplex` = 1 ", dbesc($dfrn_id));
				$my_id = '1:' . $dfrn_id;
				break;
			case 1:
				$sql_extra = sprintf(" AND `dfrn-id` = '%s' AND `duplex` = 1 ", dbesc($dfrn_id));
				$my_id = '0:' . $dfrn_id;
				break;
			default:
				$status = 1;
				break; // NOTREACHED
		}

		$r = q("SELECT `contact`.*, `user`.`nickname`, `user`.`page-flags` FROM `contact` LEFT JOIN `user` ON `user`.`uid` = `contact`.`uid`
				WHERE `contact`.`blocked` = 0 AND `contact`.`pending` = 0 AND `user`.`nickname` = '%s'
				AND `user`.`account_expired` = 0 AND `user`.`account_removed` = 0 $sql_extra LIMIT 1",
				dbesc($a->argv[1])
		);

		if (! dbm::is_result($r)) {
			$status = 1;
		}

		logger("Remote rino version: ".$rino_remote." for ".$r[0]["url"], LOGGER_DEBUG);

		$challenge = '';
		$encrypted_id = '';
		$id_str = $my_id . '.' . mt_rand(1000,9999);

		$prv_key = trim($r[0]['prvkey']);
		$pub_key = trim($r[0]['pubkey']);
		$dplx = intval($r[0]['duplex']);

		if ((($dplx) && (strlen($prv_key))) || ((strlen($prv_key)) && (!(strlen($pub_key))))) {
			openssl_private_encrypt($hash,$challenge,$prv_key);
			openssl_private_encrypt($id_str,$encrypted_id,$prv_key);
		} elseif (strlen($pub_key)) {
			openssl_public_encrypt($hash,$challenge,$pub_key);
			openssl_public_encrypt($id_str,$encrypted_id,$pub_key);
		} else {
			$status = 1;
		}

		$challenge    = bin2hex($challenge);
		$encrypted_id = bin2hex($encrypted_id);


		$rino = get_config('system','rino_encrypt');
		$rino = intval($rino);
		// use RINO1 if mcrypt isn't installed and RINO2 was selected
		if ($rino == 2 and !function_exists('mcrypt_create_iv')) {
			$rino=1;
		}

		logger("Local rino version: ". $rino, LOGGER_DEBUG);

		// if requested rino is lower than enabled local rino, lower local rino version
		// if requested rino is higher than enabled local rino, reply with local rino
		if ($rino_remote < $rino) $rino = $rino_remote;

		if ((($r[0]['rel']) && ($r[0]['rel'] != CONTACT_IS_SHARING)) || ($r[0]['page-flags'] == PAGE_COMMUNITY)) {
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
