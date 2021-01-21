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
	 * Select rows from the given view
	 *
	 * @param string $view      View (post-view or post-thread-view)
	 * @param array  $selected  Array of selected fields, empty for all
	 * @param array  $condition Array of fields for condition
	 * @param array  $params    Array of several parameters
	 *
	 * @return boolean|object
	 * @throws \Exception
	 */
	private static function selectView(string $view, array $selected = [], array $condition = [], $params = [])
	{
		if (empty($selected)) {
			$selected = array_merge(['author-addr', 'author-nick', 'owner-addr', 'owner-nick', 'causer-addr', 'causer-nick',
				'causer-network', 'photo', 'name-date', 'uri-date', 'avatar-date', 'thumb', 'dfrn-id',
				'parent-guid', 'parent-network', 'parent-author-id', 'parent-author-link', 'parent-author-name',
				'parent-author-network', 'signed_text'], Item::DISPLAY_FIELDLIST, Item::ITEM_FIELDLIST, Item::CONTENT_FIELDLIST);
			
			if ($view == 'post-thread-view') {
				$selected = array_merge($selected, ['ignored', 'iid']);
			}
		}

		$selected = array_unique($selected);

		return DBA::select($view, $selected, $condition, $params);
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
		return self::selectView('post-view', $selected, $condition, $params);
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
	public static function selectThread(array $selected = [], array $condition = [], $params = [])
	{
		return self::selectView('post-thread-view', $selected, $condition, $params);
	}

	/**
	 * Select rows from the given view for a given user
	 *
	 * @param string  $view      View (post-view or post-thread-view)
	 * @param integer $uid       User ID
	 * @param array   $selected  Array of selected fields, empty for all
	 * @param array   $condition Array of fields for condition
	 * @param array   $params    Array of several parameters
	 *
	 * @return boolean|object
	 * @throws \Exception
	 */
	private static function selectViewForUser(string $view, $uid, array $selected = [], array $condition = [], $params = [])
	{
		if (empty($selected)) {
			$selected = Item::DISPLAY_FIELDLIST;
		}

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

			$select_string = "(SELECT `pinned` FROM `user-item` WHERE `iid` = `" . $view . "`.`id` AND uid=`" . $view . "`.`uid`) AS `pinned`, ";
		}

		$select_string .= implode(', ', array_map([DBA::class, 'quoteIdentifier'], $selected));

		$condition_string = DBA::buildCondition($condition);
		$param_string = DBA::buildParameter($params);

		$sql = "SELECT " . $select_string . " FROM `" . $view . "` " . $condition_string . $param_string;
		$sql = DBA::cleanQuery($sql);

		return DBA::p($sql, $condition);
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
		return self::selectViewForUser('post-view', $uid, $selected, $condition, $params);
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
	public static function selectThreadForUser($uid, array $selected = [], array $condition = [], $params = [])
	{
		return self::selectViewForUser('post-thread-view', $uid, $selected, $condition, $params);
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

	/**
	 * Retrieve a single record from the starting post in the item table and returns it in an associative array
	 *
	 * @param integer $uid User ID
	 * @param array   $selected
	 * @param array   $condition
	 * @param array   $params
	 * @return bool|array
	 * @throws \Exception
	 * @see   DBA::select
	 */
	public static function selectFirstThreadForUser($uid, array $selected = [], array $condition = [], $params = [])
	{
		$params['limit'] = 1;

		$result = self::selectThreadForUser($uid, $selected, $condition, $params);

		if (is_bool($result)) {
			return $result;
		} else {
			$row = self::fetch($result);
			DBA::close($result);
			return $row;
		}
	}

	/**
	 * Select pinned rows from the item table for a given user
	 *
	 * @param integer $uid       User ID
	 * @param array   $selected  Array of selected fields, empty for all
	 * @param array   $condition Array of fields for condition
	 * @param array   $params    Array of several parameters
	 *
	 * @return boolean|object
	 * @throws \Exception
	 */
	public static function selectPinned(int $uid, array $selected = [], array $condition = [], $params = [])
	{
		$useritems = DBA::select('user-item', ['iid'], ['uid' => $uid, 'pinned' => true]);
		if (!DBA::isResult($useritems)) {
			return $useritems;
		}

		$pinned = [];
		while ($useritem = DBA::fetch($useritems)) {
			$pinned[] = $useritem['iid'];
		}
		DBA::close($useritems);

		if (empty($pinned)) {
			return [];
		}

		$condition = DBA::mergeConditions(['iid' => $pinned], $condition);

		return self::selectThreadForUser($uid, $selected, $condition, $params);
	}
}
