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
 */

use Friendica\App;
use Friendica\Content\Feature;
use Friendica\Content\Nav;
use Friendica\Content\Pager;
use Friendica\Content\Text\BBCode;
use Friendica\Content\Widget;
use Friendica\Core\ACL;
use Friendica\Core\Addon;
use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\Renderer;
use Friendica\Core\Session;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Contact;
use Friendica\Model\Item;
use Friendica\Model\Photo;
use Friendica\Model\Post;
use Friendica\Model\Profile;
use Friendica\Model\Tag;
use Friendica\Model\User;
use Friendica\Module\BaseProfile;
use Friendica\Network\Probe;
use Friendica\Object\Image;
use Friendica\Protocol\Activity;
use Friendica\Util\Crypto;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Images;
use Friendica\Util\Map;
use Friendica\Security\Security;
use Friendica\Util\Strings;
use Friendica\Util\Temporal;
use Friendica\Util\XML;
use Friendica\Network\HTTPException;

function photos_init(App $a) {

	if (DI::config()->get('system', 'block_public') && !Session::isAuthenticated()) {
		return;
	}

	Nav::setSelected('home');

	if (DI::args()->getArgc() > 1) {
		$owner = User::getOwnerDataByNick(DI::args()->getArgv()[1]);
		if (!isset($owner['account_removed']) || $owner['account_removed']) {
			throw new HTTPException\NotFoundException(DI::l10n()->t('User not found.'));
		}

		$is_owner = (local_user() && (local_user() == $owner['uid']));

		$albums = Photo::getAlbums($owner['uid']);

		$albums_visible = ((intval($owner['hidewall']) && !Session::isAuthenticated()) ? false : true);

		// add various encodings to the array so we can just loop through and pick them out in a template
		$ret = ['success' => false];

		if ($albums) {
			if ($albums_visible) {
				$ret['success'] = true;
			}

			$ret['albums'] = [];
			foreach ($albums as $k => $album) {
				$entry = [
					'text'      => $album['album'],
					'total'     => $album['total'],
					'url'       => 'photos/' . $owner['nickname'] . '/album/' . bin2hex($album['album']),
					'urlencode' => urlencode($album['album']),
					'bin2hex'   => bin2hex($album['album'])
				];
				$ret['albums'][] = $entry;
			}
		}

		if (local_user() && $owner['uid'] == local_user()) {
			$can_post = true;
		} else {
			$can_post = false;
		}

		if ($ret['success']) {
			$photo_albums_widget = Renderer::replaceMacros(Renderer::getMarkupTemplate('photo_albums.tpl'), [
				'$nick'     => $owner['nickname'],
				'$title'    => DI::l10n()->t('Photo Albums'),
				'$recent'   => DI::l10n()->t('Recent Photos'),
				'$albums'   => $ret['albums'],
				'$upload'   => [DI::l10n()->t('Upload New Photos'), 'photos/' . $owner['nickname'] . '/upload'],
				'$can_post' => $can_post
			]);
		}

		if (empty(DI::page()['aside'])) {
			DI::page()['aside'] = '';
		}

		DI::page()['aside'] .= Widget\VCard::getHTML($owner);

		if (!empty($photo_albums_widget)) {
			DI::page()['aside'] .= $photo_albums_widget;
		}

		$tpl = Renderer::getMarkupTemplate("photos_head.tpl");

		DI::page()['htmlhead'] .= Renderer::replaceMacros($tpl,[
			'$ispublic' => DI::l10n()->t('everybody')
		]);
	}

	return;
}

