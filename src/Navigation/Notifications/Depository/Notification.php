<?php

namespace Friendica\Navigation\Notifications\Depository;

use Exception;
use Friendica\BaseCollection;
use Friendica\BaseDepository;
use Friendica\Database\Database;
use Friendica\Database\DBA;
use Friendica\Model\Verb;
use Friendica\Navigation\Notifications\Collection;
use Friendica\Navigation\Notifications\Entity;
use Friendica\Navigation\Notifications\Factory;
use Friendica\Network\HTTPException\NotFoundException;
use Friendica\Util\DateTimeFormat;
use Psr\Log\LoggerInterface;

class Notification extends BaseDepository
{
	/** @var Factory\Notification  */
	protected $factory;

	protected static $table_name = 'notification';

	public function __construct(Database $database, LoggerInterface $logger, Factory\Notification $factory = null)
	{
		parent::__construct($database, $logger, $factory ?? new Factory\Notification($logger));
	}

	/**
	 * @param array $condition
	 * @param array $params
	 * @return Entity\Notification
	 * @throws NotFoundException
	 */
	private function selectOne(array $condition, array $params = []): Entity\Notification
	{
		return parent::_selectOne($condition, $params);
	}

	private function select(array $condition, array $params = []): Collection\Notifications
	{
		return new Collection\Notifications(parent::_select($condition, $params)->getArrayCopy());
	}

	public function countForUser($uid, array $condition, array $params = []): int
	{
		$condition = DBA::mergeConditions($condition, ['uid' => $uid]);

		return $this->count($condition, $params);
	}

	public function existsForUser($uid, array $condition): bool
	{
		$condition = DBA::mergeConditions($condition, ['uid' => $uid]);

		return $this->exists($condition);
	}

	/**
	 * @param int $id
	 * @return Entity\Notification
	 * @throws NotFoundException
	 */
	public function selectOneById(int $id): Entity\Notification
	{
		return $this->selectOne(['id' => $id]);
	}

	public function selectOneForUser(int $uid, array $condition, array $params = []): Entity\Notification
	{
		$condition = DBA::mergeConditions($condition, ['uid' => $uid]);

		return $this->selectOne($condition, $params);
	}

	public function selectForUser(int $uid, array $condition = [], array $params = []): Collection\Notifications
	{
		$condition = DBA::mergeConditions($condition, ['uid' => $uid]);

		return $this->select($condition, $params);
	}

	public function selectAllForUser(int $uid): Collection\Notifications
	{
		return $this->selectForUser($uid);
	}

	/**
	 * @param array    $condition
	 * @param array    $params
	 * @param int|null $min_id Retrieve models with an id no fewer than this, as close to it as possible
	 * @param int|null $max_id Retrieve models with an id no greater than this, as close to it as possible
	 * @param int      $limit
	 * @return BaseCollection
	 * @throws Exception
	 * @see _selectByBoundaries
	 */
	public function selectByBoundaries(array $condition = [], array $params = [], int $min_id = null, int $max_id = null, int $limit = self::LIMIT)
	{
		$BaseCollection = parent::_selectByBoundaries($condition, $params, $min_id, $max_id, $limit);

		return new Collection\Notifications($BaseCollection->getArrayCopy(), $BaseCollection->getTotalCount());
	}

	public function setAllSeenForUser(int $uid, array $condition = []): bool
	{
		$condition = DBA::mergeConditions($condition, ['uid' => $uid]);

		return $this->db->update(self::$table_name, ['seen' => true], $condition);
	}

	/**
	 * @param Entity\Notification $Notification
	 * @return Entity\Notification
	 * @throws Exception
	 */
	public function save(Entity\Notification $Notification): Entity\Notification
	{
		$fields = [
			'uid'           => $Notification->uid,
			'vid'           => Verb::getID($Notification->verb),
			'type'          => $Notification->type,
			'actor-id'      => $Notification->actorId,
			'target-uri-id' => $Notification->targetUriId,
			'parent-uri-id' => $Notification->parentUriId,
			'seen'          => $Notification->seen,
		];

		if ($Notification->id) {
			$this->db->update(self::$table_name, $fields, ['id' => $Notification->id]);
		} else {
			$fields['created'] = DateTimeFormat::utcNow();
			$this->db->insert(self::$table_name, $fields);

			$Notification = $this->selectOneById($this->db->lastInsertId());
		}

		return $Notification;
	}
}
