<?php
/**
 * @file include/items.php
 */
use Friendica\App;
use Friendica\ParseUrl;
use Friendica\Content\Feature;
use Friendica\Core\Config;
use Friendica\Core\PConfig;
use Friendica\Core\Worker;
use Friendica\Core\System;
use Friendica\Database\DBM;
use Friendica\Model\Contact;
use Friendica\Model\GContact;
use Friendica\Object\Image;
use Friendica\Protocol\DFRN;
use Friendica\Protocol\OStatus;

require_once 'include/bbcode.php';
require_once 'include/oembed.php';
require_once 'include/crypto.php';
require_once 'include/tags.php';
require_once 'include/files.php';
require_once 'include/text.php';
require_once 'include/threads.php';
require_once 'include/plaintext.php';
require_once 'include/feed.php';
require_once 'mod/share.php';
require_once 'include/enotify.php';
require_once 'include/group.php';

function construct_verb($item) {
	if ($item['verb']) {
		return $item['verb'];
	}
	return ACTIVITY_POST;
}

/* limit_body_size()
 *
 *		The purpose of this function is to apply system message length limits to
 *		imported messages without including any embedded photos in the length
 */
if (! function_exists('limit_body_size')) {
function limit_body_size($body) {

//	logger('limit_body_size: start', LOGGER_DEBUG);

	$maxlen = get_max_import_size();

	// If the length of the body, including the embedded images, is smaller
	// than the maximum, then don't waste time looking for the images
	if ($maxlen && (strlen($body) > $maxlen)) {

		logger('limit_body_size: the total body length exceeds the limit', LOGGER_DEBUG);

		$orig_body = $body;
		$new_body = '';
		$textlen = 0;
		$max_found = false;

		$img_start = strpos($orig_body, '[img');
		$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
		$img_end = ($img_start !== false ? strpos(substr($orig_body, $img_start), '[/img]') : false);
		while (($img_st_close !== false) && ($img_end !== false)) {

			$img_st_close++; // make it point to AFTER the closing bracket
			$img_end += $img_start;
			$img_end += strlen('[/img]');

			if (! strcmp(substr($orig_body, $img_start + $img_st_close, 5), 'data:')) {
				// This is an embedded image

				if (($textlen + $img_start) > $maxlen ) {
					if ($textlen < $maxlen) {
						logger('limit_body_size: the limit happens before an embedded image', LOGGER_DEBUG);
						$new_body = $new_body . substr($orig_body, 0, $maxlen - $textlen);
						$textlen = $maxlen;
					}
				} else {
					$new_body = $new_body . substr($orig_body, 0, $img_start);
					$textlen += $img_start;
				}

				$new_body = $new_body . substr($orig_body, $img_start, $img_end - $img_start);
			} else {

				if (($textlen + $img_end) > $maxlen ) {
					if ($textlen < $maxlen) {
						logger('limit_body_size: the limit happens before the end of a non-embedded image', LOGGER_DEBUG);
						$new_body = $new_body . substr($orig_body, 0, $maxlen - $textlen);
						$textlen = $maxlen;
					}
				} else {
					$new_body = $new_body . substr($orig_body, 0, $img_end);
					$textlen += $img_end;
				}
			}
			$orig_body = substr($orig_body, $img_end);

			if ($orig_body === false) {
				// in case the body ends on a closing image tag
				$orig_body = '';
			}

			$img_start = strpos($orig_body, '[img');
			$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
			$img_end = ($img_start !== false ? strpos(substr($orig_body, $img_start), '[/img]') : false);
		}

		if (($textlen + strlen($orig_body)) > $maxlen) {
			if ($textlen < $maxlen) {
				logger('limit_body_size: the limit happens after the end of the last image', LOGGER_DEBUG);
				$new_body = $new_body . substr($orig_body, 0, $maxlen - $textlen);
				$textlen = $maxlen;
			}
		} else {
			logger('limit_body_size: the text size with embedded images extracted did not violate the limit', LOGGER_DEBUG);
			$new_body = $new_body . $orig_body;
			$textlen += strlen($orig_body);
		}

		return $new_body;
	} else {
		return $body;
	}
}}

function title_is_body($title, $body) {

	$title = strip_tags($title);
	$title = trim($title);
	$title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
	$title = str_replace(array("\n", "\r", "\t", " "), array("", "", "", ""), $title);

	$body = strip_tags($body);
	$body = trim($body);
	$body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
	$body = str_replace(array("\n", "\r", "\t", " "), array("", "", "", ""), $body);

	if (strlen($title) < strlen($body)) {
		$body = substr($body, 0, strlen($title));
	}

	if (($title != $body) && (substr($title, -3) == "...")) {
		$pos = strrpos($title, "...");
		if ($pos > 0) {
			$title = substr($title, 0, $pos);
			$body = substr($body, 0, $pos);
		}
	}

	return ($title == $body);
}

function add_page_info_data($data) {
	call_hooks('page_info_data', $data);

	// It maybe is a rich content, but if it does have everything that a link has,
	// then treat it that way
	if (($data["type"] == "rich") && is_string($data["title"]) &&
		is_string($data["text"]) && (sizeof($data["images"]) > 0)) {
		$data["type"] = "link";
	}

	if ((($data["type"] != "link") && ($data["type"] != "video") && ($data["type"] != "photo")) || ($data["title"] == $data["url"])) {
		return "";
	}

	if ($no_photos && ($data["type"] == "photo")) {
		return "";
	}

	if (sizeof($data["images"]) > 0) {
		$preview = $data["images"][0];
	} else {
		$preview = "";
	}

	// Escape some bad characters
	$data["url"] = str_replace(array("[", "]"), array("&#91;", "&#93;"), htmlentities($data["url"], ENT_QUOTES, 'UTF-8', false));
	$data["title"] = str_replace(array("[", "]"), array("&#91;", "&#93;"), htmlentities($data["title"], ENT_QUOTES, 'UTF-8', false));

	$text = "[attachment type='".$data["type"]."'";

	if ($data["text"] == "") {
		$data["text"] = $data["title"];
	}

	if ($data["text"] == "") {
		$data["text"] = $data["url"];
	}

	if ($data["url"] != "") {
		$text .= " url='".$data["url"]."'";
	}

	if ($data["title"] != "") {
		$text .= " title='".$data["title"]."'";
	}

	if (sizeof($data["images"]) > 0) {
		$preview = str_replace(array("[", "]"), array("&#91;", "&#93;"), htmlentities($data["images"][0]["src"], ENT_QUOTES, 'UTF-8', false));
		// if the preview picture is larger than 500 pixels then show it in a larger mode
		// But only, if the picture isn't higher than large (To prevent huge posts)
		if (!Config::get('system', 'always_show_preview') && ($data["images"][0]["width"] >= 500)
			&& ($data["images"][0]["width"] >= $data["images"][0]["height"])) {
			$text .= " image='".$preview."'";
		} else {
			$text .= " preview='".$preview."'";
		}
	}

	$text .= "]".$data["text"]."[/attachment]";

	$hashtags = "";
	if (isset($data["keywords"]) && count($data["keywords"])) {
		$hashtags = "\n";
		foreach ($data["keywords"] AS $keyword) {
			/// @todo make a positive list of allowed characters
			$hashtag = str_replace(array(" ", "+", "/", ".", "#", "'", "’", "`", "(", ")", "„", "“"),
						array("", "", "", "", "", "", "", "", "", "", "", ""), $keyword);
			$hashtags .= "#[url=" . System::baseUrl() . "/search?tag=" . rawurlencode($hashtag) . "]" . $hashtag . "[/url] ";
		}
	}

	return "\n".$text.$hashtags;
}

function query_page_info($url, $no_photos = false, $photo = "", $keywords = false, $keyword_blacklist = "") {

	$data = ParseUrl::getSiteinfoCached($url, true);

	if ($photo != "") {
		$data["images"][0]["src"] = $photo;
	}

	logger('fetch page info for ' . $url . ' ' . print_r($data, true), LOGGER_DEBUG);

	if (!$keywords && isset($data["keywords"])) {
		unset($data["keywords"]);
	}

	if (($keyword_blacklist != "") && isset($data["keywords"])) {
		$list = explode(", ", $keyword_blacklist);
		foreach ($list AS $keyword) {
			$keyword = trim($keyword);
			$index = array_search($keyword, $data["keywords"]);
			if ($index !== false) {
				unset($data["keywords"][$index]);
			}
		}
	}

	return $data;
}

function add_page_keywords($url, $no_photos = false, $photo = "", $keywords = false, $keyword_blacklist = "") {
	$data = query_page_info($url, $no_photos, $photo, $keywords, $keyword_blacklist);

	$tags = "";
	if (isset($data["keywords"]) && count($data["keywords"])) {
		foreach ($data["keywords"] AS $keyword) {
			$hashtag = str_replace(array(" ", "+", "/", ".", "#", "'"),
				array("", "", "", "", "", ""), $keyword);

			if ($tags != "") {
				$tags .= ", ";
			}

			$tags .= "#[url=" . System::baseUrl() . "/search?tag=" . rawurlencode($hashtag) . "]" . $hashtag . "[/url]";
		}
	}

	return $tags;
}

function add_page_info($url, $no_photos = false, $photo = "", $keywords = false, $keyword_blacklist = "") {
	$data = query_page_info($url, $no_photos, $photo, $keywords, $keyword_blacklist);

	$text = add_page_info_data($data);

	return $text;
}

function add_page_info_to_body($body, $texturl = false, $no_photos = false) {

	logger('add_page_info_to_body: fetch page info for body ' . $body, LOGGER_DEBUG);

	$URLSearchString = "^\[\]";

	// Fix for Mastodon where the mentions are in a different format
	$body = preg_replace("/\[url\=([$URLSearchString]*)\]([#!@])(.*?)\[\/url\]/ism",
		'$2[url=$1]$3[/url]', $body);

	// Adding these spaces is a quick hack due to my problems with regular expressions :)
	preg_match("/[^!#@]\[url\]([$URLSearchString]*)\[\/url\]/ism", " " . $body, $matches);

	if (!$matches) {
		preg_match("/[^!#@]\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", " " . $body, $matches);
	}

	// Convert urls without bbcode elements
	if (!$matches && $texturl) {
		preg_match("/([^\]\='".'"'."]|^)(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)/ism", " ".$body, $matches);

		// Yeah, a hack. I really hate regular expressions :)
		if ($matches) {
			$matches[1] = $matches[2];
		}
	}

	if ($matches) {
		$footer = add_page_info($matches[1], $no_photos);
	}

	// Remove the link from the body if the link is attached at the end of the post
	if (isset($footer) && (trim($footer) != "") && (strpos($footer, $matches[1]))) {
		$removedlink = trim(str_replace($matches[1], "", $body));
		if (($removedlink == "") || strstr($body, $removedlink)) {
			$body = $removedlink;
		}

		$url = str_replace(array('/', '.'), array('\/', '\.'), $matches[1]);
		$removedlink = preg_replace("/\[url\=" . $url . "\](.*?)\[\/url\]/ism", '', $body);
		if (($removedlink == "") || strstr($body, $removedlink)) {
			$body = $removedlink;
		}
	}

	// Add the page information to the bottom
	if (isset($footer) && (trim($footer) != "")) {
		$body .= $footer;
	}

	return $body;
}

