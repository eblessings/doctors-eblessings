<?php
/**
 * @copyright Copyright (C) 2010-2023, the Friendica project
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

namespace Friendica\Object\Api\Mastodon\InstanceV2;

use Friendica\App\BaseURL;
use Friendica\BaseDataTransferObject;

/**
 * Class Thumbnail
 *
 * @see https://docs.joinmastodon.org/entities/Instance/
 */
class Thumbnail extends BaseDataTransferObject
{
	/** @var string (URL) */
	protected $url;

	public function __construct(BaseURL $baseUrl)
	{
		$this->url = $baseUrl->get() . 'images/friendica-banner.jpg';
	}
}
