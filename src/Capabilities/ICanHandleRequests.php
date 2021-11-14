<?php

namespace Friendica\Capabilities;

/**
 * This interface provides the capability to handle requests from clients and returns the desired outcome
 */
interface ICanHandleRequests
{
	/**
	 * Initialization method common to both content() and post()
	 *
	 * Extend this method if you need to do any shared processing before both
	 * content() or post()
	 */
	public static function init(array $parameters = []);

	/**
	 * Module GET method to display raw content from technical endpoints
	 *
	 * Extend this method if the module is supposed to return communication data,
	 * e.g. from protocol implementations.
	 */
	public static function rawContent(array $parameters = []);

	/**
	 * Module GET method to display any content
	 *
	 * Extend this method if the module is supposed to return any display
	 * through a GET request. It can be an HTML page through templating or a
	 * XML feed or a JSON output.
	 *
	 * @return string
	 */
	public static function content(array $parameters = []);

	/**
	 * Module DELETE method to process submitted data
	 *
	 * Extend this method if the module is supposed to process DELETE requests.
	 * Doesn't display any content
	 */
	public static function delete(array $parameters = []);

	/**
	 * Module PATCH method to process submitted data
	 *
	 * Extend this method if the module is supposed to process PATCH requests.
	 * Doesn't display any content
	 */
	public static function patch(array $parameters = []);

	/**
	 * Module POST method to process submitted data
	 *
	 * Extend this method if the module is supposed to process POST requests.
	 * Doesn't display any content
	 */
	public static function post(array $parameters = []);

	/**
	 * Called after post()
	 *
	 * Unknown purpose
	 */
	public static function afterpost(array $parameters = []);

	/**
	 * Module PUT method to process submitted data
	 *
	 * Extend this method if the module is supposed to process PUT requests.
	 * Doesn't display any content
	 */
	public static function put(array $parameters = []);

	public static function getClassName(): string;

	public static function getParameters(): array;
}
