<?php

use Friendica\App;

require_once "include/template_processor.php";
require_once "include/friendica_smarty.php";
require_once "include/Smilies.php";
require_once "include/map.php";
require_once "mod/proxy.php";

if (! function_exists('replace_macros')) {
/**
 * This is our template processor
 *
 * @param string|FriendicaSmarty $s the string requiring macro substitution,
 *				or an instance of FriendicaSmarty
 * @param array $r key value pairs (search => replace)
 * @return string substituted string
 */
function replace_macros($s, $r) {

	$stamp1 = microtime(true);

	$a = get_app();

	// pass $baseurl to all templates
	$r['$baseurl'] = App::get_baseurl();

	$t = $a->template_engine();
	try {
		$output = $t->replace_macros($s, $r);
	} catch (Exception $e) {
		echo "<pre><b>" . __FUNCTION__ . "</b>: " . $e->getMessage() . "</pre>";
		killme();
	}

	$a->save_timestamp($stamp1, "rendering");

	return $output;
}}


// random string, there are 86 characters max in text mode, 128 for hex
// output is urlsafe

define('RANDOM_STRING_HEX',  0x00 );
define('RANDOM_STRING_TEXT', 0x01 );

if (! function_exists('random_string')) {
function random_string($size = 64, $type = RANDOM_STRING_HEX) {
	// generate a bit of entropy and run it through the whirlpool
	$s = hash('whirlpool', (string) rand() . uniqid(rand(),true) . (string) rand(), (($type == RANDOM_STRING_TEXT) ? true : false));
	$s = (($type == RANDOM_STRING_TEXT) ? str_replace("\n", "", base64url_encode($s,true)) : $s);
	return(substr($s,0,$size));
}}

if (! function_exists('notags')) {
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
function notags($string) {
	return str_replace(array("<", ">"), array('[', ']'), $string);

//  High-bit filter no longer used
//	return(str_replace(array("<",">","\xBA","\xBC","\xBE"), array('[',']','','',''), $string));
}}



if (! function_exists('escape_tags')) {
/**
 * use this on "body" or "content" input where angle chars shouldn't be removed,
 * and allow them to be safely displayed.
 * @param string $string
 * @return string
 */
function escape_tags($string) {
	return htmlspecialchars($string, ENT_COMPAT, 'UTF-8', false);
}}


// generate a string that's random, but usually pronounceable.
// used to generate initial passwords

if (! function_exists('autoname')) {
/**
 * generate a string that's random, but usually pronounceable.
 * used to generate initial passwords
 * @param int $len
 * @return string
 */
function autoname($len) {

	if ($len <= 0) {
		return '';
	}

	$vowels = array('a','a','ai','au','e','e','e','ee','ea','i','ie','o','ou','u');
	if (mt_rand(0, 5) == 4) {
		$vowels[] = 'y';
	}

	$cons = array(
			'b','bl','br',
			'c','ch','cl','cr',
			'd','dr',
			'f','fl','fr',
			'g','gh','gl','gr',
			'h',
			'j',
			'k','kh','kl','kr',
			'l',
			'm',
			'n',
			'p','ph','pl','pr',
			'qu',
			'r','rh',
			's','sc','sh','sm','sp','st',
			't','th','tr',
			'v',
			'w','wh',
			'x',
			'z','zh'
			);

	$midcons = array('ck','ct','gn','ld','lf','lm','lt','mb','mm', 'mn','mp',
				'nd','ng','nk','nt','rn','rp','rt');

	$noend = array('bl', 'br', 'cl','cr','dr','fl','fr','gl','gr',
				'kh', 'kl','kr','mn','pl','pr','rh','tr','qu','wh');

	$start = mt_rand(0,2);
	if ($start == 0) {
		$table = $vowels;
	} else {
		$table = $cons;
	}

	$word = '';

	for ($x = 0; $x < $len; $x ++) {
		$r = mt_rand(0,count($table) - 1);
		$word .= $table[$r];

		if ($table == $vowels) {
			$table = array_merge($cons,$midcons);
		} else {
			$table = $vowels;
		}

	}

	$word = substr($word,0,$len);

	foreach ($noend as $noe) {
		if ((strlen($word) > 2) && (substr($word, -2) == $noe)) {
			$word = substr($word, 0, -1);
			break;
		}
	}
	if (substr($word, -1) == 'q') {
		$word = substr($word, 0, -1);
	}
	return $word;
}}


// escape text ($str) for XML transport
// returns escaped text.

if (! function_exists('xmlify')) {
/**
 * escape text ($str) for XML transport
 * @param string $str
 * @return string Escaped text.
 */
function xmlify($str) {
	/// @TODO deprecated code found?
/*	$buffer = '';

	$len = mb_strlen($str);
	for ($x = 0; $x < $len; $x ++) {
		$char = mb_substr($str,$x,1);

		switch( $char ) {

			case "\r" :
				break;
			case "&" :
				$buffer .= '&amp;';
				break;
			case "'" :
				$buffer .= '&apos;';
				break;
			case "\"" :
				$buffer .= '&quot;';
				break;
			case '<' :
				$buffer .= '&lt;';
				break;
			case '>' :
				$buffer .= '&gt;';
				break;
			case "\n" :
				$buffer .= "\n";
				break;
			default :
				$buffer .= $char;
				break;
		}
	}*/
	/*
	$buffer = mb_ereg_replace("&", "&amp;", $str);
	$buffer = mb_ereg_replace("'", "&apos;", $buffer);
	$buffer = mb_ereg_replace('"', "&quot;", $buffer);
	$buffer = mb_ereg_replace("<", "&lt;", $buffer);
	$buffer = mb_ereg_replace(">", "&gt;", $buffer);
	*/
	$buffer = htmlspecialchars($str, ENT_QUOTES, "UTF-8");
	$buffer = trim($buffer);

	return($buffer);
}}

if (! function_exists('unxmlify')) {
/**
 * undo an xmlify
 * @param string $s xml escaped text
 * @return string unescaped text
 */
function unxmlify($s) {
	/// @TODO deprecated code found?
//	$ret = str_replace('&amp;','&', $s);
//	$ret = str_replace(array('&lt;','&gt;','&quot;','&apos;'),array('<','>','"',"'"),$ret);
	/*$ret = mb_ereg_replace('&amp;', '&', $s);
	$ret = mb_ereg_replace('&apos;', "'", $ret);
	$ret = mb_ereg_replace('&quot;', '"', $ret);
	$ret = mb_ereg_replace('&lt;', "<", $ret);
	$ret = mb_ereg_replace('&gt;', ">", $ret);
	*/
	$ret = htmlspecialchars_decode($s, ENT_QUOTES);
	return $ret;
}}

if (! function_exists('hex2bin')) {
/**
 * convenience wrapper, reverse the operation "bin2hex"
 * @param string $s
 * @return number
 */
function hex2bin($s) {
	if (! (is_string($s) && strlen($s))) {
		return '';
	}

	if (! ctype_xdigit($s)) {
		return $s;
	}

	return pack("H*",$s);
}}


/**
 * @brief Paginator function. Pushes relevant links in a pager array structure.
 *
 * Links are generated depending on the current page and the total number of items.
 * Inactive links (like "first" and "prev" on page 1) are given the "disabled" class.
 * Current page link is given the "active" CSS class
 *
 * @param App $a App instance
 * @param int $count [optional] item count (used with minimal pager)
 * @return Array data for pagination template
 */
