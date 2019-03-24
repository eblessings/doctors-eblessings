<?php

namespace Friendica\Test\src\Util\Config;

use Friendica\App;
use Friendica\Core\Config\Cache\ConfigCache;
use Friendica\Test\MockedTest;
use Friendica\Test\Util\VFSTrait;
use Friendica\Util\Config\ConfigFileLoader;
use Friendica\Util\Config\ConfigFileSaver;
use Mockery\MockInterface;
use org\bovigo\vfs\vfsStream;

class ConfigFileSaverTest extends MockedTest
{
	use VFSTrait;

	/**
	 * @var App\Mode|MockInterface
	 */
	private $mode;

	protected function setUp()
	{
		parent::setUp();
		$this->setUpVfsDir();
		$this->mode = \Mockery::mock(App\Mode::class);
		$this->mode->shouldReceive('isInstall')->andReturn(true);
	}

	public function dataConfigFiles()
	{
		return [
			'config' => [
				'fileName' => 'local.config.php',
				'filePath' => dirname(__DIR__) . DIRECTORY_SEPARATOR .
					'..' . DIRECTORY_SEPARATOR .
					'..' . DIRECTORY_SEPARATOR .
					'datasets' . DIRECTORY_SEPARATOR .
					'config',
				'relativePath' => 'config',
			],
			'ini' => [
				'fileName' => 'local.ini.php',
				'filePath' => dirname(__DIR__) . DIRECTORY_SEPARATOR .
					'..' . DIRECTORY_SEPARATOR .
					'..' . DIRECTORY_SEPARATOR .
					'datasets' . DIRECTORY_SEPARATOR .
					'config',
				'relativePath' => 'config',
			],
			'htconfig' => [
				'fileName' => '.htconfig.php',
				'filePath' => dirname(__DIR__) . DIRECTORY_SEPARATOR .
					'..' . DIRECTORY_SEPARATOR .
					'..' . DIRECTORY_SEPARATOR .
					'datasets' . DIRECTORY_SEPARATOR .
					'config',
				'relativePath' => '',
			],
		];
	}

	/**
	 * Test the saveToConfigFile() method
	 * @dataProvider dataConfigFiles
	 *
	 * @todo 20190324 [nupplaphil] for ini-configs, it isn't possible to use $ or ! inside values
	 */
	public function testSaveToConfig($fileName, $filePath, $relativePath)
	{
		$this->delConfigFile('local.config.php');

		if (empty($relativePath)) {
			$root = $this->root;
			$relativeFullName = $fileName;
		} else {
			$root = $this->root->getChild($relativePath);
			$relativeFullName = $relativePath . DIRECTORY_SEPARATOR . $fileName;
		}

		vfsStream::newFile($fileName)
			->at($root)
			->setContent(file_get_contents($filePath . DIRECTORY_SEPARATOR . $fileName));

		$configFileSaver = new ConfigFileSaver($this->root->url());
		$configFileLoader = new ConfigFileLoader($this->root->url(), $this->mode);
		$configCache = new ConfigCache();
		$configFileLoader->setupCache($configCache);

		$this->assertEquals('admin@test.it', $configCache->get('config', 'admin_email'));
		$this->assertNull($configCache->get('config', 'test_val'));
		$this->assertNull($configCache->get('system', 'test_val2'));

		$configFileSaver->addConfigValue('system', 'theme', 'frio');
		$configFileSaver->addConfigValue('config', 'admin_email', 'new@mail.it');
		$configFileSaver->addConfigValue('config', 'test_val', 'Testingwith@all.we can');
		$configFileSaver->addConfigValue('system', 'theme', 'vier');
		$configFileSaver->addConfigValue('system', 'test_val2', 'TestIt Now');
		$this->assertTrue($configFileSaver->saveToConfigFile());

		$newConfigCache = new ConfigCache();
		$configFileLoader->setupCache($newConfigCache);

		$this->assertEquals('new@mail.it', $newConfigCache->get('config', 'admin_email'));
		$this->assertEquals('Testingwith@all.we can', $newConfigCache->get('config', 'test_val'));
		$this->assertEquals('vier', $newConfigCache->get('system', 'theme'));
		$this->assertEquals('TestIt Now', $newConfigCache->get('system', 'test_val2'));

		$this->assertTrue($this->root->hasChild($relativeFullName));
		$this->assertTrue($this->root->hasChild($relativeFullName . '.old'));
		$this->assertFalse($this->root->hasChild($relativeFullName . '.tmp'));

		$this->assertEquals(file_get_contents($filePath . DIRECTORY_SEPARATOR . $fileName), file_get_contents($this->root->getChild($relativeFullName . '.old')->url()));
	}

	/**
	 * Test the saveToConfigFile() method without permissions
	 * @dataProvider dataConfigFiles
	 */
	public function testNoPermission($fileName, $filePath, $relativePath)
	{
		$this->delConfigFile('local.config.php');

		if (empty($relativePath)) {
			$root = $this->root;
			$relativeFullName = $fileName;
		} else {
			$root = $this->root->getChild($relativePath);
			$relativeFullName = $relativePath . DIRECTORY_SEPARATOR . $fileName;
		}

		$root->chmod(000);

		vfsStream::newFile($fileName)
			->at($root)
			->setContent(file_get_contents($filePath . DIRECTORY_SEPARATOR . $fileName));
	}
}
