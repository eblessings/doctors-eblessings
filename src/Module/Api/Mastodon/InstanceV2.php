<?php
/**
 * @copyright Copyright (C) 2010-2023, the Friendica project
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

namespace Friendica\Module\Api\Mastodon;

use Friendica\App;
use Friendica\Core\Config\Capability\IManageConfigValues;
use Friendica\Core\L10n;
use Friendica\Core\System;
use Friendica\Database\Database;
use Friendica\Module\Api\ApiResponse;
use Friendica\Module\BaseApi;
use Friendica\Object\Api\Mastodon\InstanceV2 as InstanceEntity;
use Friendica\Util\Profiler;
use Psr\Log\LoggerInterface;

/**
 * @see https://docs.joinmastodon.org/methods/instance/
 */
class InstanceV2 extends BaseApi
{
	/** @var Database */
	private $database;

	/** @var IManageConfigValues */
	private $config;

	public function __construct(
		App $app,
		L10n $l10n,
		App\BaseURL $baseUrl,
		App\Arguments $args,
		LoggerInterface $logger,
		Profiler $profiler,
		ApiResponse $response,
		Database $database,
		IManageConfigValues $config,
		array $server,
		array $parameters = []
	) {
		parent::__construct($app, $l10n, $baseUrl, $args, $logger, $profiler, $response, $server, $parameters);

		$this->database = $database;
		$this->config   = $config;
	}

	/**
	 * @param array $request
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \Friendica\Network\HTTPException\NotFoundException
	 * @throws \ImagickException
	 */
	protected function rawContent(array $request = [])
	{
		System::jsonExit(new InstanceEntity($this->config, $this->baseUrl, $this->database, System::getRules()));
	}
}
