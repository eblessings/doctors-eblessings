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
 * This is in the same namespace as Crypto for mocking 'rand' and 'random_init'
 */
namespace Friendica\Util;

use phpseclib\Crypt\RSA;
use phpseclib\Math\BigInteger;
use PHPUnit\Framework\TestCase;

class CryptoTest extends TestCase
{
	/**
	 * Replaces random_int results with given mocks
	 *
	 */
	private function assertRandomInt($min, $max)
	{
		global $phpMock;
		$phpMock['random_int'] = function ($mMin, $mMax) use ($min, $max) {
			$this->assertEquals($min, $mMin);
			$this->assertEquals($max, $mMax);
			return 1;
		};
	}

	public function testRandomDigitsRandomInt()
	{
		$this->assertRandomInt(0, 9);

		$test = Crypto::randomDigits(1);
		$this->assertEquals(1, strlen($test));
		$this->assertEquals(1, $test);

		$test = Crypto::randomDigits(8);
		$this->assertEquals(8, strlen($test));
		$this->assertEquals(11111111, $test);
	}

	public function dataRsa()
	{
		return [
			'diaspora' => [
				'key' => file_get_contents(__DIR__ . '/../../datasets/crypto/rsa/diaspora-public-rsa-base64'),
				'expected' => file_get_contents(__DIR__ . '/../../datasets/crypto/rsa/diaspora-public-pem'),
			],
		];
	}

	/**
	 * @dataProvider dataRsa
	 */
	public function testPubRsaToMe(string $key, string $expected)
	{
		$this->assertEquals($expected, Crypto::rsaToPem(base64_decode($key)));
	}


	public function datePem()
	{
		return [
			'diaspora' => [
				'key' => file_get_contents(__DIR__ . '/../../datasets/crypto/rsa/diaspora-public-pem'),
			],
		];
	}

	/**
	 * @dataProvider datePem
	 */
	public function testPemToMe(string $key)
	{
		Crypto::pemToMe($key, $m, $e);

		$expectedRSA = new RSA();
		$expectedRSA->loadKey([
								  'e' => new BigInteger($e, 256),
								  'n' => new BigInteger($m, 256)
							  ]);

		$this->assertEquals($expectedRSA->getPublicKey(), $key);
	}
}

/**
 * A workaround to replace the PHP native random_int() (>= 7.0) with a mocked function
 *
 * @return int
 */
function random_int($min, $max)
{
	global $phpMock;
	if (isset($phpMock['random_int'])) {
		$result = call_user_func_array($phpMock['random_int'], func_get_args());
		return $result;
	}
}
