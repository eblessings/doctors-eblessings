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

use Friendica\Database\DBA;
use Friendica\Protocol\Activity;

class Post
{
	/**
	 * Fetch a single post row
	 *
	 * @param mixed $stmt statement object
	 * @return array|false current row or false
	 * @throws \Exception
	 */
	public static function fetch($stmt)
	{
		$row = DBA::fetch($stmt);

		if (!is_array($row)) {
			return $row;
		}

		if (array_key_exists('verb', $row)) {
			if (in_array($row['verb'], Item::ACTIVITIES)) {
				if (array_key_exists('title', $row)) {
					$row['title'] = '';
				}
				if (array_key_exists('body', $row)) {
					$row['body'] = $row['verb'];
				}
				if (array_key_exists('object', $row)) {
					$row['object'] = '';
				}
				if (array_key_exists('object-type', $row)) {
					$row['object-type'] = Activity\ObjectType::NOTE;
				}
			} elseif (in_array($row['verb'], ['', Activity::POST, Activity::SHARE])) {
				// Posts don't have a target - but having tags or files.
				if (array_key_exists('target', $row)) {
					$row['target'] = '';
				}
			}
		}

		if (!array_key_exists('verb', $row) || in_array($row['verb'], ['', Activity::POST, Activity::SHARE])) {
			// Build the file string out of the term entries
			if (array_key_exists('file', $row)) {
				if ($row['internal-file-count'] > 0) {
					$row['file'] = Post\Category::getTextByURIId($row['internal-uri-id'], $row['internal-uid']);
				} else {
					$row['file'] = '';
				}
			}
		}

		// Remove internal fields
		unset($row['internal-file-count']);
		unset($row['internal-uri-id']);
		unset($row['internal-uid']);

		return $row;
	}

	/**
	 * Fills an array with data from an post query
	 *
	 * @param object $stmt statement object
	 * @param bool   $do_close
	 * @return array Data array
	 */
	public static function toArray($stmt, $do_close = true) {
		if (is_bool($stmt)) {
			return $stmt;
		}

		$data = [];
		while ($row = self::fetch($stmt)) {
			$data[] = $row;
		}
		if ($do_close) {
			DBA::close($stmt);
		}
		return $data;
	}

	/**
	 * Check if post data exists
	 *
	 * @param array $condition array of fields for condition
	 *
	 * @return boolean Are there rows for that condition?
	 * @throws \Exception
	 */
	public static function exists($condition) {
		return DBA::exists('post-view', $condition);
	}

	/**
	 * Counts the posts satisfying the provided condition
	 *
	 * @param array        $condition array of fields for condition
	 * @param array        $params    Array of several parameters
	 *
	 * @return int
	 *
	 * Example:
	 * $condition = ["uid" => 1, "network" => 'dspr'];
	 * or:
	 * $condition = ["`uid` = ? AND `network` IN (?, ?)", 1, 'dfrn', 'dspr'];
	 *
	 * $count = Post::count($condition);
	 * @throws \Exception
	 */
	public static function count(array $condition = [], array $params = [])
	{
		return DBA::count('post-view', $condition, $params);
	}

	/**
	 * Retrieve a single record from the post table and returns it in an associative array
	 *
	 * @param array $fields
	 * @param array $condition
	 * @param array $params
	 * @return bool|array
	 * @throws \Exception
	 * @see   DBA::select
	 */
	public static function selectFirst(array $fields = [], array $condition = [], $params = [])
	{
		$params['limit'] = 1;

		$result = self::select($fields, $condition, $params);

		if (is_bool($result)) {
			return $result;
		} else {
			$row = self::fetch($result);
			DBA::close($result);
			return $row;
		}
	}

	/**
	 * Select rows from the post table and returns them as an array
	 *
	 * @param array $selected  Array of selected fields, empty for all
	 * @param array $condition Array of fields for condition
	 * @param array $params    Array of several parameters
	 *
	 * @return array
	 * @throws \Exception
	 */
	public static function selectToArray(array $fields = [], array $condition = [], $params = [])
	{
		$result = self::select($fields, $condition, $params);

		if (is_bool($result)) {
			return [];
		}

		$data = [];
		while ($row = self::fetch($result)) {
			$data[] = $row;
		}
		DBA::close($result);

		return $data;
	}

