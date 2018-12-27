<?php
/**
 * @file mod/wall_upload.php
 * @brief Module for uploading a picture to the profile wall
 *
 * By default the picture will be stored in the photo album with the name Wall Photos.
 * You can specify a different album by adding an optional query string "album="
 * to the url
 */

use Friendica\App;
use Friendica\Core\L10n;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Core\Config;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\Photo;
use Friendica\Object\Image;
use Friendica\Util\Strings;

function wall_upload_post(App $a, $desktopmode = true)
{
	Logger::log("wall upload: starting new upload", Logger::DEBUG);

	$r_json = (!empty($_GET['response']) && $_GET['response'] == 'json');
	$album = (!empty($_GET['album']) ? Strings::escapeTags(trim($_GET['album'])) : '');

	if ($a->argc > 1) {
		if (empty($_FILES['media'])) {
			$nick = $a->argv[1];
			$r = q("SELECT `user`.*, `contact`.`id` FROM `user`
				INNER JOIN `contact` on `user`.`uid` = `contact`.`uid`
				WHERE `user`.`nickname` = '%s' AND `user`.`blocked` = 0
				AND `contact`.`self` = 1 LIMIT 1",
				DBA::escape($nick)
			);

			if (!DBA::isResult($r)) {
				if ($r_json) {
					echo json_encode(['error' => L10n::t('Invalid request.')]);
					killme();
				}
				return;
			}
		} else {
			$user_info = api_get_user($a);
			$r = q("SELECT `user`.*, `contact`.`id` FROM `user`
				INNER JOIN `contact` on `user`.`uid` = `contact`.`uid`
				WHERE `user`.`nickname` = '%s' AND `user`.`blocked` = 0
				AND `contact`.`self` = 1 LIMIT 1",
				DBA::escape($user_info['screen_name'])
			);
		}
	} else {
		if ($r_json) {
			echo json_encode(['error' => L10n::t('Invalid request.')]);
			killme();
		}
		return;
	}

	/*
	 * Setup permissions structures
	 */
	$can_post  = false;
	$visitor   = 0;

	$page_owner_uid   = $r[0]['uid'];
	$default_cid      = $r[0]['id'];
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

			if ($contact_id) {
				$r = q("SELECT `uid` FROM `contact`
					WHERE `blocked` = 0 AND `pending` = 0
					AND `id` = %d AND `uid` = %d LIMIT 1",
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


	if (!$can_post) {
		if ($r_json) {
			echo json_encode(['error' => L10n::t('Permission denied.')]);
			killme();
		}
		notice(L10n::t('Permission denied.') . EOL);
		killme();
	}

	if (empty($_FILES['userfile']) && empty($_FILES['media'])) {
		if ($r_json) {
			echo json_encode(['error' => L10n::t('Invalid request.')]);
		}
		killme();
	}

	$src = '';
	$filename = '';
	$filesize = 0;
	$filetype = '';
	if (!empty($_FILES['userfile'])) {
		$src      = $_FILES['userfile']['tmp_name'];
		$filename = basename($_FILES['userfile']['name']);
		$filesize = intval($_FILES['userfile']['size']);
		$filetype = $_FILES['userfile']['type'];

	} elseif (!empty($_FILES['media'])) {
		if (!empty($_FILES['media']['tmp_name'])) {
			if (is_array($_FILES['media']['tmp_name'])) {
				$src = $_FILES['media']['tmp_name'][0];
			} else {
				$src = $_FILES['media']['tmp_name'];
			}
		}

		if (!empty($_FILES['media']['name'])) {
			if (is_array($_FILES['media']['name'])) {
				$filename = basename($_FILES['media']['name'][0]);
			} else {
				$filename = basename($_FILES['media']['name']);
			}
		}

		if (!empty($_FILES['media']['size'])) {
			if (is_array($_FILES['media']['size'])) {
				$filesize = intval($_FILES['media']['size'][0]);
			} else {
				$filesize = intval($_FILES['media']['size']);
			}
		}

		if (!empty($_FILES['media']['type'])) {
			if (is_array($_FILES['media']['type'])) {
				$filetype = $_FILES['media']['type'][0];
			} else {
				$filetype = $_FILES['media']['type'];
			}
		}
	}

	if ($src == "") {
		if ($r_json) {
			echo json_encode(['error' => L10n::t('Invalid request.')]);
			killme();
		}
		notice(L10n::t('Invalid request.').EOL);
		killme();
	}

	// This is a special treatment for picture upload from Twidere
	if (($filename == "octet-stream") && ($filetype != "")) {
		$filename = $filetype;
		$filetype = "";
	}

	if ($filetype == "") {
		$filetype = Image::guessType($filename);
	}

	// If there is a temp name, then do a manual check
	// This is more reliable than the provided value

	$imagedata = getimagesize($src);
	if ($imagedata) {
		$filetype = $imagedata['mime'];
	}

	Logger::log("File upload src: " . $src . " - filename: " . $filename .
		" - size: " . $filesize . " - type: " . $filetype, Logger::DEBUG);

	$maximagesize = Config::get('system', 'maximagesize');

	if (($maximagesize) && ($filesize > $maximagesize)) {
		$msg = L10n::t('Image exceeds size limit of %s', Strings::formatBytes($maximagesize));
		if ($r_json) {
			echo json_encode(['error' => $msg]);
		} else {
			echo  $msg. EOL;
		}
		@unlink($src);
		killme();
	}

	$imagedata = @file_get_contents($src);
	$Image = new Image($imagedata, $filetype);

	if (!$Image->isValid()) {
		$msg = L10n::t('Unable to process image.');
		if ($r_json) {
			echo json_encode(['error' => $msg]);
		} else {
			echo  $msg. EOL;
		}
		@unlink($src);
		killme();
	}

	$Image->orient($src);
	@unlink($src);

	$max_length = Config::get('system', 'max_image_length');
	if (!$max_length) {
		$max_length = MAX_IMAGE_LENGTH;
	}
	if ($max_length > 0) {
		$Image->scaleDown($max_length);
		Logger::log("File upload: Scaling picture to new size " . $max_length, Logger::DEBUG);
	}

	$width = $Image->getWidth();
	$height = $Image->getHeight();

	$hash = Photo::newResource();

	$smallest = 0;

	// If we don't have an album name use the Wall Photos album
	if (!strlen($album)) {
		$album = L10n::t('Wall Photos');
	}

	$defperm = '<' . $default_cid . '>';

	$r = Photo::store($Image, $page_owner_uid, $visitor, $hash, $filename, $album, 0, 0, $defperm);

	if (!$r) {
		$msg = L10n::t('Image upload failed.');
		if ($r_json) {
			echo json_encode(['error' => $msg]);
		} else {
			echo  $msg. EOL;
		}
		killme();
	}

	if ($width > 640 || $height > 640) {
		$Image->scaleDown(640);
		$r = Photo::store($Image, $page_owner_uid, $visitor, $hash, $filename, $album, 1, 0, $defperm);
		if ($r) {
			$smallest = 1;
		}
	}

	if ($width > 320 || $height > 320) {
		$Image->scaleDown(320);
		$r = Photo::store($Image, $page_owner_uid, $visitor, $hash, $filename, $album, 2, 0, $defperm);
		if ($r && ($smallest == 0)) {
			$smallest = 2;
		}
	}

	$basename = basename($filename);

	if (!$desktopmode) {
		$r = q("SELECT `id`, `datasize`, `width`, `height`, `type` FROM `photo`
			WHERE `resource-id` = '%s'
			ORDER BY `width` DESC LIMIT 1",
			$hash
		);
		if (!$r) {
			if ($r_json) {
				echo json_encode(['error' => '']);
				killme();
			}
			return false;
		}
		$picture = [];

		$picture["id"]        = $r[0]["id"];
		$picture["size"]      = $r[0]["datasize"];
		$picture["width"]     = $r[0]["width"];
		$picture["height"]    = $r[0]["height"];
		$picture["type"]      = $r[0]["type"];
		$picture["albumpage"] = System::baseUrl() . '/photos/' . $page_owner_nick . '/image/' . $hash;
		$picture["picture"]   = System::baseUrl() . "/photo/{$hash}-0." . $Image->getExt();
		$picture["preview"]   = System::baseUrl() . "/photo/{$hash}-{$smallest}." . $Image->getExt();

		if ($r_json) {
			echo json_encode(['picture' => $picture]);
			killme();
		}
		Logger::log("upload done", Logger::DEBUG);
		return $picture;
	}

	Logger::log("upload done", Logger::DEBUG);

	if ($r_json) {
		echo json_encode(['ok' => true]);
		killme();
	}

	echo  "\n\n" . '[url=' . System::baseUrl() . '/photos/' . $page_owner_nick . '/image/' . $hash . '][img]' . System::baseUrl() . "/photo/{$hash}-{$smallest}.".$Image->getExt()."[/img][/url]\n\n";
	killme();
	// NOTREACHED
}
