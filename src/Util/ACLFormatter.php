<?php

namespace Friendica\Util;

use Friendica\Model\Group;

/**
 * Util class for ACL formatting
 */
final class ACLFormatter
{
	/**
	 * Turn user/group ACLs stored as angle bracketed text into arrays
	 *
	 * @param string|null $acl_string A angle-bracketed list of IDs
	 *
	 * @return array The array based on the IDs (empty in case there is no list)
	 */
	public function expand(string $acl_string = null)
	{
		// In case there is no ID list, return empty array (=> no ACL set)
		if (!isset($acl_string)) {
			return [];
		}

		// turn string array of angle-bracketed elements into numeric array
		// e.g. "<1><2><3>" => array(1,2,3);
		preg_match_all('/<(' . Group::FOLLOWERS . '|'. Group::MUTUALS . '|[0-9]+)>/', $acl_string, $matches, PREG_PATTERN_ORDER);

		return $matches[1];
	}

	/**
	 * Takes an arbitrary ACL string and sanitizes it for storage
	 *
	 * @param string|null $acl_string
	 * @return string
	 */
	public function sanitize(string $acl_string = null)
	{
		if (empty($acl_string)) {
			return '';
		}

		$cleaned_list = trim($acl_string, '<>');

		if (empty($cleaned_list)) {
			return '';
		}

		$elements = explode('><', $cleaned_list);

		sort($elements);

		array_walk($elements, [$this, 'sanitizeItem']);

		return implode('', $elements);
	}

	/**
	 * Wrap ACL elements in angle brackets for storage
	 *
	 * @param string $item The item to sanitise
	 */
	private function sanitizeItem(string &$item) {
		// The item is an ACL int value
		if (intval($item)) {
			$item = '<' . intval(Strings::escapeTags(trim($item))) . '>';
		// The item is a allowed ACL character
		} elseif (in_array($item, [Group::FOLLOWERS, Group::MUTUALS])) {
			$item = '<' . $item . '>';
		// The item is already a ACL string
		} elseif (preg_match('/<\d+?>/', $item)) {
			unset($item);
		// The item is not supported, so remove it (cleanup)
		} else {
			$item = '';
		}
	}

	/**
	 * Convert an ACL array to a storable string
	 *
	 * Normally ACL permissions will be an array.
	 * We'll also allow a comma-separated string.
	 *
	 * @param string|array $permissions
	 *
	 * @return string
	 */
	function toString($permissions) {
		$return = '';
		if (is_array($permissions)) {
			$item = $permissions;
		} else {
			$item = explode(',', $permissions);
		}

		if (is_array($item)) {
			array_walk($item, [$this, 'sanitizeItem']);
			$return = implode('', $item);
		}
		return $return;
	}
}
