<?php

namespace Friendica\Test\src\Content\Text;

use Friendica\Content\Text\BBCode;
use Friendica\Test\MockedTest;
use Friendica\Test\Util\AppMockTrait;
use Friendica\Test\Util\L10nMockTrait;
use Friendica\Test\Util\VFSTrait;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BBCodeTest extends MockedTest
{
	use VFSTrait;
	use AppMockTrait;
	use L10nMockTrait;

	protected function setUp()
	{
		parent::setUp();
		$this->setUpVfsDir();
		$this->mockApp($this->root);
		$this->app->videowidth = 425;
		$this->app->videoheight = 350;
		$this->configMock->shouldReceive('get')
			->with('system', 'remove_multiplicated_lines')
			->andReturn(false);
		$this->configMock->shouldReceive('get')
			->with('system', 'no_oembed')
			->andReturn(false);
		$this->configMock->shouldReceive('get')
			->with('system', 'allowed_link_protocols')
			->andReturn(null);
		$this->configMock->shouldReceive('get')
			->with('system', 'itemcache_duration')
			->andReturn(-1);
		$this->configMock->shouldReceive('get')
			->with('system', 'url')
			->andReturn('friendica.local');
		$this->mockL10nT();
	}

	public function dataLinks()
	{
		return [
			/** @see https://github.com/friendica/friendica/issues/2487 */
			'bug-2487-1' => [
				'data' => 'https://de.wikipedia.org/wiki/Juha_Sipilä',
				'assertHTML' => true,
			],
			'bug-2487-2' => [
				'data' => 'https://de.wikipedia.org/wiki/Dnepr_(Motorradmarke)',
				'assertHTML' => true,
			],
			'bug-2487-3' => [
				'data' => 'https://friendica.wäckerlin.ch/friendica',
				'assertHTML' => true,
			],
			'bug-2487-4' => [
				'data' => 'https://mastodon.social/@morevnaproject',
				'assertHTML' => true,
			],
			/** @see https://github.com/friendica/friendica/issues/5795 */
			'bug-5795' => [
				'data' => 'https://social.nasqueron.org/@liw/100798039015010628',
				'assertHTML' => true,
			],
			/** @see https://github.com/friendica/friendica/issues/6095 */
			'bug-6095' => [
				'data' => 'https://en.wikipedia.org/wiki/Solid_(web_decentralization_project)',
				'assertHTML' => true,
			],
			'no-protocol' => [
				'data' => 'example.com/path',
				'assertHTML' => false
			],
			'wrong-protocol' => [
				'data' => 'ftp://example.com',
				'assertHTML' => false
			],
			'wrong-domain-without-path' => [
				'data' => 'http://example',
				'assertHTML' => false
			],
			'wrong-domain-with-path' => [
				'data' => 'http://example/path',
				'assertHTML' => false
			],
			'bug-6857-domain-start' => [
				'data' => "http://\nexample.com",
				'assertHTML' => false
			],
			'bug-6857-domain-end' => [
				'data' => "http://example\n.com",
				'assertHTML' => false
			],
			'bug-6857-tld' => [
				'data' => "http://example.\ncom",
				'assertHTML' => false
			],
			'bug-6857-end' => [
				'data' => "http://example.com\ntest",
				'assertHTML' => false
			],
			'bug-6901' => [
				'data' => "http://example.com<ul>",
				'assertHTML' => false
			],
		];
	}

	/**
	 * Test convert different links inside a text
	 * @dataProvider dataLinks
	 *
	 * @param string $data The data to text
	 * @param bool $assertHTML True, if the link is a HTML link (<a href...>...</a>)
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public function testAutoLinking($data, $assertHTML)
	{
		$output = BBCode::convert($data);
		$assert = '<a href="' . $data . '" target="_blank">' . $data . '</a>';
		if ($assertHTML) {
			$this->assertEquals($assert, $output);
		} else {
			$this->assertNotEquals($assert, $output);
		}
	}
}
