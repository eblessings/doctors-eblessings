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

namespace Friendica\Model;

use Friendica\Content\Text\BBCode;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Util\Strings;

/**
 * Class Tag
 *
 * This Model class handles tag table interactions.
 * This tables stores relevant tags related to posts, like hashtags and mentions.
 */
class Tag
{
	const UNKNOWN  = 0;
	const HASHTAG  = 1;
	const MENTION  = 2;
	const CATEGORY = 3;
	const FILE     = 5;
	/**
	 * An implicit mention is a mention in a comment body that is redundant with the threading information.
	 */
	const IMPLICIT_MENTION  = 8;
	/**
	 * An exclusive mention transfers the ownership of the post to the target account, usually a forum.
	 */
	const EXCLUSIVE_MENTION = 9;

	const TAG_CHARACTER = [
		self::HASHTAG           => '#',
		self::MENTION           => '@',
		self::IMPLICIT_MENTION  => '%',
		self::EXCLUSIVE_MENTION => '!',
	];

	/**
	 * Store tag/mention elements
	 *
	 * @param integer $uriid
	 * @param integer $type
	 * @param string  $name
	 * @param string  $url
	 * @param boolean $probing
	 */
	public static function store(int $uriid, int $type, string $name, string $url = '', $probing = true)
	{
		if ($type == self::HASHTAG) {
			// Remove some common "garbarge" from tags
			$name = trim($name, "\x00..\x20\xFF#!@,;.:'/?!^°$%".'"');

			$tags = explode(self::TAG_CHARACTER[self::HASHTAG], $name);
			if (count($tags) > 1) {
				foreach ($tags as $tag) {
					self::store($uriid, $type, $tag, $url, $probing);
				}
				return;
			}
		}

		if (empty($name)) {
			return;
		}

		$cid = 0;
		$tagid = 0;

		if (in_array($type, [self::MENTION, self::EXCLUSIVE_MENTION, self::IMPLICIT_MENTION])) {
			if (empty($url)) {
				// No mention without a contact url
				return;
			}

			if (!$probing) {
				$condition = ['nurl' => Strings::normaliseLink($url), 'uid' => 0, 'deleted' => false];
				$contact = DBA::selectFirst('contact', ['id'], $condition, ['order' => ['id']]);
				if (DBA::isResult($contact)) {
					$cid = $contact['id'];
					Logger::info('Got id for contact url', ['cid' => $cid, 'url' => $url]);
				}

				if (empty($cid)) {
					$ssl_url = str_replace('http://', 'https://', $url);
					$condition = ['`alias` IN (?, ?, ?) AND `uid` = ? AND NOT `deleted`', $url, Strings::normaliseLink($url), $ssl_url, 0];
					$contact = DBA::selectFirst('contact', ['id'], $condition, ['order' => ['id']]);
					if (DBA::isResult($contact)) {
						$cid = $contact['id'];
						Logger::info('Got id for contact alias', ['cid' => $cid, 'url' => $url]);
					}
				}
			} else {
				$cid = Contact::getIdForURL($url, 0, true);
				Logger::info('Got id by probing', ['cid' => $cid, 'url' => $url]);
			}

			if (empty($cid)) {
				// The contact wasn't found in the system (most likely some dead account)
				// We ensure that we only store a single entry by overwriting the previous name
				Logger::info('Contact not found, updating tag', ['url' => $url, 'name' => $name]);
				DBA::update('tag', ['name' => substr($name, 0, 96)], ['url' => $url]);
			}
		}

		if (empty($cid)) {
			$fields = ['name' => substr($name, 0, 96), 'url' => ''];

			if (($type != self::HASHTAG) && !empty($url) && ($url != $name)) {
				$fields['url'] = strtolower($url);
			}

			$tag = DBA::selectFirst('tag', ['id'], $fields);
			if (!DBA::isResult($tag)) {
				DBA::insert('tag', $fields, true);
				$tagid = DBA::lastInsertId();
			} else {
				$tagid = $tag['id'];
			}

			if (empty($tagid)) {
				Logger::error('No tag id created', $fields);
				return;
			}
		}

		$fields = ['uri-id' => $uriid, 'type' => $type, 'tid' => $tagid, 'cid' => $cid];

		if (in_array($type, [self::MENTION, self::EXCLUSIVE_MENTION, self::IMPLICIT_MENTION])) {
			$condition = $fields;
			$condition['type'] = [self::MENTION, self::EXCLUSIVE_MENTION, self::IMPLICIT_MENTION];
			if (DBA::exists('post-tag', $condition)) {
				Logger::info('Tag already exists', $fields);
				return;
			}
		}

		DBA::insert('post-tag', $fields, true);

		Logger::info('Stored tag/mention', ['uri-id' => $uriid, 'tag-id' => $tagid, 'contact-id' => $cid, 'name' => $name, 'type' => $type, 'callstack' => System::callstack(8)]);
	}

