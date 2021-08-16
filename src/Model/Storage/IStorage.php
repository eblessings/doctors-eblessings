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

namespace Friendica\Model\Storage;

/**
 * Interface for basic storage backends
 */
interface IStorage
{
	/**
	 * Get data from backend
	 *
	 * @param string $reference Data reference
	 *
	 * @return string
	 *
	 * @throws StorageException in case there's an unexpected error
	 * @throws ReferenceStorageException in case the reference doesn't exist
	 */
	public function get(string $reference): string;

	/**
	 * The name of the backend
	 *
	 * @return string
	 */
	public function __toString();

	/**
	 * The name of the backend
	 *
	 * @return string
	 */
	public static function getName(): string;
}
