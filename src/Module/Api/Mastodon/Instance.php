<?php

namespace Friendica\Module\Api\Mastodon;

use Friendica\Object\Api\Mastodon\Instance as InstanceEntity;
use Friendica\Core\System;
use Friendica\Module\Base\Api;

/**
 * @see https://docs.joinmastodon.org/api/rest/instances/
 */
class Instance extends Api
{
	/**
	 * @param array $parameters
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function rawContent(array $parameters = [])
	{
		System::jsonExit(InstanceEntity::get());
	}
}
