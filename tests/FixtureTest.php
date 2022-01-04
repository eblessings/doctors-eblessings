<?php
/**
 * FixtureTest class.
 */

namespace Friendica\Test;

use Dice\Dice;
use Friendica\App\Arguments;
use Friendica\App\Router;
use Friendica\Core\Config\ValueObject\Cache;
use Friendica\Core\Config\Capability\IManageConfigValues;
use Friendica\Core\Session;
use Friendica\Core\Session\Capability\IHandleSessions;
use Friendica\Database\Database;
use Friendica\Database\DBStructure;
use Friendica\DI;
use Friendica\Test\Util\Database\StaticDatabase;

/**
 * Parent class for test cases requiring fixtures
 */
abstract class FixtureTest extends DatabaseTest
{
	/** @var Dice */
	protected $dice;

	/**
	 * Create variables used by tests.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$server                   = $_SERVER;
		$server['REQUEST_METHOD'] = Router::GET;

		$this->dice = (new Dice())
			->addRules(include __DIR__ . '/../static/dependencies.config.php')
			->addRule(Database::class, ['instanceOf' => StaticDatabase::class, 'shared' => true])
			->addRule(IHandleSessions::class, ['instanceOf' => Session\Type\Memory::class, 'shared' => true, 'call' => null])
			->addRule(Arguments::class, [
				'instanceOf' => Arguments::class,
				'call'       => [
					['determine', [$server, $_GET], Dice::CHAIN_CALL],
				],
			]);
		DI::init($this->dice);

		/** @var IManageConfigValues $config */
		$configCache = $this->dice->create(Cache::class);
		$configCache->set('database', 'disable_pdo', true);

		/** @var Database $dba */
		$dba = $this->dice->create(Database::class);

		$dba->setTestmode(true);

		DBStructure::checkInitialValues();

		// Load the API dataset for the whole API
		$this->loadFixture(__DIR__ . '/datasets/api.fixture.php', $dba);
	}

	protected function useHttpMethod(string $method = Router::GET)
	{
		$server                   = $_SERVER;
		$server['REQUEST_METHOD'] = $method;

		$this->dice = $this->dice
			->addRule(Arguments::class, [
				'instanceOf' => Arguments::class,
				'call'       => [
					['determine', [$server, $_GET], Dice::CHAIN_CALL],
				],
			]);

		DI::init($this->dice);
	}
}
