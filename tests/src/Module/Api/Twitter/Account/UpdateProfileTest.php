<?php

namespace Friendica\Test\src\Module\Api\Twitter\Account;

use Friendica\App\Router;
use Friendica\DI;
use Friendica\Module\Api\Twitter\Account\UpdateProfile;
use Friendica\Test\src\Module\Api\ApiTest;

class UpdateProfileTest extends ApiTest
{
	/**
	 * Test the api_account_update_profile() function.
	 */
	public function testApiAccountUpdateProfile()
	{
		$updateProfile = new UpdateProfile(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::POST], ['extension' => 'json']);
		$response      = $updateProfile->run(['name' => 'new_name', 'description' => 'new_description']);

		$body = (string)$response->getBody();

		self::assertJson($body);

		$json = json_decode($body);

		self::assertEquals(42, $json->id);
		self::assertEquals('DFRN', $json->location);
		self::assertEquals('selfcontact', $json->screen_name);
		self::assertEquals('new_name', $json->name);
		self::assertEquals('new_description', $json->description);
	}
}
