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

namespace Friendica\Test\src\Util;

use Friendica\Test\MockedTest;
use Friendica\Test\Util\HTTPInputDataDouble;
use Friendica\Util\HTTPInputData;

/**
 * Testing HTTPInputData
 * @see	HTTPInputData
 */
class HTTPInputDataTest extends MockedTest
{
	/**
	 * Returns the data stream for the unit test
	 * Each array element of the first hierarchy represents one test run
	 * Each array element of the second hierarchy represents the parameters, passed to the test function
	 * @return array[]
	 */
	public function dataStream()
	{
		return [
			'example' => [
				'input'    => file_get_contents(__DIR__ . '/../../datasets/http/example1.httpinput'),
				'expected' => [
					'variables' => [
						'var1' => 'value1',
						'var2' => 'value2',
					],
					'files' => []
				]
			]
		];
	}

	/**
	 * Tests the HTTPInputData::process() method
	 * @see HTTPInputData::process()
	 * @param string $input The input, we got from the data stream
	 * @param array  $expected The expected output
	 * @dataProvider dataStream
	 */
	public function testHttpInput(string $input, array $expected)
	{
		HTTPInputDataDouble::setPhpInputContent($input);
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $input);
		rewind($stream);

		HTTPInputDataDouble::setPhpInputStream($stream);
		$output = HTTPInputDataDouble::process();
		$this->assertEqualsCanonicalizing($expected, $output);
	}
}