/**
 * Adds a "lang" specification in a "postopts" element of given $arr,
 * if possible and not already present.
 * Expects "body" element to exist in $arr.
 *
 * @todo Add a parameter to request forcing override
 */
function item_add_language_opt(&$arr) {

	if (version_compare(PHP_VERSION, '5.3.0', '<')) {
		 // LanguageDetect.php not available ?
		return;
	}

	if (x($arr, 'postopts') ) {
		if (strstr($arr['postopts'], 'lang=') ) {
			// do not override
			/// @TODO Add parameter to request overriding
			return;
		}
		$postopts = $arr['postopts'];
	} else {
		$postopts = "";
	}

	$naked_body = preg_replace('/\[(.+?)\]/','', $arr['body']);
	$l = new Text_LanguageDetect();
	$lng = $l->detect($naked_body, 3);

	if (sizeof($lng) > 0) {
		if ($postopts != "") $postopts .= '&'; // arbitrary separator, to be reviewed
		$postopts .= 'lang=';
		$sep = "";
		foreach ($lng as $language => $score) {
			$postopts .= $sep . $language . ";" . $score;
			$sep = ':';
		}
		$arr['postopts'] = $postopts;
	}
}

/**
 * @brief Creates an unique guid out of a given uri
 *
 * @param string $uri uri of an item entry
 * @param string $host (Optional) hostname for the GUID prefix
 * @return string unique guid
 */
function uri_to_guid($uri, $host = "") {

	// Our regular guid routine is using this kind of prefix as well
	// We have to avoid that different routines could accidentally create the same value
	$parsed = parse_url($uri);

	// When the hostname isn't given, we take it from the uri
	if ($host == "") {
		// Is it in the format data@host.tld?
		if ((count($parsed) == 1) && strstr($uri, '@')) {
			$mailparts = explode('@', $uri);
			$host = array_pop($mailparts);
		} else {
			$host = $parsed["host"];
		}
	}

	// We use a hash of the hostname as prefix for the guid
	$guid_prefix = hash("crc32", $host);

	// Remove the scheme to make sure that "https" and "http" doesn't make a difference
	unset($parsed["scheme"]);

	// Glue it together to be able to make a hash from it
	$host_id = implode("/", $parsed);

	// We could use any hash algorithm since it isn't a security issue
	$host_hash = hash("ripemd128", $host_id);

	return $guid_prefix.$host_hash;
}

/**
 * @brief Store the conversation data
 *
 * @param array $arr Item array with conversation data
 * @return array Item array with removed conversation data
 */
function store_conversation($arr) {
	if (in_array($arr['network'], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS)) && !empty($arr['uri'])) {
		$conversation = array('item-uri' => $arr['uri'], 'received' => DBM::date());

		if (isset($arr['parent-uri']) && ($arr['parent-uri'] != $arr['uri'])) {
			$conversation['reply-to-uri'] = $arr['parent-uri'];
		}
		if (isset($arr['thr-parent']) && ($arr['thr-parent'] != $arr['uri'])) {
			$conversation['reply-to-uri'] = $arr['thr-parent'];
		}

		if (isset($arr['conversation-uri'])) {
			$conversation['conversation-uri'] = $arr['conversation-uri'];
		}

		if (isset($arr['conversation-href'])) {
			$conversation['conversation-href'] = $arr['conversation-href'];
		}

		if (isset($arr['protocol'])) {
			$conversation['protocol'] = $arr['protocol'];
		}

		if (isset($arr['source'])) {
			$conversation['source'] = $arr['source'];
		}

		$old_conv = dba::fetch_first("SELECT `item-uri`, `reply-to-uri`, `conversation-uri`, `conversation-href`, `protocol`, `source`
				FROM `conversation` WHERE `item-uri` = ?", $conversation['item-uri']);
		if (DBM::is_result($old_conv)) {
			// Don't update when only the source has changed.
			// Only do this when there had been no source before.
			if ($old_conv['source'] != '') {
				unset($old_conv['source']);
			}
			// Update structure data all the time but the source only when its from a better protocol.
			if (($old_conv['protocol'] < $conversation['protocol']) && ($old_conv['protocol'] != 0)) {
				unset($conversation['protocol']);
				unset($conversation['source']);
			}
			if (!dba::update('conversation', $conversation, array('item-uri' => $conversation['item-uri']), $old_conv)) {
				logger('Conversation: update for '.$conversation['item-uri'].' from '.$conv['protocol'].' to '.$conversation['protocol'].' failed', LOGGER_DEBUG);
			}
		} else {
			if (!dba::insert('conversation', $conversation, true)) {
				logger('Conversation: insert for '.$conversation['item-uri'].' (protocol '.$conversation['protocol'].') failed', LOGGER_DEBUG);
			}
		}
	}

	unset($arr['conversation-uri']);
	unset($arr['conversation-href']);
	unset($arr['protocol']);
	unset($arr['source']);

	return $arr;
}