	/**
	 * Store tag/mention elements
	 *
	 * @param integer $uriid
	 * @param string $hash
	 * @param string $name
	 * @param string $url
	 * @param boolean $probing
	 */
	public static function storeByHash(int $uriid, string $hash, string $name, string $url = '', $probing = true)
	{
		$type = self::getTypeForHash($hash);
		if ($type == self::UNKNOWN) {
			return;
		}

		self::store($uriid, $type, $name, $url, $probing);
	}

	/**
	 * Store tags and mentions from the body
	 * 
	 * @param integer $uriid   URI-Id
	 * @param string  $body    Body of the post
	 * @param string  $tags    Accepted tags
	 * @param boolean $probing Perform a probing for contacts, adding them if needed
	 */
	public static function storeFromBody(int $uriid, string $body, string $tags = null, $probing = true)
	{
		if (is_null($tags)) {
			$tags =  self::TAG_CHARACTER[self::HASHTAG] . self::TAG_CHARACTER[self::MENTION] . self::TAG_CHARACTER[self::EXCLUSIVE_MENTION];
		}

		Logger::info('Check for tags', ['uri-id' => $uriid, 'hash' => $tags, 'callstack' => System::callstack()]);

		if (!preg_match_all("/([" . $tags . "])\[url\=([^\[\]]*)\]([^\[\]]*)\[\/url\]/ism", $body, $result, PREG_SET_ORDER)) {
			return;
		}

		Logger::info('Found tags', ['uri-id' => $uriid, 'hash' => $tags, 'result' => $result]);

		foreach ($result as $tag) {
			self::storeByHash($uriid, $tag[1], $tag[3], $tag[2], $probing);
		}
	}

	/**
	 * Store raw tags (not encapsulated in links) from the body
	 * This function is needed in the intermediate phase.
	 * Later we can call item::setHashtags in advance to have all tags converted.
	 * 
	 * @param integer $uriid URI-Id
	 * @param string  $body   Body of the post
	 */
	public static function storeRawTagsFromBody(int $uriid, string $body)
	{
		Logger::info('Check for tags', ['uri-id' => $uriid, 'callstack' => System::callstack()]);

		$result = BBCode::getTags($body);
		if (empty($result)) {
			return;
		}

		Logger::info('Found tags', ['uri-id' => $uriid, 'result' => $result]);

		foreach ($result as $tag) {
			if (substr($tag, 0, 1) != self::TAG_CHARACTER[self::HASHTAG]) {
				continue;
			}
			self::storeByHash($uriid, substr($tag, 0, 1), substr($tag, 1));
		}
	}

	/**
	 * Checks for stored hashtags and mentions for the given post
	 *
	 * @param integer $uriid
	 * @return bool
	 */
	public static function existsForPost(int $uriid)
	{
		return DBA::exists('post-tag', ['uri-id' => $uriid, 'type' => [self::HASHTAG, self::MENTION, self::IMPLICIT_MENTION, self::EXCLUSIVE_MENTION]]);
	}

	/**
	 * Remove tag/mention
	 *
	 * @param integer $uriid
	 * @param integer $type
	 * @param string $name
	 * @param string $url
	 */
	public static function remove(int $uriid, int $type, string $name, string $url = '')
	{
		$condition = ['uri-id' => $uriid, 'type' => $type, 'url' => $url];
		if ($type == self::HASHTAG) {
			$condition['name'] = $name;
		}

		$tag = DBA::selectFirst('tag-view', ['tid', 'cid'], $condition);
		if (!DBA::isResult($tag)) {
			return;
		}

		Logger::info('Removing tag/mention', ['uri-id' => $uriid, 'tid' => $tag['tid'], 'name' => $name, 'url' => $url, 'callstack' => System::callstack(8)]);
		DBA::delete('post-tag', ['uri-id' => $uriid, 'type' => $type, 'tid' => $tag['tid'], 'cid' => $tag['cid']]);
	}

	/**
	 * Remove tag/mention
	 *
	 * @param integer $uriid
	 * @param string $hash
	 * @param string $name
	 * @param string $url
	 */
	public static function removeByHash(int $uriid, string $hash, string $name, string $url = '')
	{
		$type = self::getTypeForHash($hash);
		if ($type == self::UNKNOWN) {
			return;
		}

		self::remove($uriid, $type, $name, $url);
	}

