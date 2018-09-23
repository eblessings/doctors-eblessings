<?php

/*
 * @file src/Content/Widget/TagCloud.php
 */

namespace Friendica\Content\Widget;

use Friendica\Core\L10n;
use Friendica\Core\System;
use Friendica\Database\DBA;

require_once 'include/dba.php';
require_once 'include/security.php';

/**
 * TagCloud widget
 *
 * @author Rabuzarus
 */
class TagCloud
{
	/**
	 * Construct a tag/term cloud block for an user.
	 *
	 * @brief Construct a tag/term cloud block for an user.
	 * @param int $uid      The user ID.
	 * @param int $count    Max number of displayed tags/terms.
	 * @param int $owner_id The contact ID of the owner of the tagged items.
	 * @param string $flags Special item flags.
	 * @param int $type     The tag/term type.
	 *
	 * @return string       HTML formatted output.
	 */
	public static function getHTML($uid, $count = 0, $owner_id = 0, $flags = '', $type = TERM_HASHTAG)
	{
		$o = '';
		$r = self::tagadelic($uid, $count, $owner_id, $flags, $type);
		if (count($r)) {
			$contact = DBA::selectFirst('contact', ['url'], ['uid' => $uid, 'self' => true]);
			$url = System::removedBaseUrl($contact['url']);

			foreach ($r as $rr) {
				$tag['level'] = $rr[2];
				$tag['url'] = $url . '?tag=' . urlencode($rr[0]);
				$tag['name'] = $rr[0];

				$tags[] = $tag;
			}

			$tpl = get_markup_template('tagblock_widget.tpl');
			$o = replace_macros($tpl, [
				'$title' => L10n::t('Tags'),
				'$tags' => $tags
			]);
		}
		return $o;
	}

	/**
	 * Get alphabetical sorted array of used tags/terms of an user including
	 * a weighting by frequency of use.
	 *
	 * @brief Get alphabetical sorted array of used tags/terms of an user including
	 * a weighting by frequency of use.
	 * @param int $uid      The user ID.
	 * @param int $count    Max number of displayed tags/terms.
	 * @param int $owner_id The contact id of the owner of the tagged items.
	 * @param string $flags Special item flags.
	 * @param int $type     The tag/term type.
	 *
	 * @return arr          Alphabetical sorted array of used tags of an user.
	 */
	private static function tagadelic($uid, $count = 0, $owner_id = 0, $flags = '', $type = TERM_HASHTAG)
	{
		$sql_options = item_permissions_sql($uid);
		$limit = $count ? sprintf('LIMIT %d', intval($count)) : '';

		if ($flags) {
			if ($flags === 'wall') {
				$sql_options .= ' AND `item`.`wall` ';
			}
		}

		if ($owner_id) {
			$sql_options .= ' AND `item`.`owner-id` = ' . intval($owner_id) . ' ';
		}

		// Fetch tags
		$r = DBA::p("SELECT `term`, COUNT(`term`) AS `total` FROM `term`
			LEFT JOIN `item` ON `term`.`oid` = `item`.`id`
			WHERE `term`.`uid` = ? AND `term`.`type` = ?
			AND `term`.`otype` = ?
			AND `item`.`visible` AND NOT `item`.`deleted` AND NOT `item`.`moderated`
			$sql_options
			GROUP BY `term` ORDER BY `total` DESC $limit",
			$uid,
			$type,
			TERM_OBJ_POST
		);
		if (!DBA::isResult($r)) {
			return [];
		}

		return self::tagCalc($r);
	}

	/**
	 * Calculate weighting of tags according to the frequency of use.
	 *
	 * @brief Calculate weighting of tags according to the frequency of use.
	 * @param array $arr Array of tags/terms with tag/term name and total count of use.
	 * @return array     Alphabetical sorted array of used tags/terms of an user.
	 */
	private static function tagCalc($arr)
	{
		$tags = [];
		$min = 1e9;
		$max = -1e9;
		$x = 0;

		if (!$arr) {
			return [];
		}

		foreach ($arr as $rr) {
			$tags[$x][0] = $rr['term'];
			$tags[$x][1] = log($rr['total']);
			$tags[$x][2] = 0;
			$min = min($min, $tags[$x][1]);
			$max = max($max, $tags[$x][1]);
			$x ++;
		}

		usort($tags, 'self::tagsSort');
		$range = max(.01, $max - $min) * 1.0001;

		for ($x = 0; $x < count($tags); $x ++) {
			$tags[$x][2] = 1 + floor(9 * ($tags[$x][1] - $min) / $range);
		}

		return $tags;
	}

	/**
	 * Compare function to sort tags/terms alphabetically.
	 *
	 * @brief Compare function to sort tags/terms alphabetically.
	 * @param type $a
	 * @param type $b
	 *
	 * @return int
	 */
	private static function tagsSort($a, $b)
	{
		if (strtolower($a[0]) == strtolower($b[0])) {
			return 0;
		}
		return ((strtolower($a[0]) < strtolower($b[0])) ? -1 : 1);
	}
}
