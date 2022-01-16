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

namespace Friendica\Module\Api\Twitter\Lists;

use Friendica\App;
use Friendica\Core\L10n;
use Friendica\Database\Database;
use Friendica\Database\DBA;
use Friendica\Factory\Api\Twitter\Status as TwitterStatus;
use Friendica\Module\BaseApi;
use Friendica\Model\Contact;
use Friendica\Model\Post;
use Friendica\Module\Api\ApiResponse;
use Friendica\Network\HTTPException;
use Friendica\Util\Profiler;
use Psr\Log\LoggerInterface;

/**
 * Returns recent statuses from users in the specified group.
 *
 * @see https://developer.twitter.com/en/docs/accounts-and-users/create-manage-lists/api-reference/get-lists-ownerships
 */
class Statuses extends BaseApi
{
	/** @var TwitterStatus */
	private $twitterStatus;

	/** @var Database */
	private $dba;

	public function __construct(Database $dba, TwitterStatus $twitterStatus, App $app, L10n $l10n, App\BaseURL $baseUrl, App\Arguments $args, LoggerInterface $logger, Profiler $profiler, ApiResponse $response, array $server, array $parameters = [])
	{
		parent::__construct($app, $l10n, $baseUrl, $args, $logger, $profiler, $response, $server, $parameters);

		$this->dba           = $dba;
		$this->twitterStatus = $twitterStatus;
	}

	protected function rawContent(array $request = [])
	{
		BaseApi::checkAllowedScope(BaseApi::SCOPE_READ);
		$uid = BaseApi::getCurrentUserID();

		if (empty($request['list_id'])) {
			throw new HTTPException\BadRequestException('list_id not specified');
		}

		// params
		$count           = $this->getRequestValue($request, 'count', 20);
		$page            = $this->getRequestValue($request, 'page', 1);
		$since_id        = $this->getRequestValue($request, 'since_id', 0);
		$max_id          = $this->getRequestValue($request, 'max_id', 0);
		$exclude_replies = $this->getRequestValue($request, 'exclude_replies', false);
		$conversation_id = $this->getRequestValue($request, 'conversation_id', 0);

		$start = max(0, ($page - 1) * $count);

		$groups    = $this->dba->selectToArray('group_member', ['contact-id'], ['gid' => $request['list_id']]);
		$gids      = array_column($groups, 'contact-id');
		$condition = ['uid' => $uid, 'gravity' => [GRAVITY_PARENT, GRAVITY_COMMENT], 'contact-id' => $gids];
		$condition = DBA::mergeConditions($condition, ["`id` > ?", $since_id]);

		if ($max_id > 0) {
			$condition[0] .= " AND `id` <= ?";
			$condition[] = $max_id;
		}
		if ($exclude_replies) {
			$condition[0] .= ' AND `gravity` = ?';
			$condition[] = GRAVITY_PARENT;
		}
		if ($conversation_id > 0) {
			$condition[0] .= " AND `parent` = ?";
			$condition[] = $conversation_id;
		}

		$params   = ['order' => ['id' => true], 'limit' => [$start, $count]];
		$statuses = Post::selectForUser($uid, [], $condition, $params);

		$include_entities = filter_var($request['include_entities'] ?? false, FILTER_VALIDATE_BOOLEAN);

		$items = [];
		while ($status = $this->dba->fetch($statuses)) {
			$items[] = $this->twitterStatus->createFromUriId($status['uri-id'], $status['uid'], $include_entities)->toArray();
		}
		$this->dba->close($statuses);

		$this->response->exit('statuses', ['status' => $items], $this->parameters['extension'] ?? null, Contact::getPublicIdByUserId($uid));
	}
}
