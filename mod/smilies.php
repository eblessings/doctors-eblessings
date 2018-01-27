<?php
/**
 * @file mod/smilies.php
 */
use Friendica\App;
use Friendica\Content\Smilies;
use Friendica\Util\Network;

/**
 * @param object $a App
 * @return mixed
 */
function smilies_content(App $a)
{
	if ($a->argv[1] === "json") {
		$tmp = Smilies::getList();
		$results = [];
		for ($i = 0; $i < count($tmp['texts']); $i++) {
			$results[] = ['text' => $tmp['texts'][$i], 'icon' => $tmp['icons'][$i]];
		}
		Network::jsonExit($results);
	} else {
		return Smilies::replace('', true);
	}
}