/// @TODO add type-hint array
function item_store($arr, $force_parent = false, $notify = false, $dontcache = false) {

	$a = get_app();

	// If it is a posting where users should get notifications, then define it as wall posting
	if ($notify) {
		$arr['wall'] = 1;
		$arr['type'] = 'wall';
		$arr['origin'] = 1;
		$arr['last-child'] = 1;
		$arr['network'] = NETWORK_DFRN;
		$arr['protocol'] = PROTOCOL_DFRN;

		// We have to avoid duplicates. So we create the GUID in form of a hash of the plink or uri.
		// In difference to the call to "uri_to_guid" several lines below we add the hash of our own host.
		// This is done because our host is the original creator of the post.
		if (!isset($arr['guid'])) {
			if (isset($arr['plink'])) {
				$arr['guid'] = uri_to_guid($arr['plink'], $a->get_hostname());
			} elseif (isset($arr['uri'])) {
				$arr['guid'] = uri_to_guid($arr['uri'], $a->get_hostname());
			}
		}
	}

	if ($notify) {
		$guid_prefix = "";
	} elseif ((trim($arr['guid']) == "") && (trim($arr['plink']) != "")) {
		$arr['guid'] = uri_to_guid($arr['plink']);
	} elseif ((trim($arr['guid']) == "") && (trim($arr['uri']) != "")) {
		$arr['guid'] = uri_to_guid($arr['uri']);
	} else {
		$parsed = parse_url($arr["author-link"]);
		$guid_prefix = hash("crc32", $parsed["host"]);
	}

	$arr['guid']          = ((x($arr, 'guid'))          ? notags(trim($arr['guid']))          : get_guid(32, $guid_prefix));
	$arr['uri']           = ((x($arr, 'uri'))           ? notags(trim($arr['uri']))           : item_new_uri($a->get_hostname(), $uid, $arr['guid']));

	// Store conversation data
	$arr = store_conversation($arr);

	/*
	 * If a Diaspora signature structure was passed in, pull it out of the
	 * item array and set it aside for later storage.
	 */

	$dsprsig = null;
	if (x($arr, 'dsprsig')) {
		$encoded_signature = $arr['dsprsig'];
		$dsprsig = json_decode(base64_decode($arr['dsprsig']));
		unset($arr['dsprsig']);
	}

	// Converting the plink
	/// @todo Check if this is really still needed
	if ($arr['network'] == NETWORK_OSTATUS) {
		if (isset($arr['plink'])) {
			$arr['plink'] = OStatus::convertHref($arr['plink']);
		} elseif (isset($arr['uri'])) {
			$arr['plink'] = OStatus::convertHref($arr['uri']);
		}
	}

	if (x($arr, 'gravity')) {
		$arr['gravity'] = intval($arr['gravity']);
	} elseif ($arr['parent-uri'] === $arr['uri']) {
		$arr['gravity'] = 0;
	} elseif (activity_match($arr['verb'],ACTIVITY_POST)) {
		$arr['gravity'] = 6;
	} else {
		$arr['gravity'] = 6;   // extensible catchall
	}

	if (! x($arr, 'type')) {
		$arr['type']      = 'remote';
	}

	$uid = intval($arr['uid']);

	// check for create date and expire time
	$expire_interval = Config::get('system', 'dbclean-expire-days', 0);

	$r = dba::select('user', array('expire'), array('uid' => $uid), array("limit" => 1));
	if (DBM::is_result($r) && ($r['expire'] > 0) && (($r['expire'] < $expire_interval) || ($expire_interval == 0))) {
		$expire_interval = $r['expire'];
	}

	if (($expire_interval > 0) && !empty($arr['created'])) {
		$expire_date = time() - ($expire_interval * 86400);
		$created_date = strtotime($arr['created']);
		if ($created_date < $expire_date) {
			logger('item-store: item created ('.date('c', $created_date).') before expiration time ('.date('c', $expire_date).'). ignored. ' . print_r($arr,true), LOGGER_DEBUG);
			return 0;
		}
	}

	/*
	 * Do we already have this item?
	 * We have to check several networks since Friendica posts could be repeated
	 * via OStatus (maybe Diasporsa as well)
	 */
	if (in_array(trim($arr['network']), array(NETWORK_DIASPORA, NETWORK_DFRN, NETWORK_OSTATUS, ""))) {
		$r = q("SELECT `id`, `network` FROM `item` WHERE `uri` = '%s' AND `uid` = %d AND `network` IN ('%s', '%s', '%s')  LIMIT 1",
				dbesc(trim($arr['uri'])),
				intval($uid),
				dbesc(NETWORK_DIASPORA),
				dbesc(NETWORK_DFRN),
				dbesc(NETWORK_OSTATUS)
			);
		if (DBM::is_result($r)) {
			// We only log the entries with a different user id than 0. Otherwise we would have too many false positives
			if ($uid != 0) {
				logger("Item with uri ".$arr['uri']." already existed for user ".$uid." with id ".$r[0]["id"]." target network ".$r[0]["network"]." - new network: ".$arr['network']);
			}

			return $r[0]["id"];
		}
	}

	/// @TODO old-lost code?
	// Shouldn't happen but we want to make absolutely sure it doesn't leak from a plugin.
	// Deactivated, since the bbcode parser can handle with it - and it destroys posts with some smileys that contain "<"
	//if ((strpos($arr['body'],'<') !== false) || (strpos($arr['body'],'>') !== false))
	//	$arr['body'] = strip_tags($arr['body']);

	item_add_language_opt($arr);

	$arr['wall']          = ((x($arr, 'wall'))          ? intval($arr['wall'])                : 0);
	$arr['extid']         = ((x($arr, 'extid'))         ? notags(trim($arr['extid']))         : '');
	$arr['author-name']   = ((x($arr, 'author-name'))   ? trim($arr['author-name'])   : '');
	$arr['author-link']   = ((x($arr, 'author-link'))   ? notags(trim($arr['author-link']))   : '');
	$arr['author-avatar'] = ((x($arr, 'author-avatar')) ? notags(trim($arr['author-avatar'])) : '');
	$arr['owner-name']    = ((x($arr, 'owner-name'))    ? trim($arr['owner-name'])    : '');
	$arr['owner-link']    = ((x($arr, 'owner-link'))    ? notags(trim($arr['owner-link']))    : '');
	$arr['owner-avatar']  = ((x($arr, 'owner-avatar'))  ? notags(trim($arr['owner-avatar']))  : '');
	$arr['received']      = ((x($arr, 'received') !== false) ? datetime_convert('UTC','UTC', $arr['received']) : datetime_convert());
	$arr['created']       = ((x($arr, 'created') !== false) ? datetime_convert('UTC','UTC', $arr['created']) : $arr['received']);
	$arr['edited']        = ((x($arr, 'edited') !== false) ? datetime_convert('UTC','UTC', $arr['edited']) : $arr['created']);
	$arr['changed']       = ((x($arr, 'changed') !== false) ? datetime_convert('UTC','UTC', $arr['changed']) : $arr['created']);
	$arr['commented']     = ((x($arr, 'commented') !== false) ? datetime_convert('UTC','UTC', $arr['commented']) : $arr['created']);
	$arr['title']         = ((x($arr, 'title'))         ? trim($arr['title'])         : '');
	$arr['location']      = ((x($arr, 'location'))      ? trim($arr['location'])      : '');
	$arr['coord']         = ((x($arr, 'coord'))         ? notags(trim($arr['coord']))         : '');
	$arr['last-child']    = ((x($arr, 'last-child'))    ? intval($arr['last-child'])          : 0 );
	$arr['visible']       = ((x($arr, 'visible') !== false) ? intval($arr['visible'])         : 1 );
	$arr['deleted']       = 0;
	$arr['parent-uri']    = ((x($arr, 'parent-uri'))    ? notags(trim($arr['parent-uri']))    : $arr['uri']);
	$arr['verb']          = ((x($arr, 'verb'))          ? notags(trim($arr['verb']))          : '');
	$arr['object-type']   = ((x($arr, 'object-type'))   ? notags(trim($arr['object-type']))   : '');
	$arr['object']        = ((x($arr, 'object'))        ? trim($arr['object'])                : '');
	$arr['target-type']   = ((x($arr, 'target-type'))   ? notags(trim($arr['target-type']))   : '');
	$arr['target']        = ((x($arr, 'target'))        ? trim($arr['target'])                : '');
	$arr['plink']         = ((x($arr, 'plink'))         ? notags(trim($arr['plink']))         : '');
	$arr['allow_cid']     = ((x($arr, 'allow_cid'))     ? trim($arr['allow_cid'])             : '');
	$arr['allow_gid']     = ((x($arr, 'allow_gid'))     ? trim($arr['allow_gid'])             : '');
	$arr['deny_cid']      = ((x($arr, 'deny_cid'))      ? trim($arr['deny_cid'])              : '');
	$arr['deny_gid']      = ((x($arr, 'deny_gid'))      ? trim($arr['deny_gid'])              : '');
	$arr['private']       = ((x($arr, 'private'))       ? intval($arr['private'])             : 0 );
	$arr['bookmark']      = ((x($arr, 'bookmark'))      ? intval($arr['bookmark'])            : 0 );
	$arr['body']          = ((x($arr, 'body'))          ? trim($arr['body'])                  : '');
	$arr['tag']           = ((x($arr, 'tag'))           ? notags(trim($arr['tag']))           : '');
	$arr['attach']        = ((x($arr, 'attach'))        ? notags(trim($arr['attach']))        : '');
	$arr['app']           = ((x($arr, 'app'))           ? notags(trim($arr['app']))           : '');
	$arr['origin']        = ((x($arr, 'origin'))        ? intval($arr['origin'])              : 0 );
	$arr['network']       = ((x($arr, 'network'))       ? trim($arr['network'])               : '');
	$arr['postopts']      = ((x($arr, 'postopts'))      ? trim($arr['postopts'])              : '');
	$arr['resource-id']   = ((x($arr, 'resource-id'))   ? trim($arr['resource-id'])           : '');
	$arr['event-id']      = ((x($arr, 'event-id'))      ? intval($arr['event-id'])            : 0 );
	$arr['inform']        = ((x($arr, 'inform'))        ? trim($arr['inform'])                : '');
	$arr['file']          = ((x($arr, 'file'))          ? trim($arr['file'])                  : '');

	// When there is no content then we don't post it
	if ($arr['body'].$arr['title'] == '') {
		return 0;
	}

	// Items cannot be stored before they happen ...
	if ($arr['created'] > datetime_convert()) {
		$arr['created'] = datetime_convert();
	}

	// We haven't invented time travel by now.
	if ($arr['edited'] > datetime_convert()) {
		$arr['edited'] = datetime_convert();
	}

	if (($arr['author-link'] == "") && ($arr['owner-link'] == "")) {
		logger("Both author-link and owner-link are empty. Called by: " . System::callstack(), LOGGER_DEBUG);
	}

	if ($arr['plink'] == "") {
		$arr['plink'] = System::baseUrl() . '/display/' . urlencode($arr['guid']);
	}

	if ($arr['network'] == "") {
		$r = q("SELECT `network` FROM `contact` WHERE `network` IN ('%s', '%s', '%s') AND `nurl` = '%s' AND `uid` = %d LIMIT 1",
			dbesc(NETWORK_DFRN), dbesc(NETWORK_DIASPORA), dbesc(NETWORK_OSTATUS),
			dbesc(normalise_link($arr['author-link'])),
			intval($arr['uid'])
		);

		if (!DBM::is_result($r))
			$r = q("SELECT `network` FROM `gcontact` WHERE `network` IN ('%s', '%s', '%s') AND `nurl` = '%s' LIMIT 1",
				dbesc(NETWORK_DFRN), dbesc(NETWORK_DIASPORA), dbesc(NETWORK_OSTATUS),
				dbesc(normalise_link($arr['author-link']))
			);

		if (!DBM::is_result($r)) {
			$r = q("SELECT `network` FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
				intval($arr['contact-id']),
				intval($arr['uid'])
			);
		}

		if (DBM::is_result($r)) {
			$arr['network'] = $r[0]["network"];
		}

		// Fallback to friendica (why is it empty in some cases?)
		if ($arr['network'] == "") {
			$arr['network'] = NETWORK_DFRN;
		}

		logger("item_store: Set network to " . $arr["network"] . " for " . $arr["uri"], LOGGER_DEBUG);
	}

	// The contact-id should be set before "item_store" was called - but there seems to be some issues
	if ($arr["contact-id"] == 0) {
		/*
		 * First we are looking for a suitable contact that matches with the author of the post
		 * This is done only for comments (See below explanation at "gcontact-id")
		 */
		if ($arr['parent-uri'] != $arr['uri']) {
			$arr["contact-id"] = Contact::getIdForURL($arr['author-link'], $uid);
		}

		// If not present then maybe the owner was found
		if ($arr["contact-id"] == 0) {
			$arr["contact-id"] = Contact::getIdForURL($arr['owner-link'], $uid);
		}

		// Still missing? Then use the "self" contact of the current user
		if ($arr["contact-id"] == 0) {
			$r = q("SELECT `id` FROM `contact` WHERE `self` AND `uid` = %d", intval($uid));

			if (DBM::is_result($r)) {
				$arr["contact-id"] = $r[0]["id"];
			}
		}

		logger("Contact-id was missing for post ".$arr["guid"]." from user id ".$uid." - now set to ".$arr["contact-id"], LOGGER_DEBUG);
	}

	if ($arr["gcontact-id"] == 0) {
		/*
		 * The gcontact should mostly behave like the contact. But is is supposed to be global for the system.
		 * This means that wall posts, repeated posts, etc. should have the gcontact id of the owner.
		 * On comments the author is the better choice.
		 */
		if ($arr['parent-uri'] === $arr['uri']) {
			$arr["gcontact-id"] = GContact::getId(array("url" => $arr['owner-link'], "network" => $arr['network'],
								 "photo" => $arr['owner-avatar'], "name" => $arr['owner-name']));
		} else {
			$arr["gcontact-id"] = GContact::getId(array("url" => $arr['author-link'], "network" => $arr['network'],
								 "photo" => $arr['author-avatar'], "name" => $arr['author-name']));
		}
	}

	if ($arr["author-id"] == 0) {
		$arr["author-id"] = Contact::getIdForURL($arr["author-link"], 0);
	}

	if (Contact::isBlocked($arr["author-id"])) {
		logger('Contact '.$arr["author-id"].' is blocked, item '.$arr["uri"].' will not be stored');
		return 0;
	}

	if ($arr["owner-id"] == 0) {
		$arr["owner-id"] = Contact::getIdForURL($arr["owner-link"], 0);
	}

	if (Contact::isBlocked($arr["owner-id"])) {
		logger('Contact '.$arr["owner-id"].' is blocked, item '.$arr["uri"].' will not be stored');
		return 0;
	}

	if ($arr['guid'] != "") {
		// Checking if there is already an item with the same guid
		logger('checking for an item for user '.$arr['uid'].' on network '.$arr['network'].' with the guid '.$arr['guid'], LOGGER_DEBUG);
		$r = q("SELECT `guid` FROM `item` WHERE `guid` = '%s' AND `network` = '%s' AND `uid` = '%d' LIMIT 1",
			dbesc($arr['guid']), dbesc($arr['network']), intval($arr['uid']));

		if (DBM::is_result($r)) {
			logger('found item with guid '.$arr['guid'].' for user '.$arr['uid'].' on network '.$arr['network'], LOGGER_DEBUG);
			return 0;
		}
	}

	// Check for hashtags in the body and repair or add hashtag links
	item_body_set_hashtags($arr);

	$arr['thr-parent'] = $arr['parent-uri'];

	if ($arr['parent-uri'] === $arr['uri']) {
		$parent_id = 0;
		$parent_deleted = 0;
		$allow_cid = $arr['allow_cid'];
		$allow_gid = $arr['allow_gid'];
		$deny_cid  = $arr['deny_cid'];
		$deny_gid  = $arr['deny_gid'];
		$notify_type = 'wall-new';
	} else {

		// find the parent and snarf the item id and ACLs
		// and anything else we need to inherit

		$r = q("SELECT * FROM `item` WHERE `uri` = '%s' AND `uid` = %d ORDER BY `id` ASC LIMIT 1",
			dbesc($arr['parent-uri']),
			intval($arr['uid'])
		);

		if (DBM::is_result($r)) {

			// is the new message multi-level threaded?
			// even though we don't support it now, preserve the info
			// and re-attach to the conversation parent.

			if ($r[0]['uri'] != $r[0]['parent-uri']) {
				$arr['parent-uri'] = $r[0]['parent-uri'];
				$z = q("SELECT * FROM `item` WHERE `uri` = '%s' AND `parent-uri` = '%s' AND `uid` = %d
					ORDER BY `id` ASC LIMIT 1",
					dbesc($r[0]['parent-uri']),
					dbesc($r[0]['parent-uri']),
					intval($arr['uid'])
				);

				if (DBM::is_result($z)) {
					$r = $z;
				}
			}

			$parent_id      = $r[0]['id'];
			$parent_deleted = $r[0]['deleted'];
			$allow_cid      = $r[0]['allow_cid'];
			$allow_gid      = $r[0]['allow_gid'];
			$deny_cid       = $r[0]['deny_cid'];
			$deny_gid       = $r[0]['deny_gid'];
			$arr['wall']    = $r[0]['wall'];
			$notify_type    = 'comment-new';

			/*
			 * If the parent is private, force privacy for the entire conversation
			 * This differs from the above settings as it subtly allows comments from
			 * email correspondents to be private even if the overall thread is not.
			 */
			if ($r[0]['private']) {
				$arr['private'] = $r[0]['private'];
			}

			/*
			 * Edge case. We host a public forum that was originally posted to privately.
			 * The original author commented, but as this is a comment, the permissions
			 * weren't fixed up so it will still show the comment as private unless we fix it here.
			 */
			if ((intval($r[0]['forum_mode']) == 1) && $r[0]['private']) {
				$arr['private'] = 0;
			}

			// If its a post from myself then tag the thread as "mention"
			logger("item_store: Checking if parent ".$parent_id." has to be tagged as mention for user ".$arr['uid'], LOGGER_DEBUG);
			$u = q("SELECT `nickname` FROM `user` WHERE `uid` = %d", intval($arr['uid']));
			if (DBM::is_result($u)) {
				$a = get_app();
				$self = normalise_link(System::baseUrl() . '/profile/' . $u[0]['nickname']);
				logger("item_store: 'myself' is ".$self." for parent ".$parent_id." checking against ".$arr['author-link']." and ".$arr['owner-link'], LOGGER_DEBUG);
				if ((normalise_link($arr['author-link']) == $self) || (normalise_link($arr['owner-link']) == $self)) {
					dba::update('thread', array('mention' => true), array('iid' => $parent_id));
					logger("item_store: tagged thread ".$parent_id." as mention for user ".$self, LOGGER_DEBUG);
				}
			}
		} else {
			/*
			 * Allow one to see reply tweets from status.net even when
			 * we don't have or can't see the original post.
			 */
			if ($force_parent) {
				logger('item_store: $force_parent=true, reply converted to top-level post.');
				$parent_id = 0;
				$arr['parent-uri'] = $arr['uri'];
				$arr['gravity'] = 0;
			} else {
				logger('item_store: item parent '.$arr['parent-uri'].' for '.$arr['uid'].' was not found - ignoring item');
				return 0;
			}

			$parent_deleted = 0;
		}
	}

	$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND `network` IN ('%s', '%s') AND `uid` = %d LIMIT 1",
		dbesc($arr['uri']),
		dbesc($arr['network']),
		dbesc(NETWORK_DFRN),
		intval($arr['uid'])
	);
	if (DBM::is_result($r)) {
		logger('duplicated item with the same uri found. '.print_r($arr,true));
		return 0;
	}

	// On Friendica and Diaspora the GUID is unique
	if (in_array($arr['network'], array(NETWORK_DFRN, NETWORK_DIASPORA))) {
		$r = q("SELECT `id` FROM `item` WHERE `guid` = '%s' AND `uid` = %d LIMIT 1",
			dbesc($arr['guid']),
			intval($arr['uid'])
		);
		if (DBM::is_result($r)) {
			logger('duplicated item with the same guid found. '.print_r($arr,true));
			return 0;
		}
	} else {
		// Check for an existing post with the same content. There seems to be a problem with OStatus.
		$r = q("SELECT `id` FROM `item` WHERE `body` = '%s' AND `network` = '%s' AND `created` = '%s' AND `contact-id` = %d AND `uid` = %d LIMIT 1",
			dbesc($arr['body']),
			dbesc($arr['network']),
			dbesc($arr['created']),
			intval($arr['contact-id']),
			intval($arr['uid'])
		);
		if (DBM::is_result($r)) {
			logger('duplicated item with the same body found. '.print_r($arr,true));
			return 0;
		}
	}

	// Is this item available in the global items (with uid=0)?
	if ($arr["uid"] == 0) {
		$arr["global"] = true;

		// Set the global flag on all items if this was a global item entry
		dba::update('item', array('global' => true), array('uri' => $arr["uri"]));
	} else {
		$isglobal = q("SELECT `global` FROM `item` WHERE `uid` = 0 AND `uri` = '%s'", dbesc($arr["uri"]));

		$arr["global"] = (DBM::is_result($isglobal) && count($isglobal) > 0);
	}

	// ACL settings
	if (strlen($allow_cid) || strlen($allow_gid) || strlen($deny_cid) || strlen($deny_gid)) {
		$private = 1;
	} else {
		$private = $arr['private'];
	}

	$arr["allow_cid"] = $allow_cid;
	$arr["allow_gid"] = $allow_gid;
	$arr["deny_cid"] = $deny_cid;
	$arr["deny_gid"] = $deny_gid;
	$arr["private"] = $private;
	$arr["deleted"] = $parent_deleted;

	// Fill the cache field
	put_item_in_cache($arr);

	if ($notify) {
		call_hooks('post_local', $arr);
	} else {
		call_hooks('post_remote', $arr);
	}

	// This array field is used to trigger some automatic reactions
	// It is mainly used in the "post_local" hook.
	unset($arr['api_source']);

	if (x($arr, 'cancel')) {
		logger('item_store: post cancelled by plugin.');
		return 0;
	}

	/*
	 * Check for already added items.
	 * There is a timing issue here that sometimes creates double postings.
	 * An unique index would help - but the limitations of MySQL (maximum size of index values) prevent this.
	 */
	if ($arr["uid"] == 0) {
		$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND `uid` = 0 LIMIT 1", dbesc(trim($arr['uri'])));
		if (DBM::is_result($r)) {
			logger('Global item already stored. URI: '.$arr['uri'].' on network '.$arr['network'], LOGGER_DEBUG);
			return 0;
		}
	}

	logger('item_store: ' . print_r($arr,true), LOGGER_DATA);

	dba::transaction();
	$r = dba::insert('item', $arr);

	// When the item was successfully stored we fetch the ID of the item.
	if (DBM::is_result($r)) {
		$current_post = dba::lastInsertId();
	} else {
		// This can happen - for example - if there are locking timeouts.
		dba::rollback();

		// Store the data into a spool file so that we can try again later.

		// At first we restore the Diaspora signature that we removed above.
		if (isset($encoded_signature)) {
			$arr['dsprsig'] = $encoded_signature;
		}

		// Now we store the data in the spool directory
		// We use "microtime" to keep the arrival order and "mt_rand" to avoid duplicates
		$file = 'item-'.round(microtime(true) * 10000).'-'.mt_rand().'.msg';

		$spoolpath = get_spoolpath();
		if ($spoolpath != "") {
			$spool = $spoolpath.'/'.$file;
			file_put_contents($spool, json_encode($arr));
			logger("Item wasn't stored - Item was spooled into file ".$file, LOGGER_DEBUG);
		}
		return 0;
	}

	if ($current_post == 0) {
		// This is one of these error messages that never should occur.
		logger("couldn't find created item - we better quit now.");
		dba::rollback();
		return 0;
	}

	// How much entries have we created?
	// We wouldn't need this query when we could use an unique index - but MySQL has length problems with them.
	$r = q("SELECT COUNT(*) AS `entries` FROM `item` WHERE `uri` = '%s' AND `uid` = %d AND `network` = '%s'",
		dbesc($arr['uri']),
		intval($arr['uid']),
		dbesc($arr['network'])
	);

	if (!DBM::is_result($r)) {
		// This shouldn't happen, since COUNT always works when the database connection is there.
		logger("We couldn't count the stored entries. Very strange ...");
		dba::rollback();
		return 0;
	}

	if ($r[0]["entries"] > 1) {
		// There are duplicates. We delete our just created entry.
		logger('Duplicated post occurred. uri = ' . $arr['uri'] . ' uid = ' . $arr['uid']);

		// Yes, we could do a rollback here - but we are having many users with MyISAM.
		dba::delete('item', array('id' => $current_post));
		dba::commit();
		return 0;
	} elseif ($r[0]["entries"] == 0) {
		// This really should never happen since we quit earlier if there were problems.
		logger("Something is terribly wrong. We haven't found our created entry.");
		dba::rollback();
		return 0;
	}

	logger('item_store: created item '.$current_post);
	item_set_last_item($arr);

	if (!$parent_id || ($arr['parent-uri'] === $arr['uri'])) {
		$parent_id = $current_post;
	}

	// Set parent id
	$r = dba::update('item', array('parent' => $parent_id), array('id' => $current_post));

	$arr['id'] = $current_post;
	$arr['parent'] = $parent_id;

	// update the commented timestamp on the parent
	// Only update "commented" if it is really a comment
	if (($arr['verb'] == ACTIVITY_POST) || !Config::get("system", "like_no_comment")) {
		dba::update('item', array('commented' => datetime_convert(), 'changed' => datetime_convert()), array('id' => $parent_id));
	} else {
		dba::update('item', array('changed' => datetime_convert()), array('id' => $parent_id));
	}

	if ($dsprsig) {

		/*
		 * Friendica servers lower than 3.4.3-2 had double encoded the signature ...
		 * We can check for this condition when we decode and encode the stuff again.
		 */
		if (base64_encode(base64_decode(base64_decode($dsprsig->signature))) == base64_decode($dsprsig->signature)) {
			$dsprsig->signature = base64_decode($dsprsig->signature);
			logger("Repaired double encoded signature from handle ".$dsprsig->signer, LOGGER_DEBUG);
		}

		dba::insert('sign', array('iid' => $current_post, 'signed_text' => $dsprsig->signed_text,
					'signature' => $dsprsig->signature, 'signer' => $dsprsig->signer));
	}

	$deleted = tag_deliver($arr['uid'], $current_post);

	/*
	 * current post can be deleted if is for a community page and no mention are
	 * in it.
	 */
	if (!$deleted && !$dontcache) {

		$r = q('SELECT * FROM `item` WHERE `id` = %d', intval($current_post));
		if ((DBM::is_result($r)) && (count($r) == 1)) {
			if ($notify) {
				call_hooks('post_local_end', $r[0]);
			} else {
				call_hooks('post_remote_end', $r[0]);
			}
		} else {
			logger('item_store: new item not found in DB, id ' . $current_post);
		}
	}

	if ($arr['parent-uri'] === $arr['uri']) {
		add_thread($current_post);
	} else {
		update_thread($parent_id);
	}

	dba::commit();

	/*
	 * Due to deadlock issues with the "term" table we are doing these steps after the commit.
	 * This is not perfect - but a workable solution until we found the reason for the problem.
	 */
	create_tags_from_item($current_post);
	create_files_from_item($current_post);

	/*
	 * If this is now the last-child, force all _other_ children of this parent to *not* be last-child
	 * It is done after the transaction to avoid dead locks.
	 */
	if ($arr['last-child']) {
		$r = q("UPDATE `item` SET `last-child` = 0 WHERE `parent-uri` = '%s' AND `uid` = %d AND `id` != %d",
			dbesc($arr['uri']),
			intval($arr['uid']),
			intval($current_post)
		);
	}

	if ($arr['parent-uri'] === $arr['uri']) {
		add_shadow_thread($current_post);
	} else {
		add_shadow_entry($current_post);
	}

	check_item_notification($current_post, $uid);

	if ($notify) {
		Worker::add(array('priority' => PRIORITY_HIGH, 'dont_fork' => true), "Notifier", $notify_type, $current_post);
	}

	return $current_post;
}

