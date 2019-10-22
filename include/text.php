<?php
/**
 * @file include/text.php
 */

use Friendica\App;
use Friendica\Content\Text\BBCode;
use Friendica\Core\Protocol;
use Friendica\Model\FileTag;
use Friendica\Model\Group;
use Friendica\Util\Strings;

/**
 * Turn user/group ACLs stored as angle bracketed text into arrays
 *
 * @param string $s
 * @return array
 */
function expand_acl($s) {
	// turn string array of angle-bracketed elements into numeric array
	// e.g. "<1><2><3>" => array(1,2,3);
	preg_match_all('/<(' . Group::FOLLOWERS . '|'. Group::MUTUALS . '|[0-9]+)>/', $s, $matches, PREG_PATTERN_ORDER);

	return $matches[1];
}


/**
 * Wrap ACL elements in angle brackets for storage
 * @param string $item
 */
function sanitise_acl(&$item) {
	if (intval($item)) {
		$item = '<' . intval(Strings::escapeTags(trim($item))) . '>';
	} elseif (in_array($item, [Group::FOLLOWERS, Group::MUTUALS])) {
		$item = '<' . $item . '>';
	} else {
		unset($item);
	}
}


/**
 * Convert an ACL array to a storable string
 *
 * Normally ACL permissions will be an array.
 * We'll also allow a comma-separated string.
 *
 * @param string|array $p
 * @return string
 */
function perms2str($p) {
	$ret = '';
	if (is_array($p)) {
		$tmp = $p;
	} else {
		$tmp = explode(',', $p);
	}

	if (is_array($tmp)) {
		array_walk($tmp, 'sanitise_acl');
		$ret = implode('', $tmp);
	}
	return $ret;
}

/**
 *  for html,xml parsing - let's say you've got
 *  an attribute foobar="class1 class2 class3"
 *  and you want to find out if it contains 'class3'.
 *  you can't use a normal sub string search because you
 *  might match 'notclass3' and a regex to do the job is
 *  possible but a bit complicated.
 *  pass the attribute string as $attr and the attribute you
 *  are looking for as $s - returns true if found, otherwise false
 *
 * @param string $attr attribute value
 * @param string $s string to search
 * @return boolean True if found, False otherwise
 */
function attribute_contains($attr, $s) {
	$a = explode(' ', $attr);
	return (count($a) && in_array($s,$a));
}

/**
 * Compare activity uri. Knows about activity namespace.
 *
 * @param string $haystack
 * @param string $needle
 * @return boolean
 */
function activity_match($haystack,$needle) {
	return (($haystack === $needle) || ((basename($needle) === $haystack) && strstr($needle, NAMESPACE_ACTIVITY_SCHEMA)));
}

/**
 * quick and dirty quoted_printable encoding
 *
 * @param string $s
 * @return string
 */
function qp($s) {
	return str_replace("%", "=", rawurlencode($s));
}

/**
 * @brief Given a text string, convert from bbcode to html and add smilie icons.
 *
 * @param string $text String with bbcode.
 * @return string Formatted HTML
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function prepare_text($text)
{
	$s = BBCode::convert($text);
	return trim($s);
}

/**
 * return array with details for categories and folders for an item
 *
 * @param array $item
 * @return array
 *
  * [
 *      [ // categories array
 *          {
 *               'name': 'category name',
 *               'removeurl': 'url to remove this category',
 *               'first': 'is the first in this array? true/false',
 *               'last': 'is the last in this array? true/false',
 *           } ,
 *           ....
 *       ],
 *       [ //folders array
 *			{
 *               'name': 'folder name',
 *               'removeurl': 'url to remove this folder',
 *               'first': 'is the first in this array? true/false',
 *               'last': 'is the last in this array? true/false',
 *           } ,
 *           ....
 *       ]
 *  ]
 */
function get_cats_and_terms($item)
{
	$categories = [];
	$folders = [];
	$first = true;

	foreach (FileTag::fileToArray($item['file'] ?? '', 'category') as $savedFolderName) {
		$categories[] = [
			'name' => $savedFolderName,
			'url' => "#",
			'removeurl' => ((local_user() == $item['uid']) ? 'filerm/' . $item['id'] . '?f=&cat=' . rawurlencode($savedFolderName) : ""),
			'first' => $first,
			'last' => false
		];
		$first = false;
	}

	if (count($categories)) {
		$categories[count($categories) - 1]['last'] = true;
	}

	if (local_user() == $item['uid']) {
		foreach (FileTag::fileToArray($item['file'] ?? '') as $savedFolderName) {
			$folders[] = [
				'name' => $savedFolderName,
				'url' => "#",
				'removeurl' => ((local_user() == $item['uid']) ? 'filerm/' . $item['id'] . '?f=&term=' . rawurlencode($savedFolderName) : ""),
				'first' => $first,
				'last' => false
			];
			$first = false;
		}
	}

	if (count($folders)) {
		$folders[count($folders) - 1]['last'] = true;
	}

	return [$categories, $folders];
}

/// @TODO Rewrite this
function is_a_date_arg($s) {
	$i = intval($s);

	if ($i > 1900) {
		$y = date('Y');

		if ($i <= $y + 1 && strpos($s, '-') == 4) {
			$m = intval(substr($s, 5));

			if ($m > 0 && $m <= 12) {
				return true;
			}
		}
	}

	return false;
}