function paginate_data(App $a, $count = null) {
	$stripped = preg_replace('/([&?]page=[0-9]*)/', '', $a->query_string);

	$stripped = str_replace('q=', '', $stripped);
	$stripped = trim($stripped, '/');
	$pagenum = $a->pager['page'];

	if (($a->page_offset != '') AND !preg_match('/[?&].offset=/', $stripped)) {
		$stripped .= '&offset=' . urlencode($a->page_offset);
	}

	$url = $stripped;
	$data = array();

	function _l(&$d, $name, $url, $text, $class = '') {
		if (strpos($url, '?') === false && ($pos = strpos($url, '&')) !== false) {
			$url = substr($url, 0, $pos) . '?' . substr($url, $pos + 1);
		}

		$d[$name] = array('url' => $url, 'text' => $text, 'class' => $class);
	}

	if (!is_null($count)) {
		// minimal pager (newer / older)
		$data['class'] = 'pager';
		_l($data, 'prev', $url . '&page=' . ($a->pager['page'] - 1), t('newer'), 'previous' . ($a->pager['page'] == 1 ? ' disabled' : ''));
		_l($data, 'next', $url . '&page=' . ($a->pager['page'] + 1), t('older'), 'next' . ($count <= 0 ? ' disabled' : ''));
	} else {
		// full pager (first / prev / 1 / 2 / ... / 14 / 15 / next / last)
		$data['class'] = 'pagination';
		if ($a->pager['total'] > $a->pager['itemspage']) {
			_l($data, 'first', $url . '&page=1',  t('first'), $a->pager['page'] == 1 ? 'disabled' : '');
			_l($data, 'prev', $url . '&page=' . ($a->pager['page'] - 1), t('prev'), $a->pager['page'] == 1 ? 'disabled' : '');

			$numpages = $a->pager['total'] / $a->pager['itemspage'];

			$numstart = 1;
			$numstop = $numpages;

			// Limit the number of displayed page number buttons.
			if ($numpages > 8) {
				$numstart = (($pagenum > 4) ? ($pagenum - 4) : 1);
				$numstop = (($pagenum > ($numpages - 7)) ? $numpages : ($numstart + 8));
			}

			$pages = array();

			for ($i = $numstart; $i <= $numstop; $i++) {
				if ($i == $a->pager['page']) {
					_l($pages, $i, '#',  $i, 'current active');
				} else {
					_l($pages, $i, $url . '&page='. $i, $i, 'n');
				}
			}

			if (($a->pager['total'] % $a->pager['itemspage']) != 0) {
				if ($i == $a->pager['page']) {
					_l($pages, $i, '#',  $i, 'current active');
				} else {
					_l($pages, $i, $url . '&page=' . $i, $i, 'n');
				}
			}

			$data['pages'] = $pages;

			$lastpage = (($numpages > intval($numpages)) ? intval($numpages)+1 : $numpages);
			_l($data, 'next', $url . '&page=' . ($a->pager['page'] + 1), t('next'), $a->pager['page'] == $lastpage ? 'disabled' : '');
			_l($data, 'last', $url . '&page=' . $lastpage, t('last'), $a->pager['page'] == $lastpage ? 'disabled' : '');
		}
	}

	return $data;
}

if (! function_exists('paginate')) {
/**
 * Automatic pagination.
 *
 *  To use, get the count of total items.
 * Then call $a->set_pager_total($number_items);
 * Optionally call $a->set_pager_itemspage($n) to the number of items to display on each page
 * Then call paginate($a) after the end of the display loop to insert the pager block on the page
 * (assuming there are enough items to paginate).
 * When using with SQL, the setting LIMIT %d, %d => $a->pager['start'],$a->pager['itemspage']
 * will limit the results to the correct items for the current page.
 * The actual page handling is then accomplished at the application layer.
 *
 * @param App $a App instance
 * @return string html for pagination #FIXME remove html
 */
function paginate(App $a) {

	$data = paginate_data($a);
	$tpl = get_markup_template("paginate.tpl");
	return replace_macros($tpl, array("pager" => $data));

}}

if (! function_exists('alt_pager')) {
/**
 * Alternative pager
 * @param App $a App instance
 * @param int $i
 * @return string html for pagination #FIXME remove html
 */
function alt_pager(App $a, $i) {

	$data = paginate_data($a, $i);
	$tpl = get_markup_template("paginate.tpl");
	return replace_macros($tpl, array('pager' => $data));

}}

if (! function_exists('scroll_loader')) {
/**
 * Loader for infinite scrolling
 * @return string html for loader
 */
function scroll_loader() {
	$tpl = get_markup_template("scroll_loader.tpl");
	return replace_macros($tpl, array(
		'wait' => t('Loading more entries...'),
		'end' => t('The end')
	));
}}

if (! function_exists('expand_acl')) {
/**
 * Turn user/group ACLs stored as angle bracketed text into arrays
 *
 * @param string $s
 * @return array
 */
function expand_acl($s) {
	// turn string array of angle-bracketed elements into numeric array
	// e.g. "<1><2><3>" => array(1,2,3);
	$ret = array();

	if (strlen($s)) {
		$t = str_replace('<','',$s);
		$a = explode('>',$t);
		foreach($a as $aa) {
			if (intval($aa)) {
				$ret[] = intval($aa);
			}
		}
	}
	return $ret;
}}

if (! function_exists('sanitise_acl')) {
/**
 * Wrap ACL elements in angle brackets for storage
 * @param string $item
 */
function sanitise_acl(&$item) {
	if (intval($item))
		$item = '<' . intval(notags(trim($item))) . '>';
	else
		unset($item);
}}


if (! function_exists('perms2str')) {
/**
 * Convert an ACL array to a storable string
 *
 * Normally ACL permissions will be an array.
 * We'll also allow a comma-separated string.
 *
 * @param string|array $p
 * @return string
 */
function perms2str($p) {
	$ret = '';
	if (is_array($p))
		$tmp = $p;
	else
		$tmp = explode(',',$p);

	if (is_array($tmp)) {
		array_walk($tmp,'sanitise_acl');
		$ret = implode('',$tmp);
	}
	return $ret;
}}


if (! function_exists('item_new_uri')) {
/**
 * generate a guaranteed unique (for this domain) item ID for ATOM
 * safe from birthday paradox
 *
 * @param string $hostname
 * @param int $uid
 * @return string
 */
function item_new_uri($hostname, $uid, $guid = "") {

	do {
		$dups = false;

		if ($guid == "") {
			$hash = get_guid(32);
		} else {
			$hash = $guid;
			$guid = "";
		}

		$uri = "urn:X-dfrn:" . $hostname . ':' . $uid . ':' . $hash;

		$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' LIMIT 1",
			dbesc($uri));
		if (dbm::is_result($r)) {
			$dups = true;
		}
	} while ($dups == true);

	return $uri;
}}

// Generate a guaranteed unique photo ID.
// safe from birthday paradox

if (! function_exists('photo_new_resource')) {
/**
 * Generate a guaranteed unique photo ID.
 * safe from birthday paradox
 *
 * @return string
 */
function photo_new_resource() {

	do {
		$found = false;
		$resource = hash('md5',uniqid(mt_rand(),true));
		$r = q("SELECT `id` FROM `photo` WHERE `resource-id` = '%s' LIMIT 1",
			dbesc($resource)
		);

		if (dbm::is_result($r)) {
			$found = true;
		}
	} while ($found == true);

	return $resource;
}}


if (! function_exists('load_view_file')) {
/**
 * @deprecated
 * wrapper to load a view template, checking for alternate
 * languages before falling back to the default
 *
 * @global string $lang
 * @global App $a
 * @param string $s view name
 * @return string
 */
function load_view_file($s) {
	global $lang, $a;
	if (! isset($lang)) {
		$lang = 'en';
	}
	$b = basename($s);
	$d = dirname($s);
	if (file_exists("$d/$lang/$b")) {
		$stamp1 = microtime(true);
		$content = file_get_contents("$d/$lang/$b");
		$a->save_timestamp($stamp1, "file");
		return $content;
	}

	$theme = current_theme();

	if (file_exists("$d/theme/$theme/$b")) {
		$stamp1 = microtime(true);
		$content = file_get_contents("$d/theme/$theme/$b");
		$a->save_timestamp($stamp1, "file");
		return $content;
	}

	$stamp1 = microtime(true);
	$content = file_get_contents($s);
	$a->save_timestamp($stamp1, "file");
	return $content;
}}

if (! function_exists('get_intltext_template')) {
/**
 * load a view template, checking for alternate
 * languages before falling back to the default
 *
 * @global string $lang
 * @param string $s view path
 * @return string
 */
function get_intltext_template($s) {
	global $lang;

	$a = get_app();
	$engine = '';
	if ($a->theme['template_engine'] === 'smarty3') {
		$engine = "/smarty3";
	}

	if (! isset($lang)) {
		$lang = 'en';
	}

	if (file_exists("view/lang/$lang$engine/$s")) {
		$stamp1 = microtime(true);
		$content = file_get_contents("view/lang/$lang$engine/$s");
		$a->save_timestamp($stamp1, "file");
		return $content;
	} elseif (file_exists("view/lang/en$engine/$s")) {
		$stamp1 = microtime(true);
		$content = file_get_contents("view/lang/en$engine/$s");
		$a->save_timestamp($stamp1, "file");
		return $content;
	} else {
		$stamp1 = microtime(true);
		$content = file_get_contents("view$engine/$s");
		$a->save_timestamp($stamp1, "file");
		return $content;
	}
}}

