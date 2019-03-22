<?php

namespace Friendica\Util;

class BasePath
{
	/**
	 * @brief Returns the base filesystem path of the App
	 *
	 * It first checks for the internal variable, then for DOCUMENT_ROOT and
	 * finally for PWD
	 *
	 * @param string|null $basePath The default base path
	 * @param array       $server   server arguments
	 *
	 * @return string
	 *
	 * @throws \Exception if directory isn't usable
	 */
	public static function create($basePath, $server = [])
	{
		if (!$basePath && !empty($server['DOCUMENT_ROOT'])) {
			$basePath = $server['DOCUMENT_ROOT'];
		}

		if (!$basePath && !empty($server['PWD'])) {
			$basePath = $server['PWD'];
		}

		return self::getRealPath($basePath);
	}

	/**
	 * @brief Returns a normalized file path
	 *
	 * This is a wrapper for the "realpath" function.
	 * That function cannot detect the real path when some folders aren't readable.
	 * Since this could happen with some hosters we need to handle this.
	 *
	 * @param string $path The path that is about to be normalized
	 * @return string normalized path - when possible
	 */
	public static function getRealPath($path)
	{
		$normalized = realpath($path);

		if (!is_bool($normalized)) {
			return $normalized;
		} else {
			return $path;
		}
	}
}
