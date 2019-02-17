<?php

namespace Friendica\Factory;

use Friendica\Core\Config\Configuration;
use Friendica\Core\Logger;
use Friendica\Network\HTTPException\InternalServerErrorException;
use Friendica\Util\Logger\FriendicaDevelopHandler;
use Friendica\Util\Logger\FriendicaIntrospectionProcessor;
use Friendica\Util\Profiler;
use Monolog;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * A logger factory
 *
 * Currently only Monolog is supported
 */
class LoggerFactory
{
	/**
	 * Creates a new PSR-3 compliant logger instances
	 *
	 * @param string        $channel The channel of the logger instance
	 * @param Configuration $config  The config
	 *
	 * @return LoggerInterface The PSR-3 compliant logger instance
	 */
	public static function create($channel, Configuration $config)
	{
		$logger = new Monolog\Logger($channel);
		$logger->pushProcessor(new Monolog\Processor\PsrLogMessageProcessor());
		$logger->pushProcessor(new Monolog\Processor\ProcessIdProcessor());
		$logger->pushProcessor(new Monolog\Processor\UidProcessor());
		$logger->pushProcessor(new FriendicaIntrospectionProcessor(LogLevel::DEBUG, [Logger::class, Profiler::class]));

		$debugging = $config->get('system', 'debugging');
		$stream    = $config->get('system', 'logfile');
		$level     = $config->get('system', 'loglevel');

		if ($debugging) {
			$loglevel = self::mapLegacyConfigDebugLevel((string)$level);
			static::addStreamHandler($logger, $stream, $loglevel);
		}

		Logger::init($logger);

		return $logger;
	}

	/**
	 * Creates a new PSR-3 compliant develop logger
	 *
	 * If you want to debug only interactions from your IP or the IP of a remote server for federation debug,
	 * you'll use this logger instance for the duration of your work.
	 *
	 * It should never get filled during normal usage of Friendica
	 *
	 * @param string        $channel The channel of the logger instance
	 * @param Configuration $config  The config
	 *
	 * @return LoggerInterface The PSR-3 compliant logger instance
	 */
	public static function createDev($channel, Configuration $config)
	{
		$debugging   = $config->get('system', 'debugging');
		$stream      = $config->get('system', 'dlogfile');
		$developerIp = $config->get('system', 'dlogip');

		if (!isset($developerIp) || !$debugging) {
			return null;
		}

		$logger = new Monolog\Logger($channel);
		$logger->pushProcessor(new Monolog\Processor\PsrLogMessageProcessor());
		$logger->pushProcessor(new Monolog\Processor\ProcessIdProcessor());
		$logger->pushProcessor(new Monolog\Processor\UidProcessor());
		$logger->pushProcessor(new FriendicaIntrospectionProcessor(LogLevel::DEBUG, ['Friendica\\Core\\Logger']));

		$logger->pushHandler(new FriendicaDevelopHandler($developerIp));

		static::addStreamHandler($logger, $stream, LogLevel::DEBUG);

		Logger::setDevLogger($logger);

		return $logger;
	}

	/**
	 * Mapping a legacy level to the PSR-3 compliant levels
	 * @see https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md#5-psrlogloglevel
	 *
	 * @param string $level the level to be mapped
	 *
	 * @return string the PSR-3 compliant level
	 */
	private static function mapLegacyConfigDebugLevel($level)
	{
		switch ($level) {
			// legacy WARNING
			case "0":
				return LogLevel::ERROR;
			// legacy INFO
			case "1":
				return LogLevel::WARNING;
			// legacy TRACE
			case "2":
				return LogLevel::NOTICE;
			// legacy DEBUG
			case "3":
				return LogLevel::INFO;
			// legacy DATA
			case "4":
				return LogLevel::DEBUG;
			// legacy ALL
			case "5":
				return LogLevel::DEBUG;
			// default if nothing set
			default:
				return $level;
		}
	}

	/**
	 * Adding a handler to a given logger instance
	 *
	 * @param LoggerInterface $logger  The logger instance
	 * @param mixed           $stream  The stream which handles the logger output
	 * @param string          $level   The level, for which this handler at least should handle logging
	 *
	 * @return void
	 *
	 * @throws InternalServerErrorException if the logger is incompatible to the logger factory
	 * @throws \Exception in case of general failures
	 */
	public static function addStreamHandler($logger, $stream, $level = LogLevel::NOTICE)
	{
		if ($logger instanceof Monolog\Logger) {
			$loglevel = Monolog\Logger::toMonologLevel($level);

			// fallback to notice if an invalid loglevel is set
			if (!is_int($loglevel)) {
				$loglevel = LogLevel::NOTICE;
			}
			$fileHandler = new Monolog\Handler\StreamHandler($stream, $loglevel);

			$formatter = new Monolog\Formatter\LineFormatter("%datetime% %channel% [%level_name%]: %message% %context% %extra%\n");
			$fileHandler->setFormatter($formatter);

			$logger->pushHandler($fileHandler);
		} else {
			throw new InternalServerErrorException('Logger instance incompatible for MonologFactory');
		}
	}
}
