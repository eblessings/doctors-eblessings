<?php
/**
 * @file mod/wall_attach.php
 */

use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Mimetype;
use Friendica\Util\Strings;

function wall_attach_post(App $a) {

	$r_json = (!empty($_GET['response']) && $_GET['response']=='json');

	if ($a->argc > 1) {
		$nick = $a->argv[1];
		$r = q("SELECT `user`.*, `contact`.`id` FROM `user` LEFT JOIN `contact` on `user`.`uid` = `contact`.`uid`  WHERE `user`.`nickname` = '%s' AND `user`.`blocked` = 0 and `contact`.`self` = 1 LIMIT 1",
			DBA::escape($nick)
		);

		if (! DBA::isResult($r)) {
			if ($r_json) {
				echo json_encode(['error' => L10n::t('Invalid request.')]);
				killme();
			}
			return;
		}
	} else {
		if ($r_json) {
			echo json_encode(['error' => L10n::t('Invalid request.')]);
			killme();
		}

		return;
	}

	$can_post  = false;
	$visitor   = 0;

	$page_owner_uid   = $r[0]['uid'];
	$page_owner_cid   = $r[0]['id'];
	$page_owner_nick  = $r[0]['nickname'];
	$community_page   = (($r[0]['page-flags'] == Contact::PAGE_COMMUNITY) ? true : false);

	if ((local_user()) && (local_user() == $page_owner_uid)) {
		$can_post = true;
	} else {
		if ($community_page && remote_user()) {
			$contact_id = 0;

			if (is_array($_SESSION['remote'])) {
				foreach ($_SESSION['remote'] as $v) {
					if ($v['uid'] == $page_owner_uid) {
						$contact_id = $v['cid'];
						break;
					}
				}
			}

			if ($contact_id > 0) {
				$r = q("SELECT `uid` FROM `contact` WHERE `blocked` = 0 AND `pending` = 0 AND `id` = %d AND `uid` = %d LIMIT 1",
					intval($contact_id),
					intval($page_owner_uid)
				);

				if (DBA::isResult($r)) {
					$can_post = true;
					$visitor = $contact_id;
				}
			}
		}
	}

	if (! $can_post) {
		if ($r_json) {
			echo json_encode(['error' => L10n::t('Permission denied.')]);
			killme();
		}
		notice(L10n::t('Permission denied.') . EOL );
		killme();
	}

	if (empty($_FILES['userfile'])) {
		if ($r_json) {
			echo json_encode(['error' => L10n::t('Invalid request.')]);
		}
		killme();
	}

	$src      = $_FILES['userfile']['tmp_name'];
	$filename = basename($_FILES['userfile']['name']);
	$filesize = intval($_FILES['userfile']['size']);

	$maxfilesize = Config::get('system','maxfilesize');

	/* Found html code written in text field of form,
	 * when trying to upload a file with filesize
	 * greater than upload_max_filesize. Cause is unknown.
	 * Then Filesize gets <= 0.
	 */

	if ($filesize <= 0) {
		$msg = L10n::t('Sorry, maybe your upload is bigger than the PHP configuration allows') . EOL .(L10n::t('Or - did you try to upload an empty file?'));
		if ($r_json) {
			echo json_encode(['error' => $msg]);
		} else {
			notice($msg . EOL);
		}
		@unlink($src);
		killme();
	}

	if ($maxfilesize && $filesize > $maxfilesize) {
		$msg = L10n::t('File exceeds size limit of %s', Strings::formatBytes($maxfilesize));
		if ($r_json) {
			echo json_encode(['error' => $msg]);
		} else {
			echo $msg . EOL;
		}
		@unlink($src);
		killme();
	}

	$filedata = @file_get_contents($src);
	$mimetype = Mimetype::getContentType($filename);
	$hash = System::createGUID(64);
	$created = DateTimeFormat::utcNow();

	$fields = ['uid' => $page_owner_uid, 'hash' => $hash, 'filename' => $filename, 'filetype' => $mimetype,
		'filesize' => $filesize, 'data' => $filedata, 'created' => $created, 'edited' => $created,
		'allow_cid' => '<' . $page_owner_cid . '>', 'allow_gid' => '','deny_cid' => '', 'deny_gid' => ''];

	$r = DBA::insert('attach', $fields);

	@unlink($src);

	if (! $r) {
		$msg =  L10n::t('File upload failed.');
		if ($r_json) {
			echo json_encode(['error' => $msg]);
		} else {
			echo $msg . EOL;
		}
		killme();
	}

	$r = q("SELECT `id` FROM `attach` WHERE `uid` = %d AND `created` = '%s' AND `hash` = '%s' LIMIT 1",
		intval($page_owner_uid),
		DBA::escape($created),
		DBA::escape($hash)
	);

	if (! DBA::isResult($r)) {
		$msg = L10n::t('File upload failed.');
		if ($r_json) {
			echo json_encode(['error' => $msg]);
		} else {
			echo $msg . EOL;
		}
		killme();
	}

	if ($r_json) {
		echo json_encode(['ok' => true]);
		killme();
	}

	$lf = "\n";

	echo  $lf . $lf . '[attachment]' . $r[0]['id'] . '[/attachment]' . $lf;

	killme();
	// NOTREACHED
}
