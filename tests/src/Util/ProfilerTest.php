<?php

namespace src\Util;

use Friendica\Test\MockedTest;
use Friendica\Util\Profiler;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;

class ProfilerTest extends MockedTest
{
	/**
	 * @var LoggerInterface|MockInterface
	 */
	private $logger;

	protected function setUp()
	{
		parent::setUp();

		$this->logger = \Mockery::mock('Psr\Log\LoggerInterface');
	}

	/**
	 * Test the Profiler setup
	 */
	public function testSetUp()
	{
		$profiler = new Profiler(true, true);
	}

	/**
	 * A dataset for different profiling settings
	 * @return array
	 */
	public function dataPerformance()
	{
		return [
			'database' => [
				'timestamp' => time(),
				'name' => 'database',
				'functions' => ['test', 'it'],
			],
			'database_write' => [
				'timestamp' => time(),
				'name' => 'database_write',
				'functions' => ['test', 'it2'],
			],
			'cache' => [
				'timestamp' => time(),
				'name' => 'cache',
				'functions' => ['test', 'it3'],
			],
			'cache_write' => [
				'timestamp' => time(),
				'name' => 'cache_write',
				'functions' => ['test', 'it4'],
			],
			'network' => [
				'timestamp' => time(),
				'name' => 'network',
				'functions' => ['test', 'it5'],
			],
			'file' => [
				'timestamp' => time(),
				'name' => 'file',
				'functions' => [],
			],
			'rendering' => [
				'timestamp' => time(),
				'name' => 'rendering',
				'functions' => ['test', 'it7'],
			],
			'parser' => [
				'timestamp' => time(),
				'name' => 'parser',
				'functions' => ['test', 'it8'],
			],
			'marktime' => [
				'timestamp' => time(),
				'name' => 'parser',
				'functions' => ['test'],
			],
			// This one isn't set during reset
			'unknown' => [
				'timestamp' => time(),
				'name' => 'unknown',
				'functions' => ['test'],
			],
		];
	}

	/**
	 * Test the Profiler savetimestamp
	 * @dataProvider dataPerformance
	 */
	public function testSaveTimestamp($timestamp, $name, array $functions)
	{
		$profiler = new Profiler(true, true);

		foreach ($functions as $function) {
			$profiler->saveTimestamp($timestamp, $name, $function);
		}

		$this->assertGreaterThanOrEqual(0, $profiler->get($name));
	}

	/**
	 * Test the Profiler reset
	 * @dataProvider dataPerformance
	 */
	public function testReset($timestamp, $name, array $functions)
	{
		$profiler = new Profiler(true, true);

		$profiler->saveTimestamp($timestamp, $name);
		$profiler->reset();

		$this->assertEquals(0, $profiler->get($name));
	}

	public function dataBig()
	{
		return [
			'big' => [
				'data' => [
					'database' => [
						'timestamp' => time(),
						'name' => 'database',
						'functions' => ['test', 'it'],
					],
					'database_write' => [
						'timestamp' => time(),
						'name' => 'database_write',
						'functions' => ['test', 'it2'],
					],
					'cache' => [
						'timestamp' => time(),
						'name' => 'cache',
						'functions' => ['test', 'it3'],
					],
					'cache_write' => [
						'timestamp' => time(),
						'name' => 'cache_write',
						'functions' => ['test', 'it4'],
					],
					'network' => [
						'timestamp' => time(),
						'name' => 'network',
						'functions' => ['test', 'it5'],
					],
				]
			]
		];
	}

	/**
	 * Test the output of the Profiler
	 * @dataProvider dataBig
	 */
	public function testSaveLog($data)
	{
		$this->logger
			->shouldReceive('info')
			->with('test', \Mockery::any())
			->once();
		$this->logger
			->shouldReceive('info')
			->once();

		$profiler = new Profiler(true, true);

		foreach ($data as $perf => $items) {
			foreach ($items['functions'] as $function) {
				$profiler->saveTimestamp($items['timestamp'], $items['name'], $function);
			}
		}

		$profiler->saveLog($this->logger, 'test');
	}
}
