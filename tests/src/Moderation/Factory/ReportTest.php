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

namespace Friendica\Test\src\Moderation\Factory;

use Friendica\Moderation\Factory;
use Friendica\Moderation\Entity;
use Friendica\Test\MockedTest;
use Psr\Log\NullLogger;

class ReportTest extends MockedTest
{
	public function dataCreateFromTableRow(): array
	{
		return [
			'default' => [
				'row' => [
					'id'      => 11,
					'uid'     => 12,
					'cid'     => 13,
					'comment' => '',
					'forward' => false,
					'created' => null
				],
				'postUriIds' => [],
				'assertion'  => new Entity\Report(
					12,
					13,
					new \DateTime('now', new \DateTimeZone('UTC')),
					'',
					false,
					[],
					11,
				),
			],
			'full' => [
				'row' => [
					'id'      => 11,
					'uid'     => 12,
					'cid'     => 13,
					'comment' => 'Report',
					'forward' => true,
					'created' => '2021-10-12 12:23:00'
				],
				'postUriIds' => [89, 90],
				'assertion'  => new Entity\Report(
					12,
					13,
					new \DateTime('2021-10-12 12:23:00', new \DateTimeZone('UTC')),
					'Report',
					true,
					[89, 90],
					11
				),
			],
		];
	}

	public function assertReport(Entity\Report $assertion, Entity\Report $report)
	{
		self::assertEquals(
			$assertion->id,
			$report->id
		);
		self::assertEquals($assertion->uid, $report->uid);
		self::assertEquals($assertion->cid, $report->cid);
		self::assertEquals($assertion->comment, $report->comment);
		self::assertEquals($assertion->forward, $report->forward);
		// No way to test "now" at the moment
		//self::assertEquals($assertion->created, $report->created);
		self::assertEquals($assertion->postUriIds, $report->postUriIds);
	}

	/**
	 * @dataProvider dataCreateFromTableRow
	 */
	public function testCreateFromTableRow(array $row, array $postUriIds, Entity\Report $assertion)
	{
		$factory = new Factory\Report(new NullLogger());

		$this->assertReport($factory->createFromTableRow($row, $postUriIds), $assertion);
	}

	public function dataCreateFromReportsRequest(): array
	{
		return [
			'default' => [
				'uid'        => 12,
				'cid'        => 13,
				'comment'    => '',
				'forward'    => false,
				'postUriIds' => [],
				'assertion'  => new Entity\Report(
					12,
					13,
					new \DateTime('now', new \DateTimeZone('UTC')),
					'',
					false,
					[],
					null
				),
			],
			'full' => [
				'uid'        => 12,
				'cid'        => 13,
				'comment'    => 'Report',
				'forward'    => true,
				'postUriIds' => [89, 90],
				'assertion'  => new Entity\Report(
					12,
					13,
					new \DateTime('now', new \DateTimeZone('UTC')),
					'Report',
					true,
					[89, 90],
					null
				),
			],
		];
	}

	/**
	 * @dataProvider dataCreateFromReportsRequest
	 */
	public function testCreateFromReportsRequest(int $uid, int $cid, string $comment, bool $forward, array $postUriIds, Entity\Report $assertion)
	{
		$factory = new Factory\Report(new NullLogger());

		$this->assertReport($factory->createFromReportsRequest($uid, $cid, $comment, $forward, $postUriIds), $assertion);
	}
}