/**
 * @brief Set "success_update" and "last-item" to the date of the last time we heard from this contact
 *
 * This can be used to filter for inactive contacts.
 * Only do this for public postings to avoid privacy problems, since poco data is public.
 * Don't set this value if it isn't from the owner (could be an author that we don't know)
 *
 * @param array $arr Contains the just posted item record
 */
function item_set_last_item($arr) {
	// Unarchive the author
	$contact = dba::select('contact', [], ['id' => $arr["author-link"]], ['limit' => 1]);
	if ($contact['term-date'] > NULL_DATE) {
		 Contact::unmarkForArchival($contact);
	}

	// Unarchive the contact if it is a toplevel posting
	if ($arr["parent-uri"] === $arr["uri"]) {
		$contact = dba::select('contact', [], ['id' => $arr["contact-id"]], ['limit' => 1]);
		if ($contact['term-date'] > NULL_DATE) {
			 Contact::unmarkForArchival($contact);
		}
	}

	$update = (!$arr['private'] && (($arr["author-link"] === $arr["owner-link"]) || ($arr["parent-uri"] === $arr["uri"])));

	// Is it a forum? Then we don't care about the rules from above
	if (!$update && ($arr["network"] == NETWORK_DFRN) && ($arr["parent-uri"] === $arr["uri"])) {
		$isforum = q("SELECT `forum` FROM `contact` WHERE `id` = %d AND `forum`",
				intval($arr['contact-id']));
		if (DBM::is_result($isforum)) {
			$update = true;
		}
	}

	if ($update) {
		dba::update('contact', array('success_update' => $arr['received'], 'last-item' => $arr['received']),
			array('id' => $arr['contact-id']));
	}
	// Now do the same for the system wide contacts with uid=0
	if (!$arr['private']) {
		dba::update('contact', array('success_update' => $arr['received'], 'last-item' => $arr['received']),
			array('id' => $arr['owner-id']));

		if ($arr['owner-id'] != $arr['author-id']) {
			dba::update('contact', array('success_update' => $arr['received'], 'last-item' => $arr['received']),
				array('id' => $arr['author-id']));
		}
	}
}

