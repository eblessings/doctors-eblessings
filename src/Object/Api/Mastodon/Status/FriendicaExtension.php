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

namespace Friendica\Object\Api\Mastodon\Status;

use Friendica\BaseDataTransferObject;

/**
 * Class FriendicaExtension
 *
 * Additional fields on Mastodon Statuses for storing Friendica specific data
 *
 * @see https://docs.joinmastodon.org/entities/status
 */
class FriendicaExtension extends BaseDataTransferObject
{
	/** @var string */
	protected $title;

	/** @var FriendicaDeliveryData */
	protected $delivery_data;
	/** @var int */
	protected $dislikes_count;

	/**
	 * Creates a status count object
	 *
	 * @param string $title
	 * @param int $dislikes_count
	 * @param FriendicaDeliveryData $delivery_data
	 */
	public function __construct(string $title, int $dislikes_count, FriendicaDeliveryData $delivery_data)
	{
		$this->title          = $title;
		$this->delivery_data  = $delivery_data;
		$this->dislikes_count = $dislikes_count;
	}
}