function photos_post(App $a)
{
	$user = User::getByNickname(DI::args()->getArgv()[1]);
	if (!DBA::isResult($user)) {
		throw new HTTPException\NotFoundException(DI::l10n()->t('User not found.'));
	}

	$phototypes = Images::supportedTypes();

	$can_post  = false;
	$visitor   = 0;

	$page_owner_uid = intval($user['uid']);
	$community_page = $user['page-flags'] == User::PAGE_FLAGS_COMMUNITY;

	if (local_user() && (local_user() == $page_owner_uid)) {
		$can_post = true;
	} elseif ($community_page && !empty(Session::getRemoteContactID($page_owner_uid))) {
		$contact_id = Session::getRemoteContactID($page_owner_uid);
		$can_post = true;
		$visitor = $contact_id;
	}

	if (!$can_post) {
		notice(DI::l10n()->t('Permission denied.'));
		System::exit();
	}

	$owner_record = User::getOwnerDataById($page_owner_uid);

	if (!$owner_record) {
		notice(DI::l10n()->t('Contact information unavailable'));
		DI::logger()->info('photos_post: unable to locate contact record for page owner. uid=' . $page_owner_uid);
		System::exit();
	}

	$aclFormatter = DI::aclFormatter();
	$str_contact_allow = isset($_REQUEST['contact_allow']) ? $aclFormatter->toString($_REQUEST['contact_allow']) : $owner_record['allow_cid'] ?? '';
	$str_group_allow   = isset($_REQUEST['group_allow'])   ? $aclFormatter->toString($_REQUEST['group_allow'])   : $owner_record['allow_gid'] ?? '';
	$str_contact_deny  = isset($_REQUEST['contact_deny'])  ? $aclFormatter->toString($_REQUEST['contact_deny'])  : $owner_record['deny_cid']  ?? '';
	$str_group_deny    = isset($_REQUEST['group_deny'])    ? $aclFormatter->toString($_REQUEST['group_deny'])    : $owner_record['deny_gid']  ?? '';

	$visibility = $_REQUEST['visibility'] ?? '';
	if ($visibility === 'public') {
		// The ACL selector introduced in version 2019.12 sends ACL input data even when the Public visibility is selected
		$str_contact_allow = $str_group_allow = $str_contact_deny = $str_group_deny = '';
	} else if ($visibility === 'custom') {
		// Since we know from the visibility parameter the item should be private, we have to prevent the empty ACL
		// case that would make it public. So we always append the author's contact id to the allowed contacts.
		// See https://github.com/friendica/friendica/issues/9672
		$str_contact_allow .= $aclFormatter->toString(Contact::getPublicIdByUserId($page_owner_uid));
	}

	if (DI::args()->getArgc() > 3 && DI::args()->getArgv()[2] === 'album') {
		if (!Strings::isHex(DI::args()->getArgv()[3] ?? '')) {
			DI::baseUrl()->redirect('photos/' . $user['nickname'] . '/album');
		}
		$album = hex2bin(DI::args()->getArgv()[3]);

		if (!DBA::exists('photo', ['album' => $album, 'uid' => $page_owner_uid, 'photo-type' => Photo::DEFAULT])) {
			notice(DI::l10n()->t('Album not found.'));
			DI::baseUrl()->redirect('photos/' . $user['nickname'] . '/album');
			return; // NOTREACHED
		}

		// Check if the user has responded to a delete confirmation query
		if (!empty($_REQUEST['canceled'])) {
			DI::baseUrl()->redirect('photos/' . $user['nickname'] . '/album/' . DI::args()->getArgv()[3]);
		}

		// RENAME photo album
		$newalbum = trim($_POST['albumname'] ?? '');
		if ($newalbum != $album) {
			Photo::update(['album' => $newalbum], ['album' => $album, 'uid' => $page_owner_uid]);
			// Update the photo albums cache
			Photo::clearAlbumCache($page_owner_uid);

			DI::baseUrl()->redirect('photos/' . $a->getLoggedInUserNickname() . '/album/' . bin2hex($newalbum));
			return; // NOTREACHED
		}

		/*
		 * DELETE all photos filed in a given album
		 */
		if (!empty($_POST['dropalbum'])) {
			$res = [];

			// get the list of photos we are about to delete
			if ($visitor) {
				$r = DBA::toArray(DBA::p("SELECT distinct(`resource-id`) as `rid` FROM `photo` WHERE `contact-id` = ? AND `uid` = ? AND `album` = ?",
					$visitor,
					$page_owner_uid,
					$album
				));
			} else {
				$r = DBA::toArray(DBA::p("SELECT distinct(`resource-id`) as `rid` FROM `photo` WHERE `uid` = ? AND `album` = ?",
					local_user(),
					$album
				));
			}

			if (DBA::isResult($r)) {
				foreach ($r as $rr) {
					$res[] = $rr['rid'];
				}

				// remove the associated photos
				Photo::delete(['resource-id' => $res, 'uid' => $page_owner_uid]);

				// find and delete the corresponding item with all the comments and likes/dislikes
				Item::deleteForUser(['resource-id' => $res, 'uid' => $page_owner_uid], $page_owner_uid);

				// Update the photo albums cache
				Photo::clearAlbumCache($page_owner_uid);
				notice(DI::l10n()->t('Album successfully deleted'));
			} else {
				notice(DI::l10n()->t('Album was empty.'));
			}
		}

		DI::baseUrl()->redirect('photos/' . $user['nickname'] . '/album');
	}

	if (DI::args()->getArgc() > 3 && DI::args()->getArgv()[2] === 'image') {
		// Check if the user has responded to a delete confirmation query for a single photo
		if (!empty($_POST['canceled'])) {
			DI::baseUrl()->redirect('photos/' . DI::args()->getArgv()[1] . '/image/' . DI::args()->getArgv()[3]);
		}

		if (!empty($_POST['delete'])) {
			// same as above but remove single photo
			if ($visitor) {
				$condition = ['contact-id' => $visitor, 'uid' => $page_owner_uid, 'resource-id' => DI::args()->getArgv()[3]];

			} else {
				$condition = ['uid' => local_user(), 'resource-id' => DI::args()->getArgv()[3]];
			}

			$photo = DBA::selectFirst('photo', ['resource-id'], $condition);

			if (DBA::isResult($photo)) {
				Photo::delete(['uid' => $page_owner_uid, 'resource-id' => $photo['resource-id']]);

				Item::deleteForUser(['resource-id' => $photo['resource-id'], 'uid' => $page_owner_uid], $page_owner_uid);

				// Update the photo albums cache
				Photo::clearAlbumCache($page_owner_uid);
			} else {
				notice(DI::l10n()->t('Failed to delete the photo.'));
				DI::baseUrl()->redirect('photos/' . DI::args()->getArgv()[1] . '/image/' . DI::args()->getArgv()[3]);
			}

			DI::baseUrl()->redirect('photos/' . DI::args()->getArgv()[1]);
			return; // NOTREACHED
		}
	}

	if (DI::args()->getArgc() > 2 && (!empty($_POST['desc']) || !empty($_POST['newtag']) || isset($_POST['albname']))) {
		$desc      = !empty($_POST['desc'])      ? trim($_POST['desc'])      : '';
		$rawtags   = !empty($_POST['newtag'])    ? trim($_POST['newtag'])    : '';
		$item_id   = !empty($_POST['item_id'])   ? intval($_POST['item_id']) : 0;
		$albname   = !empty($_POST['albname'])   ? trim($_POST['albname'])   : '';
		$origaname = !empty($_POST['origaname']) ? trim($_POST['origaname']) : '';

		$resource_id = DI::args()->getArgv()[3];

		if (!strlen($albname)) {
			$albname = DateTimeFormat::localNow('Y');
		}

		if (!empty($_POST['rotate']) && (intval($_POST['rotate']) == 1 || intval($_POST['rotate']) == 2)) {
			Logger::debug('rotate');

			$photo = Photo::getPhotoForUser($page_owner_uid, $resource_id);

			if (DBA::isResult($photo)) {
				$image = Photo::getImageForPhoto($photo);

				if ($image->isValid()) {
					$rotate_deg = ((intval($_POST['rotate']) == 1) ? 270 : 90);
					$image->rotate($rotate_deg);

					$width  = $image->getWidth();
					$height = $image->getHeight();

					Photo::update(['height' => $height, 'width' => $width], ['resource-id' => $resource_id, 'uid' => $page_owner_uid, 'scale' => 0], $image);

					if ($width > 640 || $height > 640) {
						$image->scaleDown(640);
						$width  = $image->getWidth();
						$height = $image->getHeight();

						Photo::update(['height' => $height, 'width' => $width], ['resource-id' => $resource_id, 'uid' => $page_owner_uid, 'scale' => 1], $image);
					}

					if ($width > 320 || $height > 320) {
						$image->scaleDown(320);
						$width  = $image->getWidth();
						$height = $image->getHeight();

						Photo::update(['height' => $height, 'width' => $width], ['resource-id' => $resource_id, 'uid' => $page_owner_uid, 'scale' => 2], $image);
					}
				}
			}
		}

		$photos_stmt = DBA::select('photo', [], ['resource-id' => $resource_id, 'uid' => $page_owner_uid], ['order' => ['scale' => true]]);

		$photos = DBA::toArray($photos_stmt);

		if (DBA::isResult($photos)) {
			$photo = $photos[0];
			$ext = $phototypes[$photo['type']];
			Photo::update(
				['desc' => $desc, 'album' => $albname, 'allow_cid' => $str_contact_allow, 'allow_gid' => $str_group_allow, 'deny_cid' => $str_contact_deny, 'deny_gid' => $str_group_deny],
				['resource-id' => $resource_id, 'uid' => $page_owner_uid]
			);

			// Update the photo albums cache if album name was changed
			if ($albname !== $origaname) {
				Photo::clearAlbumCache($page_owner_uid);
			}
		}

		if (DBA::isResult($photos) && !$item_id) {
			// Create item container
			$title = '';
			$uri = Item::newURI();

			$arr = [];
			$arr['guid']          = System::createUUID();
			$arr['uid']           = $page_owner_uid;
			$arr['uri']           = $uri;
			$arr['post-type']     = Item::PT_IMAGE;
			$arr['wall']          = 1;
			$arr['resource-id']   = $photo['resource-id'];
			$arr['contact-id']    = $owner_record['id'];
			$arr['owner-name']    = $owner_record['name'];
			$arr['owner-link']    = $owner_record['url'];
			$arr['owner-avatar']  = $owner_record['thumb'];
			$arr['author-name']   = $owner_record['name'];
			$arr['author-link']   = $owner_record['url'];
			$arr['author-avatar'] = $owner_record['thumb'];
			$arr['title']         = $title;
			$arr['allow_cid']     = $photo['allow_cid'];
			$arr['allow_gid']     = $photo['allow_gid'];
			$arr['deny_cid']      = $photo['deny_cid'];
			$arr['deny_gid']      = $photo['deny_gid'];
			$arr['visible']       = 0;
			$arr['origin']        = 1;

			$arr['body']          = '[url=' . DI::baseUrl() . '/photos/' . $user['nickname'] . '/image/' . $photo['resource-id'] . ']'
						. '[img]' . DI::baseUrl() . '/photo/' . $photo['resource-id'] . '-' . $photo['scale'] . '.'. $ext . '[/img]'
						. '[/url]';

			$item_id = Item::insert($arr);
		}

		if ($item_id) {
			$item = Post::selectFirst(['inform', 'uri-id'], ['id' => $item_id, 'uid' => $page_owner_uid]);

			if (DBA::isResult($item)) {
				$old_inform = $item['inform'];
			}
		}

		if (strlen($rawtags)) {
			$inform   = '';

			// if the new tag doesn't have a namespace specifier (@foo or #foo) give it a hashtag
			$x = substr($rawtags, 0, 1);
			if ($x !== '@' && $x !== '#') {
				$rawtags = '#' . $rawtags;
			}

			$taginfo = [];
			$tags = BBCode::getTags($rawtags);

			if (count($tags)) {
				foreach ($tags as $tag) {
					if (strpos($tag, '@') === 0) {
						$profile = '';
						$contact = null;
						$name = substr($tag,1);

						if ((strpos($name, '@')) || (strpos($name, 'http://'))) {
							$newname = $name;
							$links = @Probe::lrdd($name);

							if (count($links)) {
								foreach ($links as $link) {
									if ($link['@attributes']['rel'] === 'http://webfinger.net/rel/profile-page') {
										$profile = $link['@attributes']['href'];
									}

									if ($link['@attributes']['rel'] === 'salmon') {
										$salmon = '$url:' . str_replace(',', '%sc', $link['@attributes']['href']);

										if (strlen($inform)) {
											$inform .= ',';
										}

										$inform .= $salmon;
									}
								}
							}

							$taginfo[] = [$newname, $profile, $salmon];
						} else {
							$newname = $name;
							$tagcid = 0;

							if (strrpos($newname, '+')) {
								$tagcid = intval(substr($newname, strrpos($newname, '+') + 1));
							}

							if ($tagcid) {
								$contact = DBA::selectFirst('contact', [], ['id' => $tagcid, 'uid' => $page_owner_uid]);
							} else {
								$newname = str_replace('_',' ',$name);

								//select someone from this user's contacts by name
								$contact = DBA::selectFirst('contact', [], ['name' => $newname, 'uid' => $page_owner_uid]);
								if (!DBA::isResult($contact)) {
									//select someone by attag or nick and the name passed in
									$contact = DBA::selectFirst('contact', [],
										['(`attag` = ? OR `nick` = ?) AND `uid` = ?', $name, $name, $page_owner_uid],
										['order' => ['attag' => true]]
									);
								}
							}

							if (DBA::isResult($contact)) {
								$newname = $contact['name'];
								$profile = $contact['url'];

								$notify = 'cid:' . $contact['id'];
								if (strlen($inform)) {
									$inform .= ',';
								}
								$inform .= $notify;
							}
						}

						if ($profile) {
							if (!empty($contact)) {
								$taginfo[] = [$newname, $profile, $notify, $contact];
							} else {
								$taginfo[] = [$newname, $profile, $notify, null];
							}

							$profile = str_replace(',', '%2c', $profile);

							if (!empty($item['uri-id'])) {
								Tag::store($item['uri-id'], Tag::MENTION, $newname, $profile);
							}
						}
					} elseif (strpos($tag, '#') === 0) {
						$tagname = substr($tag, 1);
						if (!empty($item['uri-id'])) {
							Tag::store($item['uri-id'], Tag::HASHTAG, $tagname);
						}
					}
				}
			}

			$newinform = $old_inform ?? '';
			if (strlen($newinform) && strlen($inform)) {
				$newinform .= ',';
			}
			$newinform .= $inform;

			$fields = ['inform' => $newinform, 'edited' => DateTimeFormat::utcNow(), 'changed' => DateTimeFormat::utcNow()];
			$condition = ['id' => $item_id];
			Item::update($fields, $condition);

			$best = 0;
			foreach ($photos as $scales) {
				if (intval($scales['scale']) == 2) {
					$best = 2;
					break;
				}

				if (intval($scales['scale']) == 4) {
					$best = 4;
					break;
				}
			}

			if (count($taginfo)) {
				foreach ($taginfo as $tagged) {
					$uri = Item::newURI();

					$arr = [];
					$arr['guid']          = System::createUUID();
					$arr['uid']           = $page_owner_uid;
					$arr['uri']           = $uri;
					$arr['wall']          = 1;
					$arr['contact-id']    = $owner_record['id'];
					$arr['owner-name']    = $owner_record['name'];
					$arr['owner-link']    = $owner_record['url'];
					$arr['owner-avatar']  = $owner_record['thumb'];
					$arr['author-name']   = $owner_record['name'];
					$arr['author-link']   = $owner_record['url'];
					$arr['author-avatar'] = $owner_record['thumb'];
					$arr['title']         = '';
					$arr['allow_cid']     = $photo['allow_cid'];
					$arr['allow_gid']     = $photo['allow_gid'];
					$arr['deny_cid']      = $photo['deny_cid'];
					$arr['deny_gid']      = $photo['deny_gid'];
					$arr['visible']       = 0;
					$arr['verb']          = Activity::TAG;
					$arr['gravity']       = GRAVITY_PARENT;
					$arr['object-type']   = Activity\ObjectType::PERSON;
					$arr['target-type']   = Activity\ObjectType::IMAGE;
					$arr['inform']        = $tagged[2];
					$arr['origin']        = 1;
					$arr['body']          = DI::l10n()->t('%1$s was tagged in %2$s by %3$s', '[url=' . $tagged[1] . ']' . $tagged[0] . '[/url]', '[url=' . DI::baseUrl() . '/photos/' . $owner_record['nickname'] . '/image/' . $photo['resource-id'] . ']' . DI::l10n()->t('a photo') . '[/url]', '[url=' . $owner_record['url'] . ']' . $owner_record['name'] . '[/url]') ;
					$arr['body'] .= "\n\n" . '[url=' . DI::baseUrl() . '/photos/' . $owner_record['nickname'] . '/image/' . $photo['resource-id'] . ']' . '[img]' . DI::baseUrl() . "/photo/" . $photo['resource-id'] . '-' . $best . '.' . $ext . '[/img][/url]' . "\n" ;

					$arr['object'] = '<object><type>' . Activity\ObjectType::PERSON . '</type><title>' . $tagged[0] . '</title><id>' . $tagged[1] . '/' . $tagged[0] . '</id>';
					$arr['object'] .= '<link>' . XML::escape('<link rel="alternate" type="text/html" href="' . $tagged[1] . '" />' . "\n");
					if ($tagged[3]) {
						$arr['object'] .= XML::escape('<link rel="photo" type="' . $photo['type'] . '" href="' . $tagged[3]['photo'] . '" />' . "\n");
					}
					$arr['object'] .= '</link></object>' . "\n";

					$arr['target'] = '<target><type>' . Activity\ObjectType::IMAGE . '</type><title>' . $photo['desc'] . '</title><id>'
						. DI::baseUrl() . '/photos/' . $owner_record['nickname'] . '/image/' . $photo['resource-id'] . '</id>';
					$arr['target'] .= '<link>' . XML::escape('<link rel="alternate" type="text/html" href="' . DI::baseUrl() . '/photos/' . $owner_record['nickname'] . '/image/' . $photo['resource-id'] . '" />' . "\n" . '<link rel="preview" type="' . $photo['type'] . '" href="' . DI::baseUrl() . "/photo/" . $photo['resource-id'] . '-' . $best . '.' . $ext . '" />') . '</link></target>';

					Item::insert($arr);
				}
			}
		}
		DI::baseUrl()->redirect($_SESSION['photo_return']);
		return; // NOTREACHED
	}


	// default post action - upload a photo
	Hook::callAll('photo_post_init', $_POST);

	// Determine the album to use
	$album    = trim($_REQUEST['album'] ?? '');
	$newalbum = trim($_REQUEST['newalbum'] ?? '');

	Logger::debug('album= ' . $album . ' newalbum= ' . $newalbum);

	if (!strlen($album)) {
		if (strlen($newalbum)) {
			$album = $newalbum;
		} else {
			$album = DateTimeFormat::localNow('Y');
		}
	}

	/*
	 * We create a wall item for every photo, but we don't want to
	 * overwhelm the data stream with a hundred newly uploaded photos.
	 * So we will make the first photo uploaded to this album in the last several hours
	 * visible by default, the rest will become visible over time when and if
	 * they acquire comments, likes, dislikes, and/or tags
	 */

	$r = Photo::selectToArray([], ['`album` = ? AND `uid` = ? AND `created` > ?', $album, $page_owner_uid, DateTimeFormat::utc('now - 3 hours')]);

	if (!DBA::isResult($r) || ($album == DI::l10n()->t(Photo::PROFILE_PHOTOS))) {
		$visible = 1;
	} else {
		$visible = 0;
	}

	if (!empty($_REQUEST['not_visible']) && $_REQUEST['not_visible'] !== 'false') {
		$visible = 0;
	}

	$ret = ['src' => '', 'filename' => '', 'filesize' => 0, 'type' => ''];

	Hook::callAll('photo_post_file', $ret);

	if (!empty($ret['src']) && !empty($ret['filesize'])) {
		$src      = $ret['src'];
		$filename = $ret['filename'];
		$filesize = $ret['filesize'];
		$type     = $ret['type'];
		$error    = UPLOAD_ERR_OK;
	} elseif (!empty($_FILES['userfile'])) {
		$src      = $_FILES['userfile']['tmp_name'];
		$filename = basename($_FILES['userfile']['name']);
		$filesize = intval($_FILES['userfile']['size']);
		$type     = $_FILES['userfile']['type'];
		$error    = $_FILES['userfile']['error'];
	} else {
		$error    = UPLOAD_ERR_NO_FILE;
	}

	if ($error !== UPLOAD_ERR_OK) {
		switch ($error) {
			case UPLOAD_ERR_INI_SIZE:
				notice(DI::l10n()->t('Image exceeds size limit of %s', ini_get('upload_max_filesize')));
				break;
			case UPLOAD_ERR_FORM_SIZE:
				notice(DI::l10n()->t('Image exceeds size limit of %s', Strings::formatBytes($_REQUEST['MAX_FILE_SIZE'] ?? 0)));
				break;
			case UPLOAD_ERR_PARTIAL:
				notice(DI::l10n()->t('Image upload didn\'t complete, please try again'));
				break;
			case UPLOAD_ERR_NO_FILE:
				notice(DI::l10n()->t('Image file is missing'));
				break;
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
			case UPLOAD_ERR_EXTENSION:
				notice(DI::l10n()->t('Server can\'t accept new file upload at this time, please contact your administrator'));
				break;
		}
		@unlink($src);
		$foo = 0;
		Hook::callAll('photo_post_end', $foo);
		return;
	}

	$type = Images::getMimeTypeBySource($src, $filename, $type);

	Logger::info('photos: upload: received file: ' . $filename . ' as ' . $src . ' ('. $type . ') ' . $filesize . ' bytes');

	$maximagesize = DI::config()->get('system', 'maximagesize');

	if ($maximagesize && ($filesize > $maximagesize)) {
		notice(DI::l10n()->t('Image exceeds size limit of %s', Strings::formatBytes($maximagesize)));
		@unlink($src);
		$foo = 0;
		Hook::callAll('photo_post_end', $foo);
		return;
	}

	if (!$filesize) {
		notice(DI::l10n()->t('Image file is empty.'));
		@unlink($src);
		$foo = 0;
		Hook::callAll('photo_post_end', $foo);
		return;
	}

	Logger::debug('loading contents', ['src' => $src]);

	$imagedata = @file_get_contents($src);

	$image = new Image($imagedata, $type);

	if (!$image->isValid()) {
		Logger::notice('unable to process image');
		notice(DI::l10n()->t('Unable to process image.'));
		@unlink($src);
		$foo = 0;
		Hook::callAll('photo_post_end',$foo);
		return;
	}

	$exif = $image->orient($src);
	@unlink($src);

	$max_length = DI::config()->get('system', 'max_image_length');
	if ($max_length > 0) {
		$image->scaleDown($max_length);
	}

	$width  = $image->getWidth();
	$height = $image->getHeight();

	$smallest = 0;

	$resource_id = Photo::newResource();

	$r = Photo::store($image, $page_owner_uid, $visitor, $resource_id, $filename, $album, 0 , Photo::DEFAULT, $str_contact_allow, $str_group_allow, $str_contact_deny, $str_group_deny);

	if (!$r) {
		Logger::warning('image store failed');
		notice(DI::l10n()->t('Image upload failed.'));
		return;
	}

	if ($width > 640 || $height > 640) {
		$image->scaleDown(640);
		Photo::store($image, $page_owner_uid, $visitor, $resource_id, $filename, $album, 1, Photo::DEFAULT, $str_contact_allow, $str_group_allow, $str_contact_deny, $str_group_deny);
		$smallest = 1;
	}

	if ($width > 320 || $height > 320) {
		$image->scaleDown(320);
		Photo::store($image, $page_owner_uid, $visitor, $resource_id, $filename, $album, 2, Photo::DEFAULT, $str_contact_allow, $str_group_allow, $str_contact_deny, $str_group_deny);
		$smallest = 2;
	}

	$uri = Item::newURI();

	// Create item container
	$lat = $lon = null;
	if (!empty($exif['GPS']) && Feature::isEnabled($page_owner_uid, 'photo_location')) {
		$lat = Photo::getGps($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef']);
		$lon = Photo::getGps($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef']);
	}

	$arr = [];
	if ($lat && $lon) {
		$arr['coord'] = $lat . ' ' . $lon;
	}

	$arr['guid']          = System::createUUID();
	$arr['uid']           = $page_owner_uid;
	$arr['uri']           = $uri;
	$arr['post-type']     = Item::PT_IMAGE;
	$arr['wall']          = 1;
	$arr['resource-id']   = $resource_id;
	$arr['contact-id']    = $owner_record['id'];
	$arr['owner-name']    = $owner_record['name'];
	$arr['owner-link']    = $owner_record['url'];
	$arr['owner-avatar']  = $owner_record['thumb'];
	$arr['author-name']   = $owner_record['name'];
	$arr['author-link']   = $owner_record['url'];
	$arr['author-avatar'] = $owner_record['thumb'];
	$arr['title']         = '';
	$arr['allow_cid']     = $str_contact_allow;
	$arr['allow_gid']     = $str_group_allow;
	$arr['deny_cid']      = $str_contact_deny;
	$arr['deny_gid']      = $str_group_deny;
	$arr['visible']       = $visible;
	$arr['origin']        = 1;

	$arr['body']          = '[url=' . DI::baseUrl() . '/photos/' . $owner_record['nickname'] . '/image/' . $resource_id . ']'
				. '[img]' . DI::baseUrl() . "/photo/{$resource_id}-{$smallest}.".$image->getExt() . '[/img]'
				. '[/url]';

	$item_id = Item::insert($arr);
	// Update the photo albums cache
	Photo::clearAlbumCache($page_owner_uid);

	Hook::callAll('photo_post_end', $item_id);

	// addon uploaders should call "exit()" within the photo_post_end hook
	// if they do not wish to be redirected

	DI::baseUrl()->redirect($_SESSION['photo_return']);
	// NOTREACHED
}

function photos_content(App $a)
{
	// URLs:
	// photos/name
	// photos/name/upload
	// photos/name/upload/xxxxx (xxxxx is album name)
	// photos/name/album/xxxxx
	// photos/name/album/xxxxx/edit
	// photos/name/album/xxxxx/drop
	// photos/name/image/xxxxx
	// photos/name/image/xxxxx/edit
	// photos/name/image/xxxxx/drop

	$user = User::getByNickname(DI::args()->getArgv()[1]);
	if (!DBA::isResult($user)) {
		throw new HTTPException\NotFoundException(DI::l10n()->t('User not found.'));
	}

	if (DI::config()->get('system', 'block_public') && !Session::isAuthenticated()) {
		notice(DI::l10n()->t('Public access denied.'));
		return;
	}

	if (empty($user)) {
		notice(DI::l10n()->t('No photos selected'));
		return;
	}

	$profile = Profile::getByUID($user['uid']);

	$phototypes = Images::supportedTypes();

	$_SESSION['photo_return'] = DI::args()->getCommand();

	// Parse arguments
	$datum = null;
	if (DI::args()->getArgc() > 3) {
		$datatype = DI::args()->getArgv()[2];
		$datum = DI::args()->getArgv()[3];
	} elseif ((DI::args()->getArgc() > 2) && (DI::args()->getArgv()[2] === 'upload')) {
		$datatype = 'upload';
	} else {
		$datatype = 'summary';
	}

	if (DI::args()->getArgc() > 4) {
		$cmd = DI::args()->getArgv()[4];
	} else {
		$cmd = 'view';
	}

	// Setup permissions structures
	$can_post       = false;
	$visitor        = 0;
	$contact        = null;
	$remote_contact = false;
	$contact_id     = 0;
	$edit           = '';
	$drop           = '';

	$owner_uid = $user['uid'];

	$community_page = (($user['page-flags'] == User::PAGE_FLAGS_COMMUNITY) ? true : false);

	if (local_user() && (local_user() == $owner_uid)) {
		$can_post = true;
	} elseif ($community_page && !empty(Session::getRemoteContactID($owner_uid))) {
		$contact_id = Session::getRemoteContactID($owner_uid);
		$contact = DBA::selectFirst('contact', [], ['id' => $contact_id, 'uid' => $owner_uid, 'blocked' => false, 'pending' => false]);

		if (DBA::isResult($contact)) {
			$can_post = true;
			$remote_contact = true;
			$visitor = $contact_id;
		}
	}

	// perhaps they're visiting - but not a community page, so they wouldn't have write access
	if (!empty(Session::getRemoteContactID($owner_uid)) && !$visitor) {
		$contact_id = Session::getRemoteContactID($owner_uid);

		$contact = DBA::selectFirst('contact', [], ['id' => $contact_id, 'uid' => $owner_uid, 'blocked' => false, 'pending' => false]);

		$remote_contact = DBA::isResult($contact);
	}

	if (!$remote_contact && local_user()) {
		$contact_id = $_SESSION['cid'];

		$contact = DBA::selectFirst('contact', [], ['id' => $contact_id, 'uid' => $owner_uid, 'blocked' => false, 'pending' => false]);
	}

	if ($user['hidewall'] && (local_user() != $owner_uid) && !$remote_contact) {
		notice(DI::l10n()->t('Access to this item is restricted.'));
		return;
	}

	$sql_extra = Security::getPermissionsSQLByUserId($owner_uid);

	$o = "";

	// tabs
	$is_owner = (local_user() && (local_user() == $owner_uid));
	$o .= BaseProfile::getTabsHTML($a, 'photos', $is_owner, $user['nickname'], $profile['hide-friends']);

	// Display upload form
	if ($datatype === 'upload') {
		if (!$can_post) {
			notice(DI::l10n()->t('Permission denied.'));
			return;
		}

		$selname = (!is_null($datum) && Strings::isHex($datum)) ? hex2bin($datum) : '';

		$albumselect = '';

		$albumselect .= '<option value="" ' . (!$selname ? ' selected="selected" ' : '') . '>&lt;current year&gt;</option>';
		$albums = Photo::getAlbums($owner_uid);
		if (!empty($albums)) {
			foreach ($albums as $album) {
				if ($album['album'] === '') {
					continue;
				}
				$selected = (($selname === $album['album']) ? ' selected="selected" ' : '');
				$albumselect .= '<option value="' . $album['album'] . '"' . $selected . '>' . $album['album'] . '</option>';
			}
		}

		$uploader = '';

		$ret = ['post_url' => 'photos/' . $user['nickname'],
				'addon_text' => $uploader,
				'default_upload' => true];

		Hook::callAll('photo_upload_form',$ret);

		$default_upload_box = Renderer::replaceMacros(Renderer::getMarkupTemplate('photos_default_uploader_box.tpl'), []);
		$default_upload_submit = Renderer::replaceMacros(Renderer::getMarkupTemplate('photos_default_uploader_submit.tpl'), [
			'$submit' => DI::l10n()->t('Submit'),
		]);

		$usage_message = '';

		$tpl = Renderer::getMarkupTemplate('photos_upload.tpl');

		$aclselect_e = ($visitor ? '' : ACL::getFullSelectorHTML(DI::page(), $a->getLoggedInUserId()));

		$o .= Renderer::replaceMacros($tpl,[
			'$pagename' => DI::l10n()->t('Upload Photos'),
			'$sessid' => session_id(),
			'$usage' => $usage_message,
			'$nickname' => $user['nickname'],
			'$newalbum' => DI::l10n()->t('New album name: '),
			'$existalbumtext' => DI::l10n()->t('or select existing album:'),
			'$nosharetext' => DI::l10n()->t('Do not show a status post for this upload'),
			'$albumselect' => $albumselect,
			'$permissions' => DI::l10n()->t('Permissions'),
			'$aclselect' => $aclselect_e,
			'$lockstate' => ACL::getLockstateForUserId($a->getLoggedInUserId()) ? 'lock' : 'unlock',
			'$alt_uploader' => $ret['addon_text'],
			'$default_upload_box' => ($ret['default_upload'] ? $default_upload_box : ''),
			'$default_upload_submit' => ($ret['default_upload'] ? $default_upload_submit : ''),
			'$uploadurl' => $ret['post_url'],

			// ACL permissions box
			'$return_path' => DI::args()->getQueryString(),
		]);

		return $o;
	}

	// Display a single photo album
	if ($datatype === 'album') {
		// if $datum is not a valid hex, redirect to the default page
		if (is_null($datum) || !Strings::isHex($datum)) {
			DI::baseUrl()->redirect('photos/' . $user['nickname']. '/album');
		}
		$album = hex2bin($datum);

		if ($can_post && !Photo::exists(['uid' => $owner_uid, 'album' => $album, 'photo-type' => Photo::DEFAULT])) {
			$can_post = false;
		}

		$total = 0;
		$r = DBA::toArray(DBA::p("SELECT `resource-id`, max(`scale`) AS `scale` FROM `photo` WHERE `uid` = ? AND `album` = ?
			AND `scale` <= 4 $sql_extra GROUP BY `resource-id`",
			$owner_uid,
			$album
		));
		if (DBA::isResult($r)) {
			$total = count($r);
		}

		$pager = new Pager(DI::l10n(), DI::args()->getQueryString(), 20);

		/// @TODO I have seen this many times, maybe generalize it script-wide and encapsulate it?
		$order_field = $_GET['order'] ?? '';
		if ($order_field === 'created') {
			$order = 'ASC';
		} else {
			$order = 'DESC';
		}

		$r = DBA::toArray(DBA::p("SELECT `resource-id`, ANY_VALUE(`id`) AS `id`, ANY_VALUE(`filename`) AS `filename`,
			ANY_VALUE(`type`) AS `type`, max(`scale`) AS `scale`, ANY_VALUE(`desc`) as `desc`,
			ANY_VALUE(`created`) as `created`
			FROM `photo` WHERE `uid` = ? AND `album` = ?
			AND `scale` <= 4 $sql_extra GROUP BY `resource-id` ORDER BY `created` $order LIMIT ? , ?",
			intval($owner_uid),
			DBA::escape($album),
			$pager->getStart(),
			$pager->getItemsPerPage()
		));

		if ($cmd === 'drop') {
			$drop_url = DI::args()->getQueryString();

			return Renderer::replaceMacros(Renderer::getMarkupTemplate('confirm.tpl'), [
				'$l10n'           => [
					'message' => DI::l10n()->t('Do you really want to delete this photo album and all its photos?'),
					'confirm' => DI::l10n()->t('Delete Album'),
					'cancel'  => DI::l10n()->t('Cancel'),
				],
				'$method'        => 'post',
				'$confirm_url'   => $drop_url,
				'$confirm_name'  => 'dropalbum',
				'$confirm_value' => 'dropalbum',
			]);
		}

		// edit album name
		if ($cmd === 'edit') {
			if ($can_post) {
				$edit_tpl = Renderer::getMarkupTemplate('album_edit.tpl');

				$album_e = $album;

				$o .= Renderer::replaceMacros($edit_tpl,[
					'$nametext' => DI::l10n()->t('New album name: '),
					'$nickname' => $user['nickname'],
					'$album' => $album_e,
					'$hexalbum' => bin2hex($album),
					'$submit' => DI::l10n()->t('Submit'),
					'$dropsubmit' => DI::l10n()->t('Delete Album')
				]);
			}
		} elseif ($can_post) {
			$edit = [DI::l10n()->t('Edit Album'), 'photos/' . $user['nickname'] . '/album/' . bin2hex($album) . '/edit'];
			$drop = [DI::l10n()->t('Drop Album'), 'photos/' . $user['nickname'] . '/album/' . bin2hex($album) . '/drop'];
		}

		if ($order_field === 'created') {
			$order =  [DI::l10n()->t('Show Newest First'), 'photos/' . $user['nickname'] . '/album/' . bin2hex($album), 'oldest'];
		} else {
			$order = [DI::l10n()->t('Show Oldest First'), 'photos/' . $user['nickname'] . '/album/' . bin2hex($album) . '?order=created', 'newest'];
		}

		$photos = [];

		if (DBA::isResult($r)) {
			// "Twist" is only used for the duepunto theme with style "slackr"
			$twist = false;
			foreach ($r as $rr) {
				$twist = !$twist;

				$ext = $phototypes[$rr['type']];

				$imgalt_e = $rr['filename'];
				$desc_e = $rr['desc'];

				$photos[] = [
					'id' => $rr['id'],
					'twist' => ' ' . ($twist ? 'rotleft' : 'rotright') . rand(2,4),
					'link' => 'photos/' . $user['nickname'] . '/image/' . $rr['resource-id']
						. ($order_field === 'created' ? '?order=created' : ''),
					'title' => DI::l10n()->t('View Photo'),
					'src' => 'photo/' . $rr['resource-id'] . '-' . $rr['scale'] . '.' .$ext,
					'alt' => $imgalt_e,
					'desc'=> $desc_e,
					'ext' => $ext,
					'hash'=> $rr['resource-id'],
				];
			}
		}

		$tpl = Renderer::getMarkupTemplate('photo_album.tpl');
		$o .= Renderer::replaceMacros($tpl, [
			'$photos' => $photos,
			'$album' => $album,
			'$can_post' => $can_post,
			'$upload' => [DI::l10n()->t('Upload New Photos'), 'photos/' . $user['nickname'] . '/upload/' . bin2hex($album)],
			'$order' => $order,
			'$edit' => $edit,
			'$drop' => $drop,
			'$paginate' => $pager->renderFull($total),
		]);

		return $o;

	}

	// Display one photo
	if ($datatype === 'image') {
		// fetch image, item containing image, then comments
		$ph = Photo::selectToArray([], ["`uid` = ? AND `resource-id` = ? " . $sql_extra, $owner_uid, $datum], ['order' => ['scale']]);

		if (!DBA::isResult($ph)) {
			if (DBA::exists('photo', ['resource-id' => $datum, 'uid' => $owner_uid])) {
				notice(DI::l10n()->t('Permission denied. Access to this item may be restricted.'));
			} else {
				notice(DI::l10n()->t('Photo not available'));
			}
			return;
		}

		if ($cmd === 'drop') {
			$drop_url = DI::args()->getQueryString();

			return Renderer::replaceMacros(Renderer::getMarkupTemplate('confirm.tpl'), [
				'$l10n'           => [
					'message' => DI::l10n()->t('Do you really want to delete this photo?'),
					'confirm' => DI::l10n()->t('Delete Photo'),
					'cancel'  => DI::l10n()->t('Cancel'),
				],
				'$method'        => 'post',
				'$confirm_url'   => $drop_url,
				'$confirm_name'  => 'delete',
				'$confirm_value' => 'delete',
			]);
		}

		$prevlink = '';
		$nextlink = '';

		/*
		 * @todo This query is totally bad, the whole functionality has to be changed
		 * The query leads to a really intense used index.
		 * By now we hide it if someone wants to.
		 */
		if ($cmd === 'view' && !DI::config()->get('system', 'no_count', false)) {
			$order_field = $_GET['order'] ?? '';

			if ($order_field === 'created') {
				$params = ['order' => [$order_field]];
			} elseif (!empty($order_field)) {
				$params = ['order' => [$order_field => true]];
			} else {
				$params = [];
			}

			$prvnxt = Photo::selectToArray(['resource-id'], ["`album` = ? AND `uid` = ? AND `scale` = ?" . $sql_extra, $ph[0]['album'], $owner_uid, 0], $params);

			if (DBA::isResult($prvnxt)) {
				$prv = null;
				$nxt = null;
				foreach ($prvnxt as $z => $entry) {
					if ($entry['resource-id'] == $ph[0]['resource-id']) {
						$prv = $z - 1;
						$nxt = $z + 1;
						if ($prv < 0) {
							$prv = count($prvnxt) - 1;
						}
						if ($nxt >= count($prvnxt)) {
							$nxt = 0;
						}
						break;
					}
				}

				if (!is_null($prv)) {
					$prevlink = 'photos/' . $user['nickname'] . '/image/' . $prvnxt[$prv]['resource-id'] . ($order_field === 'created' ? '?order=created' : '');
				}
				if (!is_null($nxt)) {
					$nextlink = 'photos/' . $user['nickname'] . '/image/' . $prvnxt[$nxt]['resource-id'] . ($order_field === 'created' ? '?order=created' : '');
				}

				$tpl = Renderer::getMarkupTemplate('photo_edit_head.tpl');
				DI::page()['htmlhead'] .= Renderer::replaceMacros($tpl,[
					'$prevlink' => $prevlink,
					'$nextlink' => $nextlink
				]);

				if ($prevlink) {
					$prevlink = [$prevlink, '<div class="icon prev"></div>'];
				}

				if ($nextlink) {
					$nextlink = [$nextlink, '<div class="icon next"></div>'];
				}
 			}
		}

		if (count($ph) == 1) {
			$hires = $lores = $ph[0];
		}

		if (count($ph) > 1) {
			if ($ph[1]['scale'] == 2) {
				// original is 640 or less, we can display it directly
				$hires = $lores = $ph[0];
			} else {
				$hires = $ph[0];
				$lores = $ph[1];
			}
		}

		$album_link = 'photos/' . $user['nickname'] . '/album/' . bin2hex($ph[0]['album']);

		$tools = null;

		if ($can_post && ($ph[0]['uid'] == $owner_uid)) {
			$tools = [];
			if ($cmd === 'edit') {
				$tools['view'] = ['photos/' . $user['nickname'] . '/image/' . $datum, DI::l10n()->t('View photo')];
			} else {
				$tools['edit'] = ['photos/' . $user['nickname'] . '/image/' . $datum . '/edit', DI::l10n()->t('Edit photo')];
				$tools['delete'] = ['photos/' . $user['nickname'] . '/image/' . $datum . '/drop', DI::l10n()->t('Delete photo')];
				$tools['profile'] = ['settings/profile/photo/crop/' . $ph[0]['resource-id'], DI::l10n()->t('Use as profile photo')];
			}

			if (
				$ph[0]['uid'] == local_user()
				&& (strlen($ph[0]['allow_cid']) || strlen($ph[0]['allow_gid']) || strlen($ph[0]['deny_cid']) || strlen($ph[0]['deny_gid']))
			) {
				$tools['lock'] = DI::l10n()->t('Private Photo');
			}
		}

		$photo = [
			'href' => 'photo/' . $hires['resource-id'] . '-' . $hires['scale'] . '.' . $phototypes[$hires['type']],
			'title'=> DI::l10n()->t('View Full Size'),
			'src'  => 'photo/' . $lores['resource-id'] . '-' . $lores['scale'] . '.' . $phototypes[$lores['type']] . '?_u=' . DateTimeFormat::utcNow('ymdhis'),
			'height' => $hires['height'],
			'width' => $hires['width'],
			'album' => $hires['album'],
			'filename' => $hires['filename'],
		];

		$map = null;
		$link_item = [];
		$total = 0;

		// Do we have an item for this photo?

		// FIXME! - replace following code to display the conversation with our normal
		// conversation functions so that it works correctly and tracks changes
		// in the evolving conversation code.
		// The difference is that we won't be displaying the conversation head item
		// as a "post" but displaying instead the photo it is linked to

		$link_item = Post::selectFirst([], ["`resource-id` = ?" . $sql_extra, $datum]);

		if (!empty($link_item['parent']) && !empty($link_item['uid'])) {
			$condition = ["`parent` = ? AND `gravity` = ?",  $link_item['parent'], GRAVITY_COMMENT];
			$total = Post::count($condition);

			$pager = new Pager(DI::l10n(), DI::args()->getQueryString());

			$params = ['order' => ['id'], 'limit' => [$pager->getStart(), $pager->getItemsPerPage()]];
			$items = Post::toArray(Post::selectForUser($link_item['uid'], Item::ITEM_FIELDLIST, $condition, $params));

			if (local_user() == $link_item['uid']) {
				Item::update(['unseen' => false], ['parent' => $link_item['parent']]);
			}
		}

		if (!empty($link_item['coord'])) {
			$map = Map::byCoordinates($link_item['coord']);
		}

		$tags = null;

		if (!empty($link_item['id'])) {
			// parse tags and add links
			$tag_arr = [];
			foreach (Tag::getByURIId($link_item['uri-id']) as $tag) {
				$tag_arr[] = [
					'name' => $tag['name'],
					'removeurl' => '/tagrm/' . $link_item['id'] . '/' . bin2hex($tag['name'])
				];
			}
			$tags = ['title' => DI::l10n()->t('Tags: '), 'tags' => $tag_arr];
			if ($cmd === 'edit') {
				$tags['removeanyurl'] = 'tagrm/' . $link_item['id'];
				$tags['removetitle'] = DI::l10n()->t('[Select tags to remove]');
			}
		}


		$edit = Null;
		if ($cmd === 'edit' && $can_post) {
			$edit_tpl = Renderer::getMarkupTemplate('photo_edit.tpl');

			$album_e = $ph[0]['album'];
			$caption_e = $ph[0]['desc'];
			$aclselect_e = ACL::getFullSelectorHTML(DI::page(), $a->getLoggedInUserId(), false, ACL::getDefaultUserPermissions($ph[0]));

			$edit = Renderer::replaceMacros($edit_tpl, [
				'$id' => $ph[0]['id'],
				'$album' => ['albname', DI::l10n()->t('New album name'), $album_e,''],
				'$caption' => ['desc', DI::l10n()->t('Caption'), $caption_e, ''],
				'$tags' => ['newtag', DI::l10n()->t('Add a Tag'), "", DI::l10n()->t('Example: @bob, @Barbara_Jensen, @jim@example.com, #California, #camping')],
				'$rotate_none' => ['rotate', DI::l10n()->t('Do not rotate'),0,'', true],
				'$rotate_cw' => ['rotate', DI::l10n()->t("Rotate CW \x28right\x29"),1,''],
				'$rotate_ccw' => ['rotate', DI::l10n()->t("Rotate CCW \x28left\x29"),2,''],

				'$nickname' => $user['nickname'],
				'$resource_id' => $ph[0]['resource-id'],
				'$permissions' => DI::l10n()->t('Permissions'),
				'$aclselect' => $aclselect_e,

				'$item_id' => $link_item['id'] ?? 0,
				'$submit' => DI::l10n()->t('Submit'),
				'$delete' => DI::l10n()->t('Delete Photo'),

				// ACL permissions box
				'$return_path' => DI::args()->getQueryString(),
			]);
		}

		$like = '';
		$dislike = '';
		$likebuttons = '';
		$comments = '';
		$paginate = '';

		if (!empty($link_item['id']) && !empty($link_item['uri'])) {
			$cmnt_tpl = Renderer::getMarkupTemplate('comment_item.tpl');
			$tpl = Renderer::getMarkupTemplate('photo_item.tpl');
			$return_path = DI::args()->getCommand();

			if (!DBA::isResult($items)) {
				if (($can_post || Security::canWriteToUserWall($owner_uid))) {
					/*
					 * Hmmm, code depending on the presence of a particular addon?
					 * This should be better if done by a hook
					 */
					$qcomment = null;
					if (Addon::isEnabled('qcomment')) {
						$words = DI::pConfig()->get(local_user(), 'qcomment', 'words');
						$qcomment = $words ? explode("\n", $words) : [];
					}

					$comments .= Renderer::replaceMacros($cmnt_tpl, [
						'$return_path' => '',
						'$jsreload' => $return_path,
						'$id' => $link_item['id'],
						'$parent' => $link_item['id'],
						'$profile_uid' =>  $owner_uid,
						'$mylink' => $contact['url'],
						'$mytitle' => DI::l10n()->t('This is you'),
						'$myphoto' => $contact['thumb'],
						'$comment' => DI::l10n()->t('Comment'),
						'$submit' => DI::l10n()->t('Submit'),
						'$preview' => DI::l10n()->t('Preview'),
						'$loading' => DI::l10n()->t('Loading...'),
						'$qcomment' => $qcomment,
						'$rand_num' => Crypto::randomDigits(12)
					]);
				}
			}

			$conv_responses = [
				'like'        => [],
				'dislike'     => [],
				'attendyes'   => [],
				'attendno'    => [],
				'attendmaybe' => []
			];

			if (DI::pConfig()->get(local_user(), 'system', 'hide_dislike')) {
				unset($conv_responses['dislike']);
			}

			// display comments
			if (DBA::isResult($items)) {
				foreach ($items as $item) {
					DI::conversation()->builtinActivityPuller($item, $conv_responses);
				}

				if (!empty($conv_responses['like'][$link_item['uri']])) {
					$like = DI::conversation()->formatActivity($conv_responses['like'][$link_item['uri']]['links'], 'like', $link_item['id']);
				}

				if (!empty($conv_responses['dislike'][$link_item['uri']])) {
					$dislike = DI::conversation()->formatActivity($conv_responses['dislike'][$link_item['uri']]['links'], 'dislike', $link_item['id']);
				}

				if (($can_post || Security::canWriteToUserWall($owner_uid))) {
					/*
					 * Hmmm, code depending on the presence of a particular addon?
					 * This should be better if done by a hook
					 */
					$qcomment = null;
					if (Addon::isEnabled('qcomment')) {
						$words = DI::pConfig()->get(local_user(), 'qcomment', 'words');
						$qcomment = $words ? explode("\n", $words) : [];
					}

					$comments .= Renderer::replaceMacros($cmnt_tpl,[
						'$return_path' => '',
						'$jsreload' => $return_path,
						'$id' => $link_item['id'],
						'$parent' => $link_item['id'],
						'$profile_uid' =>  $owner_uid,
						'$mylink' => $contact['url'],
						'$mytitle' => DI::l10n()->t('This is you'),
						'$myphoto' => $contact['thumb'],
						'$comment' => DI::l10n()->t('Comment'),
						'$submit' => DI::l10n()->t('Submit'),
						'$preview' => DI::l10n()->t('Preview'),
						'$qcomment' => $qcomment,
						'$rand_num' => Crypto::randomDigits(12)
					]);
				}

				foreach ($items as $item) {
					$comment = '';
					$template = $tpl;

					$activity = DI::activity();

					if (($activity->match($item['verb'], Activity::LIKE) ||
					     $activity->match($item['verb'], Activity::DISLIKE)) &&
					    ($item['gravity'] != GRAVITY_PARENT)) {
						continue;
					}

					$author = ['uid' => 0, 'id' => $item['author-id'],
						'network' => $item['author-network'], 'url' => $item['author-link']];
					$profile_url = Contact::magicLinkByContact($author);
					if (strpos($profile_url, 'redir/') === 0) {
						$sparkle = ' sparkle';
					} else {
						$sparkle = '';
					}

					$dropping = (($item['contact-id'] == $contact_id) || ($item['uid'] == local_user()));
					$drop = [
						'dropping' => $dropping,
						'pagedrop' => false,
						'select' => DI::l10n()->t('Select'),
						'delete' => DI::l10n()->t('Delete'),
					];

					$title_e = $item['title'];
					$body_e = BBCode::convertForUriId($item['uri-id'], $item['body']);

					$comments .= Renderer::replaceMacros($template,[
						'$id' => $item['id'],
						'$profile_url' => $profile_url,
						'$name' => $item['author-name'],
						'$thumb' => $item['author-avatar'],
						'$sparkle' => $sparkle,
						'$title' => $title_e,
						'$body' => $body_e,
						'$ago' => Temporal::getRelativeDate($item['created']),
						'$indent' => (($item['parent'] != $item['id']) ? ' comment' : ''),
						'$drop' => $drop,
						'$comment' => $comment
					]);

					if (($can_post || Security::canWriteToUserWall($owner_uid))) {
						/*
						 * Hmmm, code depending on the presence of a particular addon?
						 * This should be better if done by a hook
						 */
						$qcomment = null;
						if (Addon::isEnabled('qcomment')) {
							$words = DI::pConfig()->get(local_user(), 'qcomment', 'words');
							$qcomment = $words ? explode("\n", $words) : [];
						}

						$comments .= Renderer::replaceMacros($cmnt_tpl, [
							'$return_path' => '',
							'$jsreload' => $return_path,
							'$id' => $item['id'],
							'$parent' => $item['parent'],
							'$profile_uid' =>  $owner_uid,
							'$mylink' => $contact['url'],
							'$mytitle' => DI::l10n()->t('This is you'),
							'$myphoto' => $contact['thumb'],
							'$comment' => DI::l10n()->t('Comment'),
							'$submit' => DI::l10n()->t('Submit'),
							'$preview' => DI::l10n()->t('Preview'),
							'$qcomment' => $qcomment,
							'$rand_num' => Crypto::randomDigits(12)
						]);
					}
				}
			}

			$responses = [];
			foreach ($conv_responses as $verb => $activity) {
				if (isset($activity[$link_item['uri']])) {
					$responses[$verb] = $activity[$link_item['uri']];
				}
			}

			if ($cmd === 'view' && ($can_post || Security::canWriteToUserWall($owner_uid))) {
				$like_tpl = Renderer::getMarkupTemplate('like_noshare.tpl');
				$likebuttons = Renderer::replaceMacros($like_tpl, [
					'$id' => $link_item['id'],
					'$like' => DI::l10n()->t('Like'),
					'$like_title' => DI::l10n()->t('I like this (toggle)'),
					'$dislike' => DI::l10n()->t('Dislike'),
					'$wait' => DI::l10n()->t('Please wait'),
					'$dislike_title' => DI::l10n()->t('I don\'t like this (toggle)'),
					'$hide_dislike' => DI::pConfig()->get(local_user(), 'system', 'hide_dislike'),
					'$responses' => $responses,
					'$return_path' => DI::args()->getQueryString(),
				]);
			}

			$paginate = $pager->renderFull($total);
		}

		$photo_tpl = Renderer::getMarkupTemplate('photo_view.tpl');
		$o .= Renderer::replaceMacros($photo_tpl, [
			'$id' => $ph[0]['id'],
			'$album' => [$album_link, $ph[0]['album']],
			'$tools' => $tools,
			'$photo' => $photo,
			'$prevlink' => $prevlink,
			'$nextlink' => $nextlink,
			'$desc' => $ph[0]['desc'],
			'$tags' => $tags,
			'$edit' => $edit,
			'$map' => $map,
			'$map_text' => DI::l10n()->t('Map'),
			'$likebuttons' => $likebuttons,
			'$like' => $like,
			'$dislike' => $dislike,
			'$comments' => $comments,
			'$paginate' => $paginate,
		]);

		DI::page()['htmlhead'] .= "\n" . '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		DI::page()['htmlhead'] .= '<meta name="twitter:title" content="' . $photo["album"] . '" />' . "\n";
		DI::page()['htmlhead'] .= '<meta name="twitter:image" content="' . DI::baseUrl() . "/" . $photo["href"] . '" />' . "\n";
		DI::page()['htmlhead'] .= '<meta name="twitter:image:width" content="' . $photo["width"] . '" />' . "\n";
		DI::page()['htmlhead'] .= '<meta name="twitter:image:height" content="' . $photo["height"] . '" />' . "\n";

		return $o;
	}

	// Default - show recent photos with upload link (if applicable)
	//$o = '';
	$total = 0;
	$r = DBA::toArray(DBA::p("SELECT `resource-id`, max(`scale`) AS `scale` FROM `photo` WHERE `uid` = ? AND `photo-type` = ?
		$sql_extra GROUP BY `resource-id`",
		$user['uid'],
		Photo::DEFAULT,
	));
	if (DBA::isResult($r)) {
		$total = count($r);
	}

	$pager = new Pager(DI::l10n(), DI::args()->getQueryString(), 20);

	$r = DBA::toArray(DBA::p("SELECT `resource-id`, ANY_VALUE(`id`) AS `id`, ANY_VALUE(`filename`) AS `filename`,
		ANY_VALUE(`type`) AS `type`, ANY_VALUE(`album`) AS `album`, max(`scale`) AS `scale`,
		ANY_VALUE(`created`) AS `created` FROM `photo`
		WHERE `uid` = ? AND `photo-type` = ?
		$sql_extra GROUP BY `resource-id` ORDER BY `created` DESC LIMIT ? , ?",
		$user['uid'],
		Photo::DEFAULT,
		$pager->getStart(),
		$pager->getItemsPerPage()
	));

	$photos = [];
	if (DBA::isResult($r)) {
		// "Twist" is only used for the duepunto theme with style "slackr"
		$twist = false;
		foreach ($r as $rr) {
			$twist = !$twist;
			$ext = $phototypes[$rr['type']];

			$alt_e = $rr['filename'];
			$name_e = $rr['album'];

			$photos[] = [
				'id'    => $rr['id'],
				'twist' => ' ' . ($twist ? 'rotleft' : 'rotright') . rand(2,4),
				'link'  => 'photos/' . $user['nickname'] . '/image/' . $rr['resource-id'],
				'title' => DI::l10n()->t('View Photo'),
				'src'   => 'photo/' . $rr['resource-id'] . '-' . ((($rr['scale']) == 6) ? 4 : $rr['scale']) . '.' . $ext,
				'alt'   => $alt_e,
				'album' => [
					'link' => 'photos/' . $user['nickname'] . '/album/' . bin2hex($rr['album']),
					'name' => $name_e,
					'alt'  => DI::l10n()->t('View Album'),
				],

			];
		}
	}

	$tpl = Renderer::getMarkupTemplate('photos_recent.tpl');
	$o .= Renderer::replaceMacros($tpl, [
		'$title' => DI::l10n()->t('Recent Photos'),
		'$can_post' => $can_post,
		'$upload' => [DI::l10n()->t('Upload New Photos'), 'photos/' . $user['nickname'] . '/upload'],
		'$photos' => $photos,
		'$paginate' => $pager->renderFull($total),
	]);

	return $o;
}
