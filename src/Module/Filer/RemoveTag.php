<?php
/**
 * @copyright Copyright (C) 2020, Friendica
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Module\Filer;

use Friendica\BaseModule;
use Friendica\DI;
use Friendica\Model\FileTag;
use Friendica\Network\HTTPException;
use Friendica\Util\XML;

/**
 * Remove a tag from a file
 */
class RemoveTag extends BaseModule
{
	public static function content(array $parameters = [])
	{
		if (!local_user()) {
			throw new HTTPException\ForbiddenException();
		}

		$app = DI::app();
		$logger = DI::logger();

		$item_id = (($app->argc > 1) ? intval($app->argv[1]) : 0);

		$term = XML::unescape(trim($_GET['term'] ?? ''));
		$cat = XML::unescape(trim($_GET['cat'] ?? ''));

		$category = (($cat) ? true : false);

		if ($category) {
			$term = $cat;
		}

		$logger->info('Filer - Remove Tag', [
			'term'     => $term,
			'item'     => $item_id,
			'category' => ($category ? 'true' : 'false')
		]);

		if ($item_id && strlen($term)) {
			if (FileTag::unsaveFile(local_user(), $item_id, $term, $category)) {
				info('Item removed');
			}
		} else {
			info('Item was not deleted');
		}

		DI::baseUrl()->redirect('network?file=' . rawurlencode($term));
	}
}
