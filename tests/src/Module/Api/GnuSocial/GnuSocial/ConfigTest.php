<?php

namespace Friendica\Test\src\Module\Api\GnuSocial\GnuSocial;

use Friendica\App\Router;
use Friendica\DI;
use Friendica\Module\Api\GNUSocial\GNUSocial\Config;
use Friendica\Test\src\Module\Api\ApiTest;

class ConfigTest extends ApiTest
{
	/**
	 * Test the api_statusnet_config() function.
	 */
	public function testApiStatusnetConfig()
	{
		$config   = new Config(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::GET]);
		$response = $config->run();
		$body     = (string)$response->getBody();

		self::assertJson($body);

		$json = json_decode($body);

		self::assertEquals(1, 1);

		self::assertEquals('localhost', $json->site->server);
		self::assertEquals('frio', $json->site->theme);
		self::assertEquals(DI::baseUrl() . '/images/friendica-64.png', $json->site->logo);
		self::assertTrue($json->site->fancy);
		self::assertEquals('en', $json->site->language);
		self::assertEquals('UTC', $json->site->timezone);
		self::assertEquals(200000, $json->site->textlimit);
		self::assertFalse($json->site->private);
		self::assertEquals('always', $json->site->ssl);
	}
}
