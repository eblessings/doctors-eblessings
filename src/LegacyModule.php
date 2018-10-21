<?php

namespace Friendica;

/**
 * This mock module enable class encapsulation of legacy global function modules.
 * After having provided the module file name, all the methods will behave like a normal Module class.
 *
 * @author Hypolite Petovan <mrpetovan@gmail.com>
 */
class LegacyModule extends BaseModule
{
	/**
	 * The module name, which is the name of the file (without the .php suffix)
	 * It's used to check for existence of the module functions.
	 *
	 * @var string
	 */
	private static $moduleName = '';

	/**
	 * The only method that needs to be called, with the module/addon file name.
	 *
	 * @param string $file_path
	 */
	public static function setModuleFile($file_path)
	{
		if (!is_readable($file_path)) {
			throw new Exception(Core\L10n::t('Legacy module file not found: %s', $file_path));
		}

		self::$moduleName = basename($file_path, '.php');

		require_once $file_path;
	}

	public static function init()
	{
		self::runModuleFunction('init');
	}

	public static function content()
	{
		return self::runModuleFunction('content');
	}

	public static function post()
	{
		self::runModuleFunction('post');
	}

	public static function afterpost()
	{
		self::runModuleFunction('afterpost');
	}

	/**
	 * Runs the module function designated by the provided suffix if it exists, the BaseModule method otherwise
	 *
	 * @param string $function_suffix
	 * @return string
	 */
	private static function runModuleFunction($function_suffix)
	{
		$function_name = static::$moduleName . '_' . $function_suffix;

		if (\function_exists($function_name)) {
			return $function_name(self::getApp());
		} else {
			return parent::{$function_suffix}();
		}
	}
}
