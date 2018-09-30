<?php
/**
 * @file src/Module/Object.php
 */
namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Protocol\ActivityPub;
use Friendica\Core\System;
use Friendica\Model\Item;
use Friendica\Database\DBA;

/**
 * ActivityPub Object
 */
class Object extends BaseModule
{
	public static function init()
	{
		$a = self::getApp();

		if (empty($a->argv[1])) {
			System::httpExit(404);
		}

		if (!ActivityPub::isRequest()) {
			goaway(str_replace('object/', 'display/', $a->query_string));
		}

		$item = Item::selectFirst(['id'], ['guid' => $a->argv[1], 'wall' => true, 'private' => false]);
		if (!DBA::isResult($item)) {
			System::httpExit(404);
		}

		$data = ActivityPub::createObjectFromItemID($item['id']);

		header('Content-Type: application/activity+json');
		echo json_encode($data);
		exit();
	}
}
