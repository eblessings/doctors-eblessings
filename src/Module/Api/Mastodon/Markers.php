<?php
/**
 * @copyright Copyright (C) 2010-2021, the Friendica project
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

namespace Friendica\Module\Api\Mastodon;

use Friendica\App\Router;
use Friendica\Core\System;
use Friendica\DI;
use Friendica\Module\BaseApi;

/**
 * @see https://docs.joinmastodon.org/methods/timelines/markers/
 */
class Markers extends BaseApi
{
	public function post()
	{
		self::checkAllowedScope(self::SCOPE_WRITE);

		DI::apiResponse()->unsupported(Router::POST);
	}

	/**
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public function rawContent()
	{
		self::checkAllowedScope(self::SCOPE_READ);

		System::jsonExit([]);
	}
}
