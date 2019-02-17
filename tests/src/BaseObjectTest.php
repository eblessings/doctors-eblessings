<?php
/**
 * BaseObjectTest class.
 */

namespace Friendica\Test;

use Friendica\BaseObject;
use Friendica\Test\Util\AppMockTrait;
use Friendica\Test\Util\VFSTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BaseObject class.
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
	 * Test the setApp() and getApp() function.
	 * @return void
	 */
	public function testGetSetApp()
	{
		$baseObject = new BaseObject();
		$this->setUpVfsDir();
		$configMock = \Mockery::mock('Friendica\Core\Config\Configuration');
		$this->mockApp($this->root, $configMock);

		$this->assertNull($baseObject->setApp($this->app));
		$this->assertEquals($this->app, $baseObject->getApp());
	}

	/**
	 * Test the getApp() function without App
	 * @expectedException Friendica\Network\HTTPException\InternalServerErrorException
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testGetAppFailed()
	{
		$baseObject = new BaseObject();
		$baseObject->getApp();
	}
}
