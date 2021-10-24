<?php

namespace Friendica\Contact\FriendSuggest\Depository;

use Friendica\BaseCollection;
use Friendica\BaseDepository;
use Friendica\Contact\FriendSuggest\Collection;
use Friendica\Contact\FriendSuggest\Entity;
use Friendica\Contact\FriendSuggest\Exception\FriendSuggestNotFoundException;
use Friendica\Contact\FriendSuggest\Exception\FriendSuggestPersistenceException;
use Friendica\Contact\FriendSuggest\Factory;
use Friendica\Database\Database;
use Friendica\Network\HTTPException\NotFoundException;
use Psr\Log\LoggerInterface;

class FriendSuggest extends BaseDepository
{
	/** @var Factory\FriendSuggest */
	protected $factory;

	protected static $table_name = 'fsuggest';

	public function __construct(Database $database, LoggerInterface $logger, Factory\FriendSuggest $factory)
	{
		parent::__construct($database, $logger, $factory);
	}

	private function convertToTableRow(Entity\FriendSuggest $fsuggest): array
	{
		return [
			'uid'     => $fsuggest->uid,
			'cid'     => $fsuggest->cid,
			'name'    => $fsuggest->name,
			'url'     => $fsuggest->url,
			'request' => $fsuggest->request,
			'photo'   => $fsuggest->photo,
			'note'    => $fsuggest->note,
		];
	}

	/**
	 * @param array $condition
	 * @param array $params
	 *
	 * @return Entity\FriendSuggest
	 *
	 * @throws NotFoundException The underlying exception if there's no FriendSuggest with the given conditions
	 */
	private function selectOne(array $condition, array $params = []): Entity\FriendSuggest
	{
		return parent::_selectOne($condition, $params);
	}

	/**
	 * @param array $condition
	 * @param array $params
	 *
	 * @return Collection\FriendSuggests
	 *
	 * @throws \Exception
	 */
	private function select(array $condition, array $params = []): Collection\FriendSuggests
	{
		return parent::_select($condition, $params);
	}

	/**
	 * @param int $id
	 *
	 * @return Entity\FriendSuggest
	 *
	 * @throws FriendSuggestNotFoundException in case there's no suggestion for this id
	 */
	public function selectOneById(int $id): Entity\FriendSuggest
	{
		try {
			return $this->selectOne(['id' => $id]);
		} catch (NotFoundException $e) {
			throw new FriendSuggestNotFoundException(sprintf('No FriendSuggest found for id %d', $id));
		}
	}

	/**
	 * @param int $cid
	 *
	 * @return Collection\FriendSuggests
	 *
	 * @throws FriendSuggestPersistenceException In case the underlying storage cannot select the suggestion
	 */
	public function selectForContact(int $cid): Collection\FriendSuggests
	{
		try {
			return $this->select(['cid' => $cid]);
		} catch (\Exception $e) {
			throw new FriendSuggestPersistenceException(sprintf('Cannot select FriendSuggestion for contact %d', $cid));
		}
	}

	/**
	 * @param Entity\FriendSuggest $fsuggest
	 *
	 * @return Entity\FriendSuggest
	 *
	 * @throws FriendSuggestNotFoundException in case the underlying storage cannot save the suggestion
	 */
	public function save(Entity\FriendSuggest $fsuggest): Entity\FriendSuggest
	{
		try {
			$fields = $this->convertToTableRow($fsuggest);

			if ($fsuggest->id) {
				$this->db->update(self::$table_name, $fields, ['id' => $fsuggest->id]);
				return $this->factory->createFromTableRow($fields);
			} else {
				$this->db->insert(self::$table_name, $fields);
				return $this->selectOneById($this->db->lastInsertId());
			}
		} catch (\Exception $exception) {
			throw new FriendSuggestNotFoundException(sprintf('Cannot insert/update the FriendSuggestion %d for user %d', $fsuggest->id, $fsuggest->uid), $exception);
		}
	}

	/**
	 * @param Collection\FriendSuggest $fsuggests
	 *
	 * @return bool
	 *
	 * @throws FriendSuggestNotFoundException in case the underlying storage cannot delete the suggestion
	 */
	public function delete(Collection\FriendSuggests $fsuggests): bool
	{
		try {
			$ids = $fsuggests->column('id');
			return $this->db->delete(self::$table_name, ['id' => $ids]);
		} catch (\Exception $exception) {
			throw new FriendSuggestNotFoundException('Cannot delete the FriendSuggestions', $exception);
		}
	}
}
