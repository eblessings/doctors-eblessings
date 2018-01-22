<?php
/**
 * @file src/Core/L10n.php
 */
namespace Friendica\Core;

use Friendica\Core\Config;
use dba;

require_once 'boot.php';
require_once 'include/dba.php';

/**
 * Provide Languange, Translation, and Localisation functions to the application
 * Localisation can be referred to by the numeronym L10N (as in: "L", followed by ten more letters, and then "N").
 */
class L10n
{
	/**
	 * @brief get the prefered language from the HTTP_ACCEPT_LANGUAGE header
	 */
	public static function getBrowserLanguage()
	{
		$lang_list = [];

		if (x($_SERVER, 'HTTP_ACCEPT_LANGUAGE')) {
			// break up string into pieces (languages and q factors)
			preg_match_all('/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $lang_parse);

			if (count($lang_parse[1])) {
				// go through the list of prefered languages and add a generic language
				// for sub-linguas (e.g. de-ch will add de) if not already in array
				for ($i = 0; $i < count($lang_parse[1]); $i++) {
					$lang_list[] = strtolower($lang_parse[1][$i]);
					if (strlen($lang_parse[1][$i])>3) {
						$dashpos = strpos($lang_parse[1][$i], '-');
						if (!in_array(substr($lang_parse[1][$i], 0, $dashpos), $lang_list)) {
							$lang_list[] = strtolower(substr($lang_parse[1][$i], 0, $dashpos));
						}
					}
				}
			}
		}

		// check if we have translations for the preferred languages and pick the 1st that has
		foreach ($lang_list as $lang) {
			if ($lang === 'en' || (file_exists("view/lang/$lang") && is_dir("view/lang/$lang"))) {
				$preferred = $lang;
				break;
			}
		}
		if (isset($preferred)) {
			return $preferred;
		}

		// in case none matches, get the system wide configured language, or fall back to English
		return Config::get('system', 'language', 'en');
	}

	/**
	 * @param string $language language
	 */
	public static function pushLang($language)
	{
		global $lang, $a;

		$a->langsave = $lang;

		if ($language === $lang) {
			return;
		}

		if (isset($a->strings) && count($a->strings)) {
			$a->stringsave = $a->strings;
		}
		$a->strings = [];
		self::loadTranslationTable($language);
		$lang = $language;
	}

	/**
	 * Pop language off the top of the stack
	 */
	public static function popLang()
	{
		global $lang, $a;

		if ($lang === $a->langsave) {
			return;
		}

		if (isset($a->stringsave)) {
			$a->strings = $a->stringsave;
		} else {
			$a->strings = [];
		}

		$lang = $a->langsave;
	}

	/**
	 * load string translation table for alternate language
	 *
	 * first addon strings are loaded, then globals
	 *
	 * @param string $lang language code to load
	 */
	public static function loadTranslationTable($lang)
	{
		$a = get_app();

		$a->strings = [];
		// load enabled addons strings
		$addons = dba::select('addon', ['name'], ['installed' => true]);
		while ($p = dba::fetch($addons)) {
			$name = $p['name'];
			if (file_exists("addon/$name/lang/$lang/strings.php")) {
				include("addon/$name/lang/$lang/strings.php");
			}
		}

		if (file_exists("view/lang/$lang/strings.php")) {
			include("view/lang/$lang/strings.php");
		}
	}

	/**
	 * @brief Return the localized version of the provided string with optional string interpolation
	 *
	 * This function takes a english string as parameter, and if a localized version
	 * exists for the current language, substitutes it before performing an eventual
	 * string interpolation (sprintf) with additional optional arguments.
	 *
	 * Usages:
	 * - t('This is an example')
	 * - t('URL %s returned no result', $url)
	 * - t('Current version: %s, new version: %s', $current_version, $new_version)
	 *
	 * @param string $s
	 * @return string
	 */
	public static function t($s)
	{
		$a = get_app();

		if (x($a->strings, $s)) {
			$t = $a->strings[$s];
			$s = is_array($t) ? $t[0] : $t;
		}
		if (func_num_args() > 1) {
			$args = array_slice(func_get_args(), 1);
			$s = @vsprintf($s, $args);
		}

		return $s;
	}

	/**
	 * @brief Return the localized version of a singular/plural string with optional string interpolation
	 *
	 * This function takes two english strings as parameters, singular and plural, as
	 * well as a count. If a localized version exists for the current language, they
	 * are used instead. Discrimination between singular and plural is done using the
	 * localized function if any or the default one. Finally, a string interpolation
	 * is performed using the count as parameter.
	 *
	 * Usages:
	 * - tt('Like', 'Likes', $count)
	 * - tt("%s user deleted", "%s users deleted", count($users))
	 *
	 * @global type $lang
	 * @param string $singular
	 * @param string $plural
	 * @param int $count
	 * @return string
	 */
	public static function tt($singular, $plural, $count)
	{
		global $lang;
		$a = get_app();

		if (x($a->strings, $singular)) {
			$t = $a->strings[$singular];
			if (is_array($t)) {
				$plural_function = 'string_plural_select_' . str_replace('-', '_', $lang);
				if (function_exists($plural_function)) {
					$plural_function = 'self::stringPluralSelectDefault';
				}
				$i = $plural_function($count);
				$s = $t[$i];
			} else {
				$s = $t;
			}
		} elseif (self::stringPluralSelectDefault($count)) {
			$s = $plural;
		} else {
			$s = $singular;
		}

		$s = @sprintf($s, $count);

		return $s;
	}

	/**
	 * Provide a fallback which will not collide with a function defined in any language file
	 */
	private static function stringPluralSelectDefault($n)
	{
		return $n != 1;
	}



	/**
	 * @brief Return installed languages codes as associative array
	 *
	 * Scans the view/lang directory for the existence of "strings.php" files, and
	 * returns an alphabetical list of their folder names (@-char language codes).
	 * Adds the english language if it's missing from the list.
	 *
	 * Ex: array('de' => 'de', 'en' => 'en', 'fr' => 'fr', ...)
	 *
	 * @return array
	 */
	public static function getAvailableLanguages()
	{
		$langs = [];
		$strings_file_paths = glob('view/lang/*/strings.php');

		if (is_array($strings_file_paths) && count($strings_file_paths)) {
			if (!in_array('view/lang/en/strings.php', $strings_file_paths)) {
				$strings_file_paths[] = 'view/lang/en/strings.php';
			}
			asort($strings_file_paths);
			foreach ($strings_file_paths as $strings_file_path) {
				$path_array = explode('/', $strings_file_path);
				$langs[$path_array[2]] = $path_array[2];
			}
		}
		return $langs;
	}
}