	/**
	 * Select rows from the post table
	 *
	 * @param array $selected  Array of selected fields, empty for all
	 * @param array $condition Array of fields for condition
	 * @param array $params    Array of several parameters
	 *
	 * @return boolean|object
	 * @throws \Exception
	 */
	public static function select(array $selected = [], array $condition = [], $params = [])
	{
		if (empty($selected)) {
			$selected = array_merge(['author-addr', 'author-nick', 'owner-addr', 'owner-nick', 'causer-addr', 'causer-nick',
				'causer-network', 'photo', 'name-date', 'uri-date', 'avatar-date', 'thumb', 'dfrn-id',
				'parent-guid', 'parent-network', 'parent-author-id', 'parent-author-link', 'parent-author-name',
				'parent-author-network', 'signed_text'], Item::DISPLAY_FIELDLIST, Item::ITEM_FIELDLIST, Item::CONTENT_FIELDLIST);
		}

		$selected = array_merge($selected, ['internal-uri-id', 'internal-uid', 'internal-file-count']);
		$selected = array_unique($selected);

		return DBA::select('post-view', $selected, $condition, $params);
	}

	/**
	 * Select rows from the post view for a given user
	 *
	 * @param integer $uid       User ID
	 * @param array   $selected  Array of selected fields, empty for all
	 * @param array   $condition Array of fields for condition
	 * @param array   $params    Array of several parameters
	 *
	 * @return boolean|object
	 * @throws \Exception
	 */
	public static function selectForUser($uid, array $selected = [], array $condition = [], $params = [])
	{
		if (empty($selected)) {
			$selected = Item::DISPLAY_FIELDLIST;
		}

		$selected = array_unique(array_merge($selected, ['internal-uri-id', 'internal-uid', 'internal-file-count']));

		$condition = DBA::mergeConditions($condition,
			["`visible` AND NOT `deleted` AND NOT `moderated`
			AND NOT `author-blocked` AND NOT `owner-blocked`
			AND (NOT `causer-blocked` OR `causer-id` = ?) AND NOT `contact-blocked`
			AND ((NOT `contact-readonly` AND NOT `contact-pending` AND (`contact-rel` IN (?, ?)))
				OR `self` OR `gravity` != ? OR `contact-uid` = ?)
			AND NOT EXISTS (SELECT `iid` FROM `user-item` WHERE `hidden` AND `iid` = `id` AND `uid` = ?)
			AND NOT EXISTS (SELECT `cid` FROM `user-contact` WHERE `uid` = ? AND `cid` = `author-id` AND `blocked`)
			AND NOT EXISTS (SELECT `cid` FROM `user-contact` WHERE `uid` = ? AND `cid` = `owner-id` AND `blocked`)
			AND NOT EXISTS (SELECT `cid` FROM `user-contact` WHERE `uid` = ? AND `cid` = `author-id` AND `ignored` AND `gravity` = ?)
			AND NOT EXISTS (SELECT `cid` FROM `user-contact` WHERE `uid` = ? AND `cid` = `owner-id` AND `ignored` AND `gravity` = ?)",
			0, Contact::SHARING, Contact::FRIEND, GRAVITY_PARENT, 0, $uid, $uid, $uid, $uid, GRAVITY_PARENT, $uid, GRAVITY_PARENT]);

		$select_string = '';

		if (in_array('pinned', $selected)) {
			$selected = array_flip($selected);
			unset($selected['pinned']);
			$selected = array_flip($selected);	

			$select_string = '(SELECT `pinned` FROM `user-item` WHERE `iid` = `post-view`.`id` AND uid=`post-view`.`uid`) AS `pinned`, ';
		}

		$select_string .= implode(', ', array_map([DBA::class, 'quoteIdentifier'], $selected));

		$condition_string = DBA::buildCondition($condition);
		$param_string = DBA::buildParameter($params);

		$sql = "SELECT " . $select_string . " FROM `post-view` " . $condition_string . $param_string;
		$sql = DBA::cleanQuery($sql);

		return DBA::p($sql, $condition);
	}

	/**
	 * Retrieve a single record from the post view for a given user and returns it in an associative array
	 *
	 * @param integer $uid User ID
	 * @param array   $selected
	 * @param array   $condition
	 * @param array   $params
	 * @return bool|array
	 * @throws \Exception
	 * @see   DBA::select
	 */
	public static function selectFirstForUser($uid, array $selected = [], array $condition = [], $params = [])
	{
		$params['limit'] = 1;

		$result = self::selectForUser($uid, $selected, $condition, $params);

		if (is_bool($result)) {
			return $result;
		} else {
			$row = self::fetch($result);
			DBA::close($result);
			return $row;
		}
	}
}
