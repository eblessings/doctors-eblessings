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

namespace Friendica\Factory\Api\Mastodon;

use Friendica\BaseFactory;
use Friendica\Database\Database;
use Friendica\Model\Post;
use Friendica\Network\HTTPException;
use Psr\Log\LoggerInterface;

class ScheduledStatus extends BaseFactory
{
	/** @var Database */
	private $dba;

	public function __construct(LoggerInterface $logger, Database $dba)
	{
		parent::__construct($logger);
		$this->dba = $dba;
	}

	/**
	 * @param int $id  Id of the delayed post
	 * @param int $uid Post user
	 *
	 * @return \Friendica\Object\Api\Mastodon\ScheduledStatus
	 * @throws HTTPException\InternalServerErrorException
	 */
	public function createFromDelayedPostId(int $id, int $uid): \Friendica\Object\Api\Mastodon\ScheduledStatus
	{
		$delayed_post = $this->dba->selectFirst('delayed-post', [], ['id' => $id, 'uid' => $uid]);
		if (empty($delayed_post)) {
			throw new HTTPException\NotFoundException('Scheduled status with ID ' . $id . ' not found for user ' . $uid . '.');
		}

		$parameters = Post\Delayed::getParametersForid($delayed_post['id']);
		if (empty($parameters)) {
			throw new HTTPException\NotFoundException('Scheduled status with ID ' . $id . ' not found for user ' . $uid . '.');
		}

		return new \Friendica\Object\Api\Mastodon\ScheduledStatus($delayed_post, $parameters);
	}
}
