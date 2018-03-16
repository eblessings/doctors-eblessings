<?php
/**
 * @file src/Module/Hashtag.php
 */
namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Core\System;
use dba;

/**
 * Hashtag module.
 */
class Hashtag extends BaseModule
{

	public static function content()
	{
		$result = [];

		$t = escape_tags($_REQUEST['t']);
		if (empty($t)) {
			System::jsonExit($result);
		}

		$taglist = dba::p("SELECT DISTINCT(`term`) FROM `term` WHERE `term` LIKE ? AND `type` = ? ORDER BY `term`",
			$t . '%',
			intval(TERM_HASHTAG)
		);
		while ($tag = dba::fetch($taglist)) {
			$result[] = ['text' => strtolower($tag['term'])];
		}
		dba::close($taglist);

		System::jsonExit($result);
	}
}