function item_body_set_hashtags(&$item) {

	$tags = get_tags($item["body"]);

	// No hashtags?
	if (!count($tags)) {
		return false;
	}

	// This sorting is important when there are hashtags that are part of other hashtags
	// Otherwise there could be problems with hashtags like #test and #test2
	rsort($tags);

	$a = get_app();

	$URLSearchString = "^\[\]";

	// All hashtags should point to the home server if "local_tags" is activated
	if (Config::get('system', 'local_tags')) {
		$item["body"] = preg_replace("/#\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism",
				"#[url=".System::baseUrl()."/search?tag=$2]$2[/url]", $item["body"]);

		$item["tag"] = preg_replace("/#\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism",
				"#[url=".System::baseUrl()."/search?tag=$2]$2[/url]", $item["tag"]);
	}

	// mask hashtags inside of url, bookmarks and attachments to avoid urls in urls
	$item["body"] = preg_replace_callback("/\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism",
		function ($match) {
			return ("[url=" . str_replace("#", "&num;", $match[1]) . "]" . str_replace("#", "&num;", $match[2]) . "[/url]");
		}, $item["body"]);

	$item["body"] = preg_replace_callback("/\[bookmark\=([$URLSearchString]*)\](.*?)\[\/bookmark\]/ism",
		function ($match) {
			return ("[bookmark=" . str_replace("#", "&num;", $match[1]) . "]" . str_replace("#", "&num;", $match[2]) . "[/bookmark]");
		}, $item["body"]);

	$item["body"] = preg_replace_callback("/\[attachment (.*)\](.*?)\[\/attachment\]/ism",
		function ($match) {
			return ("[attachment " . str_replace("#", "&num;", $match[1]) . "]" . $match[2] . "[/attachment]");
		}, $item["body"]);

	// Repair recursive urls
	$item["body"] = preg_replace("/&num;\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism",
			"&num;$2", $item["body"]);

	foreach ($tags as $tag) {
		if ((strpos($tag, '#') !== 0) || (strpos($tag, '[url='))) {
			continue;
		}

		$basetag = str_replace('_',' ',substr($tag,1));

		$newtag = '#[url=' . System::baseUrl() . '/search?tag=' . rawurlencode($basetag) . ']' . $basetag . '[/url]';

		$item["body"] = str_replace($tag, $newtag, $item["body"]);

		if (!stristr($item["tag"], "/search?tag=" . $basetag . "]" . $basetag . "[/url]")) {
			if (strlen($item["tag"])) {
				$item["tag"] = ','.$item["tag"];
			}
			$item["tag"] = $newtag.$item["tag"];
		}
	}

	// Convert back the masked hashtags
	$item["body"] = str_replace("&num;", "#", $item["body"]);
}

function get_item_guid($id) {
	$r = q("SELECT `guid` FROM `item` WHERE `id` = %d LIMIT 1", intval($id));
	if (DBM::is_result($r)) {
		return $r[0]["guid"];
	} else {
		/// @TODO This else-block can be elimited again
		return "";
	}
}

