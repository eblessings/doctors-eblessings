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

namespace Friendica\Contact\Introduction\Factory;

use Friendica\BaseFactory;
use Friendica\Contact\Introduction\Entity;
use Friendica\Capabilities\ICanCreateFromTableRow;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Strings;

class Introduction extends BaseFactory implements ICanCreateFromTableRow
{
	/**
	 * @inheritDoc
	 */
	public function createFromTableRow(array $row): Entity\Introduction
	{
		return new Entity\Introduction(
			$row['uid'] ?? 0,
			$row['suggest-cid'] ?? 0,
			$row['fid'] ?? null,
			$row['contact-id'] ?? null,
			!empty($row['knowyou']),
			!empty($row['duplex']),
			$row['note'] ?? '',
			$row['hash'] ?? '',
			new \DateTime($row['datetime'] ?? 'now', new \DateTimeZone('UTC')),
			!empty($row['blocked']),
			!empty($row['ignore']),
			$row['id'] ?? null
		);
	}

	public function createNew(
		int $uid,
		int $cid,
		string $note,
		int $fid = null,
		int $sid = null,
		bool $knowyou = false,
		bool $duplex = false
	): Entity\Introduction {
		return $this->createFromTableRow([
			'uid'         => $uid,
			'fid'         => $fid,
			'contact-id'  => $cid,
			'suggest-cid' => $sid,
			'knowyou'     => $knowyou,
			'duplex'      => $duplex,
			'note'        => $note,
			'hash'        => Strings::getRandomHex(),
			'datetime'    => DateTimeFormat::utcNow(),
			'blocked'     => false,
			'ignore'      => false,
		]);
	}

	public function createDummy(int $id): Entity\Introduction
	{
		return $this->createFromTableRow(['id' => $id]);
	}
}
