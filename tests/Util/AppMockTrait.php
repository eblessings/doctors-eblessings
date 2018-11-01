<?php

namespace Friendica\Test\Util;

use Friendica\App;
use Friendica\BaseObject;
use Friendica\Render\FriendicaSmartyEngine;
use Mockery\MockInterface;
use org\bovigo\vfs\vfsStreamDirectory;

/**
 * Trait to Mock the global App instance
 */
trait AppMockTrait
{
	use ConfigMockTrait;

	/**
	 * @var MockInterface|App The mocked Friendica\App
	 */
	protected $app;

	/**
	 * Mock the App
	 *
	 * @param vfsStreamDirectory $root The root directory
	 */
	public function mockApp($root)
	{
		// simply returning the input when using L10n::t()
		$l10nMock = \Mockery::mock('alias:Friendica\Core\L10n');
		$l10nMock->shouldReceive('t')
			->andReturnUsing(function ($arg) { return $arg; });

		$this->mockConfigGet('system', 'theme', 'testtheme');

		// Mocking App and most used functions
		$this->app = \Mockery::mock(App::class);
		$this->app
			->shouldReceive('getBasePath')
			->andReturn($root->url());

		$this->app
			->shouldReceive('getConfigValue')
			->with('database', 'hostname')
			->andReturn(getenv('MYSQL_HOST'));
		$this->app
			->shouldReceive('getConfigValue')
			->with('database', 'username')
			->andReturn(getenv('MYSQL_USERNAME'));
		$this->app
			->shouldReceive('getConfigValue')
			->with('database', 'password')
			->andReturn(getenv('MYSQL_PASSWORD'));
		$this->app
			->shouldReceive('getConfigValue')
			->with('database', 'database')
			->andReturn(getenv('MYSQL_DATABASE'));
		$this->app
			->shouldReceive('getTemplateEngine')
			->andReturn(new FriendicaSmartyEngine());
		$this->app
			->shouldReceive('getCurrentTheme')
			->andReturn('Smarty3');
		$this->app
			->shouldReceive('saveTimestamp')
			->andReturn(true);
		$this->app
			->shouldReceive('getBaseUrl')
			->andReturn('http://friendica.local');

		BaseObject::setApp($this->app);
	}
}
