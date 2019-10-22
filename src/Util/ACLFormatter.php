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
	 * @param string $ids A angle-bracketed list of IDs
	 *
	 * @return array The array based on the IDs
	 */
	public function expand(string $ids)
	{
		// turn string array of angle-bracketed elements into numeric array
		// e.g. "<1><2><3>" => array(1,2,3);
		preg_match_all('/<(' . Group::FOLLOWERS . '|'. Group::MUTUALS . '|[0-9]+)>/', $ids, $matches, PREG_PATTERN_ORDER);

		return $matches[1];
	}
}
