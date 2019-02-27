<?php

namespace Friendica\Test\Core\Config\Cache;

use Friendica\Core\Config\Cache\ConfigCache;
use Friendica\Test\MockedTest;

class ConfigCacheTest extends MockedTest
{
	public function dataTests()
	{
		return [
			'normal' => [
				'data' => [
					'system' => [
						'test' => 'it',
						'boolTrue' => true,
						'boolFalse' => false,
						'int' => 235,
						'dec' => 2.456,
						'array' => ['1', 2, '3', true, false],
					],
					'config' => [
						'a' => 'value',
					],
				]
			]
		];
	}

	private function assertConfigValues($data, ConfigCache $configCache, $uid = null)
	{
		foreach ($data as $cat => $values) {
			foreach ($values as $key => $value) {
				if (isset($uid)) {
					$this->assertEquals($data[$cat][$key], $configCache->getP($uid, $cat, $key));
				} else {
					$this->assertEquals($data[$cat][$key], $configCache->get($cat, $key));
				}
			}
		}
	}

	/**
	 * Test the loadConfigArray() method without override
	 * @dataProvider dataTests
	 */
	public function testLoadConfigArray($data)
	{
		$configCache = new ConfigCache();
		$configCache->load($data);

		$this->assertConfigValues($data, $configCache);
	}

	/**
	 * Test the loadConfigArray() method with overrides
	 * @dataProvider dataTests
	 */
	public function testLoadConfigArrayOverride($data)
	{
		$override = [
			'system' => [
				'test' => 'not',
				'boolTrue' => false,
			]
		];

		$configCache = new ConfigCache();
		$configCache->load($data);
		$configCache->load($override);

		$this->assertConfigValues($data, $configCache);

		// override the value
		$configCache->load($override, true);

		$this->assertEquals($override['system']['test'], $configCache->get('system', 'test'));
		$this->assertEquals($override['system']['boolTrue'], $configCache->get('system', 'boolTrue'));
	}

	/**
	 * Test the loadConfigArray() method with wrong/empty datasets
	 */
	public function testLoadConfigArrayWrong()
	{
		$configCache = new ConfigCache();

		// empty dataset
		$configCache->load([]);
		$this->assertEmpty($configCache->getAll());

		// wrong dataset
		$configCache->load(['system' => 'not_array']);
		$this->assertEmpty($configCache->getAll());

		// incomplete dataset (key is integer ID of the array)
		$configCache->load(['system' => ['value']]);
		$this->assertEquals('value', $configCache->get('system', 0));
	}

	/**
	 * Test the getAll() method
	 * @dataProvider dataTests
	 */
	public function testGetAll($data)
	{
		$configCache = new ConfigCache();
		$configCache->load($data);

		$all = $configCache->getAll();

		$this->assertContains($data['system'], $all);
		$this->assertContains($data['config'], $all);
	}

	/**
	 * Test the set() and get() method
	 * @dataProvider dataTests
	 */
	public function testSetGet($data)
	{
		$configCache = new ConfigCache();

		foreach ($data as $cat => $values) {
			foreach ($values as $key => $value) {
				$configCache->set($cat, $key, $value);
			}
		}

		$this->assertConfigValues($data, $configCache);
	}

	/**
	 * Test the get() method without a value
	 */
	public function testGetEmpty()
	{
		$configCache = new ConfigCache();

		$this->assertNull($configCache->get('something', 'value'));
	}

	/**
	 * Test the get() method with a category
	 */
	public function testGetCat()
	{
		$configCache = new ConfigCache([
			'system' => [
				'key1' => 'value1',
				'key2' => 'value2',
			],
			'config' => [
				'key3' => 'value3',
			],
		]);

		$this->assertEquals([
			'key1' => 'value1',
			'key2' => 'value2',
		], $configCache->get('system'));
	}

	/**
	 * Test the delete() method
	 * @dataProvider dataTests
	 */
	public function testDelete($data)
	{
		$configCache = new ConfigCache($data);

		foreach ($data as $cat => $values) {
			foreach ($values as $key => $value) {
				$configCache->delete($cat, $key);
			}
		}

		$this->assertEmpty($configCache->getAll());
	}

	/**
	 * Test the setP() and getP() methods
	 * @dataProvider dataTests
	 */
	public function testSetGetP($data)
	{
		$configCache = new ConfigCache();
		$uid = 345;

		foreach ($data as $cat => $values) {
			foreach ($values as $key => $value) {
				$configCache->setP($uid, $cat, $key, $value);
			}
		}

		$this->assertConfigValues($data, $configCache, $uid);
	}


	/**
	 * Test the getP() method with a category
	 */
	public function testGetPCat()
	{
		$configCache = new ConfigCache();
		$uid = 345;

		$configCache->loadP($uid, [
			'system' => [
				'key1' => 'value1',
				'key2' => 'value2',
			],
			'config' => [
				'key3' => 'value3',
			],
		]);

		$this->assertEquals([
			'key1' => 'value1',
			'key2' => 'value2',
		], $configCache->get($uid, 'system'));
	}

	/**
	 * Test the deleteP() method
	 * @dataProvider dataTests
	 */
	public function testDeleteP($data)
	{
		$configCache = new ConfigCache();
		$uid = 345;

		foreach ($data as $cat => $values) {
			foreach ($values as $key => $value) {
				$configCache->setP($uid, $cat, $key, $value);
			}
		}

		foreach ($data as $cat => $values) {
			foreach ($values as $key => $value) {
				$configCache->deleteP($uid, $cat, $key);
			}
		}

		$this->assertEmpty($configCache->getAll());
	}
}
