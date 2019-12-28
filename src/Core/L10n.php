<?php
/**
 * @file src/Core/L10n.php
 */
namespace Friendica\Core;

use Friendica\BaseObject;
use Friendica\Core\L10n\L10n as L10nClass;

/**
 * Provide Language, Translation, and Localization functions to the application
 * Localization can be referred to by the numeronym L10N (as in: "L", followed by ten more letters, and then "N").
 */
class L10n extends BaseObject
{
	/**
	 * Returns the current language code
	 *
	 * @return string Language code
	 */
	public static function getCurrentLang()
	{
		return self::getClass(L10nClass::class)->getCurrentLang();
	}

	/**
	 * @param string $lang
	 *
	 * @return L10nClass The new L10n class with the new language
	 *
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function withLang(string $lang)
	{
		return self::getClass(L10nClass::class)->withLang($lang);
	}

	/**
	 * @brief Return the localized version of the provided string with optional string interpolation
	 *
	 * This function takes a english string as parameter, and if a localized version
	 * exists for the current language, substitutes it before performing an eventual
	 * string interpolation (sprintf) with additional optional arguments.
	 *
	 * Usages:
	 * - L10n::t('This is an example')
	 * - L10n::t('URL %s returned no result', $url)
	 * - L10n::t('Current version: %s, new version: %s', $current_version, $new_version)
	 *
	 * @param string $s
	 * @param array  $vars Variables to interpolate in the translation string
	 *
	 * @return string
	 */
	public static function t($s, ...$vars)
	{
		return self::getClass(L10nClass::class)->t($s, ...$vars);
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
	 * - L10n::tt('Like', 'Likes', $count)
	 * - L10n::tt("%s user deleted", "%s users deleted", count($users))
	 *
	 * @param string $singular
	 * @param string $plural
	 * @param int    $count
	 *
	 * @return string
	 * @throws \Exception
	 */
	public static function tt(string $singular, string $plural, int $count)
	{
		return self::getClass(L10nClass::class)->tt($singular, $plural, $count);
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
		return L10nClass::getAvailableLanguages();
	}

	/**
	 * @brief Translate days and months names.
	 *
	 * @param string $s String with day or month name.
	 *
	 * @return string Translated string.
	 */
	public static function getDay($s)
	{
		return self::getClass(L10nClass::class)->getDay($s);
	}

	/**
	 * @brief Translate short days and months names.
	 *
	 * @param string $s String with short day or month name.
	 *
	 * @return string Translated string.
	 */
	public static function getDayShort($s)
	{
		return self::getClass(L10nClass::class)->getDayShort($s);
	}

	/**
	 * Load poke verbs
	 *
	 * @return array index is present tense verb
	 *                 value is array containing past tense verb, translation of present, translation of past
	 * @hook poke_verbs pokes array
	 */
	public static function getPokeVerbs()
	{
		return self::getClass(L10nClass::class)->getPokeVerbs();
	}
}
