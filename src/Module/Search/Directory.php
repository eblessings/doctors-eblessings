<?php

namespace Friendica\Module\Search;

use Friendica\Content\Widget;
use Friendica\Core\L10n;
use Friendica\Module\BaseSearchModule;

/**
 * Directory search module
 */
class Directory extends BaseSearchModule
{
	public static function content()
	{
		if (!local_user()) {
			notice(L10n::t('Permission denied.'));
			return Login::form();
		}

		$a = self::getApp();

		if (empty($a->page['aside'])) {
			$a->page['aside'] = '';
		}

		$a->page['aside'] .= Widget::findPeople();
		$a->page['aside'] .= Widget::follow();

		return self::performSearch();
	}
}