function get_item_id($guid, $uid = 0) {

	$nick = "";
	$id = 0;

	if ($uid == 0) {
		$uid == local_user();
	}

	// Does the given user have this item?
	if ($uid) {
		$r = q("SELECT `item`.`id`, `user`.`nickname` FROM `item` INNER JOIN `user` ON `user`.`uid` = `item`.`uid`
			WHERE `item`.`visible` = 1 AND `item`.`deleted` = 0 AND `item`.`moderated` = 0
				AND `item`.`guid` = '%s' AND `item`.`uid` = %d", dbesc($guid), intval($uid));
		if (DBM::is_result($r)) {
			$id = $r[0]["id"];
			$nick = $r[0]["nickname"];
		}
	}

	// Or is it anywhere on the server?
	if ($nick == "") {
		$r = q("SELECT `item`.`id`, `user`.`nickname` FROM `item` INNER JOIN `user` ON `user`.`uid` = `item`.`uid`
			WHERE `item`.`visible` = 1 AND `item`.`deleted` = 0 AND `item`.`moderated` = 0
				AND `item`.`allow_cid` = ''  AND `item`.`allow_gid` = ''
				AND `item`.`deny_cid`  = '' AND `item`.`deny_gid`  = ''
				AND `item`.`private` = 0 AND `item`.`wall` = 1
				AND `item`.`guid` = '%s'", dbesc($guid));
		if (DBM::is_result($r)) {
			$id = $r[0]["id"];
			$nick = $r[0]["nickname"];
		}
	}
	return array("nick" => $nick, "id" => $id);
}

// return - test
function get_item_contact($item, $contacts) {
	if (! count($contacts) || (! is_array($item))) {
		return false;
	}
	foreach ($contacts as $contact) {
		if ($contact['id'] == $item['contact-id']) {
			return $contact;
			break; // NOTREACHED
		}
	}
	return false;
}

/**
 * look for mention tags and setup a second delivery chain for forum/community posts if appropriate
 * @param int $uid
 * @param int $item_id
 * @return bool true if item was deleted, else false
 */
function tag_deliver($uid, $item_id) {

	$a = get_app();

	$mention = false;

	$u = q("SELECT * FROM `user` WHERE `uid` = %d LIMIT 1",
		intval($uid)
	);

	if (! DBM::is_result($u)) {
		return;
	}

	$community_page = (($u[0]['page-flags'] == PAGE_COMMUNITY) ? true : false);
	$prvgroup = (($u[0]['page-flags'] == PAGE_PRVGROUP) ? true : false);


	$i = q("SELECT * FROM `item` WHERE `id` = %d AND `uid` = %d LIMIT 1",
		intval($item_id),
		intval($uid)
	);
	if (! DBM::is_result($i)) {
		return;
	}

	$item = $i[0];

	$link = normalise_link(System::baseUrl() . '/profile/' . $u[0]['nickname']);

	/*
	 * Diaspora uses their own hardwired link URL in @-tags
	 * instead of the one we supply with webfinger
	 */
	$dlink = normalise_link(System::baseUrl() . '/u/' . $u[0]['nickname']);

	$cnt = preg_match_all('/[\@\!]\[url\=(.*?)\](.*?)\[\/url\]/ism', $item['body'], $matches, PREG_SET_ORDER);
	if ($cnt) {
		foreach ($matches as $mtch) {
			if (link_compare($link, $mtch[1]) || link_compare($dlink, $mtch[1])) {
				$mention = true;
				logger('tag_deliver: mention found: ' . $mtch[2]);
			}
		}
	}

	if (! $mention) {
		if (($community_page || $prvgroup) &&
			  (!$item['wall']) && (!$item['origin']) && ($item['id'] == $item['parent'])) {
			// mmh.. no mention.. community page or private group... no wall.. no origin.. top-post (not a comment)
			// delete it!
			logger("tag_deliver: no-mention top-level post to communuty or private group. delete.");
			dba::delete('item', array('id' => $item_id));
			return true;
		}
		return;
	}

	$arr = array('item' => $item, 'user' => $u[0], 'contact' => $r[0]);

	call_hooks('tagged', $arr);

	if ((! $community_page) && (! $prvgroup)) {
		return;
	}

	/*
	 * tgroup delivery - setup a second delivery chain
	 * prevent delivery looping - only proceed
	 * if the message originated elsewhere and is a top-level post
	 */
	if (($item['wall']) || ($item['origin']) || ($item['id'] != $item['parent'])) {
		return;
	}

	// now change this copy of the post to a forum head message and deliver to all the tgroup members
	$c = q("SELECT `name`, `url`, `thumb` FROM `contact` WHERE `self` = 1 AND `uid` = %d LIMIT 1",
		intval($u[0]['uid'])
	);
	if (! DBM::is_result($c)) {
		return;
	}

	// also reset all the privacy bits to the forum default permissions

	$private = ($u[0]['allow_cid'] || $u[0]['allow_gid'] || $u[0]['deny_cid'] || $u[0]['deny_gid']) ? 1 : 0;

	$forum_mode = (($prvgroup) ? 2 : 1);

	q("UPDATE `item` SET `wall` = 1, `origin` = 1, `forum_mode` = %d, `owner-name` = '%s', `owner-link` = '%s', `owner-avatar` = '%s',
		`private` = %d, `allow_cid` = '%s', `allow_gid` = '%s', `deny_cid` = '%s', `deny_gid` = '%s'  WHERE `id` = %d",
		intval($forum_mode),
		dbesc($c[0]['name']),
		dbesc($c[0]['url']),
		dbesc($c[0]['thumb']),
		intval($private),
		dbesc($u[0]['allow_cid']),
		dbesc($u[0]['allow_gid']),
		dbesc($u[0]['deny_cid']),
		dbesc($u[0]['deny_gid']),
		intval($item_id)
	);
	update_thread($item_id);

	Worker::add(array('priority' => PRIORITY_HIGH, 'dont_fork' => true), 'Notifier', 'tgroup', $item_id);

}



function tgroup_check($uid, $item) {

	$mention = false;

	// check that the message originated elsewhere and is a top-level post

	if (($item['wall']) || ($item['origin']) || ($item['uri'] != $item['parent-uri'])) {
		return false;
	}

	/// @TODO Encapsulate this or find it encapsulated and replace all occurrances
	$u = q("SELECT * FROM `user` WHERE `uid` = %d LIMIT 1",
		intval($uid)
	);
	if (! DBM::is_result($u)) {
		return false;
	}

	$community_page = (($u[0]['page-flags'] == PAGE_COMMUNITY) ? true : false);
	$prvgroup = (($u[0]['page-flags'] == PAGE_PRVGROUP) ? true : false);

	$link = normalise_link(System::baseUrl() . '/profile/' . $u[0]['nickname']);

	/*
	 * Diaspora uses their own hardwired link URL in @-tags
	 * instead of the one we supply with webfinger
	 */
	$dlink = normalise_link(System::baseUrl() . '/u/' . $u[0]['nickname']);

	$cnt = preg_match_all('/[\@\!]\[url\=(.*?)\](.*?)\[\/url\]/ism', $item['body'], $matches, PREG_SET_ORDER);
	if ($cnt) {
		foreach ($matches as $mtch) {
			if (link_compare($link, $mtch[1]) || link_compare($dlink, $mtch[1])) {
				$mention = true;
				logger('tgroup_check: mention found: ' . $mtch[2]);
			}
		}
	}

	if (! $mention) {
		return false;
	}

	/// @TODO Combine both return statements into one
	return (($community_page) || ($prvgroup));
}

/**
 * This function returns true if $update has an edited timestamp newer
 * than $existing, i.e. $update contains new data which should override
 * what's already there.  If there is no timestamp yet, the update is
 * assumed to be newer.  If the update has no timestamp, the existing
 * item is assumed to be up-to-date.  If the timestamps are equal it
 * assumes the update has been seen before and should be ignored.
 *
 * @todo fix type-hints (both array)
 */
function edited_timestamp_is_newer($existing, $update) {
	if (!x($existing, 'edited') || !$existing['edited']) {
		return true;
	}
	if (!x($update, 'edited') || !$update['edited']) {
		return false;
	}

	$existing_edited = datetime_convert('UTC', 'UTC', $existing['edited']);
	$update_edited = datetime_convert('UTC', 'UTC', $update['edited']);
	return (strcmp($existing_edited, $update_edited) < 0);
}

/**
 *
 * consume_feed - process atom feed and update anything/everything we might need to update
 *
 * $xml = the (atom) feed to consume - RSS isn't as fully supported but may work for simple feeds.
 *
 * $importer = the contact_record (joined to user_record) of the local user who owns this relationship.
 *             It is this person's stuff that is going to be updated.
 * $contact =  the person who is sending us stuff. If not set, we MAY be processing a "follow" activity
 *             from an external network and MAY create an appropriate contact record. Otherwise, we MUST
 *             have a contact record.
 * $hub = should we find a hub declation in the feed, pass it back to our calling process, who might (or
 *        might not) try and subscribe to it.
 * $datedir sorts in reverse order
 * $pass - by default ($pass = 0) we cannot guarantee that a parent item has been
 *      imported prior to its children being seen in the stream unless we are certain
 *      of how the feed is arranged/ordered.
 * With $pass = 1, we only pull parent items out of the stream.
 * With $pass = 2, we only pull children (comments/likes).
 *
 * So running this twice, first with pass 1 and then with pass 2 will do the right
 * thing regardless of feed ordering. This won't be adequate in a fully-threaded
 * model where comments can have sub-threads. That would require some massive sorting
 * to get all the feed items into a mostly linear ordering, and might still require
 * recursion.
 *
 * @todo find proper type-hints
 */
function consume_feed($xml, $importer, &$contact, &$hub, $datedir = 0, $pass = 0) {
	if ($contact['network'] === NETWORK_OSTATUS) {
		if ($pass < 2) {
			// Test - remove before flight
			//$tempfile = tempnam(get_temppath(), "ostatus2");
			//file_put_contents($tempfile, $xml);
			logger("Consume OStatus messages ", LOGGER_DEBUG);
			OStatus::import($xml, $importer, $contact, $hub);
		}
		return;
	}

	if ($contact['network'] === NETWORK_FEED) {
		if ($pass < 2) {
			logger("Consume feeds", LOGGER_DEBUG);
			feed_import($xml, $importer, $contact, $hub);
		}
		return;
	}

	if ($contact['network'] === NETWORK_DFRN) {
		logger("Consume DFRN messages", LOGGER_DEBUG);

		$r = q("SELECT `contact`.*, `contact`.`uid` AS `importer_uid`,
					`contact`.`pubkey` AS `cpubkey`,
					`contact`.`prvkey` AS `cprvkey`,
					`contact`.`thumb` AS `thumb`,
					`contact`.`url` as `url`,
					`contact`.`name` as `senderName`,
					`user`.*
			FROM `contact`
			LEFT JOIN `user` ON `contact`.`uid` = `user`.`uid`
			WHERE `contact`.`id` = %d AND `user`.`uid` = %d",
			dbesc($contact["id"]), dbesc($importer["uid"])
		);
		if (DBM::is_result($r)) {
			logger("Now import the DFRN feed");
			DFRN::import($xml, $r[0], true);
			return;
		}
	}
}

/// @TODO type-hint is array
function item_is_remote_self($contact, &$datarray) {
	$a = get_app();

	if (!$contact['remote_self']) {
		return false;
	}

	// Prevent the forwarding of posts that are forwarded
	if ($datarray["extid"] == NETWORK_DFRN) {
		return false;
	}

	// Prevent to forward already forwarded posts
	if ($datarray["app"] == $a->get_hostname()) {
		return false;
	}

	// Only forward posts
	if ($datarray["verb"] != ACTIVITY_POST) {
		return false;
	}

	if (($contact['network'] != NETWORK_FEED) && $datarray['private']) {
		return false;
	}

	$datarray2 = $datarray;
	logger('remote-self start - Contact '.$contact['url'].' - '.$contact['remote_self'].' Item '.print_r($datarray, true), LOGGER_DEBUG);
	if ($contact['remote_self'] == 2) {
		$r = q("SELECT `id`,`url`,`name`,`thumb` FROM `contact` WHERE `uid` = %d AND `self`",
			intval($contact['uid']));
		if (DBM::is_result($r)) {
			$datarray['contact-id'] = $r[0]["id"];

			$datarray['owner-name'] = $r[0]["name"];
			$datarray['owner-link'] = $r[0]["url"];
			$datarray['owner-avatar'] = $r[0]["thumb"];

			$datarray['author-name']   = $datarray['owner-name'];
			$datarray['author-link']   = $datarray['owner-link'];
			$datarray['author-avatar'] = $datarray['owner-avatar'];

			unset($datarray['created']);
			unset($datarray['edited']);
		}

		if ($contact['network'] != NETWORK_FEED) {
			$datarray["guid"] = get_guid(32);
			unset($datarray["plink"]);
			$datarray["uri"] = item_new_uri($a->get_hostname(), $contact['uid'], $datarray["guid"]);
			$datarray["parent-uri"] = $datarray["uri"];
			$datarray["extid"] = $contact['network'];
			$urlpart = parse_url($datarray2['author-link']);
			$datarray["app"] = $urlpart["host"];
		} else {
			$datarray['private'] = 0;
		}
	}

	if ($contact['network'] != NETWORK_FEED) {
		// Store the original post
		$r = item_store($datarray2, false, false);
		logger('remote-self post original item - Contact '.$contact['url'].' return '.$r.' Item '.print_r($datarray2, true), LOGGER_DEBUG);
	} else {
		$datarray["app"] = "Feed";
	}

	// Trigger automatic reactions for addons
	$datarray['api_source'] = true;

	// We have to tell the hooks who we are - this really should be improved
	$_SESSION["authenticated"] = true;
	$_SESSION["uid"] = $contact['uid'];

	return true;
}

/// @TODO find proper type-hints
function new_follower($importer, $contact, $datarray, $item, $sharing = false) {
	$url = notags(trim($datarray['author-link']));
	$name = notags(trim($datarray['author-name']));
	$photo = notags(trim($datarray['author-avatar']));

	if (is_object($item)) {
		$rawtag = $item->get_item_tags(NAMESPACE_ACTIVITY,'actor');
		if ($rawtag && $rawtag[0]['child'][NAMESPACE_POCO]['preferredUsername'][0]['data']) {
			$nick = $rawtag[0]['child'][NAMESPACE_POCO]['preferredUsername'][0]['data'];
		}
	} else {
		$nick = $item;
	}

	if (is_array($contact)) {
		if (($contact['network'] == NETWORK_OSTATUS && $contact['rel'] == CONTACT_IS_SHARING)
			|| ($sharing && $contact['rel'] == CONTACT_IS_FOLLOWER)) {
			$r = dba::update('contact', array('rel' => CONTACT_IS_FRIEND, 'writable' => true),
					array('id' => $contact['id'], 'uid' => $importer['uid']));
		}
		// send email notification to owner?
	} else {

		// create contact record

		$r = q("INSERT INTO `contact` (`uid`, `created`, `url`, `nurl`, `name`, `nick`, `photo`, `network`, `rel`,
			`blocked`, `readonly`, `pending`, `writable`)
			VALUES (%d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d, 0, 0, 1, 1)",
			intval($importer['uid']),
			dbesc(datetime_convert()),
			dbesc($url),
			dbesc(normalise_link($url)),
			dbesc($name),
			dbesc($nick),
			dbesc($photo),
			dbesc(NETWORK_OSTATUS),
			intval(CONTACT_IS_FOLLOWER)
		);
		$r = q("SELECT `id`, `network` FROM `contact` WHERE `uid` = %d AND `url` = '%s' AND `pending` = 1 LIMIT 1",
				intval($importer['uid']),
				dbesc($url)
		);
		if (DBM::is_result($r)) {
			$contact_record = $r[0];
			Contact::updateAvatar($photo, $importer["uid"], $contact_record["id"], true);
		}

		/// @TODO Encapsulate this into a function/method
		$r = q("SELECT * FROM `user` WHERE `uid` = %d LIMIT 1",
			intval($importer['uid'])
		);

		if (DBM::is_result($r) && !in_array($r[0]['page-flags'], array(PAGE_SOAPBOX, PAGE_FREELOVE, PAGE_COMMUNITY))) {

			// create notification
			$hash = random_string();

			if (is_array($contact_record)) {
				dba::insert('intro', array('uid' => $importer['uid'], 'contact-id' => $contact_record['id'],
							'blocked' => false, 'knowyou' => false,
							'hash' => $hash, 'datetime' => datetime_convert()));
			}

			$def_gid = get_default_group($importer['uid'], $contact_record["network"]);

			if (intval($def_gid)) {
				group_add_member($importer['uid'], '', $contact_record['id'], $def_gid);
			}

			if (($r[0]['notify-flags'] & NOTIFY_INTRO) &&
				in_array($r[0]['page-flags'], array(PAGE_NORMAL))) {

				notification(array(
					'type'         => NOTIFY_INTRO,
					'notify_flags' => $r[0]['notify-flags'],
					'language'     => $r[0]['language'],
					'to_name'      => $r[0]['username'],
					'to_email'     => $r[0]['email'],
					'uid'          => $r[0]['uid'],
					'link'		   => System::baseUrl() . '/notifications/intro',
					'source_name'  => ((strlen(stripslashes($contact_record['name']))) ? stripslashes($contact_record['name']) : t('[Name Withheld]')),
					'source_link'  => $contact_record['url'],
					'source_photo' => $contact_record['photo'],
					'verb'         => ($sharing ? ACTIVITY_FRIEND : ACTIVITY_FOLLOW),
					'otype'        => 'intro'
				));

			}
		} elseif (DBM::is_result($r) && in_array($r[0]['page-flags'], array(PAGE_SOAPBOX, PAGE_FREELOVE, PAGE_COMMUNITY))) {
			$r = q("UPDATE `contact` SET `pending` = 0 WHERE `uid` = %d AND `url` = '%s' AND `pending` LIMIT 1",
					intval($importer['uid']),
					dbesc($url)
			);
		}

	}
}

function lose_follower($importer, $contact, array $datarray = array(), $item = "") {

	if (($contact['rel'] == CONTACT_IS_FRIEND) || ($contact['rel'] == CONTACT_IS_SHARING)) {
		dba::update('contact', array('rel' => CONTACT_IS_SHARING), array('id' => $contact['id']));
	} else {
		Contact::remove($contact['id']);
	}
}

function lose_sharer($importer, $contact, array $datarray = array(), $item = "") {

	if (($contact['rel'] == CONTACT_IS_FRIEND) || ($contact['rel'] == CONTACT_IS_FOLLOWER)) {
		dba::update('contact', array('rel' => CONTACT_IS_FOLLOWER), array('id' => $contact['id']));
	} else {
		Contact::remove($contact['id']);
	}
}

function subscribe_to_hub($url, $importer, $contact, $hubmode = 'subscribe') {

	$a = get_app();

	if (is_array($importer)) {
		$r = q("SELECT `nickname` FROM `user` WHERE `uid` = %d LIMIT 1",
			intval($importer['uid'])
		);
	}

	/*
	 * Diaspora has different message-ids in feeds than they do
	 * through the direct Diaspora protocol. If we try and use
	 * the feed, we'll get duplicates. So don't.
	 */
	if ((! DBM::is_result($r)) || $contact['network'] === NETWORK_DIASPORA) {
		return;
	}

	$push_url = Config::get('system','url') . '/pubsub/' . $r[0]['nickname'] . '/' . $contact['id'];

	// Use a single verify token, even if multiple hubs
	$verify_token = ((strlen($contact['hub-verify'])) ? $contact['hub-verify'] : random_string());

	$params= 'hub.mode=' . $hubmode . '&hub.callback=' . urlencode($push_url) . '&hub.topic=' . urlencode($contact['poll']) . '&hub.verify=async&hub.verify_token=' . $verify_token;

	logger('subscribe_to_hub: ' . $hubmode . ' ' . $contact['name'] . ' to hub ' . $url . ' endpoint: '  . $push_url . ' with verifier ' . $verify_token);

	if (!strlen($contact['hub-verify']) || ($contact['hub-verify'] != $verify_token)) {
		$r = dba::update('contact', array('hub-verify' => $verify_token), array('id' => $contact['id']));
	}

	post_url($url, $params);

	logger('subscribe_to_hub: returns: ' . $a->get_curl_code(), LOGGER_DEBUG);

	return;

}

function fix_private_photos($s, $uid, $item = null, $cid = 0) {

	if (Config::get('system','disable_embedded')) {
		return $s;
	}

	$a = get_app();

	logger('fix_private_photos: check for photos', LOGGER_DEBUG);
	$site = substr(System::baseUrl(),strpos(System::baseUrl(),'://'));

	$orig_body = $s;
	$new_body = '';

	$img_start = strpos($orig_body, '[img');
	$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
	$img_len = ($img_start !== false ? strpos(substr($orig_body, $img_start + $img_st_close + 1), '[/img]') : false);
	while ( ($img_st_close !== false) && ($img_len !== false) ) {

		$img_st_close++; // make it point to AFTER the closing bracket
		$image = substr($orig_body, $img_start + $img_st_close, $img_len);

		logger('fix_private_photos: found photo ' . $image, LOGGER_DEBUG);


		if (stristr($image , $site . '/photo/')) {
			// Only embed locally hosted photos
			$replace = false;
			$i = basename($image);
			$i = str_replace(array('.jpg', '.png', '.gif'),array('', '',''), $i);
			$x = strpos($i, '-');

			if ($x) {
				$res = substr($i, $x + 1);
				$i = substr($i, 0, $x);
				$r = q("SELECT * FROM `photo` WHERE `resource-id` = '%s' AND `scale` = %d AND `uid` = %d",
					dbesc($i),
					intval($res),
					intval($uid)

				);
				if (DBM::is_result($r)) {
					/*
					 * Check to see if we should replace this photo link with an embedded image
					 * 1. No need to do so if the photo is public
					 * 2. If there's a contact-id provided, see if they're in the access list
					 *    for the photo. If so, embed it.
					 * 3. Otherwise, if we have an item, see if the item permissions match the photo
					 *    permissions, regardless of order but first check to see if they're an exact
					 *    match to save some processing overhead.
					 */
					if (has_permissions($r[0])) {
						if ($cid) {
							$recips = enumerate_permissions($r[0]);
							if (in_array($cid, $recips)) {
								$replace = true;
							}
						} elseif ($item) {
							if (compare_permissions($item, $r[0])) {
								$replace = true;
							}
						}
					}
					if ($replace) {
						$data = $r[0]['data'];
						$type = $r[0]['type'];

						// If a custom width and height were specified, apply before embedding
						if (preg_match("/\[img\=([0-9]*)x([0-9]*)\]/is", substr($orig_body, $img_start, $img_st_close), $match)) {
							logger('fix_private_photos: scaling photo', LOGGER_DEBUG);

							$width = intval($match[1]);
							$height = intval($match[2]);

							$Image = new Image($data, $type);
							if ($Image->isValid()) {
								$Image->scaleDown(max($width, $height));
								$data = $Image->asString();
								$type = $Image->getType();
							}
						}

						logger('fix_private_photos: replacing photo', LOGGER_DEBUG);
						$image = 'data:' . $type . ';base64,' . base64_encode($data);
						logger('fix_private_photos: replaced: ' . $image, LOGGER_DATA);
					}
				}
			}
		}

		$new_body = $new_body . substr($orig_body, 0, $img_start + $img_st_close) . $image . '[/img]';
		$orig_body = substr($orig_body, $img_start + $img_st_close + $img_len + strlen('[/img]'));
		if ($orig_body === false) {
			$orig_body = '';
		}

		$img_start = strpos($orig_body, '[img');
		$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
		$img_len = ($img_start !== false ? strpos(substr($orig_body, $img_start + $img_st_close + 1), '[/img]') : false);
	}

	$new_body = $new_body . $orig_body;

	return $new_body;
}

/// @TODO type-hint is array
function has_permissions($obj) {
	return (
		(
			x($obj, 'allow_cid')
		) || (
			x($obj, 'allow_gid')
		) || (
			x($obj, 'deny_cid')
		) || (
			x($obj, 'deny_gid')
		)
	);
}

/// @TODO type-hint is array
function compare_permissions($obj1, $obj2) {
	// first part is easy. Check that these are exactly the same.
	if (($obj1['allow_cid'] == $obj2['allow_cid'])
		&& ($obj1['allow_gid'] == $obj2['allow_gid'])
		&& ($obj1['deny_cid'] == $obj2['deny_cid'])
		&& ($obj1['deny_gid'] == $obj2['deny_gid'])) {
		return true;
	}

	// This is harder. Parse all the permissions and compare the resulting set.
	$recipients1 = enumerate_permissions($obj1);
	$recipients2 = enumerate_permissions($obj2);
	sort($recipients1);
	sort($recipients2);

	/// @TODO Comparison of arrays, maybe use array_diff_assoc() here?
	return ($recipients1 == $recipients2);
}

// returns an array of contact-ids that are allowed to see this object
/// @TODO type-hint is array
function enumerate_permissions($obj) {
	$allow_people = expand_acl($obj['allow_cid']);
	$allow_groups = expand_groups(expand_acl($obj['allow_gid']));
	$deny_people  = expand_acl($obj['deny_cid']);
	$deny_groups  = expand_groups(expand_acl($obj['deny_gid']));
	$recipients   = array_unique(array_merge($allow_people, $allow_groups));
	$deny         = array_unique(array_merge($deny_people, $deny_groups));
	$recipients   = array_diff($recipients, $deny);
	return $recipients;
}

function item_getfeedtags($item) {
	$ret = array();
	$matches = false;
	$cnt = preg_match_all('|\#\[url\=(.*?)\](.*?)\[\/url\]|', $item['tag'], $matches);
	if ($cnt) {
		for ($x = 0; $x < $cnt; $x ++) {
			if ($matches[1][$x]) {
				$ret[$matches[2][$x]] = array('#', $matches[1][$x], $matches[2][$x]);
			}
		}
	}
	$matches = false;
	$cnt = preg_match_all('|\@\[url\=(.*?)\](.*?)\[\/url\]|', $item['tag'], $matches);
	if ($cnt) {
		for ($x = 0; $x < $cnt; $x ++) {
			if ($matches[1][$x]) {
				$ret[] = array('@', $matches[1][$x], $matches[2][$x]);
			}
		}
	}
	return $ret;
}

function item_expire($uid, $days, $network = "", $force = false) {

	if ((! $uid) || ($days < 1)) {
		return;
	}

	/*
	 * $expire_network_only = save your own wall posts
	 * and just expire conversations started by others
	 */
	$expire_network_only = PConfig::get($uid,'expire', 'network_only');
	$sql_extra = ((intval($expire_network_only)) ? " AND wall = 0 " : "");

	if ($network != "") {
		$sql_extra .= sprintf(" AND network = '%s' ", dbesc($network));

		/*
		 * There is an index "uid_network_received" but not "uid_network_created"
		 * This avoids the creation of another index just for one purpose.
		 * And it doesn't really matter wether to look at "received" or "created"
		 */
		$range = "AND `received` < UTC_TIMESTAMP() - INTERVAL %d DAY ";
	} else {
		$range = "AND `created` < UTC_TIMESTAMP() - INTERVAL %d DAY ";
	}

	$r = q("SELECT `file`, `resource-id`, `starred`, `type`, `id` FROM `item`
		WHERE `uid` = %d $range
		AND `id` = `parent`
		$sql_extra
		AND `deleted` = 0",
		intval($uid),
		intval($days)
	);

	if (! DBM::is_result($r)) {
		return;
	}

	$expire_items = PConfig::get($uid, 'expire', 'items', 1);

	// Forcing expiring of items - but not notes and marked items
	if ($force) {
		$expire_items = true;
	}

	$expire_notes = PConfig::get($uid, 'expire', 'notes', 1);
	$expire_starred = PConfig::get($uid, 'expire', 'starred', 1);
	$expire_photos = PConfig::get($uid, 'expire', 'photos', 0);

	logger('User '.$uid.': expire: # items=' . count($r). "; expire items: $expire_items, expire notes: $expire_notes, expire starred: $expire_starred, expire photos: $expire_photos");

	foreach ($r as $item) {

		// don't expire filed items

		if (strpos($item['file'],'[') !== false) {
			continue;
		}

		// Only expire posts, not photos and photo comments

		if ($expire_photos == 0 && strlen($item['resource-id'])) {
			continue;
		} elseif ($expire_starred == 0 && intval($item['starred'])) {
			continue;
		} elseif ($expire_notes == 0 && $item['type'] == 'note') {
			continue;
		} elseif ($expire_items == 0 && $item['type'] != 'note') {
			continue;
		}

		drop_item($item['id'], false);
	}

	Worker::add(array('priority' => PRIORITY_LOW, 'dont_fork' => true), "Notifier", "expire", $uid);
}

/// @TODO type-hint is array
function drop_items($items) {
	$uid = 0;

	if (! local_user() && ! remote_user()) {
		return;
	}

	if (count($items)) {
		foreach ($items as $item) {
			$owner = drop_item($item,false);
			if ($owner && ! $uid)
				$uid = $owner;
		}
	}

	// multiple threads may have been deleted, send an expire notification

	if ($uid) {
		Worker::add(array('priority' => PRIORITY_LOW, 'dont_fork' => true), "Notifier", "expire", $uid);
	}
}


function drop_item($id, $interactive = true) {

	$a = get_app();

	// locate item to be deleted

	$r = q("SELECT * FROM `item` WHERE `id` = %d LIMIT 1",
		intval($id)
	);

	if (! DBM::is_result($r)) {
		if (! $interactive) {
			return 0;
		}
		notice( t('Item not found.') . EOL);
		goaway(System::baseUrl() . '/' . $_SESSION['return_url']);
	}

	$item = $r[0];

	if ($item['deleted']) {
		return 0;
	}

	$owner = $item['uid'];

	$contact_id = 0;

	// check if logged in user is either the author or owner of this item

	if (is_array($_SESSION['remote'])) {
		foreach ($_SESSION['remote'] as $visitor) {
			if ($visitor['uid'] == $item['uid'] && $visitor['cid'] == $item['contact-id']) {
				$contact_id = $visitor['cid'];
				break;
			}
		}
	}


	if ((local_user() == $item['uid']) || $contact_id || !$interactive) {

		// Check if we should do HTML-based delete confirmation
		if ($_REQUEST['confirm']) {
			// <form> can't take arguments in its "action" parameter
			// so add any arguments as hidden inputs
			$query = explode_querystring($a->query_string);
			$inputs = array();
			foreach ($query['args'] as $arg) {
				if (strpos($arg, 'confirm=') === false) {
					$arg_parts = explode('=', $arg);
					$inputs[] = array('name' => $arg_parts[0], 'value' => $arg_parts[1]);
				}
			}

			return replace_macros(get_markup_template('confirm.tpl'), array(
				'$method' => 'get',
				'$message' => t('Do you really want to delete this item?'),
				'$extra_inputs' => $inputs,
				'$confirm' => t('Yes'),
				'$confirm_url' => $query['base'],
				'$confirm_name' => 'confirmed',
				'$cancel' => t('Cancel'),
			));
		}
		// Now check how the user responded to the confirmation query
		if ($_REQUEST['canceled']) {
			goaway(System::baseUrl() . '/' . $_SESSION['return_url']);
		}

		logger('delete item: ' . $item['id'], LOGGER_DEBUG);

		// delete the item
		$r = dba::update('item', array('deleted' => true, 'title' => '', 'body' => '',
					'edited' => datetime_convert(), 'changed' => datetime_convert()),
				array('id' => $item['id']));

		create_tags_from_item($item['id']);
		create_files_from_item($item['id']);
		delete_thread($item['id'], $item['parent-uri']);

		// clean up categories and tags so they don't end up as orphans

		$matches = false;
		$cnt = preg_match_all('/<(.*?)>/', $item['file'], $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				file_tag_unsave_file($item['uid'], $item['id'], $mtch[1],true);
			}
		}

		$matches = false;

		$cnt = preg_match_all('/\[(.*?)\]/', $item['file'], $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				file_tag_unsave_file($item['uid'], $item['id'], $mtch[1],false);
			}
		}

		/*
		 * If item is a link to a photo resource, nuke all the associated photos
		 * (visitors will not have photo resources)
		 * This only applies to photos uploaded from the photos page. Photos inserted into a post do not
		 * generate a resource-id and therefore aren't intimately linked to the item.
		 */
		if (strlen($item['resource-id'])) {
			dba::delete('photo', array('resource-id' => $item['resource-id'], 'uid' => $item['uid']));
		}

		// If item is a link to an event, nuke the event record.
		if (intval($item['event-id'])) {
			dba::delete('event', array('id' => $item['event-id'], 'uid' => $item['uid']));
		}

		// If item has attachments, drop them
		foreach (explode(", ", $item['attach']) as $attach) {
			preg_match("|attach/(\d+)|", $attach, $matches);
			dba::delete('attach', array('id' => $matches[1], 'uid' => $item['uid']));
		}

		// The new code splits the queries since the mysql optimizer really has bad problems with subqueries

		// Creating list of parents
		$r = q("SELECT `id` FROM `item` WHERE `parent` = %d AND `uid` = %d",
			intval($item['id']),
			intval($item['uid'])
		);

		$parentid = "";

		foreach ($r as $row) {
			if ($parentid != "") {
				$parentid .= ", ";
			}

			$parentid .= $row["id"];
		}

		// Now delete them
		if ($parentid != "") {
			$r = q("DELETE FROM `sign` WHERE `iid` IN (%s)", dbesc($parentid));
		}

		// If it's the parent of a comment thread, kill all the kids
		if ($item['uri'] == $item['parent-uri']) {
			$r = dba::update('item', array('deleted' => true, 'title' => '', 'body' => '',
					'edited' => datetime_convert(), 'changed' => datetime_convert()),
				array('parent-uri' => $item['parent-uri'], 'uid' => $item['uid']));

			create_tags_from_itemuri($item['parent-uri'], $item['uid']);
			create_files_from_itemuri($item['parent-uri'], $item['uid']);
			delete_thread_uri($item['parent-uri'], $item['uid']);
			// ignore the result
		} else {
			// ensure that last-child is set in case the comment that had it just got wiped.
			dba::update('item', array('last-child' => false, 'changed' => datetime_convert()),
					array('parent-uri' => $item['parent-uri'], 'uid' => $item['uid']));

			// who is the last child now?
			$r = q("SELECT `id` FROM `item` WHERE `parent-uri` = '%s' AND `type` != 'activity' AND `deleted` = 0 AND `uid` = %d ORDER BY `edited` DESC LIMIT 1",
				dbesc($item['parent-uri']),
				intval($item['uid'])
			);
			if (DBM::is_result($r)) {
				dba::update('item', array('last-child' => true), array('id' => $r[0]['id']));
			}
		}

		// send the notification upstream/downstream
		// The priority depends on how the deletion is done.
		$drop_id = intval($item['id']);
		$priority = ($interactive ? PRIORITY_HIGH : PRIORITY_LOW);

		Worker::add(array('priority' => $priority, 'dont_fork' => true), "Notifier", "drop", $drop_id);

		if (! $interactive) {
			return $owner;
		}
		goaway(System::baseUrl() . '/' . $_SESSION['return_url']);
		//NOTREACHED
	} else {
		if (! $interactive) {
			return 0;
		}
		notice( t('Permission denied.') . EOL);
		goaway(System::baseUrl() . '/' . $_SESSION['return_url']);
		//NOTREACHED
	}

}

