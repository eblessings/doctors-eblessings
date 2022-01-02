<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
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

namespace Friendica\Module\Api\Mastodon\Instance;

use Friendica\Content\Text\BBCode;
use Friendica\Content\Text\HTML;
use Friendica\Core\System;
use Friendica\DI;
use Friendica\Module\BaseApi;
use Friendica\Network\HTTPException;

/**
 * Undocumented API endpoint
 */
class Rules extends BaseApi
{
	/**
	 * @throws HTTPException\InternalServerErrorException
	 */
	protected function rawContent(array $request = [])
	{
		$rules = [];
		$id    = 0;

		if (DI::config()->get('system', 'tosdisplay')) {
			$html = BBCode::convert(DI::config()->get('system', 'tostext'), false, BBCode::EXTERNAL);

			$msg = HTML::toPlaintext($html, 0, true);
			foreach (explode("\n", $msg) as $line) {
				$line = trim($line);
				if ($line) {
					$rules[] = ['id' => (string)++$id, 'text' => $line];
				}
			}
		}

		System::jsonExit($rules);
	}
}
