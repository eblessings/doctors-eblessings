<?php

use Friendica\Content\Text\Markdown;
use Friendica\Core\Addon;
use Friendica\Core\System;
use Friendica\Model\Contact;
use Friendica\Network\Probe;
use Friendica\Util\Network;
use League\HTMLToMarkdown\HtmlConverter;

require_once 'include/event.php';
require_once 'include/html2bbcode.php';
require_once 'include/bbcode.php';

/**
 * @brief Callback function to replace a Diaspora style mention in a mention for Friendica
 *
 * @param array $match Matching values for the callback
 * @return string Replaced mention
 */
function diaspora_mention2bb($match) {
	if ($match[2] == '') {
		return;
	}

	$data = Contact::getDetailsByAddr($match[2]);

	$name = $match[1];

	if ($name == '') {
		$name = $data['name'];
	}

	return '@[url=' . $data['url'] . ']' . $name . '[/url]';
}

/*
 * we don't want to support a bbcode specific markdown interpreter
 * and the markdown library we have is pretty good, but provides HTML output.
 * So we'll use that to convert to HTML, then convert the HTML back to bbcode,
 * and then clean up a few Diaspora specific constructs.
 */
function diaspora2bb($s) {

	$s = html_entity_decode($s, ENT_COMPAT, 'UTF-8');

	// Handles single newlines
	$s = str_replace("\r\n", "\n", $s);
	$s = str_replace("\n", " \n", $s);
	$s = str_replace("\r", " \n", $s);

	// Replace lonely stars in lines not starting with it with literal stars
	$s = preg_replace('/^([^\*]+)\*([^\*]*)$/im', '$1\*$2', $s);

	// The parser cannot handle paragraphs correctly
	$s = str_replace(['</p>', '<p>', '<p dir="ltr">'], ['<br>', '<br>', '<br>'], $s);

	// Escaping the hash tags
	$s = preg_replace('/\#([^\s\#])/', '&#35;$1', $s);

	$s = Markdown::convert($s);

	$regexp = "/@\{(?:([^\}]+?); )?([^\} ]+)\}/";
	$s = preg_replace_callback($regexp, 'diaspora_mention2bb', $s);

	$s = str_replace('&#35;', '#', $s);

	$s = html2bbcode($s);

	// protect the recycle symbol from turning into a tag, but without unescaping angles and naked ampersands
	$s = str_replace('&#x2672;', html_entity_decode('&#x2672;', ENT_QUOTES, 'UTF-8'), $s);

	// Convert everything that looks like a link to a link
	$s = preg_replace('/([^\]=]|^)(https?\:\/\/)([a-zA-Z0-9:\/\-?&;.=_~#%$!+,@]+(?<!,))/ism', '$1[url=$2$3]$2$3[/url]', $s);

	//$s = preg_replace("/([^\]\=]|^)(https?\:\/\/)(vimeo|youtu|www\.youtube|soundcloud)([a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)/ism", '$1[url=$2$3$4]$2$3$4[/url]',$s);
	$s = bb_tag_preg_replace('/\[url\=?(.*?)\]https?:\/\/www.youtube.com\/watch\?v\=(.*?)\[\/url\]/ism', '[youtube]$2[/youtube]', 'url', $s);
	$s = bb_tag_preg_replace('/\[url\=https?:\/\/www.youtube.com\/watch\?v\=(.*?)\].*?\[\/url\]/ism'   , '[youtube]$1[/youtube]', 'url', $s);
	$s = bb_tag_preg_replace('/\[url\=?(.*?)\]https?:\/\/vimeo.com\/([0-9]+)(.*?)\[\/url\]/ism'        , '[vimeo]$2[/vimeo]'    , 'url', $s);
	$s = bb_tag_preg_replace('/\[url\=https?:\/\/vimeo.com\/([0-9]+)\](.*?)\[\/url\]/ism'              , '[vimeo]$1[/vimeo]'    , 'url', $s);

	// remove duplicate adjacent code tags
	$s = preg_replace('/(\[code\])+(.*?)(\[\/code\])+/ism', '[code]$2[/code]', $s);

	// Don't show link to full picture (until it is fixed)
	$s = Network::scaleExternalImages($s, false);

	return $s;
}

/**
 * @brief Callback function to replace a Friendica style mention in a mention for Diaspora
 *
 * @param array $match Matching values for the callback
 * @return string Replaced mention
 */
function diaspora_mentions($match) {

	$contact = Contact::getDetailsByURL($match[3]);

	if (!x($contact, 'addr')) {
		$contact = Probe::uri($match[3]);
	}

	if (!x($contact, 'addr')) {
		return $match[0];
	}

	$mention = '@{' . $match[2] . '; ' . $contact['addr'] . '}';
	return $mention;
}

/**
 * @brief Converts a BBCode text into Markdown
 *
 * This function converts a BBCode item body to be sent to Markdown-enabled
 * systems like Diaspora and Libertree
 *
 * @param string $Text
 * @param bool $preserve_nl Effects unclear, unused in Friendica
 * @param bool $fordiaspora Diaspora requires more changes than Libertree
 * @return string
 */
