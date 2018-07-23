<?php
/**
 * @file src/Model/PermissionSet.php
 */
namespace Friendica\Model;

use Friendica\BaseObject;
use Friendica\Database\DBA;

require_once 'include/dba.php';

/**
 * @brief functions for interacting with the permission set of an object (item, photo, event, ...)
 */
class PermissionSet extends BaseObject
{
	/**
	 * Fetch the id of a given permission set. Generate a new one when needed
	 *
	 * @param array $postarray The array from an item, picture or event post
	 * @return id
	 */
	public static function fetchIDForPost($postarray)
	{
		$condition = ['uid' => $postarray['uid'],
			'allow_cid' => self::sortPermissions(defaults($postarray, 'allow_cid', '')),
			'allow_gid' => self::sortPermissions(defaults($postarray, 'allow_gid', '')),
			'deny_cid' => self::sortPermissions(defaults($postarray, 'deny_cid', '')),
			'deny_gid' => self::sortPermissions(defaults($postarray, 'deny_gid', ''))];

		$set = DBA::selectFirst('permissionset', ['id'], $condition);

		if (!DBA::is_result($set)) {
			DBA::insert('permissionset', $condition, true);

			$set = DBA::selectFirst('permissionset', ['id'], $condition);
		}
		return $set['id'];
	}

	private static function sortPermissions($permissionlist)
	{
		$cleaned_list = trim($permissionlist, '<>');

		if (empty($cleaned_list)) {
			return $permissionlist;
		}

		$elements = explode('><', $cleaned_list);

		if (count($elements) <= 1) {
			return $permissionlist;
		}

		asort($elements);

		return '<' . implode('><', $elements) . '>';
	}
}
