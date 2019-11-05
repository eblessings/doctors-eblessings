<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Content;
use Friendica\Core\Renderer;
use Friendica\Core\System;

/**
 * Prints the possible Smilies of this node
 */
class Smilies extends BaseModule
{
	public static function rawContent($parameters)
	{
		$app = self::getApp();

		if (!empty($app->argv[1]) && ($app->argv[1] === "json")) {
			$smilies = Content\Smilies::getList();
			$results = [];
			for ($i = 0; $i < count($smilies['texts']); $i++) {
				$results[] = ['text' => $smilies['texts'][$i], 'icon' => $smilies['icons'][$i]];
			}
			System::jsonExit($results);
		}
	}

	public static function content($parameters)
	{
		$smilies = Content\Smilies::getList();
		$count = count($smilies['texts'] ?? []);

		$tpl = Renderer::getMarkupTemplate('smilies.tpl');
		return Renderer::replaceMacros($tpl, [
			'$count'   => $count,
			'$smilies' => $smilies,
		]);
	}
}
