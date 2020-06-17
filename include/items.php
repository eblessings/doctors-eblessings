<?php
/**
 * @copyright Copyright (C) 2020, Friendica
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

use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Core\Renderer;
use Friendica\Core\Session;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Item;
use Friendica\Protocol\DFRN;
use Friendica\Protocol\Feed;
use Friendica\Protocol\OStatus;
use Friendica\Util\Network;
use Friendica\Util\ParseUrl;
use Friendica\Util\Strings;

require_once __DIR__ . '/../mod/share.php';

function add_page_info_data(array $data, $no_photos = false)
{
	Hook::callAll('page_info_data', $data);

	if (empty($data['type'])) {
		return '';
	}

	// It maybe is a rich content, but if it does have everything that a link has,
	// then treat it that way
	if (($data["type"] == "rich") && is_string($data["title"]) &&
		is_string($data["text"]) && !empty($data["images"])) {
		$data["type"] = "link";
	}

	$data["title"] = $data["title"] ?? '';

	if ((($data["type"] != "link") && ($data["type"] != "video") && ($data["type"] != "photo")) || ($data["title"] == $data["url"])) {
		return "";
	}

	if ($no_photos && ($data["type"] == "photo")) {
		return "";
	}

	// Escape some bad characters
	$data["url"] = str_replace(["[", "]"], ["&#91;", "&#93;"], htmlentities($data["url"], ENT_QUOTES, 'UTF-8', false));
	$data["title"] = str_replace(["[", "]"], ["&#91;", "&#93;"], htmlentities($data["title"], ENT_QUOTES, 'UTF-8', false));

	$text = "[attachment type='".$data["type"]."'";

	if (empty($data["text"])) {
		$data["text"] = $data["title"];
	}

	if (empty($data["text"])) {
		$data["text"] = $data["url"];
	}

	if (!empty($data["url"])) {
		$text .= " url='".$data["url"]."'";
	}

	if (!empty($data["title"])) {
		$text .= " title='".$data["title"]."'";
	}

	// Only embedd a picture link when it seems to be a valid picture ("width" is set)
	if (!empty($data["images"]) && !empty($data["images"][0]["width"])) {
		$preview = str_replace(["[", "]"], ["&#91;", "&#93;"], htmlentities($data["images"][0]["src"], ENT_QUOTES, 'UTF-8', false));
		// if the preview picture is larger than 500 pixels then show it in a larger mode
		// But only, if the picture isn't higher than large (To prevent huge posts)
		if (!DI::config()->get('system', 'always_show_preview') && ($data["images"][0]["width"] >= 500)
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
		foreach ($data["keywords"] as $keyword) {
			/// @TODO make a positive list of allowed characters
			$hashtag = str_replace([' ', '+', '/', '.', '#', '@', "'", '"', '’', '`', '(', ')', '„', '“'], '', $keyword);
			$hashtags .= "#[url=" . DI::baseUrl() . "/search?tag=" . $hashtag . "]" . $hashtag . "[/url] ";
		}
	}

	return "\n".$text.$hashtags;
}

function query_page_info($url, $photo = "", $keywords = false, $keyword_denylist = "")
{
	$data = ParseUrl::getSiteinfoCached($url, true);

	if ($photo != "") {
		$data["images"][0]["src"] = $photo;
	}

	Logger::log('fetch page info for ' . $url . ' ' . print_r($data, true), Logger::DEBUG);

	if (!$keywords && isset($data["keywords"])) {
		unset($data["keywords"]);
	}

	if (($keyword_denylist != "") && isset($data["keywords"])) {
		$list = explode(", ", $keyword_denylist);

		foreach ($list as $keyword) {
			$keyword = trim($keyword);

			$index = array_search($keyword, $data["keywords"]);
			if ($index !== false) {
				unset($data["keywords"][$index]);
			}
		}
	}

	return $data;
}

function get_page_keywords($url, $photo = "", $keywords = false, $keyword_denylist = "")
{
	$data = query_page_info($url, $photo, $keywords, $keyword_denylist);
	if (empty($data["keywords"]) || !is_array($data["keywords"])) {
		return [];
	}

	$taglist = [];
	foreach ($data['keywords'] as $keyword) {
		$hashtag = str_replace([" ", "+", "/", ".", "#", "'"],
			["", "", "", "", "", ""], $keyword);

		$taglist[] = $hashtag;
	}

	return $taglist;
}

function add_page_info($url, $no_photos = false, $photo = "", $keywords = false, $keyword_denylist = "")
{
	$data = query_page_info($url, $photo, $keywords, $keyword_denylist);

	$text = '';

	if (is_array($data)) {
		$text = add_page_info_data($data, $no_photos);
	}

	return $text;
}

function add_page_info_to_body($body, $texturl = false, $no_photos = false)
{
	Logger::log('add_page_info_to_body: fetch page info for body ' . $body, Logger::DEBUG);

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

		$removedlink = preg_replace("/\[url\=" . preg_quote($matches[1], '/') . "\](.*?)\[\/url\]/ism", '', $body);
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
 * @param       $xml
 * @param array $importer
 * @param array $contact
 * @param       $hub
 * @throws ImagickException
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function consume_feed($xml, array $importer, array $contact, &$hub)
{
	if ($contact['network'] === Protocol::OSTATUS) {
		Logger::log("Consume OStatus messages ", Logger::DEBUG);
		OStatus::import($xml, $importer, $contact, $hub);

		return;
	}

	if ($contact['network'] === Protocol::FEED) {
		Logger::log("Consume feeds", Logger::DEBUG);
		Feed::import($xml, $importer, $contact);

		return;
	}

	if ($contact['network'] === Protocol::DFRN) {
		Logger::log("Consume DFRN messages", Logger::DEBUG);
		$dfrn_importer = DFRN::getImporter($contact["id"], $importer["uid"]);
		if (!empty($dfrn_importer)) {
			Logger::log("Now import the DFRN feed");
			DFRN::import($xml, $dfrn_importer, true);
			return;
		}
	}
}

function subscribe_to_hub($url, array $importer, array $contact, $hubmode = 'subscribe')
{
	/*
	 * Diaspora has different message-ids in feeds than they do
	 * through the direct Diaspora protocol. If we try and use
	 * the feed, we'll get duplicates. So don't.
	 */
	if ($contact['network'] === Protocol::DIASPORA) {
		return;
	}

	// Without an importer we don't have a user id - so we quit
	if (empty($importer)) {
		return;
	}

	$user = DBA::selectFirst('user', ['nickname'], ['uid' => $importer['uid']]);

	// No user, no nickname, we quit
	if (!DBA::isResult($user)) {
		return;
	}

	$push_url = DI::baseUrl() . '/pubsub/' . $user['nickname'] . '/' . $contact['id'];

	// Use a single verify token, even if multiple hubs
	$verify_token = ((strlen($contact['hub-verify'])) ? $contact['hub-verify'] : Strings::getRandomHex());

	$params= 'hub.mode=' . $hubmode . '&hub.callback=' . urlencode($push_url) . '&hub.topic=' . urlencode($contact['poll']) . '&hub.verify=async&hub.verify_token=' . $verify_token;

	Logger::log('subscribe_to_hub: ' . $hubmode . ' ' . $contact['name'] . ' to hub ' . $url . ' endpoint: '  . $push_url . ' with verifier ' . $verify_token);

	if (!strlen($contact['hub-verify']) || ($contact['hub-verify'] != $verify_token)) {
		DBA::update('contact', ['hub-verify' => $verify_token], ['id' => $contact['id']]);
	}

	$postResult = Network::post($url, $params);

	Logger::log('subscribe_to_hub: returns: ' . $postResult->getReturnCode(), Logger::DEBUG);

	return;

}