function bb2diaspora($Text, $preserve_nl = false, $fordiaspora = true) {
	$a = get_app();

	$OriginalText = $Text;

	// Since Diaspora is creating a summary for links, this function removes them before posting
	if ($fordiaspora) {
		$Text = bb_remove_share_information($Text);
	}

	/**
	 * Transform #tags, strip off the [url] and replace spaces with underscore
	 */
	$URLSearchString = "^\[\]";
	$Text = preg_replace_callback("/#\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/i",
		function ($matches) {
			return '#' . str_replace(' ', '_', $matches[2]);
		}
	, $Text);

	// Converting images with size parameters to simple images. Markdown doesn't know it.
	$Text = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $Text);

	// Extracting multi-line code blocks before the whitespace processing/code highlighter in bbcode()
	$codeblocks = [];

	$Text = preg_replace_callback("#\[code(?:=([^\]]*))?\](.*?)\[\/code\]#is",
		function ($matches) use (&$codeblocks) {
			$return = $matches[0];
			if (strpos($matches[2], "\n") !== false) {
				$return = '#codeblock-' . count($codeblocks) . '#';

				$prefix = '````' . $matches[1] . PHP_EOL;
				$codeblocks[] = $prefix . trim($matches[2]) . PHP_EOL . '````';
			}
			return $return;
		}
	, $Text);

	// Convert it to HTML - don't try oembed
	if ($fordiaspora) {
		$Text = bbcode($Text, $preserve_nl, false, 3);

		// Add all tags that maybe were removed
		if (preg_match_all("/#\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", $OriginalText, $tags)) {
			$tagline = "";
			foreach ($tags[2] as $tag) {
				$tag = html_entity_decode($tag, ENT_QUOTES, 'UTF-8');
				if (!strpos(html_entity_decode($Text, ENT_QUOTES, 'UTF-8'), '#' . $tag)) {
					$tagline .= '#' . $tag . ' ';
				}
			}
			$Text = $Text." ".$tagline;
		}
	} else {
		$Text = bbcode($Text, $preserve_nl, false, 4);
	}

	// mask some special HTML chars from conversation to markdown
	$Text = str_replace(['&lt;', '&gt;', '&amp;'], ['&_lt_;', '&_gt_;', '&_amp_;'], $Text);

	// If a link is followed by a quote then there should be a newline before it
	// Maybe we should make this newline at every time before a quote.
	$Text = str_replace(["</a><blockquote>"], ["</a><br><blockquote>"], $Text);

	$stamp1 = microtime(true);

	// Now convert HTML to Markdown
	$converter = new HtmlConverter();
	$Text = $converter->convert($Text);

	// unmask the special chars back to HTML
	$Text = str_replace(['&\_lt\_;', '&\_gt\_;', '&\_amp\_;'], ['&lt;', '&gt;', '&amp;'], $Text);

	$a->save_timestamp($stamp1, "parser");

	// Libertree has a problem with escaped hashtags.
	$Text = str_replace(['\#'], ['#'], $Text);

	// Remove any leading or trailing whitespace, as this will mess up
	// the Diaspora signature verification and cause the item to disappear
	$Text = trim($Text);

	if ($fordiaspora) {
		$URLSearchString = "^\[\]";
		$Text = preg_replace_callback("/([@]\[(.*?)\])\(([$URLSearchString]*?)\)/ism", 'diaspora_mentions', $Text);
	}

	// Restore code blocks
	$Text = preg_replace_callback('/#codeblock-([0-9]+)#/iU',
		function ($matches) use ($codeblocks) {
            $return = '';
            if (isset($codeblocks[intval($matches[1])])) {
                $return = $codeblocks[$matches[1]];
            }
			return $return;
		}
	, $Text);

	Addon::callHooks('bb2diaspora',$Text);

	return $Text;
}

function unescape_underscores_in_links($m) {
	$y = str_replace('\\_', '_', $m[2]);
	return('[' . $m[1] . '](' . $y . ')');
}

function format_event_diaspora($ev) {
	if (! ((is_array($ev)) && count($ev))) {
		return '';
	}

	$bd_format = L10n::t('l F d, Y \@ g:i A') ; // Friday January 18, 2011 @ 8 AM

	$o = 'Friendica event notification:' . "\n";

	$o .= '**' . (($ev['summary']) ? bb2diaspora($ev['summary']) : bb2diaspora($ev['desc'])) .  '**' . "\n";

	$o .= L10n::t('Starts:') . ' ' . '['
		. (($ev['adjust']) ? day_translate(datetime_convert('UTC', 'UTC',
			$ev['start'] , $bd_format ))
			:  day_translate(datetime_convert('UTC', 'UTC',
			$ev['start'] , $bd_format)))
		.  '](' . System::baseUrl() . '/localtime/?f=&time=' . urlencode(datetime_convert('UTC','UTC',$ev['start'])) . ")\n";

	if (! $ev['nofinish']) {
		$o .= L10n::t('Finishes:') . ' ' . '['
			. (($ev['adjust']) ? day_translate(datetime_convert('UTC', 'UTC',
				$ev['finish'] , $bd_format ))
				:  day_translate(datetime_convert('UTC', 'UTC',
				$ev['finish'] , $bd_format )))
			. '](' . System::baseUrl() . '/localtime/?f=&time=' . urlencode(datetime_convert('UTC','UTC',$ev['finish'])) . ")\n";
	}

	if (strlen($ev['location'])) {
		$o .= L10n::t('Location:') . bb2diaspora($ev['location'])
			. "\n";
	}

	$o .= "\n";
	return $o;
}
