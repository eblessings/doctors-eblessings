<?php

namespace Friendica\Test\src\Module\Api\Twitter\Media;

use Friendica\App\Router;
use Friendica\DI;
use Friendica\Module\Api\Twitter\Media\Upload;
use Friendica\Network\HTTPException\BadRequestException;
use Friendica\Network\HTTPException\InternalServerErrorException;
use Friendica\Network\HTTPException\UnauthorizedException;
use Friendica\Test\src\Module\Api\ApiTest;
use Friendica\Test\Util\AuthTestConfig;

class UploadTest extends ApiTest
{
	/**
	 * Test the \Friendica\Module\Api\Twitter\Media\Upload module.
	 */
	public function testApiMediaUpload()
	{
		$this->expectException(BadRequestException::class);
		$upload = new Upload(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::POST]);
		$upload->run();
	}

	/**
	 * Test the \Friendica\Module\Api\Twitter\Media\Upload module without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiMediaUploadWithoutAuthenticatedUser()
	{
		$this->expectException(UnauthorizedException::class);
		AuthTestConfig::$authenticated = false;
		(new Upload(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::POST]))->run();
	}

	/**
	 * Test the \Friendica\Module\Api\Twitter\Media\Upload module with an invalid uploaded media.
	 *
	 * @return void
	 */
	public function testApiMediaUploadWithMedia()
	{
		$this->expectException(InternalServerErrorException::class);
		$_FILES = [
			'media' => [
				'id'       => 666,
				'tmp_name' => 'tmp_name'
			]
		];
		(new Upload(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::POST]))->run();
	}

	/**
	 * Test the \Friendica\Module\Api\Twitter\Media\Upload module with an valid uploaded media.
	 *
	 * @return void
	 */
	public function testApiMediaUploadWithValidMedia()
	{
		$_FILES = [
			'media' => [
				'id'       => 666,
				'size'     => 666,
				'width'    => 666,
				'height'   => 666,
				'tmp_name' => $this->getTempImage(),
				'name'     => 'spacer.png',
				'type'     => 'image/png'
			]
		];

		$response = (new Upload(DI::app(), DI::l10n(), DI::baseUrl(), DI::args(), DI::logger(), DI::profiler(), DI::apiResponse(), ['REQUEST_METHOD' => Router::POST]))->run();

		$media = $this->toJson($response);

		self::assertEquals('image/png', $media->image->image_type);
		self::assertEquals(1, $media->image->w);
		self::assertEquals(1, $media->image->h);
		self::assertNotEmpty($media->image->friendica_preview_url);
	}

	/**
	 * Get the path to a temporary empty PNG image.
	 *
	 * @return string Path
	 */
	private function getTempImage()
	{
		$tmpFile = tempnam(sys_get_temp_dir(), 'tmp_file');
		file_put_contents(
			$tmpFile,
			base64_decode(
			// Empty 1x1 px PNG image
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=='
			)
		);

		return $tmpFile;
	}
}
