<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Module for uploading a picture to the profile wall
 *
 * By default the picture will be stored in the photo album with the name Wall Photos.
 * You can specify a different album by adding an optional query string "album="
 * to the url
 *
 */

use Friendica\App;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Photo;
use Friendica\Model\User;
use Friendica\Module\BaseApi;
use Friendica\Object\Image;
use Friendica\Util\Images;
use Friendica\Util\Strings;

function wall_upload_post(App $a, $desktopmode = true)
{
	Logger::info('wall upload: starting new upload');

	$isJson = (!empty($_GET['response']) && $_GET['response'] == 'json');
	$album  = trim($_GET['album'] ?? '');

	if (DI::args()->getArgc() > 1) {
		if (empty($_FILES['media'])) {
			$nick = DI::args()->getArgv()[1];
			$user = DBA::selectFirst('owner-view', ['id', 'uid', 'nickname', 'page-flags'], ['nickname' => $nick, 'blocked' => false]);
			if (!DBA::isResult($user)) {
				Logger::warning('wall upload: user instance is not valid', ['user' => $user, 'nickname' => $nick]);
				if ($isJson) {
					System::jsonExit(['error' => DI::l10n()->t('Invalid request.')]);
				}
				return;
			}
		} else {
			$user = DBA::selectFirst('owner-view', ['id', 'uid', 'nickname', 'page-flags'], ['uid' => BaseApi::getCurrentUserID(), 'blocked' => false]);
		}
	} else {
		Logger:warning('Argument count is zero or one (invalid)');
		if ($isJson) {
			System::jsonExit(['error' => DI::l10n()->t('Invalid request.')]);
		}
		return;
	}

	/*
	 * Setup permissions structures
	 */
	$can_post = false;
	$visitor  = 0;

	$page_owner_uid  = $user['uid'];
	$default_cid     = $user['id'];
	$page_owner_nick = $user['nickname'];
	$community_page  = ($user['page-flags'] == User::PAGE_FLAGS_COMMUNITY);

	if ((DI::userSession()->getLocalUserId()) && (DI::userSession()->getLocalUserId() == $page_owner_uid)) {
		$can_post = true;
	} elseif ($community_page && !empty(DI::userSession()->getRemoteContactID($page_owner_uid))) {
		$contact_id = DI::userSession()->getRemoteContactID($page_owner_uid);
		$can_post   = DBA::exists('contact', ['blocked' => false, 'pending' => false, 'id' => $contact_id, 'uid' => $page_owner_uid]);
		$visitor    = $contact_id;
	}

	if (!$can_post) {
		Logger::warning('No permission to upload files', ['contact_id' => $contact_id, 'page_owner_uid' => $page_owner_uid]);
		$msg = DI::l10n()->t('Permission denied.');
		if ($isJson) {
			System::jsonExit(['error' => $msg]);
		}
		DI::sysmsg()->addNotice($msg);
		System::exit();
	}

	if (empty($_FILES['userfile']) && empty($_FILES['media'])) {
		Logger::warning('Empty "userfile" and "media" field');
		if ($isJson) {
			System::jsonExit(['error' => DI::l10n()->t('Invalid request.')]);
		}
		System::exit();
	}

	$src      = '';
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

	if ($src == '') {
		Logger::warning('File source (temporary file) cannot be determined');
		$msg = DI::l10n()->t('Invalid request.');
		if ($isJson) {
			System::jsonExit(['error' => $msg]);
		}
		DI::sysmsg()->addNotice($msg);
		System::exit();
	}

	$filetype = Images::getMimeTypeBySource($src, $filename, $filetype);

	Logger::info('File upload:', [
		'src'      => $src,
		'filename' => $filename,
		'filesize' => $filesize,
		'filetype' => $filetype,
	]);

	$imagedata = @file_get_contents($src);
	$image     = new Image($imagedata, $filetype);

	if (!$image->isValid()) {
		$msg = DI::l10n()->t('Unable to process image.');
		Logger::warning($msg, ['imagedata[]' => gettype($imagedata), 'filetype' => $filetype]);
		@unlink($src);
		if ($isJson) {
			System::jsonExit(['error' => $msg]);
		} else {
			echo $msg . '<br />';
		}
		System::exit();
	}

	$image->orient($src);
	@unlink($src);

	$max_length = DI::config()->get('system', 'max_image_length');
	if ($max_length > 0) {
		$image->scaleDown($max_length);
		$filesize = strlen($image->asString());
		Logger::info('File upload: Scaling picture to new size', ['max_length' => $max_length]);
	}

	$width  = $image->getWidth();
	$height = $image->getHeight();

	$maximagesize = DI::config()->get('system', 'maximagesize');

	if (!empty($maximagesize) && ($filesize > $maximagesize)) {
		// Scale down to multiples of 640 until the maximum size isn't exceeded anymore
		foreach ([5120, 2560, 1280, 640] as $pixels) {
			if (($filesize > $maximagesize) && (max($width, $height) > $pixels)) {
				Logger::info('Resize', ['size' => $filesize, 'width' => $width, 'height' => $height, 'max' => $maximagesize, 'pixels' => $pixels]);
				$image->scaleDown($pixels);
				$filesize = strlen($image->asString());
				$width    = $image->getWidth();
				$height   = $image->getHeight();
			}
		}
		if ($filesize > $maximagesize) {
			Logger::notice('Image size is too big', ['size' => $filesize, 'max' => $maximagesize]);
			$msg = DI::l10n()->t('Image exceeds size limit of %s', Strings::formatBytes($maximagesize));
			@unlink($src);
			if ($isJson) {
				System::jsonExit(['error' => $msg]);
			} else {
				echo  $msg . '<br />';
			}
			System::exit();
		}
	}

	$resource_id = Photo::newResource();

	$smallest = 0;

	// If we don't have an album name use the Wall Photos album
	if (!strlen($album)) {
		$album = DI::l10n()->t('Wall Photos');
	}

	$defperm = '<' . $default_cid . '>';

	$r = Photo::store($image, $page_owner_uid, $visitor, $resource_id, $filename, $album, 0, Photo::DEFAULT, $defperm);

	if (!$r) {
		$msg = DI::l10n()->t('Image upload failed.');
		Logger::warning('Photo::store() failed', ['r' => $r]);
		if ($isJson) {
			System::jsonExit(['error' => $msg]);
		} else {
			echo  $msg . '<br />';
		}
		System::exit();
	}

	if ($width > 640 || $height > 640) {
		$image->scaleDown(640);
		$r = Photo::store($image, $page_owner_uid, $visitor, $resource_id, $filename, $album, 1, Photo::DEFAULT, $defperm);
		if ($r) {
			$smallest = 1;
		}
	}

	if ($width > 320 || $height > 320) {
		$image->scaleDown(320);
		$r = Photo::store($image, $page_owner_uid, $visitor, $resource_id, $filename, $album, 2, Photo::DEFAULT, $defperm);
		if ($r && ($smallest == 0)) {
			$smallest = 2;
		}
	}

	if (!$desktopmode) {
		$photo = Photo::selectFirst(['id', 'datasize', 'width', 'height', 'type'], ['resource-id' => $resource_id], ['order' => ['width']]);
		if (!$photo) {
			Logger::warning('Cannot find photo in database', ['resource-id' => $resource_id]);
			if ($isJson) {
				System::jsonExit(['error' => 'Cannot find photo']);
			}
			return false;
		}

		$picture = [
			'id'        => $photo['id'],
			'size'      => $photo['datasize'],
			'width'     => $photo['width'],
			'height'    => $photo['height'],
			'type'      => $photo['type'],
			'albumpage' => DI::baseUrl() . '/photos/' . $page_owner_nick . '/image/' . $resource_id,
			'picture'   => DI::baseUrl() . "/photo/{$resource_id}-0." . $image->getExt(),
			'preview'   => DI::baseUrl() . "/photo/{$resource_id}-{$smallest}." . $image->getExt(),
		];

		if ($isJson) {
			System::jsonExit(['picture' => $picture]);
		}
		Logger::info('upload done');
		return $picture;
	}

	Logger::info('upload done');

	if ($isJson) {
		System::jsonExit(['ok' => true]);
	}

	echo  "\n\n" . '[url=' . DI::baseUrl() . '/photos/' . $page_owner_nick . '/image/' . $resource_id . '][img]' . DI::baseUrl() . "/photo/{$resource_id}-{$smallest}." . $image->getExt() . "[/img][/url]\n\n";
	System::exit();
	// NOTREACHED
}
