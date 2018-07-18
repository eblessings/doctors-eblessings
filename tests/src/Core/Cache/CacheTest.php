<?php

namespace Friendica\Test\src\Core\Cache;

use Friendica\Core\Config;
use Friendica\Test\DatabaseTest;
use Friendica\Util\DateTimeFormat;

abstract class CacheTest extends DatabaseTest
{
	/**
	 * @var \Friendica\Core\Cache\ICacheDriver
	 */
	protected $instance;

	abstract protected function getInstance();

	protected function setUp()
	{
		parent::setUp();
		$this->instance = $this->getInstance();

		// Reusable App object
		$this->app = \Friendica\BaseObject::getApp();

		// Default config
		Config::set('config', 'hostname', 'localhost');
		Config::set('system', 'throttle_limit_day', 100);
		Config::set('system', 'throttle_limit_week', 100);
		Config::set('system', 'throttle_limit_month', 100);
		Config::set('system', 'theme', 'system_theme');
	}

	function testSimple() {
		$this->assertNull($this->instance->get('value1'));

		$value = 'foobar';
		$this->instance->set('value1', $value);
		$received = $this->instance->get('value1');
		$this->assertEquals($value, $received, 'Value received from cache not equal to the original');

		$value = 'ipsum lorum';
		$this->instance->set('value1', $value);
		$received = $this->instance->get('value1');
		$this->assertEquals($value, $received, 'Value not overwritten by second set');

		$value2 = 'foobar';
		$this->instance->set('value2', $value2);
		$received2 = $this->instance->get('value2');
		$this->assertEquals($value, $received, 'Value changed while setting other variable');
		$this->assertEquals($value2, $received2, 'Second value not equal to original');

		$this->assertNull($this->instance->get('not_set'), 'Unset value not equal to null');

		$this->assertTrue($this->instance->delete('value1'));
		$this->assertNull($this->instance->get('value1'));
	}

	function testClear() {
		$value = 'ipsum lorum';
		$this->instance->set('1_value1', $value . '1');
		$this->instance->set('1_value2', $value . '2');
		$this->instance->set('2_value1', $value . '3');
		$this->instance->set('3_value1', $value . '4');

		$this->assertEquals([
			'1_value1' => 'ipsum lorum1',
			'1_value2' => 'ipsum lorum2',
			'2_value1' => 'ipsum lorum3',
			'3_value1' => 'ipsum lorum4',
		], [
			'1_value1' => $this->instance->get('1_value1'),
			'1_value2' => $this->instance->get('1_value2'),
			'2_value1' => $this->instance->get('2_value1'),
			'3_value1' => $this->instance->get('3_value1'),
		]);

		$this->assertTrue($this->instance->clear(false));

		$this->assertEquals([
			'1_value1' => null,
			'1_value2' => null,
			'2_value1' => null,
			'3_value1' => null,
		], [
			'1_value1' => $this->instance->get('1_value1'),
			'1_value2' => $this->instance->get('1_value2'),
			'2_value1' => $this->instance->get('2_value1'),
			'3_value1' => $this->instance->get('3_value1'),
		]);
	}

	function testTTL() {
		$this->assertNull($this->instance->get('value1'));

		$value = 'foobar';
		$this->instance->set('value1', $value, 1);
		$received = $this->instance->get('value1');
		$this->assertEquals($value, $received, 'Value received from cache not equal to the original');

		sleep(2);

		$this->assertNull($this->instance->get('value1'));
	}

	function testDifferentTypesInCache() {
		// String test
		$value = "foobar";
		$this->instance->set('stringVal', $value);
		$received = $this->instance->get('stringVal');
		$this->assertEquals($value, $received, 'Value type changed from ' . gettype($value) . ' to ' . gettype($received));

		// Integer test
		$value = 1;
		$this->instance->set('intVal', $value);
		$received = $this->instance->get('intVal');
		$this->assertEquals($value, $received, 'Value type changed from ' . gettype($value) . ' to ' . gettype($received));

		// Boolean test
		$value = true;
		$this->instance->set('boolValTrue', $value);
		$received = $this->instance->get('boolValTrue');
		$this->assertEquals($value, $received, 'Value type changed from ' . gettype($value) . ' to ' . gettype($received));

		$value = false;
		$this->instance->set('boolValFalse', $value);
		$received = $this->instance->get('boolValFalse');
		$this->assertEquals($value, $received, 'Value type changed from ' . gettype($value) . ' to ' . gettype($received));

		// float
		$value = 4.6634234;
		$this->instance->set('decVal', $value);
		$received = $this->instance->get('decVal');
		$this->assertEquals($value, $received, 'Value type changed from ' . gettype($value) . ' to ' . gettype($received));

		// array
		$value = array('1', '2', '3', '4', '5');
		$this->instance->set('arrayVal', $value);
		$received = $this->instance->get('arrayVal');
		$this->assertEquals($value, $received, 'Value type changed from ' . gettype($value) . ' to ' . gettype($received));

		// object
		$value = new DateTimeFormat();
		$this->instance->set('objVal', $value);
		$received = $this->instance->get('objVal');
		$this->assertEquals($value, $received, 'Value type changed from ' . gettype($value) . ' to ' . gettype($received));

		// null
		$value = null;
		$this->instance->set('objVal', $value);
		$received = $this->instance->get('objVal');
		$this->assertEquals($value, $received, 'Value type changed from ' . gettype($value) . ' to ' . gettype($received));
	}
}
