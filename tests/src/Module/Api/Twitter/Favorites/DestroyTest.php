<?php

namespace Friendica\Test\src\Module\Api\Twitter\Favorites;

use Friendica\App\Router;
use Friendica\DI;
use Friendica\Module\Api\Twitter\Favorites\Destroy;
use Friendica\Network\HTTPException\BadRequestException;
use Friendica\Test\src\Module\Api\ApiTest;

class DestroyTest extends ApiTest
{
	/**
	 * Test the api_favorites_create_destroy() function with an invalid ID.
	 *
	 * @return void
	 */
	public function testApiFavoritesCreateDestroyWithInvalidId()
	{
		$this->expectException(BadRequestException::class);

		$destroy = new Destroy(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::POST]);
		$destroy->run();
	}

	/**
	 * Test the api_favorites_create_destroy() function with the destroy action.
	 *
	 * @return void
	 */
	public function testApiFavoritesCreateDestroyWithDestroyAction()
	{
		$destroy  = new Destroy(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::POST]);
		$response = $destroy->run(['id' => 3]);

		$json = $this->toJson($response);

		self::assertStatus($json);
	}

	/**
	 * Test the api_favorites_create_destroy() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiFavoritesCreateDestroyWithoutAuthenticatedUser()
	{
		self::markTestIncomplete('Needs refactoring of Lists - replace filter_input() with $request parameter checks');

		/*
		$this->expectException(\Friendica\Network\HTTPException\UnauthorizedException::class);
		DI::args()->setArgv(['api', '1.1', 'favorites', 'create.json']);
		BasicAuth::setCurrentUserID();
		$_SESSION['authenticated'] = false;
		api_favorites_create_destroy('json');
		*/
	}
}