if (! function_exists('get_markup_template')) {
/**
 * load template $s
 *
 * @param string $s
 * @param string $root
 * @return string
 */
function get_markup_template($s, $root = '') {
	$stamp1 = microtime(true);

	$a = get_app();
	$t = $a->template_engine();
	try {
		$template = $t->get_template_file($s, $root);
	} catch (Exception $e) {
		echo "<pre><b>" . __FUNCTION__ . "</b>: " . $e->getMessage() . "</pre>";
		killme();
	}

	$a->save_timestamp($stamp1, "file");

	return $template;
}}

if (! function_exists("get_template_file")) {
/**
 *
 * @param App $a
 * @param string $filename
 * @param string $root
 * @return string
 */
function get_template_file($a, $filename, $root = '') {
	$theme = current_theme();

	// Make sure $root ends with a slash /
	if ($root !== '' && $root[strlen($root) - 1] !== '/') {
		$root = $root . '/';
	}

	if (file_exists("{$root}view/theme/$theme/$filename")) {
		$template_file = "{$root}view/theme/$theme/$filename";
	} elseif (x($a->theme_info, "extends") && file_exists("{$root}view/theme/{$a->theme_info[\"extends\"]}/$filename")) {
		$template_file = "{$root}view/theme/{$a->theme_info[\"extends\"]}/$filename";
	} elseif (file_exists("{$root}/$filename")) {
		$template_file = "{$root}/$filename";
	} else {
		$template_file = "{$root}view/$filename";
	}

	return $template_file;
}}







if (! function_exists('attribute_contains')) {
/**
 *  for html,xml parsing - let's say you've got
 *  an attribute foobar="class1 class2 class3"
 *  and you want to find out if it contains 'class3'.
 *  you can't use a normal sub string search because you
 *  might match 'notclass3' and a regex to do the job is
 *  possible but a bit complicated.
 *  pass the attribute string as $attr and the attribute you
 *  are looking for as $s - returns true if found, otherwise false
 *
 * @param string $attr attribute value
 * @param string $s string to search
 * @return boolean True if found, False otherwise
 */
function attribute_contains($attr, $s) {
	$a = explode(' ', $attr);
	return (count($a) && in_array($s,$a));
}}

if (! function_exists('logger')) {
/* setup int->string log level map */
$LOGGER_LEVELS = array();

/**
 * @brief Logs the given message at the given log level
 *
 * log levels:
 * LOGGER_NORMAL (default)
 * LOGGER_TRACE
 * LOGGER_DEBUG
 * LOGGER_DATA
 * LOGGER_ALL
 *
 * @global App $a
 * @global dba $db
 * @global array $LOGGER_LEVELS
 * @param string $msg
 * @param int $level
 */
function logger($msg, $level = 0) {
	$a = get_app();
	global $db;
	global $LOGGER_LEVELS;

	// turn off logger in install mode
	if (
		$a->module == 'install'
		|| ! ($db && $db->connected)
	) {
		return;
	}

	$debugging = get_config('system','debugging');
	$logfile   = get_config('system','logfile');
	$loglevel = intval(get_config('system','loglevel'));

	if (
		! $debugging
		|| ! $logfile
		|| $level > $loglevel
	) {
		return;
	}

	if (count($LOGGER_LEVELS) == 0) {
		foreach (get_defined_constants() as $k => $v) {
			if (substr($k, 0, 7) == "LOGGER_") {
				$LOGGER_LEVELS[$v] = substr($k, 7, 7);
			}
		}
	}

	$process_id = session_id();

	if ($process_id == '') {
		$process_id = get_app()->process_id;
	}

	$callers = debug_backtrace();
	$logline = sprintf("%s@%s\t[%s]:%s:%s:%s\t%s\n",
			datetime_convert(),
			$process_id,
			$LOGGER_LEVELS[$level],
			basename($callers[0]['file']),
			$callers[0]['line'],
			$callers[1]['function'],
			$msg
		);

	$stamp1 = microtime(true);
	@file_put_contents($logfile, $logline, FILE_APPEND);
	$a->save_timestamp($stamp1, "file");
}}

/**
 * @brief An alternative logger for development.
 * Works largely as logger() but allows developers
 * to isolate particular elements they are targetting
 * personally without background noise
 *
 * log levels:
 * LOGGER_NORMAL (default)
 * LOGGER_TRACE
 * LOGGER_DEBUG
 * LOGGER_DATA
 * LOGGER_ALL
 *
 * @global App $a
 * @global dba $db
 * @global array $LOGGER_LEVELS
 * @param string $msg
 * @param int $level
 */

function dlogger($msg, $level = 0) {
	$a = get_app();
	global $db;

	// turn off logger in install mode
	if (
		$a->module == 'install'
		|| ! ($db && $db->connected)
	) {
		return;
	}

	$logfile = get_config('system','dlogfile');

	if (! $logfile) {
		return;
	}

	if (count($LOGGER_LEVELS) == 0) {
		foreach (get_defined_constants() as $k => $v) {
			if (substr($k, 0, 7) == "LOGGER_") {
				$LOGGER_LEVELS[$v] = substr($k, 7, 7);
			}
		}
	}

	$process_id = session_id();

	if ($process_id == '') {
		$process_id = get_app()->process_id;
	}

	$callers = debug_backtrace();
	$logline = sprintf("%s@\t%s:\t%s:\t%s\t%s\t%s\n",
			datetime_convert(),
			$process_id,
			basename($callers[0]['file']),
			$callers[0]['line'],
			$callers[1]['function'],
			$msg
		);

	$stamp1 = microtime(true);
	@file_put_contents($logfile, $logline, FILE_APPEND);
	$a->save_timestamp($stamp1, "file");
}

if (! function_exists('activity_match')) {
/**
 * Compare activity uri. Knows about activity namespace.
 *
 * @param string $haystack
 * @param string $needle
 * @return boolean
 */
function activity_match($haystack,$needle) {
	return (($haystack === $needle) || ((basename($needle) === $haystack) && strstr($needle, NAMESPACE_ACTIVITY_SCHEMA)));
}}


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
 * @return array List of tag and person names
 */
