<?php
/**
 * BaseObjectTest class.
 */

namespace Friendica\Test;

use Friendica\App;
use Friendica\BaseObject;
use Friendica\Test\Util\AppMockTrait;
use Friendica\Test\Util\VFSTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BaseObject class.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BaseObjectTest extends TestCase
{
	use VFSTrait;
	use AppMockTrait;

	/**
	 * @var BaseObject
	 */
	private $baseObject;

	/**
	 * Create variables used in tests.
	 */
	protected function setUp()
	{
		$this->setUpVfsDir();
		$this->mockApp($this->root);

		$this->baseObject = new BaseObject();
	}

	/**
	 * Test the getApp() function.
	 * @return void
	 */
	public function testGetApp()
	{
		$this->assertInstanceOf(App::class, $this->baseObject->getApp());
	}

	/**
	 * Test the setApp() function.
	 * @return void
	 */
	public function testSetApp()
	{
		$this->assertNull($this->baseObject->setApp($this->app));
		$this->assertEquals($this->app, $this->baseObject->getApp());
	}
}
