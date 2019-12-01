<?php

namespace Friendica\Module\Item;

use Friendica\App;
use Friendica\BaseModule;
use Friendica\Core\L10n\L10n;
use Friendica\Core\Session;
use Friendica\Core\System;
use Friendica\Database\Database;
use Friendica\Model\Item;
use Friendica\Network\HTTPException;

/**
 * Module for ignoring threads or user items
 */
class Ignore extends BaseModule
{
	public static function rawContent(array $parameters = [])
	{
		/** @var L10n $l10n */
		$l10n = self::getClass(L10n::class);

		if (!Session::isAuthenticated()) {
			throw new HttpException\ForbiddenException($l10n->t('Access denied.'));
		}

		/** @var App\Arguments $args */
		$args = self::getClass(App\Arguments::class);
		/** @var Database $dba */
		$dba = self::getClass(Database::class);

		$message_id = intval($args->get(2));

		if (empty($message_id) || !is_int($message_id)) {
			throw new HTTPException\BadRequestException();
		}

		$thread = Item::selectFirstThreadForUser(local_user(), ['uid', 'ignored'], ['iid' => $message_id]);
		if (!$dba->isResult($thread)) {
			throw new HTTPException\BadRequestException();
		}

		// Numeric values are needed for the json output further below
		$ignored = !empty($thread['ignored']) ? 0 : 1;

		switch ($thread['uid'] ?? 0) {
			// if the thread is from the current user
			case local_user():
				$dba->update('thread', ['ignored' => $ignored], ['iid' => $message_id]);
				break;
			// 0 (null will get transformed to 0) => it's a public post
			case 0:
				$dba->update('user-item', ['ignored' => $ignored], ['iid' => $message_id, 'uid' => local_user()], true);
				break;
			// Throws a BadRequestException and not a ForbiddenException on purpose
			// Avoids harvesting existing, but forbidden IIDs (security issue)
			default:
				throw new HTTPException\BadRequestException();
		}

		// See if we've been passed a return path to redirect to
		$return_path = $_REQUEST['return'] ?? '';
		if (!empty($return_path)) {
			$rand = '_=' . time();
			if (strpos($return_path, '?')) {
				$rand = "&$rand";
			} else {
				$rand = "?$rand";
			}

			self::getApp()->internalRedirect($return_path . $rand);
		}

		// the json doesn't really matter, it will either be 0 or 1
		System::jsonExit($ignored);
	}
}
