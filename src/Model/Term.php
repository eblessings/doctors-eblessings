<?php
/**
 * @file src/Model/Term
 */
namespace Friendica\Model;

use Friendica\Core\System;
use Friendica\Database\DBM;
use Friendica\Model\Item;
use dba;

require_once 'boot.php';
require_once 'include/conversation.php';
require_once 'include/dba.php';

class Term
{
	public static function insertFromTagFieldByItemId($itemid)
	{
		$profile_base = System::baseUrl();
		$profile_data = parse_url($profile_base);
		$profile_path = defaults($profile_data, 'path', '');
		$profile_base_friendica = $profile_data['host'] . $profile_path . '/profile/';
		$profile_base_diaspora = $profile_data['host'] . $profile_path . '/u/';

		$fields = ['guid', 'uid', 'id', 'edited', 'deleted', 'created', 'received', 'title', 'body', 'tag', 'parent'];
		$message = Item::selectFirst($fields, ['id' => $itemid]);
		if (!DBM::is_result($message)) {
			return;
		}

		// Clean up all tags
		dba::delete('term', ['otype' => TERM_OBJ_POST, 'oid' => $itemid, 'type' => [TERM_HASHTAG, TERM_MENTION]]);

		if ($message['deleted']) {
			return;
		}

		$taglist = explode(',', $message['tag']);

		$tags_string = '';
		foreach ($taglist as $tag) {
			if ((substr(trim($tag), 0, 1) == '#') || (substr(trim($tag), 0, 1) == '@')) {
				$tags_string .= ' ' . trim($tag);
			} else {
				$tags_string .= ' #' . trim($tag);
			}
		}

		$data = ' ' . $message['title'] . ' ' . $message['body'] . ' ' . $tags_string . ' ';

		// ignore anything in a code block
		$data = preg_replace('/\[code\](.*?)\[\/code\]/sm', '', $data);

		$tags = [];

		$pattern = '/\W\#([^\[].*?)[\s\'".,:;\?!\[\]\/]/ism';
		if (preg_match_all($pattern, $data, $matches)) {
			foreach ($matches[1] as $match) {
				$tags['#' . strtolower($match)] = '';
			}
		}

		$pattern = '/\W([\#@])\[url\=(.*?)\](.*?)\[\/url\]/ism';
		if (preg_match_all($pattern, $data, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$tags[$match[1] . strtolower(trim($match[3], ',.:;[]/\"?!'))] = $match[2];
			}
		}

		foreach ($tags as $tag => $link) {
			if (substr(trim($tag), 0, 1) == '#') {
				// try to ignore #039 or #1 or anything like that
				if (ctype_digit(substr(trim($tag), 1))) {
					continue;
				}

				// try to ignore html hex escapes, e.g. #x2317
				if ((substr(trim($tag), 1, 1) == 'x' || substr(trim($tag), 1, 1) == 'X') && ctype_digit(substr(trim($tag), 2))) {
					continue;
				}

				$type = TERM_HASHTAG;
				$term = substr($tag, 1);
			} elseif (substr(trim($tag), 0, 1) == '@') {
				$type = TERM_MENTION;
				$term = substr($tag, 1);
			} else { // This shouldn't happen
				$type = TERM_HASHTAG;
				$term = $tag;
			}

			if ($message['uid'] == 0) {
				$global = true;
				dba::update('term', ['global' => true], ['otype' => TERM_OBJ_POST, 'guid' => $message['guid']]);
			} else {
				$global = dba::exists('term', ['uid' => 0, 'otype' => TERM_OBJ_POST, 'guid' => $message['guid']]);
			}

			dba::insert('term', [
				'uid'      => $message['uid'],
				'oid'      => $itemid,
				'otype'    => TERM_OBJ_POST,
				'type'     => $type,
				'term'     => $term,
				'url'      => $link,
				'guid'     => $message['guid'],
				'created'  => $message['created'],
				'received' => $message['received'],
				'global'   => $global
			]);

			// Search for mentions
			if ((substr($tag, 0, 1) == '@') && (strpos($link, $profile_base_friendica) || strpos($link, $profile_base_diaspora))) {
				$users = q("SELECT `uid` FROM `contact` WHERE self AND (`url` = '%s' OR `nurl` = '%s')", $link, $link);
				foreach ($users AS $user) {
					if ($user['uid'] == $message['uid']) {
						/// @todo This function is called frim Item::update - so we mustn't call that function here
						dba::update('item', ['mention' => true], ['id' => $itemid]);
						dba::update('thread', ['mention' => true], ['iid' => $message['parent']]);
					}
				}
			}
		}
	}

	/**
	 * @param integer $itemid item id
	 * @return void
	 */
	public static function insertFromFileFieldByItemId($itemid)
	{
		$message = Item::selectFirst(['uid', 'deleted', 'file'], ['id' => $itemid]);
		if (!DBM::is_result($message)) {
			return;
		}

		// Clean up all tags
		dba::delete('term', ['otype' => TERM_OBJ_POST, 'oid' => $itemid, 'type' => [TERM_FILE, TERM_CATEGORY]]);

		if ($message["deleted"]) {
			return;
		}

		if (preg_match_all("/\[(.*?)\]/ism", $message["file"], $files)) {
			foreach ($files[1] as $file) {
				dba::insert('term', [
					'uid' => $message["uid"],
					'oid' => $itemid,
					'otype' => TERM_OBJ_POST,
					'type' => TERM_FILE,
					'term' => $file
				]);
			}
		}

		if (preg_match_all("/\<(.*?)\>/ism", $message["file"], $files)) {
			foreach ($files[1] as $file) {
				dba::insert('term', [
					'uid' => $message["uid"],
					'oid' => $itemid,
					'otype' => TERM_OBJ_POST,
					'type' => TERM_CATEGORY,
					'term' => $file
				]);
			}
		}
	}

	/**
	 * Sorts an item's tags into mentions, hashtags and other tags. Generate personalized URLs by user and modify the
	 * provided item's body with them.
	 *
	 * @param array $item
	 * @return array
	 */
	public static function populateTagsFromItem(&$item)
	{
		$return = [
			'tags' => [],
			'hashtags' => [],
			'mentions' => [],
		];

		$searchpath = System::baseUrl() . "/search?tag=";

		$taglist = dba::select(
			'term',
			['type', 'term', 'url'],
			["`otype` = ? AND `oid` = ? AND `type` IN (?, ?)", TERM_OBJ_POST, $item['id'], TERM_HASHTAG, TERM_MENTION],
			['order' => ['tid']]
		);

		while ($tag = dba::fetch($taglist)) {
			if ($tag["url"] == "") {
				$tag["url"] = $searchpath . strtolower($tag["term"]);
			}

			$orig_tag = $tag["url"];

			$tag["url"] = Contact::magicLinkById($item['author-id'], $tag['url']);

			if ($tag["type"] == TERM_HASHTAG) {
				if ($orig_tag != $tag["url"]) {
					$item['body'] = str_replace($orig_tag, $tag["url"], $item['body']);
				}

				$return['hashtags'][] = "#<a href=\"" . $tag["url"] . "\" target=\"_blank\">" . $tag["term"] . "</a>";
				$prefix = "#";
			} elseif ($tag["type"] == TERM_MENTION) {
				$return['mentions'][] = "@<a href=\"" . $tag["url"] . "\" target=\"_blank\">" . $tag["term"] . "</a>";
				$prefix = "@";
			}

			$return['tags'][] = $prefix . "<a href=\"" . $tag["url"] . "\" target=\"_blank\">" . $tag["term"] . "</a>";
		}
		dba::close($taglist);

		return $return;
	}
}
