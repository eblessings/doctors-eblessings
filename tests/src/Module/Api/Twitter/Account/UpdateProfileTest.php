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
		$this->useHttpMethod(Router::POST);

		$response = (new UpdateProfile(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), [], ['extension' => 'json']))
			->run([
				'name'        => 'new_name',
				'description' => 'new_description'
			]);

		$json = $this->toJson($response);

		self::assertEquals('new_name', $json->name);
		self::assertEquals('new_description', $json->description);
	}
}