/// @todo: This query seems to be really slow
function first_post_date($uid, $wall = false) {
	$r = q("SELECT `id`, `created` FROM `item`
		WHERE `uid` = %d AND `wall` = %d AND `deleted` = 0 AND `visible` = 1 AND `moderated` = 0
		AND `id` = `parent`
		ORDER BY `created` ASC LIMIT 1",
		intval($uid),
		intval($wall ? 1 : 0)
	);
	if (DBM::is_result($r)) {
		// logger('first_post_date: ' . $r[0]['id'] . ' ' . $r[0]['created'], LOGGER_DATA);
		return substr(datetime_convert('',date_default_timezone_get(), $r[0]['created']),0,10);
	}
	return false;
}

/* modified posted_dates() {below} to arrange the list in years */
function list_post_dates($uid, $wall) {
	$dnow = datetime_convert('',date_default_timezone_get(), 'now','Y-m-d');

	$dthen = first_post_date($uid, $wall);
	if (! $dthen) {
		return array();
	}

	// Set the start and end date to the beginning of the month
	$dnow = substr($dnow, 0, 8) . '01';
	$dthen = substr($dthen, 0, 8) . '01';

	$ret = array();

	/*
	 * Starting with the current month, get the first and last days of every
	 * month down to and including the month of the first post
	 */
	while (substr($dnow, 0, 7) >= substr($dthen, 0, 7)) {
		$dyear = intval(substr($dnow, 0, 4));
		$dstart = substr($dnow, 0, 8) . '01';
		$dend = substr($dnow, 0, 8) . get_dim(intval($dnow), intval(substr($dnow, 5)));
		$start_month = datetime_convert('', '', $dstart, 'Y-m-d');
		$end_month = datetime_convert('', '', $dend, 'Y-m-d');
		$str = day_translate(datetime_convert('', '', $dnow, 'F'));
		if (!$ret[$dyear]) {
			$ret[$dyear] = array();
		}
		$ret[$dyear][] = array($str, $end_month, $start_month);
		$dnow = datetime_convert('', '', $dnow . ' -1 month', 'Y-m-d');
	}
	return $ret;
}

