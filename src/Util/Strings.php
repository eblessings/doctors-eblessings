<?php
/**
 * @file src/Util/Strings.php
 */

namespace Friendica\Util;

/**
 * @brief This class handles string functions
 */
class Strings
{
    /**
     * @brief Generates a pseudo-random string of hexadecimal characters
     *
     * @param int $size
     * @return string
     */
    public static function getRandomHex($size = 64)
    {
        $byte_size = ceil($size / 2);

        $bytes = random_bytes($byte_size);

        $return = substr(bin2hex($bytes), 0, $size);

        return $return;
    }

    /**
     * This is our primary input filter.
     *
     * The high bit hack only involved some old IE browser, forget which (IE5/Mac?)
     * that had an XSS attack vector due to stripping the high-bit on an 8-bit character
     * after cleansing, and angle chars with the high bit set could get through as markup.
     *
     * This is now disabled because it was interfering with some legitimate unicode sequences
     * and hopefully there aren't a lot of those browsers left.
     *
     * Use this on any text input where angle chars are not valid or permitted
     * They will be replaced with safer brackets. This may be filtered further
     * if these are not allowed either.
     *
     * @param string $string Input string
     * @return string Filtered string
     */
    public static function removeTags($string) // notags()
    {
        return str_replace(["<", ">"], ['[', ']'], $string);
    }

    /**
     * @brief Use this on "body" or "content" input where angle chars shouldn't be removed,
     * and allow them to be safely displayed.
     * @param string $string
     * 
     * @return string
     */
    public static function escapeTags($string) // escape_tags()
    {
        return htmlspecialchars($string, ENT_COMPAT, 'UTF-8', false);
    }

    /**
     * @brief Generate a string that's random, but usually pronounceable. Used to generate initial passwords
     * 
     * @param int $len  length
     * 
     * @return string
     */
    public static function getRandomName($len) // autoname()
    {
        if ($len <= 0) {
            return '';
        }

        $vowels = ['a', 'a', 'ai', 'au', 'e', 'e', 'e', 'ee', 'ea', 'i', 'ie', 'o', 'ou', 'u'];

        if (mt_rand(0, 5) == 4) {
            $vowels[] = 'y';
        }

        $cons = [
                'b', 'bl', 'br',
                'c', 'ch', 'cl', 'cr',
                'd', 'dr',
                'f', 'fl', 'fr',
                'g', 'gh', 'gl', 'gr',
                'h',
                'j',
                'k', 'kh', 'kl', 'kr',
                'l',
                'm',
                'n',
                'p', 'ph', 'pl', 'pr',
                'qu',
                'r', 'rh',
                's' ,'sc', 'sh', 'sm', 'sp', 'st',
                't', 'th', 'tr',
                'v',
                'w', 'wh',
                'x',
                'z', 'zh'
            ];

        $midcons = ['ck', 'ct', 'gn', 'ld', 'lf', 'lm', 'lt', 'mb', 'mm', 'mn', 'mp',
                    'nd', 'ng', 'nk', 'nt', 'rn', 'rp', 'rt'];

        $noend = ['bl', 'br', 'cl', 'cr', 'dr', 'fl', 'fr', 'gl', 'gr',
                    'kh', 'kl', 'kr', 'mn', 'pl', 'pr', 'rh', 'tr', 'qu', 'wh', 'q'];

        $start = mt_rand(0, 2);
        if ($start == 0) {
            $table = $vowels;
        } else {
            $table = $cons;
        }

        $word = '';

        for ($x = 0; $x < $len; $x ++) {
            $r = mt_rand(0, count($table) - 1);
            $word .= $table[$r];

            if ($table == $vowels) {
                $table = array_merge($cons, $midcons);
            } else {
                $table = $vowels;
            }

        }

        $word = substr($word, 0, $len);

        foreach ($noend as $noe) {
            $noelen = strlen($noe);
            if ((strlen($word) > $noelen) && (substr($word, -$noelen) == $noe)) {
                $word = self::getRandomName($len);
                break;
            }
        }

        return $word;
    }

    /**
     * @brief translate and format the networkname of a contact
     *
     * @param string $network   Networkname of the contact (e.g. dfrn, rss and so on)
     * @param string $url       The contact url
     * 
     * @return string   Formatted network name
     */
    public static function formatNetworkName($network, $url = 0) // format_network_name()
    {
        if ($network != "") {
            if ($url != "") {
                $network_name = '<a href="'.$url.'">'.ContactSelector::networkToName($network, $url)."</a>";
            } else {
                $network_name = ContactSelector::networkToName($network);
            }

            return $network_name;
        }
    }

    /**
     * @brief Remove intentation from a text
     * 
     * @param string $text  String to be transformed.
     * @param string $chr   Optional. Indentation tag. Default tab (\t).
     * @param int    $count Optional. Default null.
     * 
     * @return string       Transformed string.
     */
    public static function deindent($text, $chr = "[\t ]", $count = NULL)
    {
        $lines = explode("\n", $text);

        if (is_null($count)) {
            $m = [];
            $k = 0;
            while ($k < count($lines) && strlen($lines[$k]) == 0) {
                $k++;
            }
            preg_match("|^" . $chr . "*|", $lines[$k], $m);
            $count = strlen($m[0]);
        }

        for ($k = 0; $k < count($lines); $k++) {
            $lines[$k] = preg_replace("|^" . $chr . "{" . $count . "}|", "", $lines[$k]);
        }

        return implode("\n", $lines);
    }

    /**
     * @brief Get byte size returned in a Data Measurement (KB, MB, GB)
     * 
     * @param int $bytes    The number of bytes to be measured
     * @param int $precision    Optional. Default 2.
     * 
     * @return string   Size with measured units.
     */
    public static function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * @brief Protect percent characters in sprintf calls
     * 
     * @param string $s String to transform.
     * 
     * @return string   Transformed string.
     */
    public static function protectSprintf($s) // protect_sprintf()
    {
        return str_replace('%', '%%', $s);
    }

