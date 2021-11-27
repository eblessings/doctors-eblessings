<?php

namespace Friendica\Capabilities;

use Friendica\Network\HTTPException;

/**
 * This interface provides the capability to handle requests from clients and returns the desired outcome
 */
interface ICanHandleRequests
{
	/**
	 * @param array $post    The $_POST content (in case of POST)
	 * @param array $request The $_REQUEST content (in case of GET, POST)
	 *
	 * @return IRespondToRequests responding to the request handling
	 *
	 * @throws HTTPException\InternalServerErrorException
	 */
	public function run(array $post = [], array $request = []): IRespondToRequests;
}
