<?php

namespace Friendica\Test\src\Util\Logger;

use Friendica\Test\Util\VFSTrait;
use Friendica\Util\Logger\StreamLogger;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;
use Psr\Log\LogLevel;

class StreamLoggerTest extends AbstractLoggerTest
{
	use VFSTrait;

	/**
	 * @var StreamLogger
	 */
	private $logger;

	/**
	 * @var vfsStreamFile
	 */
	private $logfile;

	protected function setUp()
	{
		parent::setUp();

		$this->setUpVfsDir();
	}

	/**
	 * {@@inheritdoc}
	 */
	protected function getInstance($level = LogLevel::DEBUG)
	{
		$this->logfile = vfsStream::newFile('friendica.log')
			->at($this->root);

		$this->logger = new StreamLogger('test', $this->logfile->url(), $this->introspection, $level);

		return $this->logger;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function getContent()
	{
		return $this->logfile->getContent();
	}

	/**
	 * Test if a stream is working
	 */
	public function testStream()
	{
		$logfile = vfsStream::newFile('friendica.log')
			->at($this->root);

		$filehandler = fopen($logfile->url(), 'ab');

		$logger = new StreamLogger('test', $filehandler, $this->introspection);
		$logger->emergency('working');

		$text = $logfile->getContent();

		$this->assertLogline($text);
	}

	/**
	 * Test if the close statement is working
	 */
	public function testClose()
	{
		$logfile = vfsStream::newFile('friendica.log')
			->at($this->root);

		$logger = new StreamLogger('test', $logfile->url(), $this->introspection);
		$logger->emergency('working');
		$logger->close();
		// close doesn't affect
		$logger->emergency('working too');

		$text = $logfile->getContent();

		$this->assertLoglineNums(2, $text);
	}

	/**
	 * Test when a file isn't set
	 * @expectedException \LogicException
	 * @expectedExceptionMessage Missing stream URL.
	 */
	public function testNoUrl()
	{
		$logger = new StreamLogger('test', '', $this->introspection);

		$logger->emergency('not working');
	}

	/**
	 * Test when a file cannot be opened
	 * @expectedException \UnexpectedValueException
	 * @expectedExceptionMessageRegExp /The stream or file .* could not be opened: .* /
	 */
	public function testWrongUrl()
	{
		$logfile = vfsStream::newFile('friendica.log')
			->at($this->root)->chmod(0);

		$logger = new StreamLogger('test', $logfile->url(), $this->introspection);

		$logger->emergency('not working');
	}

	/**
	 * Test when the directory cannot get created
	 * @expectedException \UnexpectedValueException
	 * @expectedExceptionMessageRegExp /Directory .* cannot get created: .* /
	 */
	public function testWrongDir()
	{
		$logger = new StreamLogger('test', '/a/wrong/directory/file.txt', $this->introspection);

		$logger->emergency('not working');
	}

	/**
	 * Test when the minimum level is not valid
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessageRegExp /The level ".*" is not valid./
	 */
	public function testWrongMinimumLevel()
	{
		$logger = new StreamLogger('test', 'file.text', $this->introspection, 'NOPE');
	}

	/**
	 * Test when the minimum level is not valid
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessageRegExp /The level ".*" is not valid./
	 */
	public function testWrongLogLevel()
	{
		$logfile = vfsStream::newFile('friendica.log')
			->at($this->root);

		$logger = new StreamLogger('test', $logfile->url(), $this->introspection);

		$logger->log('NOPE', 'a test');
	}

	/**
	 * Test when the file is null
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage A stream must either be a resource or a string.
	 */
	public function testWrongFile()
	{
		$logger = new StreamLogger('test', null, $this->introspection);
	}
}
