<?php
/**
 * @file src/Module/Attach.php
 */


namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Core\L10n;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Model\Attach as MAttach;

/**
 * @brief Attach Module
 */
class Attach extends BaseModule
{
	/**
	 * @brief Return to user an attached file given the id
	 */
	public static function rawContent()
	{
		$a = self::getApp();
		if ($a->argc != 2) {
			System::httpExit(400); // Bad Request.
		}

		// @TODO: Replace with parameter from router
		$item_id = intval($a->argv[1]);
		
		// Check for existence
		$item = MAttach::exists(['id' => $item_id]);
		if ($item === false) {
			System::httpExit(404, ['description' => L10n::t('Item was not found.')]);
		}

		// Now we'll fetch the item, if we have enough permisson
		$item = MAttach::getByIdWithPermission($item_id);
		if ($item === false) {
			System::httpExit(403, ['description' => L10n::t('Permission denied.')]);
		}

		$data = MAttach::getData($item);
		if (is_null($data)) {
			Logger::log('NULL data for attachment with id ' . $item['id']);
			System::httpExit(404, ['description' => L10n::t('Item was not found.')]);
		}

		// Use quotes around the filename to prevent a "multiple Content-Disposition"
		// error in Chrome for filenames with commas in them
		header('Content-type: ' . $item['filetype']);
		header('Content-length: ' . $item['filesize']);
		if (isset($_GET['attachment']) && $_GET['attachment'] === '0') {
			header('Content-disposition: filename="' . $item['filename'] . '"');
		} else {
			header('Content-disposition: attachment; filename="' . $item['filename'] . '"');
		}

		echo $data;
		exit();
		// NOTREACHED
	}
}
