<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\Renderer;
use Friendica\Model;
use Friendica\Util\XML;

/**
 * Shows the App menu
 */
class Filer extends BaseModule
{
	public static function init()
	{
		if (!local_user()) {
			info(L10n::t('You must be logged in to use this module'));
			self::getApp()->internalRedirect();
		}
	}

	public static function content()
	{
		$a = self::getApp();
		$logger = $a->getLogger();

		$term = XML::unescape(trim(defaults($_GET, 'term', '')));
		$item_id = (($a->argc > 1) ? intval($a->argv[1]) : 0);

		$logger->info('filer', ['tag' => $term, 'item' => $item_id]);

		if ($item_id && strlen($term)) {
			// file item
			Model\FileTag::saveFile(local_user(), $item_id, $term);
			$a->internalRedirect();
			return;

		} else {
			// return filer dialog
			$filetags = PConfig::get(local_user(), 'system', 'filetags');
			$filetags = Model\FileTag::fileToList($filetags, 'file');
			$filetags = explode(",", $filetags);

			$tpl = Renderer::getMarkupTemplate("filer_dialog.tpl");
			return Renderer::replaceMacros($tpl, [
				'$field' => ['term', L10n::t("Save to Folder:"), '', '', $filetags, L10n::t('- select -')],
				'$submit' => L10n::t('Save'),
			]);
		}
	}
}