function get_tags($string) {
	$ret = array();

	// Convert hashtag links to hashtags
	$string = preg_replace('/#\[url\=([^\[\]]*)\](.*?)\[\/url\]/ism', '#$2', $string);

	// ignore anything in a code block
	$string = preg_replace('/\[code\](.*?)\[\/code\]/sm', '', $string);

	// Force line feeds at bbtags
	$string = str_replace(array('[', ']'), array("\n[", "]\n"), $string);

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


//

if (! function_exists('qp')) {
/**
 * quick and dirty quoted_printable encoding
 *
 * @param string $s
 * @return string
 */
function qp($s) {
	return str_replace("%", "=", rawurlencode($s));
}}

if (! function_exists('contact_block')) {
/**
 * Get html for contact block.
 *
 * @template contact_block.tpl
 * @hook contact_block_end (contacts=>array, output=>string)
 * @return string
 */
function contact_block() {
	$o = '';
	$a = get_app();

	$shown = get_pconfig($a->profile['uid'],'system','display_friend_count');
	if ($shown === false) {
		$shown = 24;
	}
	if ($shown == 0) {
		return;
	}

	if ((! is_array($a->profile)) || ($a->profile['hide-friends'])) {
		return $o;
	}
	$r = q("SELECT COUNT(*) AS `total` FROM `contact`
			WHERE `uid` = %d AND NOT `self` AND NOT `blocked`
				AND NOT `pending` AND NOT `hidden` AND NOT `archive`
				AND `network` IN ('%s', '%s', '%s')",
			intval($a->profile['uid']),
			dbesc(NETWORK_DFRN),
			dbesc(NETWORK_OSTATUS),
			dbesc(NETWORK_DIASPORA)
	);
	if (dbm::is_result($r)) {
		$total = intval($r[0]['total']);
	}
	if (! $total) {
		$contacts = t('No contacts');
		$micropro = null;
	} else {
		// Splitting the query in two parts makes it much faster
		$r = q("SELECT `id` FROM `contact`
				WHERE `uid` = %d AND NOT `self` AND NOT `blocked`
					AND NOT `pending` AND NOT `hidden` AND NOT `archive`
					AND `network` IN ('%s', '%s', '%s')
				ORDER BY RAND() LIMIT %d",
				intval($a->profile['uid']),
				dbesc(NETWORK_DFRN),
				dbesc(NETWORK_OSTATUS),
				dbesc(NETWORK_DIASPORA),
				intval($shown)
		);
		if (dbm::is_result($r)) {
			$contacts = array();
			foreach ($r AS $contact) {
				$contacts[] = $contact["id"];
			}
			$r = q("SELECT `id`, `uid`, `addr`, `url`, `name`, `thumb`, `network` FROM `contact` WHERE `id` IN (%s)",
				dbesc(implode(",", $contacts)));

			if (dbm::is_result($r)) {
				$contacts = sprintf( tt('%d Contact','%d Contacts', $total),$total);
				$micropro = Array();
				foreach ($r as $rr) {
					$micropro[] = micropro($rr,true,'mpfriend');
				}
			}
		}
	}

	$tpl = get_markup_template('contact_block.tpl');
	$o = replace_macros($tpl, array(
		'$contacts' => $contacts,
		'$nickname' => $a->profile['nickname'],
		'$viewcontacts' => t('View Contacts'),
		'$micropro' => $micropro,
	));

	$arr = array('contacts' => $r, 'output' => $o);

	call_hooks('contact_block_end', $arr);
	return $o;

}}

/**
 * @brief Format contacts as picture links or as texxt links
 *
 * @param array $contact Array with contacts which contains an array with
 *	int 'id' => The ID of the contact
 *	int 'uid' => The user ID of the user who owns this data
 *	string 'name' => The name of the contact
 *	string 'url' => The url to the profile page of the contact
 *	string 'addr' => The webbie of the contact (e.g.) username@friendica.com
 *	string 'network' => The network to which the contact belongs to
 *	string 'thumb' => The contact picture
 *	string 'click' => js code which is performed when clicking on the contact
 * @param boolean $redirect If true try to use the redir url if it's possible
 * @param string $class CSS class for the
 * @param boolean $textmode If true display the contacts as text links
 *	if false display the contacts as picture links

 * @return string Formatted html
 */
function micropro($contact, $redirect = false, $class = '', $textmode = false) {

	// Use the contact URL if no address is available
	if ($contact["addr"] == "") {
		$contact["addr"] = $contact["url"];
	}

	$url = $contact['url'];
	$sparkle = '';
	$redir = false;

	if ($redirect) {
		$a = get_app();
		$redirect_url = 'redir/' . $contact['id'];
		if (local_user() && ($contact['uid'] == local_user()) && ($contact['network'] === NETWORK_DFRN)) {
			$redir = true;
			$url = $redirect_url;
			$sparkle = ' sparkle';
		} else {
			$url = zrl($url);
		}
	}

	// If there is some js available we don't need the url
	if (x($contact, 'click')) {
		$url = '';
	}

	return replace_macros(get_markup_template(($textmode)?'micropro_txt.tpl':'micropro_img.tpl'),array(
		'$click' => (($contact['click']) ? $contact['click'] : ''),
		'$class' => $class,
		'$url' => $url,
		'$photo' => proxy_url($contact['thumb'], false, PROXY_SIZE_THUMB),
		'$name' => $contact['name'],
		'title' => $contact['name'] . ' [' . $contact['addr'] . ']',
		'$parkle' => $sparkle,
		'$redir' => $redir,

	));
}



if (! function_exists('search')) {
/**
 * search box
 *
 * @param string $s search query
 * @param string $id html id
 * @param string $url search url
 * @param boolean $savedsearch show save search button
 */
function search($s, $id = 'search-box', $url = 'search', $save = false, $aside = true) {
	$a = get_app();

	$values = array(
			'$s' => htmlspecialchars($s),
			'$id' => $id,
			'$action_url' => $url,
			'$search_label' => t('Search'),
			'$save_label' => t('Save'),
			'$savedsearch' => feature_enabled(local_user(),'savedsearch'),
			'$search_hint' => t('@name, !forum, #tags, content'),
		);

	if (!$aside) {
		$values['$searchoption'] = array(
					t("Full Text"),
					t("Tags"),
					t("Contacts"));

		if (get_config('system','poco_local_search')) {
			$values['$searchoption'][] = t("Forums");
		}
	}

	return replace_macros(get_markup_template('searchbox.tpl'), $values);
}}

if (! function_exists('valid_email')) {
/**
 * Check if $x is a valid email string
 *
 * @param string $x
 * @return boolean
 */
function valid_email($x){

	/// @TODO Removed because Fabio told me so.
	//if (get_config('system','disable_email_validation'))
	//	return true;
	return preg_match('/^[_a-zA-Z0-9\-\+]+(\.[_a-zA-Z0-9\-\+]+)*@[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)+$/', $x);
}}


if (! function_exists('linkify')) {
/**
 * Replace naked text hyperlink with HTML formatted hyperlink
 *
 * @param string $s
 */
function linkify($s) {
	$s = preg_replace("/(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\'\%\$\!\+]*)/", ' <a href="$1" target="_blank">$1</a>', $s);
	$s = preg_replace("/\<(.*?)(src|href)=(.*?)\&amp\;(.*?)\>/ism",'<$1$2=$3&$4>',$s);
	return $s;
}}


/**
 * Load poke verbs
 *
 * @return array index is present tense verb
				 value is array containing past tense verb, translation of present, translation of past
 * @hook poke_verbs pokes array
 */
function get_poke_verbs() {

	// index is present tense verb
	// value is array containing past tense verb, translation of present, translation of past

	$arr = array(
		'poke' => array( 'poked', t('poke'), t('poked')),
		'ping' => array( 'pinged', t('ping'), t('pinged')),
		'prod' => array( 'prodded', t('prod'), t('prodded')),
		'slap' => array( 'slapped', t('slap'), t('slapped')),
		'finger' => array( 'fingered', t('finger'), t('fingered')),
		'rebuff' => array( 'rebuffed', t('rebuff'), t('rebuffed')),
	);
	call_hooks('poke_verbs', $arr);
	return $arr;
}

/**
 * Load moods
 * @return array index is mood, value is translated mood
 * @hook mood_verbs moods array
 */
function get_mood_verbs() {

	$arr = array(
		'happy'      => t('happy'),
		'sad'        => t('sad'),
		'mellow'     => t('mellow'),
		'tired'      => t('tired'),
		'perky'      => t('perky'),
		'angry'      => t('angry'),
		'stupefied'  => t('stupified'),
		'puzzled'    => t('puzzled'),
		'interested' => t('interested'),
		'bitter'     => t('bitter'),
		'cheerful'   => t('cheerful'),
		'alive'      => t('alive'),
		'annoyed'    => t('annoyed'),
		'anxious'    => t('anxious'),
		'cranky'     => t('cranky'),
		'disturbed'  => t('disturbed'),
		'frustrated' => t('frustrated'),
		'motivated'  => t('motivated'),
		'relaxed'    => t('relaxed'),
		'surprised'  => t('surprised'),
	);

	call_hooks('mood_verbs', $arr);
	return $arr;
}

if (! function_exists('day_translate')) {
/**
 * Translate days and months names
 *
 * @param string $s
 * @return string
 */
function day_translate($s) {
	$ret = str_replace(array('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
		array( t('Monday'), t('Tuesday'), t('Wednesday'), t('Thursday'), t('Friday'), t('Saturday'), t('Sunday')),
		$s);

	$ret = str_replace(array('January','February','March','April','May','June','July','August','September','October','November','December'),
		array( t('January'), t('February'), t('March'), t('April'), t('May'), t('June'), t('July'), t('August'), t('September'), t('October'), t('November'), t('December')),
		$ret);

	return $ret;
}}


if (! function_exists('normalise_link')) {
/**
 * Normalize url
 *
 * @param string $url
 * @return string
 */
function normalise_link($url) {
	$ret = str_replace(array('https:', '//www.'), array('http:', '//'), $url);
	return rtrim($ret,'/');
}}



if (! function_exists('link_compare')) {
/**
 * Compare two URLs to see if they are the same, but ignore
 * slight but hopefully insignificant differences such as if one
 * is https and the other isn't, or if one is www.something and
 * the other isn't - and also ignore case differences.
 *
 * @param string $a first url
 * @param string $b second url
 * @return boolean True if the URLs match, otherwise False
 *
 */
function link_compare($a,$b) {
	return (strcasecmp(normalise_link($a), normalise_link($b)) === 0);
}}

/**
 * @brief Find any non-embedded images in private items and add redir links to them
 *
 * @param App $a
 * @param array &$item The field array of an item row
 */
function redir_private_images($a, &$item)
{
	$matches = false;
	$cnt = preg_match_all('|\[img\](http[^\[]*?/photo/[a-fA-F0-9]+?(-[0-9]\.[\w]+?)?)\[\/img\]|', $item['body'], $matches, PREG_SET_ORDER);
	if ($cnt) {
		foreach ($matches as $mtch) {
			if (strpos($mtch[1], '/redir') !== false) {
				continue;
			}

			if ((local_user() == $item['uid']) && ($item['private'] != 0) && ($item['contact-id'] != $a->contact['id']) && ($item['network'] == NETWORK_DFRN)) {
				$img_url = 'redir?f=1&quiet=1&url=' . urlencode($mtch[1]) . '&conurl=' . urlencode($item['author-link']);
				$item['body'] = str_replace($mtch[0], '[img]' . $img_url . '[/img]', $item['body']);
			}
		}
	}
}

function put_item_in_cache(&$item, $update = false) {

	if (($item["rendered-hash"] != hash("md5", $item["body"])) OR ($item["rendered-hash"] == "") OR
		($item["rendered-html"] == "") OR get_config("system", "ignore_cache")) {

		// The function "redir_private_images" changes the body.
		// I'm not sure if we should store it permanently, so we save the old value.
		$body = $item["body"];

		$a = get_app();
		redir_private_images($a, $item);

		$item["rendered-html"] = prepare_text($item["body"]);
		$item["rendered-hash"] = hash("md5", $item["body"]);
		$item["body"] = $body;

		if ($update AND ($item["id"] != 0)) {
			q("UPDATE `item` SET `rendered-html` = '%s', `rendered-hash` = '%s' WHERE `id` = %d",
				dbesc($item["rendered-html"]), dbesc($item["rendered-hash"]), intval($item["id"]));
		}
	}
}

// Given an item array, convert the body element from bbcode to html and add smilie icons.
// If attach is true, also add icons for item attachments

if (! function_exists('prepare_body')) {
/**
 * Given an item array, convert the body element from bbcode to html and add smilie icons.
 * If attach is true, also add icons for item attachments
 *
 * @param array $item
 * @param boolean $attach
 * @return string item body html
 * @hook prepare_body_init item array before any work
 * @hook prepare_body ('item'=>item array, 'html'=>body string) after first bbcode to html
 * @hook prepare_body_final ('item'=>item array, 'html'=>body string) after attach icons and blockquote special case handling (spoiler, author)
 */
function prepare_body(&$item,$attach = false, $preview = false) {

	$a = get_app();
	call_hooks('prepare_body_init', $item);

	$searchpath = z_root()."/search?tag=";

	$tags=array();
	$hashtags = array();
	$mentions = array();

	if (!get_config('system','suppress_tags')) {
		$taglist = q("SELECT `type`, `term`, `url` FROM `term` WHERE `otype` = %d AND `oid` = %d AND `type` IN (%d, %d) ORDER BY `tid`",
				intval(TERM_OBJ_POST), intval($item['id']), intval(TERM_HASHTAG), intval(TERM_MENTION));

		foreach ($taglist as $tag) {

			if ($tag["url"] == "") {
				$tag["url"] = $searchpath.strtolower($tag["term"]);
			}

			if ($tag["type"] == TERM_HASHTAG) {
				$hashtags[] = "#<a href=\"".$tag["url"]."\" target=\"_blank\">".$tag["term"]."</a>";
				$prefix = "#";
			} elseif ($tag["type"] == TERM_MENTION) {
				$mentions[] = "@<a href=\"".$tag["url"]."\" target=\"_blank\">".$tag["term"]."</a>";
				$prefix = "@";
			}
			$tags[] = $prefix."<a href=\"".$tag["url"]."\" target=\"_blank\">".$tag["term"]."</a>";
		}
	}

	$item['tags'] = $tags;
	$item['hashtags'] = $hashtags;
	$item['mentions'] = $mentions;

	// Update the cached values if there is no "zrl=..." on the links
	$update = (!local_user() and !remote_user() and ($item["uid"] == 0));

	// Or update it if the current viewer is the intented viewer
	if (($item["uid"] == local_user()) AND ($item["uid"] != 0))
		$update = true;

	put_item_in_cache($item, $update);
	$s = $item["rendered-html"];

	$prep_arr = array('item' => $item, 'html' => $s, 'preview' => $preview);
	call_hooks('prepare_body', $prep_arr);
	$s = $prep_arr['html'];

	if (! $attach) {
		// Replace the blockquotes with quotes that are used in mails
		$mailquote = '<blockquote type="cite" class="gmail_quote" style="margin:0 0 0 .8ex;border-left:1px #ccc solid;padding-left:1ex;">';
		$s = str_replace(array('<blockquote>', '<blockquote class="spoiler">', '<blockquote class="author">'), array($mailquote, $mailquote, $mailquote), $s);
		return $s;
	}

	$as = '';
	$vhead = false;
	$arr = explode('[/attach],',$item['attach']);
	if (count($arr)) {
		$as .= '<div class="body-attach">';
		foreach ($arr as $r) {
			$matches = false;
			$icon = '';
			$cnt = preg_match_all('|\[attach\]href=\"(.*?)\" length=\"(.*?)\" type=\"(.*?)\" title=\"(.*?)\"|',$r,$matches, PREG_SET_ORDER);
			if ($cnt) {
				foreach ($matches as $mtch) {
					$mime = $mtch[3];

					if ((local_user() == $item['uid']) && ($item['contact-id'] != $a->contact['id']) && ($item['network'] == NETWORK_DFRN))
						$the_url = 'redir/' . $item['contact-id'] . '?f=1&url=' . $mtch[1];
					else
						$the_url = $mtch[1];

					if (strpos($mime, 'video') !== false) {
						if (!$vhead) {
							$vhead = true;
							$a->page['htmlhead'] .= replace_macros(get_markup_template('videos_head.tpl'), array(
								'$baseurl' => z_root(),
							));
							$a->page['end'] .= replace_macros(get_markup_template('videos_end.tpl'), array(
								'$baseurl' => z_root(),
							));
						}

						$id = end(explode('/', $the_url));
						$as .= replace_macros(get_markup_template('video_top.tpl'), array(
							'$video'	=> array(
								'id'       => $id,
								'title' 	=> t('View Video'),
								'src'     	=> $the_url,
								'mime'		=> $mime,
							),
						));
					}

					$filetype = strtolower(substr( $mime, 0, strpos($mime,'/') ));
					if ($filetype) {
						$filesubtype = strtolower(substr( $mime, strpos($mime,'/') + 1 ));
						$filesubtype = str_replace('.', '-', $filesubtype);
					}
					else {
						$filetype = 'unkn';
						$filesubtype = 'unkn';
					}

					$icon = '<div class="attachtype icon s22 type-' . $filetype . ' subtype-' . $filesubtype . '"></div>';
					/*$icontype = strtolower(substr($mtch[3],0,strpos($mtch[3],'/')));
					switch($icontype) {
						case 'video':
						case 'audio':
						case 'image':
						case 'text':
							$icon = '<div class="attachtype icon s22 type-' . $icontype . '"></div>';
							break;
						default:
							$icon = '<div class="attachtype icon s22 type-unkn"></div>';
							break;
					}*/

					$title = ((strlen(trim($mtch[4]))) ? escape_tags(trim($mtch[4])) : escape_tags($mtch[1]));
					$title .= ' ' . $mtch[2] . ' ' . t('bytes');

					$as .= '<a href="' . strip_tags($the_url) . '" title="' . $title . '" class="attachlink" target="_blank" >' . $icon . '</a>';
				}
			}
		}
		$as .= '<div class="clear"></div></div>';
	}
	$s = $s . $as;

	// map
	if (strpos($s, '<div class="map">') !== false && $item['coord']) {
		$x = generate_map(trim($item['coord']));
		if ($x) {
			$s = preg_replace('/\<div class\=\"map\"\>/','$0' . $x,$s);
		}
	}


	// Look for spoiler
	$spoilersearch = '<blockquote class="spoiler">';

	// Remove line breaks before the spoiler
	while ((strpos($s, "\n" . $spoilersearch) !== false)) {
		$s = str_replace("\n" . $spoilersearch, $spoilersearch, $s);
	}
	while ((strpos($s, "<br />" . $spoilersearch) !== false)) {
		$s = str_replace("<br />" . $spoilersearch, $spoilersearch, $s);
	}

	while ((strpos($s, $spoilersearch) !== false)) {
		$pos = strpos($s, $spoilersearch);
		$rnd = random_string(8);
		$spoilerreplace = '<br /> <span id="spoiler-wrap-' . $rnd . '" class="spoiler-wrap fakelink" onclick="openClose(\'spoiler-' . $rnd . '\');">' . sprintf(t('Click to open/close')) . '</span>'.
					'<blockquote class="spoiler" id="spoiler-' . $rnd . '" style="display: none;">';
		$s = substr($s, 0, $pos) . $spoilerreplace . substr($s, $pos + strlen($spoilersearch));
	}

	// Look for quote with author
	$authorsearch = '<blockquote class="author">';

	while ((strpos($s, $authorsearch) !== false)) {
		$pos = strpos($s, $authorsearch);
		$rnd = random_string(8);
		$authorreplace = '<br /> <span id="author-wrap-' . $rnd . '" class="author-wrap fakelink" onclick="openClose(\'author-' . $rnd . '\');">' . sprintf(t('Click to open/close')) . '</span>'.
					'<blockquote class="author" id="author-' . $rnd . '" style="display: block;">';
		$s = substr($s, 0, $pos) . $authorreplace . substr($s, $pos + strlen($authorsearch));
	}

	// replace friendica image url size with theme preference
	if (x($a->theme_info, 'item_image_size')){
		$ps = $a->theme_info['item_image_size'];
		$s = preg_replace('|(<img[^>]+src="[^"]+/photo/[0-9a-f]+)-[0-9]|', "$1-" . $ps, $s);
	}

	$prep_arr = array('item' => $item, 'html' => $s);
	call_hooks('prepare_body_final', $prep_arr);

	return $prep_arr['html'];
}}


if (! function_exists('prepare_text')) {
/**
 * Given a text string, convert from bbcode to html and add smilie icons.
 *
 * @param string $text
 * @return string
 */
function prepare_text($text) {

	require_once 'include/bbcode.php';

	if (stristr($text, '[nosmile]')) {
		$s = bbcode($text);
	} else {
		$s = Smilies::replace(bbcode($text));
	}

	return trim($s);
}}



/**
 * return array with details for categories and folders for an item
 *
 * @param array $item
 * @return array
 *
  * [
 *      [ // categories array
 *          {
 *               'name': 'category name',
 *               'removeurl': 'url to remove this category',
 *               'first': 'is the first in this array? true/false',
 *               'last': 'is the last in this array? true/false',
 *           } ,
 *           ....
 *       ],
 *       [ //folders array
 *			{
 *               'name': 'folder name',
 *               'removeurl': 'url to remove this folder',
 *               'first': 'is the first in this array? true/false',
 *               'last': 'is the last in this array? true/false',
 *           } ,
 *           ....
 *       ]
 *  ]
 */
function get_cats_and_terms($item) {

	$a = get_app();
	$categories = array();
	$folders = array();

	$matches = false; $first = true;
	$cnt = preg_match_all('/<(.*?)>/',$item['file'],$matches,PREG_SET_ORDER);
	if ($cnt) {
		foreach ($matches as $mtch) {
			$categories[] = array(
				'name' => xmlify(file_tag_decode($mtch[1])),
				'url' =>  "#",
				'removeurl' => ((local_user() == $item['uid'])?'filerm/' . $item['id'] . '?f=&cat=' . xmlify(file_tag_decode($mtch[1])):""),
				'first' => $first,
				'last' => false
			);
			$first = false;
		}
	}

	if (count($categories)) {
		$categories[count($categories) - 1]['last'] = true;
	}

	if (local_user() == $item['uid']) {
		$matches = false; $first = true;
		$cnt = preg_match_all('/\[(.*?)\]/',$item['file'],$matches,PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$folders[] = array(
					'name' => xmlify(file_tag_decode($mtch[1])),
					'url' =>  "#",
					'removeurl' => ((local_user() == $item['uid']) ? 'filerm/' . $item['id'] . '?f=&term=' . xmlify(file_tag_decode($mtch[1])) : ""),
					'first' => $first,
					'last' => false
				);
				$first = false;
			}
		}
	}

	if (count($folders)) {
		$folders[count($folders) - 1]['last'] = true;
	}

	return array($categories, $folders);
}

if (! function_exists('get_plink')) {
/**
 * get private link for item
 * @param array $item
 * @return boolean|array False if item has not plink, otherwise array('href'=>plink url, 'title'=>translated title)
 */
function get_plink($item) {
	$a = get_app();

	if ($a->user['nickname'] != "") {
		$ret = array(
				//'href' => "display/" . $a->user['nickname'] . "/" . $item['id'],
				'href' => "display/" . $item['guid'],
				'orig' => "display/" . $item['guid'],
				'title' => t('View on separate page'),
				'orig_title' => t('view on separate page'),
			);

		if (x($item, 'plink')) {
			$ret["href"] = $a->remove_baseurl($item['plink']);
			$ret["title"] = t('link to source');
		}

	} elseif (x($item, 'plink') && ($item['private'] != 1)) {
		$ret = array(
				'href' => $item['plink'],
				'orig' => $item['plink'],
				'title' => t('link to source'),
			);
	} else {
		$ret = array();
	}

	//if (x($item,'plink') && ($item['private'] != 1))

	return($ret);
}}

if (! function_exists('unamp')) {
/**
 * replace html amp entity with amp char
 * @param string $s
 * @return string
 */
function unamp($s) {
	return str_replace('&amp;', '&', $s);
}}


if (! function_exists('return_bytes')) {
/**
 * return number of bytes in size (K, M, G)
 * @param string $size_str
 * @return number
 */
function return_bytes ($size_str) {
	switch (substr ($size_str, -1)) {
		case 'M': case 'm': return (int)$size_str * 1048576;
		case 'K': case 'k': return (int)$size_str * 1024;
		case 'G': case 'g': return (int)$size_str * 1073741824;
		default: return $size_str;
	}
}}

/**
 * @return string
 */
function generate_user_guid() {
	$found = true;
	do {
		$guid = get_guid(32);
		$x = q("SELECT `uid` FROM `user` WHERE `guid` = '%s' LIMIT 1",
			dbesc($guid)
		);
		if (! dbm::is_result($x)) {
			$found = false;
		}
	} while ($found == true );

	return $guid;
}


/**
 * @param string $s
 * @param boolean $strip_padding
 * @return string
 */
function base64url_encode($s, $strip_padding = false) {

	$s = strtr(base64_encode($s), '+/', '-_');

	if ($strip_padding) {
		$s = str_replace('=','',$s);
	}

	return $s;
}

/**
 * @param string $s
 * @return string
 */
function base64url_decode($s) {

	if (is_array($s)) {
		logger('base64url_decode: illegal input: ' . print_r(debug_backtrace(), true));
		return $s;
	}

/*
 *  // Placeholder for new rev of salmon which strips base64 padding.
 *  // PHP base64_decode handles the un-padded input without requiring this step
 *  // Uncomment if you find you need it.
 *
 *	$l = strlen($s);
 *	if (! strpos($s,'=')) {
 *		$m = $l % 4;
 *		if ($m == 2)
 *			$s .= '==';
 *		if ($m == 3)
 *			$s .= '=';
 *	}
 *
 */

	return base64_decode(strtr($s,'-_','+/'));
}


if (!function_exists('str_getcsv')) {
	/**
	 * Parse csv string
	 *
	 * @param string $input
	 * @param string $delimiter
	 * @param string $enclosure
	 * @param string $escape
	 * @param string $eol
	 * @return boolean|array False on error, otherwise array[row][column]
	 */
function str_getcsv($input, $delimiter = ',', $enclosure = '"', $escape = '\\', $eol = '\n') {
	if (is_string($input) && !empty($input)) {
		$output = array();
		$tmp    = preg_split("/".$eol."/",$input);
		if (is_array($tmp) && !empty($tmp)) {
			while (list($line_num, $line) = each($tmp)) {
				if (preg_match("/".$escape.$enclosure."/",$line)) {
					while ($strlen = strlen($line)) {
						$pos_delimiter       = strpos($line,$delimiter);
						$pos_enclosure_start = strpos($line,$enclosure);
						if (
							is_int($pos_delimiter) && is_int($pos_enclosure_start)
							&& ($pos_enclosure_start < $pos_delimiter)
							) {
							$enclosed_str = substr($line,1);
							$pos_enclosure_end = strpos($enclosed_str,$enclosure);
							$enclosed_str = substr($enclosed_str,0,$pos_enclosure_end);
							$output[$line_num][] = $enclosed_str;
							$offset = $pos_enclosure_end+3;
						} else {
							if (empty($pos_delimiter) && empty($pos_enclosure_start)) {
								$output[$line_num][] = substr($line,0);
								$offset = strlen($line);
							} else {
								$output[$line_num][] = substr($line,0,$pos_delimiter);
								$offset = (
									!empty($pos_enclosure_start)
									&& ($pos_enclosure_start < $pos_delimiter)
									)
									?$pos_enclosure_start
									:$pos_delimiter+1;
							}
						}
						$line = substr($line,$offset);
					}
				} else {
					$line = preg_split("/".$delimiter."/",$line);

					/*
					 * Validating against pesky extra line breaks creating false rows.
					 */
					if (is_array($line) && !empty($line[0])) {
						$output[$line_num] = $line;
				}
				}
			}
			return $output;
		} else {
		return false;
		}
	} else {
		return false;
	}
}
}

/**
 * return div element with class 'clear'
 * @return string
 * @deprecated
 */
function cleardiv() {
	return '<div class="clear"></div>';
}


function bb_translate_video($s) {

	$matches = null;
	$r = preg_match_all("/\[video\](.*?)\[\/video\]/ism",$s,$matches,PREG_SET_ORDER);
	if ($r) {
		foreach ($matches as $mtch) {
			if ((stristr($mtch[1],'youtube')) || (stristr($mtch[1],'youtu.be')))
				$s = str_replace($mtch[0],'[youtube]' . $mtch[1] . '[/youtube]',$s);
			elseif (stristr($mtch[1],'vimeo'))
				$s = str_replace($mtch[0],'[vimeo]' . $mtch[1] . '[/vimeo]',$s);
		}
	}
	return $s;
}

function html2bb_video($s) {

	$s = preg_replace('#<object[^>]+>(.*?)https?://www.youtube.com/((?:v|cp)/[A-Za-z0-9\-_=]+)(.*?)</object>#ism',
			'[youtube]$2[/youtube]', $s);

	$s = preg_replace('#<iframe[^>](.*?)https?://www.youtube.com/embed/([A-Za-z0-9\-_=]+)(.*?)</iframe>#ism',
			'[youtube]$2[/youtube]', $s);

	$s = preg_replace('#<iframe[^>](.*?)https?://player.vimeo.com/video/([0-9]+)(.*?)</iframe>#ism',
			'[vimeo]$2[/vimeo]', $s);

	return $s;
}

/**
 * apply xmlify() to all values of array $val, recursively
 * @param array $val
 * @return array
 */
function array_xmlify($val){
	if (is_bool($val)) {
		return $val?"true":"false";
	} elseif (is_array($val)) {
		return array_map('array_xmlify', $val);
	}
	return xmlify((string) $val);
}


/**
 * transorm link href and img src from relative to absolute
 *
 * @param string $text
 * @param string $base base url
 * @return string
 */
function reltoabs($text, $base) {
	if (empty($base)) {
		return $text;
	}

	$base = rtrim($base,'/');

	$base2 = $base . "/";

	// Replace links
	$pattern = "/<a([^>]*) href=\"(?!http|https|\/)([^\"]*)\"/";
	$replace = "<a\${1} href=\"" . $base2 . "\${2}\"";
	$text = preg_replace($pattern, $replace, $text);

	$pattern = "/<a([^>]*) href=\"(?!http|https)([^\"]*)\"/";
	$replace = "<a\${1} href=\"" . $base . "\${2}\"";
	$text = preg_replace($pattern, $replace, $text);

	// Replace images
	$pattern = "/<img([^>]*) src=\"(?!http|https|\/)([^\"]*)\"/";
	$replace = "<img\${1} src=\"" . $base2 . "\${2}\"";
	$text = preg_replace($pattern, $replace, $text);

	$pattern = "/<img([^>]*) src=\"(?!http|https)([^\"]*)\"/";
	$replace = "<img\${1} src=\"" . $base . "\${2}\"";
	$text = preg_replace($pattern, $replace, $text);


	// Done
	return $text;
}

/**
 * get translated item type
 *
 * @param array $itme
 * @return string
 */
function item_post_type($item) {
	if (intval($item['event-id'])) {
		return t('event');
	} elseif (strlen($item['resource-id'])) {
		return t('photo');
	} elseif (strlen($item['verb']) && $item['verb'] !== ACTIVITY_POST) {
		return t('activity');
	} elseif ($item['id'] != $item['parent']) {
		return t('comment');
	}

	return t('post');
}

// post categories and "save to file" use the same item.file table for storage.
// We will differentiate the different uses by wrapping categories in angle brackets
// and save to file categories in square brackets.
// To do this we need to escape these characters if they appear in our tag.

function file_tag_encode($s) {
	return str_replace(array('<','>','[',']'),array('%3c','%3e','%5b','%5d'),$s);
}

function file_tag_decode($s) {
	return str_replace(array('%3c','%3e','%5b','%5d'),array('<','>','[',']'),$s);
}

function file_tag_file_query($table,$s,$type = 'file') {

	if ($type == 'file')
		$str = preg_quote( '[' . str_replace('%','%%',file_tag_encode($s)) . ']' );
	else
		$str = preg_quote( '<' . str_replace('%','%%',file_tag_encode($s)) . '>' );
	return " AND " . (($table) ? dbesc($table) . '.' : '') . "file regexp '" . dbesc($str) . "' ";
}

// ex. given music,video return <music><video> or [music][video]
function file_tag_list_to_file($list,$type = 'file') {
	$tag_list = '';
	if (strlen($list)) {
		$list_array = explode(",",$list);
		if ($type == 'file') {
			$lbracket = '[';
			$rbracket = ']';
		}
		else {
			$lbracket = '<';
			$rbracket = '>';
		}

		foreach ($list_array as $item) {
		  if (strlen($item)) {
				$tag_list .= $lbracket . file_tag_encode(trim($item))  . $rbracket;
			}
		}
	}
	return $tag_list;
}

// ex. given <music><video>[friends], return music,video or friends
function file_tag_file_to_list($file,$type = 'file') {
	$matches = false;
	$list = '';
	if ($type == 'file') {
		$cnt = preg_match_all('/\[(.*?)\]/',$file,$matches,PREG_SET_ORDER);
	}
	else {
		$cnt = preg_match_all('/<(.*?)>/',$file,$matches,PREG_SET_ORDER);
	}
	if ($cnt) {
		foreach ($matches as $mtch) {
			if (strlen($list))
				$list .= ',';
			$list .= file_tag_decode($mtch[1]);
		}
	}

	return $list;
}

function file_tag_update_pconfig($uid,$file_old,$file_new,$type = 'file') {
	// $file_old - categories previously associated with an item
	// $file_new - new list of categories for an item

	if (! intval($uid))
		return false;

	if ($file_old == $file_new)
		return true;

	$saved = get_pconfig($uid,'system','filetags');
	if (strlen($saved)) {
		if ($type == 'file') {
			$lbracket = '[';
			$rbracket = ']';
			$termtype = TERM_FILE;
		}
		else {
			$lbracket = '<';
			$rbracket = '>';
			$termtype = TERM_CATEGORY;
		}

		$filetags_updated = $saved;

		// check for new tags to be added as filetags in pconfig
		$new_tags = array();
		$check_new_tags = explode(",",file_tag_file_to_list($file_new,$type));

		foreach ($check_new_tags as $tag) {
			if (! stristr($saved,$lbracket . file_tag_encode($tag) . $rbracket))
				$new_tags[] = $tag;
		}

		$filetags_updated .= file_tag_list_to_file(implode(",",$new_tags),$type);

		// check for deleted tags to be removed from filetags in pconfig
		$deleted_tags = array();
		$check_deleted_tags = explode(",",file_tag_file_to_list($file_old,$type));

		foreach ($check_deleted_tags as $tag) {
			if (! stristr($file_new,$lbracket . file_tag_encode($tag) . $rbracket))
				$deleted_tags[] = $tag;
		}

		foreach ($deleted_tags as $key => $tag) {
			$r = q("SELECT `oid` FROM `term` WHERE `term` = '%s' AND `otype` = %d AND `type` = %d AND `uid` = %d",
				dbesc($tag),
				intval(TERM_OBJ_POST),
				intval($termtype),
				intval($uid));

			//$r = q("select file from item where uid = %d " . file_tag_file_query('item',$tag,$type),
			//	intval($uid)
			//);

			if (dbm::is_result($r)) {
				unset($deleted_tags[$key]);
			}
			else {
				$filetags_updated = str_replace($lbracket . file_tag_encode($tag) . $rbracket,'',$filetags_updated);
			}
		}

		if ($saved != $filetags_updated) {
			set_pconfig($uid,'system','filetags', $filetags_updated);
		}
		return true;
	}
	else
		if (strlen($file_new)) {
			set_pconfig($uid,'system','filetags', $file_new);
		}
		return true;
}

function file_tag_save_file($uid,$item,$file) {
	require_once("include/files.php");

	$result = false;
	if (! intval($uid))
		return false;
	$r = q("SELECT `file` FROM `item` WHERE `id` = %d AND `uid` = %d LIMIT 1",
		intval($item),
		intval($uid)
	);
	if (dbm::is_result($r)) {
		if (! stristr($r[0]['file'],'[' . file_tag_encode($file) . ']'))
			q("UPDATE `item` SET `file` = '%s' WHERE `id` = %d AND `uid` = %d",
				dbesc($r[0]['file'] . '[' . file_tag_encode($file) . ']'),
				intval($item),
				intval($uid)
			);

		create_files_from_item($item);

		$saved = get_pconfig($uid,'system','filetags');
		if ((! strlen($saved)) || (! stristr($saved,'[' . file_tag_encode($file) . ']')))
			set_pconfig($uid,'system','filetags',$saved . '[' . file_tag_encode($file) . ']');
		info( t('Item filed') );
	}
	return true;
}

function file_tag_unsave_file($uid,$item,$file,$cat = false) {
	require_once("include/files.php");

	$result = false;
	if (! intval($uid))
		return false;

	if ($cat == true) {
		$pattern = '<' . file_tag_encode($file) . '>' ;
		$termtype = TERM_CATEGORY;
	} else {
		$pattern = '[' . file_tag_encode($file) . ']' ;
		$termtype = TERM_FILE;
	}


	$r = q("SELECT `file` FROM `item` WHERE `id` = %d AND `uid` = %d LIMIT 1",
		intval($item),
		intval($uid)
	);
	if (! dbm::is_result($r)) {
		return false;
	}

	q("UPDATE `item` SET `file` = '%s' WHERE `id` = %d AND `uid` = %d",
		dbesc(str_replace($pattern,'',$r[0]['file'])),
		intval($item),
		intval($uid)
	);

	create_files_from_item($item);

	$r = q("SELECT `oid` FROM `term` WHERE `term` = '%s' AND `otype` = %d AND `type` = %d AND `uid` = %d",
		dbesc($file),
		intval(TERM_OBJ_POST),
		intval($termtype),
		intval($uid));

	//$r = q("select file from item where uid = %d and deleted = 0 " . file_tag_file_query('item',$file,(($cat) ? 'category' : 'file')),
	//);

	if (! dbm::is_result($r)) {
		$saved = get_pconfig($uid,'system','filetags');
		set_pconfig($uid,'system','filetags',str_replace($pattern,'',$saved));
	}

	return true;
}

function normalise_openid($s) {
	return trim(str_replace(array('http://','https://'),array('',''),$s),'/');
}


function undo_post_tagging($s) {
	$matches = null;
	$cnt = preg_match_all('/([!#@])\[url=(.*?)\](.*?)\[\/url\]/ism',$s,$matches,PREG_SET_ORDER);
	if ($cnt) {
		foreach ($matches as $mtch) {
			$s = str_replace($mtch[0], $mtch[1] . $mtch[3],$s);
		}
	}
	return $s;
}

function protect_sprintf($s) {
	return str_replace('%', '%%', $s);
}


function is_a_date_arg($s) {
	$i = intval($s);
	if ($i > 1900) {
		$y = date('Y');
		if ($i <= $y + 1 && strpos($s, '-') == 4) {
			$m = intval(substr($s,5));
			if ($m > 0 && $m <= 12)
				return true;
		}
	}
	return false;
}

/**
 * remove intentation from a text
 */
function deindent($text, $chr = "[\t ]", $count = NULL) {
	$lines = explode("\n", $text);
	if (is_null($count)) {
		$m = array();
		$k = 0;
		while ($k < count($lines) && strlen($lines[$k]) == 0) {
			$k++;
		}
		preg_match("|^" . $chr . "*|", $lines[$k], $m);
		$count = strlen($m[0]);
	}
	for ($k=0; $k < count($lines); $k++) {
		$lines[$k] = preg_replace("|^" . $chr . "{" . $count . "}|", "", $lines[$k]);
	}

	return implode("\n", $lines);
}

function formatBytes($bytes, $precision = 2) {
	 $units = array('B', 'KB', 'MB', 'GB', 'TB');

	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);

	$bytes /= pow(1024, $pow);

	return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * @brief translate and format the networkname of a contact
 *
 * @param string $network
 *	Networkname of the contact (e.g. dfrn, rss and so on)
 * @param sting $url
 *	The contact url
 * @return string
 */
function format_network_name($network, $url = 0) {
	if ($network != "") {
		require_once('include/contact_selectors.php');
		if ($url != "")
			$network_name = '<a href="'.$url.'">'.network_to_name($network, $url)."</a>";
		else
			$network_name = network_to_name($network);

		return $network_name;
	}

}

/**
 * @brief Syntax based code highlighting for popular languages.
 * @param string $s Code block
 * @param string $lang Programming language
 * @return string Formated html
 */
function text_highlight($s, $lang) {
	if ($lang === 'js') {
		$lang = 'javascript';
	}

	// @TODO: Replace Text_Highlighter_Renderer_Html by scrivo/highlight.php

	// Autoload the library to make constants available
	class_exists('Text_Highlighter_Renderer_Html');

	$options = array(
		'numbers' => HL_NUMBERS_LI,
		'tabsize' => 4,
	);

	$tag_added = false;
	$s = trim(html_entity_decode($s, ENT_COMPAT));
	$s = str_replace('    ', "\t", $s);

	/*
	 * The highlighter library insists on an opening php tag for php code blocks. If
	 * it isn't present, nothing is highlighted. So we're going to see if it's present.
	 * If not, we'll add it, and then quietly remove it after we get the processed output back.
	 */
	if ($lang === 'php' && strpos($s, '<?php') !== 0) {
		$s = '<?php' . "\n" . $s;
		$tag_added = true;
	}

	$renderer = new Text_Highlighter_Renderer_Html($options);
	$hl = Text_Highlighter::factory($lang);
	$hl->setRenderer($renderer);
	$o = $hl->highlight($s);
	$o = str_replace("\n", '', $o);

	if ($tag_added) {
		$b = substr($o, 0, strpos($o, '<li>'));
		$e = substr($o, strpos($o, '</li>'));
		$o = $b . $e;
	}

	return '<code>' . $o . '</code>';
}
