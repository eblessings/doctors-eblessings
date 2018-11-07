<?php

namespace Friendica\Test\Util;

use Mockery\MockInterface;

/**
 * Trait to mock the DBA connection status
 */
trait DBAMockTrait
{
	/**
	 * @var MockInterface The mocking interface of Friendica\Database\DBA
	 */
	private $dbaMock;

	/**
	 * Mocking DBA::connect()
	 *
	 * @param bool $return True, if the connect was successful, otherwise false
	 * @param null|int $times How often the method will get used
	 */
	public function mockConnect($return = true, $times = null)
	{
		if (!isset($this->dbaMock)) {
			$this->dbaMock = \Mockery::mock('alias:Friendica\Database\DBA');
		}

		$this->dbaMock
			->shouldReceive('connect')
			->times($times)
			->andReturn($return);
	}

	/**
	 * Mocking DBA::connected()
	 *
	 * @param bool $return True, if the DB is connected, otherwise false
	 * @param null|int $times How often the method will get used
	 */
	public function mockConnected($return = true, $times = null)
	{
		if (!isset($this->dbaMock)) {
			$this->dbaMock = \Mockery::mock('alias:Friendica\Database\DBA');
		}

		$this->dbaMock
			->shouldReceive('connected')
			->times($times)
			->andReturn($return);
	}

	/**
	 * Mocking DBA::fetchFirst()
	 *
	 * @param string $arg The argument of fetchFirst
	 * @param bool $return True, if the DB is connected, otherwise false
	 * @param null|int $times How often the method will get used
	 */
	public function mockFetchFirst($arg, $return = true, $times = null)
	{
		if (!isset($this->dbaMock)) {
			$this->dbaMock = \Mockery::mock('alias:Friendica\Database\DBA');
		}

		$this->dbaMock
			->shouldReceive('fetchFirst')
			->with($arg)
			->times($times)
			->andReturn($return);
	}


	/**
	 * Mocking DBA::select()
	 *
	 * @param string $tableName The name of the table
	 * @param array $select The Select Array (Default is [])
	 * @param array $where The Where Array (Default is [])
	 * @param object $return The array to return (Default is [])
	 * @param null|int $times How often the method will get used
	 */
	public function mockSelect($tableName, $select = [], $where = [], $return = null, $times = null)
	{
		if (!isset($this->dbaMock)) {
			$this->dbaMock = \Mockery::mock('alias:Friendica\Database\DBA');
		}

		$this->dbaMock
			->shouldReceive('select')
			->with($tableName, $select, $where)
			->times($times)
			->andReturn($return);
	}

	/**
	 * Mocking DBA::selectFirst()
	 *
	 * @param string $tableName The name of the table
	 * @param array $select The Select Array (Default is [])
	 * @param array $where The Where Array (Default is [])
	 * @param array $return The array to return (Default is [])
	 * @param null|int $times How often the method will get used
	 */
	public function mockSelectFirst($tableName, $select = [], $where = [], $return = [], $times = null)
	{
		if (!isset($this->dbaMock)) {
			$this->dbaMock = \Mockery::mock('alias:Friendica\Database\DBA');
		}

		$this->dbaMock
			->shouldReceive('selectFirst')
			->with($tableName, $select, $where)
			->times($times)
			->andReturn($return);
	}

	/**
	 * Mocking DBA::isResult()
	 *
	 * @param object $record The record to test
	 * @param bool $return True, if the DB is connected, otherwise false
	 * @param null|int $times How often the method will get used
	 */
	public function mockIsResult($record, $return = true, $times = null)
	{
		if (!isset($this->dbaMock)) {
			$this->dbaMock = \Mockery::mock('alias:Friendica\Database\DBA');
		}

		$this->dbaMock
			->shouldReceive('isResult')
			->with($record)
			->times($times)
			->andReturn($return);
	}

	/**
	 * Mocking DBA::isResult()
	 *
	 * @param object $record The record to test
	 * @param array $return The array to return
	 * @param null|int $times How often the method will get used
	 */
	public function mockToArray($record = null, $return = [], $times = null)
	{
		if (!isset($this->dbaMock)) {
			$this->dbaMock = \Mockery::mock('alias:Friendica\Database\DBA');
		}

		$this->dbaMock
			->shouldReceive('toArray')
			->with($record)
			->times($times)
			->andReturn($return);
	}


	/**
	 * Mocking DBA::p()
	 *
	 * @param string $sql The SQL statement
	 * @param object $return The object to return
	 * @param null|int $times How often the method will get used
	 */
	public function mockP($sql = null, $return = null, $times = null)
	{
		if (!isset($this->dbaMock)) {
			$this->dbaMock = \Mockery::mock('alias:Friendica\Database\DBA');
		}

		if (!isset($sql)) {
			$this->dbaMock
				->shouldReceive('p')
				->times($times)
				->andReturn($return);
		} else {
			$this->dbaMock
				->shouldReceive('p')
				->with($sql)
				->times($times)
				->andReturn($return);
		}
	}
}
