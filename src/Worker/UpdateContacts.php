<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
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

namespace Friendica\Worker;

use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Util\DateTimeFormat;

/**
 * Update federated contacts
 */
class UpdateContacts
{
	public static function execute()
	{
		$update_limit = DI::config()->get('system', 'contact_update_limit');
		if (empty($update_limit)) {
			return;
		}

		$updating = Worker::countWorkersByCommand('UpdateContact');
		$limit = $update_limit - $updating;
		if ($limit <= 0) {
			Logger::info('The number of currently running jobs exceed the limit');
			return;
		}

		Logger::info('Updating contact', ['count' => $limit]);

		$condition = ['self' => false];

		if (DI::config()->get('system', 'update_active_contacts')) {
			$condition = array_merge(['local-data' => true], $condition);
		}

		$condition = DBA::mergeConditions(["`next-update` < ?", DateTimeFormat::utcNow()], $condition);
		$contacts = DBA::select('contact', ['id'], $condition, ['order' => ['next-update'], 'limit' => $limit]);
		$count = 0;
		while ($contact = DBA::fetch($contacts)) {
			if (Worker::add(['priority' => PRIORITY_LOW, 'dont_fork' => true], "UpdateContact", $contact['id'])) {
				++$count;
			}
		}
		DBA::close($contacts);

		Logger::info('Initiated update for federated contacts', ['count' => $count]);
	}
}
