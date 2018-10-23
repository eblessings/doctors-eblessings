<?php

// this is in the same namespace as Install for mocking 'function_exists'
namespace Friendica\Core;

use Friendica\Test\Util\VFSTrait;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class InstallTest extends TestCase
{
	use VFSTrait;

	public function setUp()
	{
		parent::setUp(); // TODO: Change the autogenerated stub

		$this->setUpVfsDir();
	}

	private function assertCheckExist($position, $title, $help, $status, $required, $assertionArray)
	{
		$this->assertArraySubset([$position => [
			'title' => $title,
			'status' => $status,
			'required' => $required,
			'error_msg' => null,
			'help' => $help]
		], $assertionArray);
	}

	/**
	 * Replaces function_exists results with given mocks
	 *
	 * @param array $functions a list from function names and their result
	 */
	private function setFunctions($functions)
	{
		global $phpMock;
		$phpMock['function_exists'] = function($function) use ($functions) {
			foreach ($functions as $name => $value) {
				if ($function == $name) {
					return $value;
				}
			}
			return '__phpunit_continue__';
		};
	}

	/**
	 * Replaces class_exist results with given mocks
	 *
	 * @param array $classes a list from class names and their results
	 */
	private function setClasses($classes)
	{
		global $phpMock;
		$phpMock['class_exists'] = function($class) use ($classes) {
			foreach ($classes as $name => $value) {
				if ($class == $name) {
					return $value;
				}
			}
			return '__phpunit_continue__';
		};
	}

	/**
	 * @small
	 */
	public function testCheckKeys()
	{
		$this->setFunctions(['openssl_pkey_new' => false]);
		$install = new Install();
		$this->assertFalse($install->checkKeys());

		$this->setFunctions(['openssl_pkey_new' => true]);
		$install = new Install();
		$this->assertTrue($install->checkKeys());
	}

	/**
	 * @small
	 */
	public function testCheckFunctions()
	{
		$this->setFunctions(['curl_init' => false]);
		$install = new Install();
		$this->assertFalse($install->checkFunctions());
		$this->assertCheckExist(3,
			L10n::t('libCurl PHP module'),
			L10n::t('Error: libCURL PHP module required but not installed.'),
			false,
			true,
			$install->getChecks());

		$this->setFunctions(['imagecreatefromjpeg' => false]);
		$install = new Install();
		$this->assertFalse($install->checkFunctions());
		$this->assertCheckExist(4,
			L10n::t('GD graphics PHP module'),
			L10n::t('Error: GD graphics PHP module with JPEG support required but not installed.'),
			false,
			true,
			$install->getChecks());

		$this->setFunctions(['openssl_public_encrypt' => false]);
		$install = new Install();
		$this->assertFalse($install->checkFunctions());
		$this->assertCheckExist(5,
			L10n::t('OpenSSL PHP module'),
			L10n::t('Error: openssl PHP module required but not installed.'),
			false,
			true,
			$install->getChecks());

		$this->setFunctions(['mb_strlen' => false]);
		$install = new Install();
		$this->assertFalse($install->checkFunctions());
		$this->assertCheckExist(6,
			L10n::t('mb_string PHP module'),
			L10n::t('Error: mb_string PHP module required but not installed.'),
			false,
			true,
			$install->getChecks());

		$this->setFunctions(['iconv_strlen' => false]);
		$install = new Install();
		$this->assertFalse($install->checkFunctions());
		$this->assertCheckExist(7,
			L10n::t('iconv PHP module'),
			L10n::t('Error: iconv PHP module required but not installed.'),
			false,
			true,
			$install->getChecks());

		$this->setFunctions(['posix_kill' => false]);
		$install = new Install();
		$this->assertFalse($install->checkFunctions());
		$this->assertCheckExist(8,
			L10n::t('POSIX PHP module'),
			L10n::t('Error: POSIX PHP module required but not installed.'),
			false,
			true,
			$install->getChecks());

		$this->setFunctions([
			'curl_init' => true,
			'imagecreatefromjpeg' => true,
			'openssl_public_encrypt' => true,
			'mb_strlen' => true,
			'iconv_strlen' => true,
			'posix_kill' => true
		]);
		$install = new Install();
		$this->assertTrue($install->checkFunctions());
	}

	/**
	 * @small
	 */
	public function testCheckLocalIni()
	{
		$this->assertTrue($this->root->hasChild('config/local.ini.php'));

		$install = new Install();
		$this->assertTrue($install->checkLocalIni());

		$this->delConfigFile('local.ini.php');

		$this->assertFalse($this->root->hasChild('config/local.ini.php'));

		$install = new Install();
		$this->assertTrue($install->checkLocalIni());
	}

	/**
	 * @small
	 */
	public function testCheckHtAccessFail()
	{
		// Mocking the CURL Response
		$curlResult = \Mockery::mock('Friendica\Network\CurlResult');
		$curlResult
			->shouldReceive('getBody')
			->andReturn('not ok');
		$curlResult
			->shouldReceive('getRedirectUrl')
			->andReturn('');
		$curlResult
			->shouldReceive('getError')
			->andReturn('test Error');

		// Mocking the CURL Request
		$networkMock = \Mockery::mock('alias:Friendica\Util\Network');
		$networkMock
			->shouldReceive('fetchUrlFull')
			->with('https://test/install/testrewrite')
			->andReturn($curlResult);
		$networkMock
			->shouldReceive('fetchUrlFull')
			->with('http://test/install/testrewrite')
			->andReturn($curlResult);

		// Mocking that we can use CURL
		$this->setFunctions(['curl_init' => true]);

		// needed because of "normalise_link"
		require_once __DIR__ . '/../../../include/text.php';

		$install = new Install();

		$this->assertFalse($install->checkHtAccess('https://test', 'https://test'));
		$this->assertSame('test Error', $install->getChecks()[0]['error_msg']['msg']);
	}

	/**
	 * @small
	 */
	public function testCheckHtAccessWork()
	{
		// Mocking the failed CURL Response
		$curlResultF = \Mockery::mock('Friendica\Network\CurlResult');
		$curlResultF
			->shouldReceive('getBody')
			->andReturn('not ok');

		// Mocking the working CURL Response
		$curlResultW = \Mockery::mock('Friendica\Network\CurlResult');
		$curlResultW
			->shouldReceive('getBody')
			->andReturn('ok');

		// Mocking the CURL Request
		$networkMock = \Mockery::mock('alias:Friendica\Util\Network');
		$networkMock
			->shouldReceive('fetchUrlFull')
			->with('https://test/install/testrewrite')
			->andReturn($curlResultF);
		$networkMock
			->shouldReceive('fetchUrlFull')
			->with('http://test/install/testrewrite')
			->andReturn($curlResultW);

		// Mocking that we can use CURL
		$this->setFunctions(['curl_init' => true]);

		// needed because of "normalise_link"
		require_once __DIR__ . '/../../../include/text.php';

		$install = new Install();

		$this->assertTrue($install->checkHtAccess('https://test', 'https://test'));
	}

	/**
	 * @small
	 */
	public function testImagick()
	{
		$imageMock = \Mockery::mock('alias:Friendica\Object\Image');
		$imageMock
			->shouldReceive('supportedTypes')
			->andReturn(['image/gif' => 'gif']);

		$this->setClasses(['Imagick' => true]);

		$install = new Install();

		// even there is no supported type, Imagick should return true (because it is not required)
		$this->assertTrue($install->checkImagick());

		$this->assertCheckExist(1,
			L10n::t('ImageMagick supports GIF'),
			'',
			true,
			false,
			$install->getChecks());
	}

	/**
	 * @small
	 */
	public function testImagickNotFound()
	{
		$imageMock = \Mockery::mock('alias:Friendica\Object\Image');
		$imageMock
			->shouldReceive('supportedTypes')
			->andReturn([]);

		$this->setClasses(['Imagick' => true]);

		$install = new Install();

		// even there is no supported type, Imagick should return true (because it is not required)
		$this->assertTrue($install->checkImagick());
		$this->assertCheckExist(1,
			L10n::t('ImageMagick supports GIF'),
			'',
			false,
			false,
			$install->getChecks());
	}

	public function testImagickNotInstalled()
	{
		$this->setClasses(['Imagick' => false]);

		$install = new Install();

		// even there is no supported type, Imagick should return true (because it is not required)
		$this->assertTrue($install->checkImagick());
		$this->assertCheckExist(0,
			L10n::t('ImageMagick PHP extension is not installed'),
			'',
			false,
			false,
			$install->getChecks());
	}
}

/**
 * A workaround to replace the PHP native function_exists with a mocked function
 *
 * @param string $function_name the Name of the function
 *
 * @return bool true or false
 */
function function_exists($function_name)
{
	global $phpMock;
	if (isset($phpMock['function_exists'])) {
		$result = call_user_func_array($phpMock['function_exists'], func_get_args());
		if ($result !== '__phpunit_continue__') {
			return $result;
		}
	}
	return call_user_func_array('\function_exists', func_get_args());
}

function class_exists($class_name)
{
	global $phpMock;
	if (isset($phpMock['class_exists'])) {
		$result = call_user_func_array($phpMock['class_exists'], func_get_args());
		if ($result !== '__phpunit_continue__') {
			return $result;
		}
	}
	return call_user_func_array('\class_exists', func_get_args());
}
