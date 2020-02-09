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

namespace Friendica\Collection\Api\Mastodon;

use Friendica\Api\Entity\Mastodon\Field;
use Friendica\BaseCollection;

class Fields extends BaseCollection
{
	/**
	 * @param Field[]  $entities
	 * @param int|null $totalCount
	 */
	public function __construct(array $entities = [], int $totalCount = null)
	{
		parent::__construct($entities);

		$this->totalCount = $totalCount ?? count($entities);
	}
}
