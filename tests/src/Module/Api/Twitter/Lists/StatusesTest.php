<?php

namespace Friendica\Test\src\Module\Api\Twitter\Lists;

use Friendica\App\Router;
use Friendica\DI;
use Friendica\Module\Api\Twitter\Lists\Statuses;
use Friendica\Network\HTTPException\BadRequestException;
use Friendica\Test\src\Module\Api\ApiTest;

class StatusesTest extends ApiTest
{
	/**
	 * Test the api_lists_statuses() function.
	 *
	 * @return void
	 */
	public function testApiListsStatuses()
	{
		$this->expectException(BadRequestException::class);

		$lists = new Statuses(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::GET]);
		$lists->run();
	}

	/**
	 * Test the api_lists_statuses() function with a list ID.
	 */
	public function testApiListsStatusesWithListId()
	{
		$lists    = new Statuses(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::GET]);
		$response = $lists->run(['list_id' => 1, 'page' => -1, 'max_id' => 10]);

		$body = (string)$response->getBody();

		self::assertJson($body);

		$json = json_decode($body);

		foreach ($json as $status) {
			self::assertIsString($status->text);
			self::assertIsInt($status->id);
		}
	}

	/**
	 * Test the api_lists_statuses() function with a list ID and a RSS result.
	 */
	public function testApiListsStatusesWithListIdAndRss()
	{
		$lists    = new Statuses(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::GET], ['extension' => 'rss']);
		$response = $lists->run(['list_id' => 1]);

		self::assertXml((string)$response->getBody());
	}

	/**
	 * Test the api_lists_statuses() function with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiListsStatusesWithUnallowedUser()
	{
		self::markTestIncomplete('Needs BasicAuth as dynamic method for overriding first');

		// $this->expectException(\Friendica\Network\HTTPException\UnauthorizedException::class);
		// BasicAuth::setCurrentUserID();
		// api_lists_statuses('json');
	}
}