function posted_dates($uid, $wall) {
	$dnow = datetime_convert('', date_default_timezone_get(), 'now', 'Y-m-d');

	$dthen = first_post_date($uid, $wall);
	if (! $dthen) {
		return array();
	}

	// Set the start and end date to the beginning of the month
	$dnow = substr($dnow, 0, 8) . '01';
	$dthen = substr($dthen, 0, 8) . '01';

	$ret = array();
	/*
	 * Starting with the current month, get the first and last days of every
	 * month down to and including the month of the first post
	 */
	while (substr($dnow, 0, 7) >= substr($dthen, 0, 7)) {
		$dstart = substr($dnow, 0, 8) . '01';
		$dend = substr($dnow, 0, 8) . get_dim(intval($dnow), intval(substr($dnow, 5)));
		$start_month = datetime_convert('', '', $dstart, 'Y-m-d');
		$end_month = datetime_convert('', '', $dend, 'Y-m-d');
		$str = day_translate(datetime_convert('', '', $dnow, 'F Y'));
		$ret[] = array($str, $end_month, $start_month);
		$dnow = datetime_convert('', '', $dnow . ' -1 month', 'Y-m-d');
	}
	return $ret;
}


function posted_date_widget($url, $uid, $wall) {
	$o = '';

	if (! Feature::isEnabled($uid, 'archives')) {
		return $o;
	}

	// For former Facebook folks that left because of "timeline"
	/*
	 * @TODO old-lost code?
	if ($wall && intval(PConfig::get($uid, 'system', 'no_wall_archive_widget')))
		return $o;
	*/

	$visible_years = PConfig::get($uid,'system','archive_visible_years');
	if (! $visible_years) {
		$visible_years = 5;
	}

	$ret = list_post_dates($uid, $wall);

	if (! DBM::is_result($ret)) {
		return $o;
	}

	$cutoff_year = intval(datetime_convert('',date_default_timezone_get(), 'now', 'Y')) - $visible_years;
	$cutoff = ((array_key_exists($cutoff_year, $ret))? true : false);

	$o = replace_macros(get_markup_template('posted_date_widget.tpl'),array(
		'$title' => t('Archives'),
		'$size' => $visible_years,
		'$cutoff_year' => $cutoff_year,
		'$cutoff' => $cutoff,
		'$url' => $url,
		'$dates' => $ret,
		'$showmore' => t('show more')

	));
	return $o;
}
