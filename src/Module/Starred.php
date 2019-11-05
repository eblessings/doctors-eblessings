<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Model\Item;

/**
 * Toggle starred items
 */
class Starred extends BaseModule
{
	public static function rawContent($parameters)
	{
		if (!local_user()) {
			exit();
		}

		if (empty($parameters['item'])) {
			exit;
		} else {
			$itemId = intval($parameters['item']);
		}

		$item = Item::selectFirstForUser(local_user(), ['starred'], ['uid' => local_user(), 'id' => $itemId]);
		if (empty($item)) {
			exit();
		}

		$starred = !(bool)$item['starred'];

		Item::update(['starred' => $starred], ['id' => $itemId]);

		// See if we've been passed a return path to redirect to
		$returnPath = $_REQUEST['return'] ?? '';
		if (!empty($returnPath)) {
			$rand = '_=' . time() . (strpos($returnPath, '?') ? '&' : '?') . 'rand';
			self::getApp()->internalRedirect($returnPath . $rand);
		}

		// the json doesn't really matter, it will either be 0 or 1
		echo json_encode((int)$starred);
		exit();
	}
}
