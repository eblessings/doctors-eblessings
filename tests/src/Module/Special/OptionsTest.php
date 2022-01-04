<?php

namespace Friendica\Test\src\Module\Special;

use Friendica\App\Arguments;
use Friendica\App\Page;
use Friendica\App\Router;
use Friendica\Capabilities\ICanCreateResponses;
use Friendica\DI;
use Friendica\Module\Special\Options;
use Friendica\Test\FixtureTest;

class OptionsTest extends FixtureTest
{
	public function testOptions()
	{
		$response = (new Options(DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::OPTIONS]))->run();

		self::assertEmpty((string)$response->getBody());
		self::assertEquals(204, $response->getStatusCode());
		self::assertEquals('No Content', $response->getReasonPhrase());
		self::assertEquals([
			'Allow'                       => [implode(',', Router::ALLOWED_METHODS)],
			ICanCreateResponses::X_HEADER => ['html'],
		], $response->getHeaders());
		self::assertEquals(implode(',', Router::ALLOWED_METHODS), $response->getHeaderLine('Allow'));
	}
}
