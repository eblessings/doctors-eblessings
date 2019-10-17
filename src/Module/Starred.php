<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Model\Item;

/**
 * Toggle starred items
 */
class Starred extends BaseModule
{
	public static function rawContent()
	{
		$a = self::getApp();
		$starred = 0;
		$itemId = null;

		if (!local_user()) {
			exit();
		}

		// @TODO: Replace with parameter from router
		if ($a->argc > 1) {
			$itemId = intval($a->argv[1]);
		}

		if (!$itemId) {
			exit();
		}

		$item = Item::selectFirstForUser(local_user(), ['starred'], ['uid' => local_user(), 'id' => $itemId]);
		if (empty($item)) {
			exit();
		}

		if (!intval($item['starred'])) {
			$starred = 1;
		}

		Item::update(['starred' => $starred], ['id' => $itemId]);

		// See if we've been passed a return path to redirect to
		$returnPath = $_REQUEST['return'] ?? '';
		if ($returnPath) {
			$rand = '_=' . time();
			if (strpos($returnPath, '?')) {
				$rand = "&$rand";
			} else {
				$rand = "?$rand";
			}

			$a->internalRedirect($returnPath . $rand);
		}

		// the json doesn't really matter, it will either be 0 or 1
		echo json_encode($starred);
		exit();
	}
}
