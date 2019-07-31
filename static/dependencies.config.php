<?php

use Dice\Dice;
use Friendica\App;
use Friendica\Core\Config;
use Friendica\Database\Database;
use Friendica\Factory;
use Friendica\Util;
use Psr\Log\LoggerInterface;

/**
 * The configuration defines "complex" dependencies inside Friendica
 * So this classes shouldn't be simple or their dependencies are already defined here.
 *
 * This kind of dependencies are NOT required to be defined here:
 *   - $a = new ClassA(new ClassB());
 *   - $a = new ClassA();
 *   - $a = new ClassA(Configuration $configuration);
 *
 * This kind of dependencies SHOULD be defined here:
 *   - $a = new ClassA();
 *     $b = $a->create();
 *
 *   - $a = new ClassA($creationPassedVariable);
 *
 */
return [
	'*' => [
		// marks all class result as shared for other creations, so there's just
		// one instance for the whole execution
		'shared' => true,
	],
	'$basepath' => [
		'instanceOf' => Util\BasePath::class,
		'call' => [
			['getPath', [], Dice::CHAIN_CALL],
		],
		'constructParams' => [
			dirname(__FILE__, 2),
			$_SERVER
		]
	],
	Util\BasePath::class => [
		'constructParams' => [
			dirname(__FILE__, 2),
			$_SERVER
		]
	],
	Util\ConfigFileLoader::class => [
		'shared' => true,
		'constructParams' => [
			[Dice::INSTANCE => '$basepath'],
		],
	],
	Config\Cache\ConfigCache::class => [
		'instanceOf' => Factory\ConfigFactory::class,
		'call'       => [
			['createCache', [], Dice::CHAIN_CALL],
		],
	],
	App\Mode::class => [
		'call'   => [
			['determine', [], Dice::CHAIN_CALL],
		],
	],
	Config\Configuration::class => [
		'instanceOf' => Factory\ConfigFactory::class,
		'call' => [
			['createConfig', [], Dice::CHAIN_CALL],
		],
	],
	Config\PConfiguration::class => [
		'instanceOf' => Factory\ConfigFactory::class,
		'call' => [
			['createPConfig', [], Dice::CHAIN_CALL],
		]
	],
	Database::class => [
		'constructParams' => [
			[DICE::INSTANCE => \Psr\Log\NullLogger::class],
			$_SERVER,
		],
	],
	/**
	 * Creates the Util\BaseURL
	 *
	 * Same as:
	 *   $baseURL = new Util\BaseURL($configuration, $_SERVER);
	 */
	Util\BaseURL::class => [
		'constructParams' => [
			$_SERVER,
		],
	],
	/**
	 * Create a Logger, which implements the LoggerInterface
	 *
	 * Same as:
	 *   $loggerFactory = new Factory\LoggerFactory();
	 *   $logger = $loggerFactory->create($channel, $configuration, $profiler);
	 *
	 * Attention1: We can use DICE for detecting dependencies inside "chained" calls too
	 * Attention2: The variable "$channel" is passed inside the creation of the dependencies per:
	 *    $app = $dice->create(App::class, [], ['$channel' => 'index']);
	 *    and is automatically passed as an argument with the same name
	 */
	LoggerInterface::class => [
		'instanceOf' => Factory\LoggerFactory::class,
		'call'       => [
			['create', [], Dice::CHAIN_CALL],
		],
	],
	'$devLogger' => [
		'instanceOf' => Factory\LoggerFactory::class,
		'call'       => [
			['createDev', [], Dice::CHAIN_CALL],
		]
	],
];
