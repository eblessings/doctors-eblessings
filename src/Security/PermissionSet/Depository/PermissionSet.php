<?php
/**
 * @copyright Copyright (C) 2010-2021, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Security\PermissionSet\Depository;

use Exception;
use Friendica\BaseDepository;
use Friendica\Database\Database;
use Friendica\Model\Contact;
use Friendica\Model\Group;
use Friendica\Network\HTTPException\NotFoundException;
use Friendica\Security\PermissionSet\Factory;
use Friendica\Security\PermissionSet\Collection;
use Friendica\Security\PermissionSet\Entity;
use Friendica\Util\ACLFormatter;
use Psr\Log\LoggerInterface;

class PermissionSet extends BaseDepository
{
	/** @var int Virtual permission set id for public permission */
	const PUBLIC = 0;

	/** @var Factory\PermissionSet */
	protected $factory;

	protected static $table_name = 'permissionset';

	/** @var ACLFormatter */
	private $aclFormatter;

	public function __construct(Database $database, LoggerInterface $logger, Factory\PermissionSet $factory, ACLFormatter $aclFormatter)
	{
		parent::__construct($database, $logger, $factory);

		$this->aclFormatter = $aclFormatter;
	}

	/**
	 * @param array $condition
	 * @param array $params
	 *
	 * @return Entity\PermissionSet
	 * @throws NotFoundException
	 */
	private function selectOne(array $condition, array $params = []): Entity\PermissionSet
	{
		return parent::_selectOne($condition, $params);
	}

	private function select(array $condition, array $params = []): Collection\PermissionSets
	{
		return new Collection\PermissionSets(parent::_select($condition, $params)->getArrayCopy());
	}

	/**
	 * Converts a given PermissionSet into a DB compatible row array
	 *
	 * @param Entity\PermissionSet $permissionSet
	 *
	 * @return array
	 */
	protected function convertToTableRow(Entity\PermissionSet $permissionSet): array
	{
		return [
			'uid'       => $permissionSet->uid,
			'allow_cid' => $this->aclFormatter->toString($permissionSet->allow_cid),
			'allow_gid' => $this->aclFormatter->toString($permissionSet->allow_gid),
			'deny_cid'  => $this->aclFormatter->toString($permissionSet->deny_cid),
			'deny_gid'  => $this->aclFormatter->toString($permissionSet->deny_gid),
		];
	}

	/**
	 * @param int      $id A permissionset table row id or self::PUBLIC
	 * @param int|null $uid Should be provided when id can be self::PUBLIC
	 * @return Entity\PermissionSet
	 * @throws NotFoundException
	 */
	public function selectOneById(int $id, int $uid = null): Entity\PermissionSet
	{
		if ($id === self::PUBLIC) {
			if (empty($uid)) {
				throw new \InvalidArgumentException('Missing uid for Public permission set instantiation');
			}

			return $this->factory->createFromString($uid);
		}

		return $this->selectOne(['id' => $id]);
	}

	/**
	 * Returns a permission set collection for a given contact
	 *
	 * @param int $cid Contact id of the visitor
	 * @param int $uid User id whom the items belong, used for ownership check.
	 *
	 * @return Collection\PermissionSets
	 */
	public function selectByContactId(int $cid, int $uid): Collection\PermissionSets
	{
		$cdata = Contact::getPublicAndUserContactID($cid, $uid);
		if (!empty($cdata)) {
			$public_contact_str = $this->aclFormatter->toString($cdata['public']);
			$user_contact_str   = $this->aclFormatter->toString($cdata['user']);
			$cid                = $cdata['user'];
		} else {
			$public_contact_str = $this->aclFormatter->toString($cid);
			$user_contact_str   = '';
		}

		$groups = [];
		if (!empty($user_contact_str) && $this->db->exists('contact', [
			'id' => $cid,
			'uid' => $uid,
			'blocked' => false
		])) {
			$groups = Group::getIdsByContactId($cid);
		}

		$group_str = '<<>>'; // should be impossible to match
		foreach ($groups as $group_id) {
			$group_str .= '|<' . preg_quote($group_id) . '>';
		}

		if (!empty($user_contact_str)) {
			$condition = ["`uid` = ? AND (NOT (`deny_cid` REGEXP ? OR `deny_cid` REGEXP ? OR deny_gid REGEXP ?)
				AND (allow_cid REGEXP ? OR allow_cid REGEXP ? OR allow_gid REGEXP ? OR (allow_cid = '' AND allow_gid = '')))",
				$uid, $user_contact_str, $public_contact_str, $group_str,
				$user_contact_str, $public_contact_str, $group_str];
		} else {
			$condition = ["`uid` = ? AND (NOT (`deny_cid` REGEXP ? OR deny_gid REGEXP ?)
				AND (allow_cid REGEXP ? OR allow_gid REGEXP ? OR (allow_cid = '' AND allow_gid = '')))",
				$uid, $public_contact_str, $group_str, $public_contact_str, $group_str];
		}

		return $this->select($condition);
	}

	/**
	 * Fetch the default PermissionSet for a given user, create it if it doesn't exist
	 *
	 * @param int $uid
	 *
	 * @return Entity\PermissionSet
	 * @throws Exception
	 */
	public function selectDefaultForUser(int $uid): Entity\PermissionSet
	{
		$self_contact = Contact::selectFirst(['id'], ['uid' => $uid, 'self' => true]);

		return $this->selectOrCreate($this->factory->createFromString(
			$uid,
			$this->aclFormatter->toString($self_contact['id'])
		));
	}

	/**
	 * Fetch the empty PermissionSet for a given user, create it if it doesn't exist
	 *
	 * @param int $uid
	 *
	 * @return Entity\PermissionSet
	 */
	public function selectEmptyForUser(int $uid): Entity\PermissionSet
	{
		return $this->selectOrCreate($this->factory->createFromString($uid));
	}

	/**
	 * Selects or creates a PermissionSet based on it's fields
	 *
	 * @param Entity\PermissionSet $permissionSet
	 *
	 * @return Entity\PermissionSet
	 */
	public function selectOrCreate(Entity\PermissionSet $permissionSet): Entity\PermissionSet
	{
		if ($permissionSet->id) {
			return $permissionSet;
		}

		try {
			return $this->selectOne($this->convertToTableRow($permissionSet));
		} catch (NotFoundException $exception) {
			return $this->save($permissionSet);
		}
	}

	public function save(Entity\PermissionSet $permissionSet): Entity\PermissionSet
	{
		$fields = $this->convertToTableRow($permissionSet);

		if ($permissionSet->id) {
			$this->db->update(self::$table_name, $fields, ['id' => $permissionSet->id]);
		} else {
			$this->db->insert(self::$table_name, $fields);

			$permissionSet = $this->selectOneById($this->db->lastInsertId());
		}

		return $permissionSet;
	}
}