    /**
     * @brief Base64 Encode URL and translate +/ to -_ Optionally strip padding.
     * 
     * @param string $s                 URL to encode
     * @param boolean $strip_padding    Optional. Default false
     * 
     * @return string   Encoded URL
     */
    public static function base64UrlEncode($s, $strip_padding = false) //base64url_encode()
    {
        $s = strtr(base64_encode($s), '+/', '-_');

        if ($strip_padding) {
            $s = str_replace('=', '', $s);
        }

        return $s;
    }

    /**
     * @brief Decode Base64 Encoded URL and translate -_ to +/
     * @param string $s URL to decode
     * 
     * @return string   Decoded URL
     */
    public static function base64url_decode($s) // base64url_decode()
    {
        if (is_array($s)) {
            Logger::log('base64url_decode: illegal input: ' . print_r(debug_backtrace(), true));
            return $s;
        }

        /*
        *  // Placeholder for new rev of salmon which strips base64 padding.
        *  // PHP base64_decode handles the un-padded input without requiring this step
        *  // Uncomment if you find you need it.
        *
        *	$l = strlen($s);
        *	if (!strpos($s,'=')) {
        *		$m = $l % 4;
        *		if ($m == 2)
        *			$s .= '==';
        *		if ($m == 3)
        *			$s .= '=';
        *	}
        *
        */

        return base64_decode(strtr($s, '-_', '+/'));
    }

    /**
     * @brief Pull out all #hashtags and @person tags from $string.
     *
     * We also get @person@domain.com - which would make
     * the regex quite complicated as tags can also
     * end a sentence. So we'll run through our results
     * and strip the period from any tags which end with one.
     * Returns array of tags found, or empty array.
     *
     * @param string $string Post content
     * 
     * @return array List of tag and person names
     */
    public static function getTags($string) // get_tags()
    {
        $ret = [];

        // Convert hashtag links to hashtags
        $string = preg_replace('/#\[url\=([^\[\]]*)\](.*?)\[\/url\]/ism', '#$2', $string);

        // ignore anything in a code block
        $string = preg_replace('/\[code\](.*?)\[\/code\]/sm', '', $string);

        // Force line feeds at bbtags
        $string = str_replace(['[', ']'], ["\n[", "]\n"], $string);

        // ignore anything in a bbtag
        $string = preg_replace('/\[(.*?)\]/sm', '', $string);

        // Match full names against @tags including the space between first and last
        // We will look these up afterward to see if they are full names or not recognisable.

        if (preg_match_all('/(@[^ \x0D\x0A,:?]+ [^ \x0D\x0A@,:?]+)([ \x0D\x0A@,:?]|$)/', $string, $matches)) {
            foreach ($matches[1] as $match) {
                if (strstr($match, ']')) {
                    // we might be inside a bbcode color tag - leave it alone
                    continue;
                }

                if (substr($match, -1, 1) === '.') {
                    $ret[] = substr($match, 0, -1);
                } else {
                    $ret[] = $match;
                }
            }
        }

        // Otherwise pull out single word tags. These can be @nickname, @first_last
        // and #hash tags.

        if (preg_match_all('/([!#@][^\^ \x0D\x0A,;:?]+)([ \x0D\x0A,;:?]|$)/', $string, $matches)) {
            foreach ($matches[1] as $match) {
                if (strstr($match, ']')) {
                    // we might be inside a bbcode color tag - leave it alone
                    continue;
                }
                if (substr($match, -1, 1) === '.') {
                    $match = substr($match,0,-1);
                }
                // ignore strictly numeric tags like #1
                if ((strpos($match, '#') === 0) && ctype_digit(substr($match, 1))) {
                    continue;
                }
                // try not to catch url fragments
                if (strpos($string, $match) && preg_match('/[a-zA-z0-9\/]/', substr($string, strpos($string, $match) - 1, 1))) {
                    continue;
                }
                $ret[] = $match;
            }
        }

        return $ret;
    }

    /**
     * @brief Check for a valid email string
     *
     * @param string $email_address Email address to be evaluated.
     * 
     * @return boolean  Value indicating whether or not the string is a valid email address.
     */
    public static function isValidEmail($email_address) // valid_email()
    {
        return preg_match('/^[_a-zA-Z0-9\-\+]+(\.[_a-zA-Z0-9\-\+]+)*@[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)+$/', $email_address);
    }

    /**
     * @brief Normalize url
     *
     * @param string $url   URL to be normalized.
     * 
     * @return string   Normalized URL.
     */
    public static function normaliseLink($url) // normalise_link()
    {
        $ret = str_replace(['https:', '//www.'], ['http:', '//'], $url);
        return rtrim($ret, '/');
    }

    /**
     * @brief Normalize OpenID identity
     * 
     * @param string $s OpenID Identity
     * 
     * @return string   normalized OpenId Identity
     */
    function normaliseOpenID($s) // normalize_openid()
    {
        return trim(str_replace(['http://', 'https://'], ['', ''], $s), '/');
    }

    /**
     * @brief Compare two URLs to see if they are the same, but ignore
     * slight but hopefully insignificant differences such as if one
     * is https and the other isn't, or if one is www.something and
     * the other isn't - and also ignore case differences.
     *
     * @param string $a first url
     * @param string $b second url
     * @return boolean True if the URLs match, otherwise False
     *
     */
    public static function compareLink($a, $b) // link_compare()
    {
        return (strcasecmp(normalise_link($a), normalise_link($b)) === 0);
    }
}