	/**
	 * Get the type for the given hash
	 *
	 * @param string $hash
	 * @return integer type
	 */
	private static function getTypeForHash(string $hash)
	{
		if ($hash == self::TAG_CHARACTER[self::MENTION]) {
			return self::MENTION;
		} elseif ($hash == self::TAG_CHARACTER[self::EXCLUSIVE_MENTION]) {
			return self::EXCLUSIVE_MENTION;
		} elseif ($hash == self::TAG_CHARACTER[self::IMPLICIT_MENTION]) {
			return self::IMPLICIT_MENTION;
		} elseif ($hash == self::TAG_CHARACTER[self::HASHTAG]) {
			return self::HASHTAG;
		} else {
			return self::UNKNOWN;
		}
	}

	/**
	 * Retrieves the terms from the provided type(s) associated with the provided item ID.
	 *
	 * @param int       $item_id
	 * @param int|array $type
	 * @return array
	 * @throws \Exception
	 */
	public static function getByURIId(int $uri_id, array $type = [self::HASHTAG, self::MENTION, self::IMPLICIT_MENTION, self::EXCLUSIVE_MENTION])
	{
		$condition = ['uri-id' => $uri_id, 'type' => $type];
		$tags = DBA::select('tag-view', ['type', 'name', 'url'], $condition);
		if (!DBA::isResult($tags)) {
			return [];
		}

		$tag_list = [];
		while ($tag = DBA::fetch($tags)) {
			$tag['term'] = $tag['name']; /// @todo Remove this line when all occurrences of "term" had been replaced with "name"
			$tag_list[] = $tag;
		}

		return $tag_list;
	}

	/**
	 * Sorts an item's tags into mentions, hashtags and other tags. Generate personalized URLs by user and modify the
	 * provided item's body with them.
	 *
	 * @param array $item
	 * @return array
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function populateFromItem(&$item)
	{
		$return = [
			'tags' => [],
			'hashtags' => [],
			'mentions' => [],
			'implicit_mentions' => [],
		];

		$searchpath = DI::baseUrl() . "/search?tag=";

		$taglist = DBA::select('tag-view', ['type', 'name', 'url'],
			['uri-id' => $item['uri-id'], 'type' => [self::HASHTAG, self::MENTION, self::EXCLUSIVE_MENTION, self::IMPLICIT_MENTION]]);
		while ($tag = DBA::fetch($taglist)) {
			if ($tag['url'] == '') {
				$tag['url'] = $searchpath . rawurlencode($tag['name']);
			}

			$orig_tag = $tag['url'];

			$prefix = self::TAG_CHARACTER[$tag['type']];
			switch($tag['type']) {
				case self::HASHTAG:
					if ($orig_tag != $tag['url']) {
						$item['body'] = str_replace($orig_tag, $tag['url'], $item['body']);
					}

					$return['hashtags'][] = $prefix . '<a href="' . $tag['url'] . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($tag['name']) . '</a>';
					$return['tags'][] = $prefix . '<a href="' . $tag['url'] . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($tag['name']) . '</a>';
					break;
				case self::MENTION:
				case self::EXCLUSIVE_MENTION:
						$tag['url'] = Contact::magicLink($tag['url']);
					$return['mentions'][] = $prefix . '<a href="' . $tag['url'] . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($tag['name']) . '</a>';
					$return['tags'][] = $prefix . '<a href="' . $tag['url'] . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($tag['name']) . '</a>';
					break;
				case self::IMPLICIT_MENTION:
					$return['implicit_mentions'][] = $prefix . $tag['name'];
					break;
			}
		}
		DBA::close($taglist);

		return $return;
	}

	/**
	 * Search posts for given tag
	 *
	 * @param string $search
	 * @param integer $uid
	 * @param integer $start
	 * @param integer $limit
	 * @return array with URI-ID
	 */
	public static function getURIIdListForTag(string $search, int $uid = 0, int $start = 0, int $limit = 100)
	{
		$condition = ["`name` = ? AND (NOT `private` OR (`private` AND `uid` = ?))", $search, $uid];
		$params = [
			'order' => ['uri-id' => true],
			'group_by' => ['uri-id'],
			'limit' => [$start, $limit]
		];

		$tags = DBA::select('tag-search-view', ['uri-id'], $condition, $params);

		$uriids = [];
		while ($tag = DBA::fetch($tags)) {
			$uriids[] = $tag['uri-id'];
		}
		DBA::close($tags);

		return $uriids;
	}
}
