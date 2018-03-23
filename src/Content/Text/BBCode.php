<?php

/**
 * @file src/Content/Text/BBCode.php
 */

namespace Friendica\Content\Text;

use DOMDocument;
use DOMXPath;
use Exception;
use Friendica\BaseObject;
use Friendica\Content\OEmbed;
use Friendica\Content\Smilies;
use Friendica\Core\Addon;
use Friendica\Core\Cache;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\Model\Contact;
use Friendica\Model\Event;
use Friendica\Network\Probe;
use Friendica\Object\Image;
use Friendica\Util\Map;
use Friendica\Util\Network;
use Friendica\Util\ParseUrl;
use League\HTMLToMarkdown\HtmlConverter;

require_once "include/event.php";
require_once "mod/proxy.php";

class BBCode extends BaseObject
{
	/**
	 * @brief Fetches attachment data that were generated the old way
	 *
	 * @param string $body Message body
	 * @return array
	 * 'type' -> Message type ("link", "video", "photo")
	 * 'text' -> Text before the shared message
	 * 'after' -> Text after the shared message
	 * 'image' -> Preview image of the message
	 * 'url' -> Url to the attached message
	 * 'title' -> Title of the attachment
	 * 'description' -> Description of the attachment
	 */
	private static function getOldAttachmentData($body)
	{
		$post = [];

		// Simplify image codes
		$body = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $body);

		if (preg_match_all("(\[class=(.*?)\](.*?)\[\/class\])ism", $body, $attached, PREG_SET_ORDER)) {
			foreach ($attached as $data) {
				if (!in_array($data[1], ["type-link", "type-video", "type-photo"])) {
					continue;
				}

				$post["type"] = substr($data[1], 5);

				$pos = strpos($body, $data[0]);
				if ($pos > 0) {
					$post["text"] = trim(substr($body, 0, $pos));
					$post["after"] = trim(substr($body, $pos + strlen($data[0])));
				} else {
					$post["text"] = trim(str_replace($data[0], "", $body));
				}

				$attacheddata = $data[2];

				$URLSearchString = "^\[\]";

				if (preg_match("/\[img\]([$URLSearchString]*)\[\/img\]/ism", $attacheddata, $matches)) {

					$picturedata = Image::getInfoFromURL($matches[1]);

					if (($picturedata[0] >= 500) && ($picturedata[0] >= $picturedata[1])) {
						$post["image"] = $matches[1];
					} else {
						$post["preview"] = $matches[1];
					}
				}

				if (preg_match("/\[bookmark\=([$URLSearchString]*)\](.*?)\[\/bookmark\]/ism", $attacheddata, $matches)) {
					$post["url"] = $matches[1];
					$post["title"] = $matches[2];
				}
				if (($post["url"] == "") && (in_array($post["type"], ["link", "video"]))
					&& preg_match("/\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", $attacheddata, $matches)) {
					$post["url"] = $matches[1];
				}

				// Search for description
				if (preg_match("/\[quote\](.*?)\[\/quote\]/ism", $attacheddata, $matches)) {
					$post["description"] = $matches[1];
				}
			}
		}
		return $post;
	}

	/**
	 * @brief Fetches attachment data that were generated with the "attachment" element
	 *
	 * @param string $body Message body
	 * @return array
	 * 'type' -> Message type ("link", "video", "photo")
	 * 'text' -> Text before the shared message
	 * 'after' -> Text after the shared message
	 * 'image' -> Preview image of the message
	 * 'url' -> Url to the attached message
	 * 'title' -> Title of the attachment
	 * 'description' -> Description of the attachment
	 */
	public static function getAttachmentData($body)
	{
		$data = [];

		if (!preg_match("/(.*)\[attachment(.*?)\](.*?)\[\/attachment\](.*)/ism", $body, $match)) {
			return self::getOldAttachmentData($body);
		}

		$attributes = $match[2];

		$data["text"] = trim($match[1]);

		$type = "";
		preg_match("/type='(.*?)'/ism", $attributes, $matches);
		if (x($matches, 1)) {
			$type = strtolower($matches[1]);
		}

		preg_match('/type="(.*?)"/ism', $attributes, $matches);
		if (x($matches, 1)) {
			$type = strtolower($matches[1]);
		}

		if ($type == "") {
			return [];
		}

		if (!in_array($type, ["link", "audio", "photo", "video"])) {
			return [];
		}

		if ($type != "") {
			$data["type"] = $type;
		}

		$url = "";
		preg_match("/url='(.*?)'/ism", $attributes, $matches);
		if (x($matches, 1)) {
			$url = $matches[1];
		}

		preg_match('/url="(.*?)"/ism', $attributes, $matches);
		if (x($matches, 1)) {
			$url = $matches[1];
		}

		if ($url != "") {
			$data["url"] = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
		}

		$title = "";
		preg_match("/title='(.*?)'/ism", $attributes, $matches);
		if (x($matches, 1)) {
			$title = $matches[1];
		}

		preg_match('/title="(.*?)"/ism', $attributes, $matches);
		if (x($matches, 1)) {
			$title = $matches[1];
		}

		if ($title != "") {
			$title = self::convert(html_entity_decode($title, ENT_QUOTES, 'UTF-8'), false, true);
			$title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
			$title = str_replace(["[", "]"], ["&#91;", "&#93;"], $title);
			$data["title"] = $title;
		}

		$image = "";
		preg_match("/image='(.*?)'/ism", $attributes, $matches);
		if (x($matches, 1)) {
			$image = $matches[1];
		}

		preg_match('/image="(.*?)"/ism', $attributes, $matches);
		if (x($matches, 1)) {
			$image = $matches[1];
		}

		if ($image != "") {
			$data["image"] = html_entity_decode($image, ENT_QUOTES, 'UTF-8');
		}

		$preview = "";
		preg_match("/preview='(.*?)'/ism", $attributes, $matches);
		if (x($matches, 1)) {
			$preview = $matches[1];
		}

		preg_match('/preview="(.*?)"/ism', $attributes, $matches);
		if (x($matches, 1)) {
			$preview = $matches[1];
		}

		if ($preview != "") {
			$data["preview"] = html_entity_decode($preview, ENT_QUOTES, 'UTF-8');
		}

		$data["description"] = trim($match[3]);

		$data["after"] = trim($match[4]);

		return $data;
	}

	public static function getAttachedData($body, $item = [])
	{
		/*
		- text:
		- type: link, video, photo
		- title:
		- url:
		- image:
		- description:
		- (thumbnail)
		*/

		$has_title = !empty($item['title']);
		$plink = (!empty($item['plink']) ? $item['plink'] : '');
		$post = self::getAttachmentData($body);

		// if nothing is found, it maybe having an image.
		if (!isset($post["type"])) {
			// Simplify image codes
			$body = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $body);

			$URLSearchString = "^\[\]";
			if (preg_match_all("(\[url=([$URLSearchString]*)\]\s*\[img\]([$URLSearchString]*)\[\/img\]\s*\[\/url\])ism", $body, $pictures, PREG_SET_ORDER)) {
				if ((count($pictures) == 1) && !$has_title) {
					// Checking, if the link goes to a picture
					$data = ParseUrl::getSiteinfoCached($pictures[0][1], true);

					// Workaround:
					// Sometimes photo posts to the own album are not detected at the start.
					// So we seem to cannot use the cache for these cases. That's strange.
					if (($data["type"] != "photo") && strstr($pictures[0][1], "/photos/")) {
						$data = ParseUrl::getSiteinfo($pictures[0][1], true);
					}

					if ($data["type"] == "photo") {
						$post["type"] = "photo";
						if (isset($data["images"][0])) {
							$post["image"] = $data["images"][0]["src"];
							$post["url"] = $data["url"];
						} else {
							$post["image"] = $data["url"];
						}

						$post["preview"] = $pictures[0][2];
						$post["text"] = str_replace($pictures[0][0], "", $body);
					} else {
						$imgdata = Image::getInfoFromURL($pictures[0][1]);
						if (substr($imgdata["mime"], 0, 6) == "image/") {
							$post["type"] = "photo";
							$post["image"] = $pictures[0][1];
							$post["preview"] = $pictures[0][2];
							$post["text"] = str_replace($pictures[0][0], "", $body);
						}
					}
				} elseif (count($pictures) > 0) {
					$post["type"] = "link";
					$post["url"] = $plink;
					$post["image"] = $pictures[0][2];
					$post["text"] = $body;
				}
			} elseif (preg_match_all("(\[img\]([$URLSearchString]*)\[\/img\])ism", $body, $pictures, PREG_SET_ORDER)) {
				if ((count($pictures) == 1) && !$has_title) {
					$post["type"] = "photo";
					$post["image"] = $pictures[0][1];
					$post["text"] = str_replace($pictures[0][0], "", $body);
				} elseif (count($pictures) > 0) {
					$post["type"] = "link";
					$post["url"] = $plink;
					$post["image"] = $pictures[0][1];
					$post["text"] = $body;
				}
			}

			// Test for the external links
			preg_match_all("(\[url\]([$URLSearchString]*)\[\/url\])ism", $body, $links1, PREG_SET_ORDER);
			preg_match_all("(\[url\=([$URLSearchString]*)\].*?\[\/url\])ism", $body, $links2, PREG_SET_ORDER);

			$links = array_merge($links1, $links2);

			// If there is only a single one, then use it.
			// This should cover link posts via API.
			if ((count($links) == 1) && !isset($post["preview"]) && !$has_title) {
				$post["type"] = "link";
				$post["text"] = trim($body);
				$post["url"] = $links[0][1];
			}

			// Now count the number of external media links
			preg_match_all("(\[vimeo\](.*?)\[\/vimeo\])ism", $body, $links1, PREG_SET_ORDER);
			preg_match_all("(\[youtube\\](.*?)\[\/youtube\\])ism", $body, $links2, PREG_SET_ORDER);
			preg_match_all("(\[video\\](.*?)\[\/video\\])ism", $body, $links3, PREG_SET_ORDER);
			preg_match_all("(\[audio\\](.*?)\[\/audio\\])ism", $body, $links4, PREG_SET_ORDER);

			// Add them to the other external links
			$links = array_merge($links, $links1, $links2, $links3, $links4);

			// Are there more than one?
			if (count($links) > 1) {
				// The post will be the type "text", which means a blog post
				unset($post["type"]);
				$post["url"] = $plink;
			}

			if (!isset($post["type"])) {
				$post["type"] = "text";
				$post["text"] = trim($body);
			}
		} elseif (isset($post["url"]) && ($post["type"] == "video")) {
			$data = ParseUrl::getSiteinfoCached($post["url"], true);

			if (isset($data["images"][0])) {
				$post["image"] = $data["images"][0]["src"];
			}
		}

		return $post;
	}

	/**
	 * @brief Convert a message into plaintext for connectors to other networks
	 *
	 * @param array $b The message array that is about to be posted
	 * @param int $limit The maximum number of characters when posting to that network
	 * @param bool $includedlinks Has an attached link to be included into the message?
	 * @param int $htmlmode This triggers the behaviour of the bbcode conversion
	 * @param string $target_network Name of the network where the post should go to.
	 *
	 * @return string The converted message
	 */
	public static function toPlaintext($b, $limit = 0, $includedlinks = false, $htmlmode = 2, $target_network = "")
	{
		// Remove the hash tags
		$URLSearchString = "^\[\]";
		$body = preg_replace("/([#@])\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", '$1$3', $b["body"]);

		// Add an URL element if the text contains a raw link
		$body = preg_replace("/([^\]\='".'"'."]|^)(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)/ism", '$1[url]$2[/url]', $body);

		// Remove the abstract
		$body = self::stripAbstract($body);

		// At first look at data that is attached via "type-..." stuff
		// This will hopefully replaced with a dedicated bbcode later
		//$post = self::getAttachedData($b["body"]);
		$post = self::getAttachedData($body, $b);

		if (($b["title"] != "") && ($post["text"] != "")) {
			$post["text"] = trim($b["title"]."\n\n".$post["text"]);
		} elseif ($b["title"] != "") {
			$post["text"] = trim($b["title"]);
		}

		$abstract = "";

		// Fetch the abstract from the given target network
		if ($target_network != "") {
			$default_abstract = self::getAbstract($b["body"]);
			$abstract = self::getAbstract($b["body"], $target_network);

			// If we post to a network with no limit we only fetch
			// an abstract exactly for this network
			if (($limit == 0) && ($abstract == $default_abstract)) {
				$abstract = "";
			}
		} else {// Try to guess the correct target network
			switch ($htmlmode) {
				case 8:
					$abstract = self::getAbstract($b["body"], NETWORK_TWITTER);
					break;
				case 7:
					$abstract = self::getAbstract($b["body"], NETWORK_STATUSNET);
					break;
				case 6:
					$abstract = self::getAbstract($b["body"], NETWORK_APPNET);
					break;
				default: // We don't know the exact target.
					// We fetch an abstract since there is a posting limit.
					if ($limit > 0) {
						$abstract = self::getAbstract($b["body"]);
					}
			}
		}

		if ($abstract != "") {
			$post["text"] = $abstract;

			if ($post["type"] == "text") {
				$post["type"] = "link";
				$post["url"] = $b["plink"];
			}
		}

		$html = self::convert($post["text"].$post["after"], false, $htmlmode);
		$msg = HTML::toPlaintext($html, 0, true);
		$msg = trim(html_entity_decode($msg, ENT_QUOTES, 'UTF-8'));

		$link = "";
		if ($includedlinks) {
			if ($post["type"] == "link") {
				$link = $post["url"];
			} elseif ($post["type"] == "text") {
				$link = $post["url"];
			} elseif ($post["type"] == "video") {
				$link = $post["url"];
			} elseif ($post["type"] == "photo") {
				$link = $post["image"];
			}

			if (($msg == "") && isset($post["title"])) {
				$msg = trim($post["title"]);
			}

			if (($msg == "") && isset($post["description"])) {
				$msg = trim($post["description"]);
			}

			// If the link is already contained in the post, then it neeedn't to be added again
			// But: if the link is beyond the limit, then it has to be added.
			if (($link != "") && strstr($msg, $link)) {
				$pos = strpos($msg, $link);

				// Will the text be shortened in the link?
				// Or is the link the last item in the post?
				if (($limit > 0) && ($pos < $limit) && (($pos + 23 > $limit) || ($pos + strlen($link) == strlen($msg)))) {
					$msg = trim(str_replace($link, "", $msg));
				} elseif (($limit == 0) || ($pos < $limit)) {
					// The limit has to be increased since it will be shortened - but not now
					// Only do it with Twitter (htmlmode = 8)
					if (($limit > 0) && (strlen($link) > 23) && ($htmlmode == 8)) {
						$limit = $limit - 23 + strlen($link);
					}

					$link = "";

					if ($post["type"] == "text") {
						unset($post["url"]);
					}
				}
			}
		}

		if ($limit > 0) {
			// Reduce multiple spaces
			// When posted to a network with limited space, we try to gain space where possible
			while (strpos($msg, "  ") !== false) {
				$msg = str_replace("  ", " ", $msg);
			}

			// Twitter is using its own limiter, so we always assume that shortened links will have this length
			if (iconv_strlen($link, "UTF-8") > 0) {
				$limit = $limit - 23;
			}

			if (iconv_strlen($msg, "UTF-8") > $limit) {
				if (($post["type"] == "text") && isset($post["url"])) {
					$post["url"] = $b["plink"];
				} elseif (!isset($post["url"])) {
					$limit = $limit - 23;
					$post["url"] = $b["plink"];
				// Which purpose has this line? It is now uncommented, but left as a reminder
				//} elseif (strpos($b["body"], "[share") !== false) {
				//	$post["url"] = $b["plink"];
				} elseif (PConfig::get($b["uid"], "system", "no_intelligent_shortening")) {
					$post["url"] = $b["plink"];
				}
				$msg = Plaintext::shorten($msg, $limit);
			}
		}

		$post["text"] = trim($msg);

		return($post);
	}

	public static function scaleExternalImages($srctext, $include_link = true, $scale_replace = false)
	{
		// Suppress "view full size"
		if (intval(Config::get('system', 'no_view_full_size'))) {
			$include_link = false;
		}

		// Picture addresses can contain special characters
		$s = htmlspecialchars_decode($srctext);

		$matches = null;
		$c = preg_match_all('/\[img.*?\](.*?)\[\/img\]/ism', $s, $matches, PREG_SET_ORDER);
		if ($c) {
			foreach ($matches as $mtch) {
				logger('scale_external_image: ' . $mtch[1]);

				$hostname = str_replace('www.', '', substr(System::baseUrl(), strpos(System::baseUrl(), '://') + 3));
				if (stristr($mtch[1], $hostname)) {
					continue;
				}

				// $scale_replace, if passed, is an array of two elements. The
				// first is the name of the full-size image. The second is the
				// name of a remote, scaled-down version of the full size image.
				// This allows Friendica to display the smaller remote image if
				// one exists, while still linking to the full-size image
				if ($scale_replace) {
					$scaled = str_replace($scale_replace[0], $scale_replace[1], $mtch[1]);
				} else {
					$scaled = $mtch[1];
				}
				$i = Network::fetchUrl($scaled);
				if (!$i) {
					return $srctext;
				}

				// guess mimetype from headers or filename
				$type = Image::guessType($mtch[1], true);

				if ($i) {
					$Image = new Image($i, $type);
					if ($Image->isValid()) {
						$orig_width = $Image->getWidth();
						$orig_height = $Image->getHeight();

						if ($orig_width > 640 || $orig_height > 640) {
							$Image->scaleDown(640);
							$new_width = $Image->getWidth();
							$new_height = $Image->getHeight();
							logger('scale_external_images: ' . $orig_width . '->' . $new_width . 'w ' . $orig_height . '->' . $new_height . 'h' . ' match: ' . $mtch[0], LOGGER_DEBUG);
							$s = str_replace(
								$mtch[0],
								'[img=' . $new_width . 'x' . $new_height. ']' . $scaled . '[/img]'
								. "\n" . (($include_link)
									? '[url=' . $mtch[1] . ']' . L10n::t('view full size') . '[/url]' . "\n"
									: ''),
								$s
							);
							logger('scale_external_images: new string: ' . $s, LOGGER_DEBUG);
						}
					}
				}
			}
		}

		// replace the special char encoding
		$s = htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
		return $s;
	}

	/**
	 * The purpose of this function is to apply system message length limits to
	 * imported messages without including any embedded photos in the length
	 *
	 * @brief Truncates imported message body string length to max_import_size
	 * @param string $body
	 * @return string
	 */
	public static function limitBodySize($body)
	{
		$maxlen = get_max_import_size();

		// If the length of the body, including the embedded images, is smaller
		// than the maximum, then don't waste time looking for the images
		if ($maxlen && (strlen($body) > $maxlen)) {

			logger('the total body length exceeds the limit', LOGGER_DEBUG);

			$orig_body = $body;
			$new_body = '';
			$textlen = 0;

			$img_start = strpos($orig_body, '[img');
			$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
			$img_end = ($img_start !== false ? strpos(substr($orig_body, $img_start), '[/img]') : false);
			while (($img_st_close !== false) && ($img_end !== false)) {

				$img_st_close++; // make it point to AFTER the closing bracket
				$img_end += $img_start;
				$img_end += strlen('[/img]');

				if (!strcmp(substr($orig_body, $img_start + $img_st_close, 5), 'data:')) {
					// This is an embedded image

					if (($textlen + $img_start) > $maxlen) {
						if ($textlen < $maxlen) {
							logger('the limit happens before an embedded image', LOGGER_DEBUG);
							$new_body = $new_body . substr($orig_body, 0, $maxlen - $textlen);
							$textlen = $maxlen;
						}
					} else {
						$new_body = $new_body . substr($orig_body, 0, $img_start);
						$textlen += $img_start;
					}

					$new_body = $new_body . substr($orig_body, $img_start, $img_end - $img_start);
				} else {

					if (($textlen + $img_end) > $maxlen) {
						if ($textlen < $maxlen) {
							logger('the limit happens before the end of a non-embedded image', LOGGER_DEBUG);
							$new_body = $new_body . substr($orig_body, 0, $maxlen - $textlen);
							$textlen = $maxlen;
						}
					} else {
						$new_body = $new_body . substr($orig_body, 0, $img_end);
						$textlen += $img_end;
					}
				}
				$orig_body = substr($orig_body, $img_end);

				if ($orig_body === false) {
					// in case the body ends on a closing image tag
					$orig_body = '';
				}

				$img_start = strpos($orig_body, '[img');
				$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
				$img_end = ($img_start !== false ? strpos(substr($orig_body, $img_start), '[/img]') : false);
			}

			if (($textlen + strlen($orig_body)) > $maxlen) {
				if ($textlen < $maxlen) {
					logger('the limit happens after the end of the last image', LOGGER_DEBUG);
					$new_body = $new_body . substr($orig_body, 0, $maxlen - $textlen);
				}
			} else {
				logger('the text size with embedded images extracted did not violate the limit', LOGGER_DEBUG);
				$new_body = $new_body . $orig_body;
			}

			return $new_body;
		} else {
			return $body;
		}
	}

	/**
	 * Processes [attachment] tags
	 *
	 * Note: Can produce a [bookmark] tag in the returned string
	 *
	 * @brief Processes [attachment] tags
	 * @param string $return
	 * @param bool|int $simplehtml
	 * @param bool $tryoembed
	 * @return string
	 */
	private static function convertAttachment($return, $simplehtml = false, $tryoembed = true)
	{
		$data = self::getAttachmentData($return);
		if (!$data) {
			return $return;
		}

		if (isset($data["title"])) {
			$data["title"] = strip_tags($data["title"]);
			$data["title"] = str_replace(["http://", "https://"], "", $data["title"]);
		}

		if (((strpos($data["text"], "[img=") !== false) || (strpos($data["text"], "[img]") !== false) || Config::get('system', 'always_show_preview')) && ($data["image"] != "")) {
			$data["preview"] = $data["image"];
			$data["image"] = "";
		}

		$return = '';
		if ($simplehtml == 7) {
			$return = self::convertUrlForOStatus($data["url"]);
		} elseif (($simplehtml != 4) && ($simplehtml != 0)) {
			$return = sprintf('<a href="%s" target="_blank">%s</a><br>', $data["url"], $data["title"]);
		} else {
			try {
				if ($tryoembed && OEmbed::isAllowedURL($data['url'])) {
					$return = OEmbed::getHTML($data['url'], $data['title']);
				} else {
					throw new Exception('OEmbed is disabled for this attachment.');
				}
			} catch (Exception $e) {
				if ($simplehtml != 4) {
					$return = sprintf('<div class="type-%s">', $data["type"]);
				}

				if ($data["image"] != "") {
					$return .= sprintf('<a href="%s" target="_blank"><img src="%s" alt="" title="%s" class="attachment-image" /></a><br />', $data["url"], proxy_url($data["image"]), $data["title"]);
				} elseif ($data["preview"] != "") {
					$return .= sprintf('<a href="%s" target="_blank"><img src="%s" alt="" title="%s" class="attachment-preview" /></a><br />', $data["url"], proxy_url($data["preview"]), $data["title"]);
				}

				if (($data["type"] == "photo") && ($data["url"] != "") && ($data["image"] != "")) {
					$return .= sprintf('<a href="%s" target="_blank"><img src="%s" alt="" title="%s" class="attachment-image" /></a>', $data["url"], proxy_url($data["image"]), $data["title"]);
				} else {
					$return .= sprintf('<h4><a href="%s">%s</a></h4>', $data['url'], $data['title']);
				}

				if ($data["description"] != "" && $data["description"] != $data["title"]) {
					// Sanitize the HTML by converting it to BBCode
					$bbcode = HTML::toBBCode($data["description"]);
					$return .= sprintf('<blockquote>%s</blockquote>', trim(self::convert($bbcode)));
				}
				if ($data["type"] == "link") {
					$return .= sprintf('<sup><a href="%s">%s</a></sup>', $data['url'], parse_url($data['url'], PHP_URL_HOST));
				}

				if ($simplehtml != 4) {
					$return .= '</div>';
				}
			}
		}

		return trim($data["text"] . ' ' . $return . ' ' . $data["after"]);
	}

	public static function removeShareInformation($Text, $plaintext = false, $nolink = false)
	{
		$data = self::getAttachmentData($Text);

		if (!$data) {
			return $Text;
		} elseif ($nolink) {
			return $data["text"] . $data["after"];
		}

		$title = htmlentities($data["title"], ENT_QUOTES, 'UTF-8', false);
		$text = htmlentities($data["text"], ENT_QUOTES, 'UTF-8', false);
		if ($plaintext || (($title != "") && strstr($text, $title))) {
			$data["title"] = $data["url"];
		} elseif (($text != "") && strstr($title, $text)) {
			$data["text"] = $data["title"];
			$data["title"] = $data["url"];
		}

		if (($data["text"] == "") && ($data["title"] != "") && ($data["url"] == "")) {
			return $data["title"] . $data["after"];
		}

		// If the link already is included in the post, don't add it again
		if (($data["url"] != "") && strpos($data["text"], $data["url"])) {
			return $data["text"] . $data["after"];
		}

		$text = $data["text"];

		if (($data["url"] != "") && ($data["title"] != "")) {
			$text .= "\n[url=" . $data["url"] . "]" . $data["title"] . "[/url]";
		} elseif (($data["url"] != "")) {
			$text .= "\n[url]" . $data["url"] . "[/url]";
		}

		return $text . "\n" . $data["after"];
	}

	/**
	 * Converts [url] BBCodes in a format that looks fine on Mastodon. (callback function)
	 *
	 * @brief Converts [url] BBCodes in a format that looks fine on Mastodon. (callback function)
	 * @param array $match Array with the matching values
	 * @return string reformatted link including HTML codes
	 */
	private static function convertUrlForOStatusCallback($match)
	{
		$url = $match[1];

		if (isset($match[2]) && ($match[1] != $match[2])) {
			return $match[0];
		}

		$parts = parse_url($url);
		if (!isset($parts['scheme'])) {
			return $match[0];
		}

		return self::convertUrlForOStatus($url);
	}

	/**
	 * @brief Converts [url] BBCodes in a format that looks fine on OStatus systems.
	 * @param string $url URL that is about to be reformatted
	 * @return string reformatted link including HTML codes
	 */
	private static function convertUrlForOStatus($url)
	{
		$parts = parse_url($url);
		$scheme = $parts['scheme'] . '://';
		$styled_url = str_replace($scheme, '', $url);

		if (strlen($styled_url) > 30) {
			$styled_url = substr($styled_url, 0, 30) . "…";
		}

		$html = '<a href="%s" target="_blank">%s</a>';

		return sprintf($html, $url, $styled_url);
	}

	/*
	 * [noparse][i]italic[/i][/noparse] turns into
	 * [noparse][ i ]italic[ /i ][/noparse],
	 * to hide them from parser.
	 */
	private static function escapeNoparseCallback($match)
	{
		$whole_match = $match[0];
		$captured = $match[1];
		$spacefied = preg_replace("/\[(.*?)\]/", "[ $1 ]", $captured);
		$new_str = str_replace($captured, $spacefied, $whole_match);
		return $new_str;
	}

	/*
	 * The previously spacefied [noparse][ i ]italic[ /i ][/noparse],
	 * now turns back and the [noparse] tags are trimed
	 * returning [i]italic[/i]
	 */
	private static function unescapeNoparseCallback($match)
	{
		$captured = $match[1];
		$unspacefied = preg_replace("/\[ (.*?)\ ]/", "[$1]", $captured);
		return $unspacefied;
	}

	/**
	 * Returns the bracket character positions of a set of opening and closing BBCode tags, optionally skipping first
	 * occurrences
	 *
	 * @param string $text        Text to search
	 * @param string $name        Tag name
	 * @param int    $occurrences Number of first occurrences to skip
	 * @return boolean|array
	 */
	public static function getTagPosition($text, $name, $occurrences = 0)
	{
		if ($occurrences < 0) {
			$occurrences = 0;
		}

		$start_open = -1;
		for ($i = 0; $i <= $occurrences; $i++) {
			if ($start_open !== false) {
				$start_open = strpos($text, '[' . $name, $start_open + 1); // allow [name= type tags
			}
		}

		if ($start_open === false) {
			return false;
		}

		$start_equal = strpos($text, '=', $start_open);
		$start_close = strpos($text, ']', $start_open);

		if ($start_close === false) {
			return false;
		}

		$start_close++;

		$end_open = strpos($text, '[/' . $name . ']', $start_close);

		if ($end_open === false) {
			return false;
		}

		$res = [
			'start' => [
				'open' => $start_open,
				'close' => $start_close
			],
			'end' => [
				'open' => $end_open,
				'close' => $end_open + strlen('[/' . $name . ']')
			],
		];

		if ($start_equal !== false) {
			$res['start']['equal'] = $start_equal + 1;
		}

		return $res;
	}

	/**
	 * Performs a preg_replace within the boundaries of all named BBCode tags in a text
	 *
	 * @param type $pattern Preg pattern string
	 * @param type $replace Preg replace string
	 * @param type $name    BBCode tag name
	 * @param type $text    Text to search
	 * @return string
	 */
	public static function pregReplaceInTag($pattern, $replace, $name, $text)
	{
		$occurrences = 0;
		$pos = self::getTagPosition($text, $name, $occurrences);
		while ($pos !== false && $occurrences++ < 1000) {
			$start = substr($text, 0, $pos['start']['open']);
			$subject = substr($text, $pos['start']['open'], $pos['end']['close'] - $pos['start']['open']);
			$end = substr($text, $pos['end']['close']);
			if ($end === false) {
				$end = '';
			}

			$subject = preg_replace($pattern, $replace, $subject);
			$text = $start . $subject . $end;

			$pos = self::getTagPosition($text, $name, $occurrences);
		}

		return $text;
	}

	private static function extractImagesFromItemBody($body)
	{
		$saved_image = [];
		$orig_body = $body;
		$new_body = '';

		$cnt = 0;
		$img_start = strpos($orig_body, '[img');
		$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
		$img_end = ($img_start !== false ? strpos(substr($orig_body, $img_start), '[/img]') : false);
		while (($img_st_close !== false) && ($img_end !== false)) {
			$img_st_close++; // make it point to AFTER the closing bracket
			$img_end += $img_start;

			if (!strcmp(substr($orig_body, $img_start + $img_st_close, 5), 'data:')) {
				// This is an embedded image
				$saved_image[$cnt] = substr($orig_body, $img_start + $img_st_close, $img_end - ($img_start + $img_st_close));
				$new_body = $new_body . substr($orig_body, 0, $img_start) . '[$#saved_image' . $cnt . '#$]';

				$cnt++;
			} else {
				$new_body = $new_body . substr($orig_body, 0, $img_end + strlen('[/img]'));
			}

			$orig_body = substr($orig_body, $img_end + strlen('[/img]'));

			if ($orig_body === false) {
				// in case the body ends on a closing image tag
				$orig_body = '';
			}

			$img_start = strpos($orig_body, '[img');
			$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
			$img_end = ($img_start !== false ? strpos(substr($orig_body, $img_start), '[/img]') : false);
		}

		$new_body = $new_body . $orig_body;

		return ['body' => $new_body, 'images' => $saved_image];
	}

	private static function interpolateSavedImagesIntoItemBody($body, array $images)
	{
		$newbody = $body;

		$cnt = 0;
		foreach ($images as $image) {
			// We're depending on the property of 'foreach' (specified on the PHP website) that
			// it loops over the array starting from the first element and going sequentially
			// to the last element
			$newbody = str_replace('[$#saved_image' . $cnt . '#$]',
				'<img src="' . proxy_url($image) . '" alt="' . L10n::t('Image/photo') . '" />', $newbody);
			$cnt++;
		}

		return $newbody;
	}

	/**
	 * Processes [share] tags
	 *
	 * Note: Can produce a [bookmark] tag in the output
	 *
	 * @brief Processes [share] tags
	 * @param array    $share      preg_match_callback result array
	 * @param bool|int $simplehtml
	 * @return string
	 */
	private static function convertShare($share, $simplehtml)
	{
		$attributes = $share[2];

		$author = "";
		preg_match("/author='(.*?)'/ism", $attributes, $matches);
		if (x($matches, 1)) {
			$author = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
		}

		preg_match('/author="(.*?)"/ism', $attributes, $matches);
		if (x($matches, 1)) {
			$author = $matches[1];
		}

		$profile = "";
		preg_match("/profile='(.*?)'/ism", $attributes, $matches);
		if (x($matches, 1)) {
			$profile = $matches[1];
		}

		preg_match('/profile="(.*?)"/ism', $attributes, $matches);
		if (x($matches, 1)) {
			$profile = $matches[1];
		}

		$avatar = "";
		preg_match("/avatar='(.*?)'/ism", $attributes, $matches);
		if (x($matches, 1)) {
			$avatar = $matches[1];
		}

		preg_match('/avatar="(.*?)"/ism', $attributes, $matches);
		if (x($matches, 1)) {
			$avatar = $matches[1];
		}

		$link = "";
		preg_match("/link='(.*?)'/ism", $attributes, $matches);
		if (x($matches, 1)) {
			$link = $matches[1];
		}

		preg_match('/link="(.*?)"/ism', $attributes, $matches);
		if (x($matches, 1)) {
			$link = $matches[1];
		}

		$posted = "";

		preg_match("/posted='(.*?)'/ism", $attributes, $matches);
		if (x($matches, 1)) {
			$posted = $matches[1];
		}

		preg_match('/posted="(.*?)"/ism', $attributes, $matches);
		if (x($matches, 1)) {
			$posted = $matches[1];
		}

		// We only call this so that a previously unknown contact can be added.
		// This is important for the function "Model\Contact::getDetailsByURL()".
		// This function then can fetch an entry from the contact table.
		Contact::getIdForURL($profile, 0, true);

		$data = Contact::getDetailsByURL($profile);

		if (x($data, "name") && x($data, "addr")) {
			$userid_compact = $data["name"] . " (" . $data["addr"] . ")";
		} else {
			$userid_compact = Protocol::getAddrFromProfileUrl($profile, $author);
		}

		if (x($data, "addr")) {
			$userid = $data["addr"];
		} else {
			$userid = Protocol::formatMention($profile, $author);
		}

		if (x($data, "name")) {
			$author = $data["name"];
		}

		if (x($data, "micro")) {
			$avatar = $data["micro"];
		}

		$preshare = trim($share[1]);
		if ($preshare != "") {
			$preshare .= "<br />";
		}

		switch ($simplehtml) {
			case 1:
				$text = $preshare . html_entity_decode("&#x2672; ", ENT_QUOTES, 'UTF-8') . ' <a href="' . $profile . '">' . $userid . "</a>: <br />»" . $share[3] . "«";
				break;
			case 2:
				$text = $preshare . html_entity_decode("&#x2672; ", ENT_QUOTES, 'UTF-8') . ' ' . $userid_compact . ": <br />" . $share[3];
				break;
			case 3: // Diaspora
				$headline = '<b>' . html_entity_decode("&#x2672; ", ENT_QUOTES, 'UTF-8') . $userid . ':</b><br />';

				$text = trim($share[1]);

				if ($text != "") {
					$text .= "<hr />";
				}

				if (stripos(normalise_link($link), 'http://twitter.com/') === 0) {
					$text .= '<br /><a href="' . $link . '">' . $link . '</a>';
				} else {
					$text .= $headline . '<blockquote>' . trim($share[3]) . "</blockquote><br />";

					if ($link != "") {
						$text .= '<br /><a href="' . $link . '">[l]</a>';
					}
				}

				break;
			case 4:
				$headline = '<br /><b>' . html_entity_decode("&#x2672; ", ENT_QUOTES, 'UTF-8');
				$headline .= L10n::t('<a href="%1$s" target="_blank">%2$s</a> %3$s', $link, $userid, $posted);
				$headline .= ":</b><br />";

				$text = trim($share[1]);

				if ($text != "") {
					$text .= "<hr />";
				}

				$text .= $headline . '<blockquote class="shared_content">' . trim($share[3]) . "</blockquote><br />";

				break;
			case 5:
				$text = $preshare . html_entity_decode("&#x2672; ", ENT_QUOTES, 'UTF-8') . ' ' . $userid_compact . ": <br />" . $share[3];
				break;
			case 6: // app.net
				$text = $preshare . "&gt;&gt; @" . $userid_compact . ": <br />" . $share[3];
				break;
			case 7: // statusnet/GNU Social
				$text = $preshare . html_entity_decode("&#x2672; ", ENT_QUOTES, 'UTF-8') . " @" . $userid_compact . ": " . $share[3];
				break;
			case 8: // twitter
				$text = $preshare . "RT @" . $userid_compact . ": " . $share[3];
				break;
			case 9: // Google+/Facebook
				$text = $preshare . html_entity_decode("&#x2672; ", ENT_QUOTES, 'UTF-8') . ' ' . $userid_compact . ": <br />" . $share[3];

				if ($link != "") {
					$text .= "<br /><br />" . $link;
				}
				break;
			default:
				// Transforms quoted tweets in rich attachments to avoid nested tweets
				if (stripos(normalise_link($link), 'http://twitter.com/') === 0 && OEmbed::isAllowedURL($link)) {
					try {
						$oembed = OEmbed::getHTML($link, $preshare);
					} catch (Exception $e) {
						$oembed = sprintf('[bookmark=%s]%s[/bookmark]', $link, $preshare);
					}

					$text = $preshare . $oembed;
				} else {
					$text = trim($share[1]) . "\n";

					$avatar = proxy_url($avatar, false, PROXY_SIZE_THUMB);

					$tpl = get_markup_template('shared_content.tpl');
					$text .= replace_macros($tpl, [
						'$profile' => $profile,
						'$avatar' => $avatar,
						'$author' => $author,
						'$link' => $link,
						'$posted' => $posted,
						'$content' => trim($share[3])
					]);
				}
				break;
		}

		return $text;
	}

	private static function removePictureLinksCallback($match)
	{
		$text = Cache::get($match[1]);

		if (is_null($text)) {
			$a = self::getApp();

			$stamp1 = microtime(true);

			$ch = @curl_init($match[1]);
			@curl_setopt($ch, CURLOPT_NOBODY, true);
			@curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			@curl_setopt($ch, CURLOPT_USERAGENT, $a->get_useragent());
			@curl_exec($ch);
			$curl_info = @curl_getinfo($ch);

			$a->save_timestamp($stamp1, "network");

			if (substr($curl_info["content_type"], 0, 6) == "image/") {
				$text = "[url=" . $match[1] . "]" . $match[1] . "[/url]";
			} else {
				$text = "[url=" . $match[2] . "]" . $match[2] . "[/url]";

				// if its not a picture then look if its a page that contains a picture link
				$body = Network::fetchUrl($match[1]);

				$doc = new DOMDocument();
				@$doc->loadHTML($body);
				$xpath = new DOMXPath($doc);
				$list = $xpath->query("//meta[@name]");
				foreach ($list as $node) {
					$attr = [];

					if ($node->attributes->length) {
						foreach ($node->attributes as $attribute) {
							$attr[$attribute->name] = $attribute->value;
						}
					}

					if (strtolower($attr["name"]) == "twitter:image") {
						$text = "[url=" . $attr["content"] . "]" . $attr["content"] . "[/url]";
					}
				}
			}
			Cache::set($match[1], $text);
		}

		return $text;
	}

	private static function expandLinksCallback($match)
	{
		if (($match[3] == "") || ($match[2] == $match[3]) || stristr($match[2], $match[3])) {
			return ($match[1] . "[url]" . $match[2] . "[/url]");
		} else {
			return ($match[1] . $match[3] . " [url]" . $match[2] . "[/url]");
		}
	}

	private static function cleanPictureLinksCallback($match)
	{
		$text = Cache::get($match[1]);

		if (is_null($text)) {
			$a = self::getApp();

			$stamp1 = microtime(true);

			$ch = @curl_init($match[1]);
			@curl_setopt($ch, CURLOPT_NOBODY, true);
			@curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			@curl_setopt($ch, CURLOPT_USERAGENT, $a->get_useragent());
			@curl_exec($ch);
			$curl_info = @curl_getinfo($ch);

			$a->save_timestamp($stamp1, "network");

			// if its a link to a picture then embed this picture
			if (substr($curl_info["content_type"], 0, 6) == "image/") {
				$text = "[img]" . $match[1] . "[/img]";
			} else {
				$text = "[img]" . $match[2] . "[/img]";

				// if its not a picture then look if its a page that contains a picture link
				$body = Network::fetchUrl($match[1]);

				$doc = new DOMDocument();
				@$doc->loadHTML($body);
				$xpath = new DOMXPath($doc);
				$list = $xpath->query("//meta[@name]");
				foreach ($list as $node) {
					$attr = [];
					if ($node->attributes->length) {
						foreach ($node->attributes as $attribute) {
							$attr[$attribute->name] = $attribute->value;
						}
					}

					if (strtolower($attr["name"]) == "twitter:image") {
						$text = "[img]" . $attr["content"] . "[/img]";
					}
				}
			}
			Cache::set($match[1], $text);
		}

		return $text;
	}

	public static function cleanPictureLinks($text)
	{
		$return = preg_replace_callback("&\[url=([^\[\]]*)\]\[img\](.*)\[\/img\]\[\/url\]&Usi", 'self::cleanPictureLinksCallback', $text);
		return $return;
	}

	private static function textHighlightCallback($match)
	{
		if (in_array(strtolower($match[1]),
				['php', 'css', 'mysql', 'sql', 'abap', 'diff', 'html', 'perl', 'ruby',
				'vbscript', 'avrc', 'dtd', 'java', 'xml', 'cpp', 'python', 'javascript', 'js', 'sh'])
		) {
			return text_highlight($match[2], strtolower($match[1]));
		}
		return $match[0];
	}

	/**
	 * @brief Converts a BBCode message to HTML message
	 *
	 * BBcode 2 HTML was written by WAY2WEB.net
	 * extended to work with Mistpark/Friendica - Mike Macgirvin
	 *
	 * Simple HTML values meaning:
	 * - 0: Friendica display
	 * - 1: Unused
	 * - 2: Used for Facebook, Google+, Windows Phone push, Friendica API
	 * - 3: Used before converting to Markdown in bb2diaspora.php
	 * - 4: Used for WordPress, Libertree (before Markdown), pump.io and tumblr
	 * - 5: Unused
	 * - 6: Used for Appnet
	 * - 7: Used for dfrn, OStatus
	 * - 8: Used for WP backlink text setting
	 *
	 * @param string $text
	 * @param bool   $try_oembed
	 * @param int    $simple_html
	 * @param bool   $for_plaintext
	 * @return string
	 */
	public static function convert($text, $try_oembed = true, $simple_html = false, $for_plaintext = false)
	{
		$a = self::getApp();

		/*
		 * preg_match_callback function to replace potential Oembed tags with Oembed content
		 *
		 * $match[0] = [tag]$url[/tag] or [tag=$url]$title[/tag]
		 * $match[1] = $url
		 * $match[2] = $title or absent
		 */
		$try_oembed_callback = function ($match)
		{
			$url = $match[1];
			$title = defaults($match, 2, null);

			try {
				$return = OEmbed::getHTML($url, $title);
			} catch (Exception $ex) {
				$return = $match[0];
			}

			return $return;
		};

		// Hide all [noparse] contained bbtags by spacefying them
		// POSSIBLE BUG --> Will the 'preg' functions crash if there's an embedded image?

		$text = preg_replace_callback("/\[noparse\](.*?)\[\/noparse\]/ism", 'self::escapeNoparseCallback', $text);
		$text = preg_replace_callback("/\[nobb\](.*?)\[\/nobb\]/ism", 'self::escapeNoparseCallback', $text);
		$text = preg_replace_callback("/\[pre\](.*?)\[\/pre\]/ism", 'self::escapeNoparseCallback', $text);

		// Remove the abstract element. It is a non visible element.
		$text = self::stripAbstract($text);

		// Move all spaces out of the tags
		$text = preg_replace("/\[(\w*)\](\s*)/ism", '$2[$1]', $text);
		$text = preg_replace("/(\s*)\[\/(\w*)\]/ism", '[/$2]$1', $text);

		// Extract the private images which use data urls since preg has issues with
		// large data sizes. Stash them away while we do bbcode conversion, and then put them back
		// in after we've done all the regex matching. We cannot use any preg functions to do this.

		$extracted = self::extractImagesFromItemBody($text);
		$text = $extracted['body'];
		$saved_image = $extracted['images'];

		// If we find any event code, turn it into an event.
		// After we're finished processing the bbcode we'll
		// replace all of the event code with a reformatted version.

		$ev = Event::fromBBCode($text);

		// Replace any html brackets with HTML Entities to prevent executing HTML or script
		// Don't use strip_tags here because it breaks [url] search by replacing & with amp

		$text = str_replace("<", "&lt;", $text);
		$text = str_replace(">", "&gt;", $text);

		// remove some newlines before the general conversion
		$text = preg_replace("/\s?\[share(.*?)\]\s?(.*?)\s?\[\/share\]\s?/ism", "[share$1]$2[/share]", $text);
		$text = preg_replace("/\s?\[quote(.*?)\]\s?(.*?)\s?\[\/quote\]\s?/ism", "[quote$1]$2[/quote]", $text);

		$text = preg_replace("/\n\[code\]/ism", "[code]", $text);
		$text = preg_replace("/\[\/code\]\n/ism", "[/code]", $text);

		// when the content is meant exporting to other systems then remove the avatar picture since this doesn't really look good on these systems
		if (!$try_oembed) {
			$text = preg_replace("/\[share(.*?)avatar\s?=\s?'.*?'\s?(.*?)\]\s?(.*?)\s?\[\/share\]\s?/ism", "\n[share$1$2]$3[/share]", $text);
		}

		// Check for [code] text here, before the linefeeds are messed with.
		// The highlighter will unescape and re-escape the content.
		if (strpos($text, '[code=') !== false) {
			$text = preg_replace_callback("/\[code=(.*?)\](.*?)\[\/code\]/ism", 'self::textHighlightCallback', $text);
		}
		// Convert new line chars to html <br /> tags

		// nlbr seems to be hopelessly messed up
		//	$Text = nl2br($Text);

		// We'll emulate it.

		$text = trim($text);
		$text = str_replace("\r\n", "\n", $text);

		// removing multiplicated newlines
		if (Config::get("system", "remove_multiplicated_lines")) {
			$search = ["\n\n\n", "\n ", " \n", "[/quote]\n\n", "\n[/quote]", "[/li]\n", "\n[li]", "\n[ul]", "[/ul]\n", "\n\n[share ", "[/attachment]\n",
					"\n[h1]", "[/h1]\n", "\n[h2]", "[/h2]\n", "\n[h3]", "[/h3]\n", "\n[h4]", "[/h4]\n", "\n[h5]", "[/h5]\n", "\n[h6]", "[/h6]\n"];
			$replace = ["\n\n", "\n", "\n", "[/quote]\n", "[/quote]", "[/li]", "[li]", "[ul]", "[/ul]", "\n[share ", "[/attachment]",
					"[h1]", "[/h1]", "[h2]", "[/h2]", "[h3]", "[/h3]", "[h4]", "[/h4]", "[h5]", "[/h5]", "[h6]", "[/h6]"];
			do {
				$oldtext = $text;
				$text = str_replace($search, $replace, $text);
			} while ($oldtext != $text);
		}

		// Set up the parameters for a URL search string
		$URLSearchString = "^\[\]";
		// Set up the parameters for a MAIL search string
		$MAILSearchString = $URLSearchString;

		// if the HTML is used to generate plain text, then don't do this search, but replace all URL of that kind to text
		if (!$for_plaintext) {
			// Autolink feature (thanks to http://code.seebz.net/p/autolink-php/)
			// Currently disabled, since the function is too greedy
			// $autolink_regex = "`([^\]\=\"']|^)(https?\://[^\s<]+[^\s<\.\)])`ism";
			$autolink_regex = "/([^\]\='".'"'."]|^)(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)/ism";
			$text = preg_replace($autolink_regex, '$1[url]$2[/url]', $text);
			if ($simple_html == 7) {
				$text = preg_replace_callback("/\[url\]([$URLSearchString]*)\[\/url\]/ism", 'self::convertUrlForOStatusCallback', $text);
				$text = preg_replace_callback("/\[url\=([$URLSearchString]*)\]([$URLSearchString]*)\[\/url\]/ism", 'self::convertUrlForOStatusCallback', $text);
			}
		} else {
			$text = preg_replace("(\[url\]([$URLSearchString]*)\[\/url\])ism", " $1 ", $text);
			$text = preg_replace_callback("&\[url=([^\[\]]*)\]\[img\](.*)\[\/img\]\[\/url\]&Usi", 'self::removePictureLinksCallback', $text);
		}


		// Handle attached links or videos
		$text = self::convertAttachment($text, $simple_html, $try_oembed);

		$text = str_replace(["\r","\n"], ['<br />', '<br />'], $text);

		// Remove all hashtag addresses
		if ((!$try_oembed || $simple_html) && !in_array($simple_html, [3, 7])) {
			$text = preg_replace("/([#@!])\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", '$1$3', $text);
		} elseif ($simple_html == 3) {
			// The ! is converted to @ since Diaspora only understands the @
			$text = preg_replace("/([@!])\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism",
				'@<a href="$2">$3</a>',
				$text);
		} elseif ($simple_html == 7) {
			$text = preg_replace("/([@!])\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism",
				'$1<span class="vcard"><a href="$2" class="url" title="$3"><span class="fn nickname mention">$3</span></a></span>',
				$text);
		} elseif (!$simple_html) {
			$text = preg_replace("/([@!])\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism",
				'$1<a href="$2" class="userinfo mention" title="$3">$3</a>',
				$text);
		}

		// Bookmarks in red - will be converted to bookmarks in friendica
		$text = preg_replace("/#\^\[url\]([$URLSearchString]*)\[\/url\]/ism", '[bookmark=$1]$1[/bookmark]', $text);
		$text = preg_replace("/#\^\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", '[bookmark=$1]$2[/bookmark]', $text);
		$text = preg_replace("/#\[url\=[$URLSearchString]*\]\^\[\/url\]\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/i",
					"[bookmark=$1]$2[/bookmark]", $text);

		if (in_array($simple_html, [2, 6, 7, 8, 9])) {
			$text = preg_replace_callback("/([^#@!])\[url\=([^\]]*)\](.*?)\[\/url\]/ism", "self::expandLinksCallback", $text);
			//$Text = preg_replace("/[^#@!]\[url\=([^\]]*)\](.*?)\[\/url\]/ism", ' $2 [url]$1[/url]', $Text);
			$text = preg_replace("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/ism", ' $2 [url]$1[/url]',$text);
		}

		if ($simple_html == 5) {
			$text = preg_replace("/[^#@!]\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", '[url]$1[/url]', $text);
		}

		// Perform URL Search
		if ($try_oembed) {
			$text = preg_replace_callback("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/ism", $try_oembed_callback, $text);
		}

		if ($simple_html == 5) {
			$text = preg_replace("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/ism", '[url]$1[/url]', $text);
		} else {
			$text = preg_replace("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/ism", '[url=$1]$2[/url]', $text);
		}

		// Handle Diaspora posts
		$text = preg_replace_callback(
			"&\[url=/posts/([^\[\]]*)\](.*)\[\/url\]&Usi",
			function ($match) {
				return "[url=" . System::baseUrl() . "/display/" . $match[1] . "]" . $match[2] . "[/url]";
			}, $text
		);

		// Server independent link to posts and comments
		// See issue: https://github.com/diaspora/diaspora_federation/issues/75
		$expression = "=diaspora://.*?/post/([0-9A-Za-z\-_@.:]{15,254}[0-9A-Za-z])=ism";
		$text = preg_replace($expression, System::baseUrl()."/display/$1", $text);

		$text = preg_replace("/([#])\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism",
					'$1<a href="' . System::baseUrl() . '/search?tag=$3" class="tag" title="$3">$3</a>', $text);

		$text = preg_replace("/\[url\=([$URLSearchString]*)\]#(.*?)\[\/url\]/ism",
					'#<a href="' . System::baseUrl() . '/search?tag=$2" class="tag" title="$2">$2</a>', $text);

		$text = preg_replace("/\[url\]([$URLSearchString]*)\[\/url\]/ism", '<a href="$1" target="_blank">$1</a>', $text);
		$text = preg_replace("/\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", '<a href="$1" target="_blank">$2</a>', $text);
		//$Text = preg_replace("/\[url\=([$URLSearchString]*)\]([$URLSearchString]*)\[\/url\]/ism", '<a href="$1" target="_blank">$2</a>', $Text);

		// Red compatibility, though the link can't be authenticated on Friendica
		$text = preg_replace("/\[zrl\=([$URLSearchString]*)\](.*?)\[\/zrl\]/ism", '<a href="$1" target="_blank">$2</a>', $text);


		// we may need to restrict this further if it picks up too many strays
		// link acct:user@host to a webfinger profile redirector

		$text = preg_replace('/acct:([^@]+)@((?!\-)(?:[a-zA-Z\d\-]{0,62}[a-zA-Z\d]\.){1,126}(?!\d+)[a-zA-Z\d]{1,63})/', '<a href="' . System::baseUrl() . '/acctlink?addr=$1@$2" target="extlink">acct:$1@$2</a>', $text);

		// Perform MAIL Search
		$text = preg_replace("/\[mail\]([$MAILSearchString]*)\[\/mail\]/", '<a href="mailto:$1">$1</a>', $text);
		$text = preg_replace("/\[mail\=([$MAILSearchString]*)\](.*?)\[\/mail\]/", '<a href="mailto:$1">$2</a>', $text);

		// leave open the posibility of [map=something]
		// this is replaced in prepare_body() which has knowledge of the item location

		if (strpos($text, '[/map]') !== false) {
			$text = preg_replace_callback(
				"/\[map\](.*?)\[\/map\]/ism",
				function ($match) use ($simple_html) {
					return str_replace($match[0], '<p class="map">' . Map::byLocation($match[1], $simple_html) . '</p>', $match[0]);
				},
				$text
			);
		}
		if (strpos($text, '[map=') !== false) {
			$text = preg_replace_callback(
				"/\[map=(.*?)\]/ism",
				function ($match) use ($simple_html) {
					return str_replace($match[0], '<p class="map">' . Map::byCoordinates(str_replace('/', ' ', $match[1]), $simple_html) . '</p>', $match[0]);
				},
				$text
			);
		}
		if (strpos($text, '[map]') !== false) {
			$text = preg_replace("/\[map\]/", '<p class="map"></p>', $text);
		}

		// Check for headers
		$text = preg_replace("(\[h1\](.*?)\[\/h1\])ism", '<h1>$1</h1>', $text);
		$text = preg_replace("(\[h2\](.*?)\[\/h2\])ism", '<h2>$1</h2>', $text);
		$text = preg_replace("(\[h3\](.*?)\[\/h3\])ism", '<h3>$1</h3>', $text);
		$text = preg_replace("(\[h4\](.*?)\[\/h4\])ism", '<h4>$1</h4>', $text);
		$text = preg_replace("(\[h5\](.*?)\[\/h5\])ism", '<h5>$1</h5>', $text);
		$text = preg_replace("(\[h6\](.*?)\[\/h6\])ism", '<h6>$1</h6>', $text);

		// Check for paragraph
		$text = preg_replace("(\[p\](.*?)\[\/p\])ism", '<p>$1</p>', $text);

		// Check for bold text
		$text = preg_replace("(\[b\](.*?)\[\/b\])ism", '<strong>$1</strong>', $text);

		// Check for Italics text
		$text = preg_replace("(\[i\](.*?)\[\/i\])ism", '<em>$1</em>', $text);

		// Check for Underline text
		$text = preg_replace("(\[u\](.*?)\[\/u\])ism", '<u>$1</u>', $text);

		// Check for strike-through text
		$text = preg_replace("(\[s\](.*?)\[\/s\])ism", '<strike>$1</strike>', $text);

		// Check for over-line text
		$text = preg_replace("(\[o\](.*?)\[\/o\])ism", '<span class="overline">$1</span>', $text);

		// Check for colored text
		$text = preg_replace("(\[color=(.*?)\](.*?)\[\/color\])ism", "<span style=\"color: $1;\">$2</span>", $text);

		// Check for sized text
		// [size=50] --> font-size: 50px (with the unit).
		$text = preg_replace("(\[size=(\d*?)\](.*?)\[\/size\])ism", "<span style=\"font-size: $1px; line-height: initial;\">$2</span>", $text);
		$text = preg_replace("(\[size=(.*?)\](.*?)\[\/size\])ism", "<span style=\"font-size: $1; line-height: initial;\">$2</span>", $text);

		// Check for centered text
		$text = preg_replace("(\[center\](.*?)\[\/center\])ism", "<div style=\"text-align:center;\">$1</div>", $text);

		// Check for list text
		$text = str_replace("[*]", "<li>", $text);

		// Check for style sheet commands
		$text = preg_replace_callback(
			"(\[style=(.*?)\](.*?)\[\/style\])ism",
			function ($match) {
				return "<span style=\"" . HTML::sanitizeCSS($match[1]) . ";\">" . $match[2] . "</span>";
			},
			$text
		);

		// Check for CSS classes
		$text = preg_replace_callback(
			"(\[class=(.*?)\](.*?)\[\/class\])ism",
			function ($match) {
				return "<span class=\"" . HTML::sanitizeCSS($match[1]) . "\">" . $match[2] . "</span>";
			},
			$text
		);

		// handle nested lists
		$endlessloop = 0;

		while ((((strpos($text, "[/list]") !== false) && (strpos($text, "[list") !== false)) ||
			   ((strpos($text, "[/ol]") !== false) && (strpos($text, "[ol]") !== false)) ||
			   ((strpos($text, "[/ul]") !== false) && (strpos($text, "[ul]") !== false)) ||
			   ((strpos($text, "[/li]") !== false) && (strpos($text, "[li]") !== false))) && (++$endlessloop < 20)) {
			$text = preg_replace("/\[list\](.*?)\[\/list\]/ism", '<ul class="listbullet" style="list-style-type: circle;">$1</ul>', $text);
			$text = preg_replace("/\[list=\](.*?)\[\/list\]/ism", '<ul class="listnone" style="list-style-type: none;">$1</ul>', $text);
			$text = preg_replace("/\[list=1\](.*?)\[\/list\]/ism", '<ul class="listdecimal" style="list-style-type: decimal;">$1</ul>', $text);
			$text = preg_replace("/\[list=((?-i)i)\](.*?)\[\/list\]/ism", '<ul class="listlowerroman" style="list-style-type: lower-roman;">$2</ul>', $text);
			$text = preg_replace("/\[list=((?-i)I)\](.*?)\[\/list\]/ism", '<ul class="listupperroman" style="list-style-type: upper-roman;">$2</ul>', $text);
			$text = preg_replace("/\[list=((?-i)a)\](.*?)\[\/list\]/ism", '<ul class="listloweralpha" style="list-style-type: lower-alpha;">$2</ul>', $text);
			$text = preg_replace("/\[list=((?-i)A)\](.*?)\[\/list\]/ism", '<ul class="listupperalpha" style="list-style-type: upper-alpha;">$2</ul>', $text);
			$text = preg_replace("/\[ul\](.*?)\[\/ul\]/ism", '<ul class="listbullet" style="list-style-type: circle;">$1</ul>', $text);
			$text = preg_replace("/\[ol\](.*?)\[\/ol\]/ism", '<ul class="listdecimal" style="list-style-type: decimal;">$1</ul>', $text);
			$text = preg_replace("/\[li\](.*?)\[\/li\]/ism", '<li>$1</li>', $text);
		}

		$text = preg_replace("/\[th\](.*?)\[\/th\]/sm", '<th>$1</th>', $text);
		$text = preg_replace("/\[td\](.*?)\[\/td\]/sm", '<td>$1</td>', $text);
		$text = preg_replace("/\[tr\](.*?)\[\/tr\]/sm", '<tr>$1</tr>', $text);
		$text = preg_replace("/\[table\](.*?)\[\/table\]/sm", '<table>$1</table>', $text);

		$text = preg_replace("/\[table border=1\](.*?)\[\/table\]/sm", '<table border="1" >$1</table>', $text);
		$text = preg_replace("/\[table border=0\](.*?)\[\/table\]/sm", '<table border="0" >$1</table>', $text);

		$text = str_replace('[hr]', '<hr />', $text);

		// This is actually executed in prepare_body()

		$text = str_replace('[nosmile]', '', $text);

		// Check for font change text
		$text = preg_replace("/\[font=(.*?)\](.*?)\[\/font\]/sm", "<span style=\"font-family: $1;\">$2</span>", $text);

		// Declare the format for [code] layout

		$CodeLayout = '<code>$1</code>';
		// Check for [code] text
		$text = preg_replace("/\[code\](.*?)\[\/code\]/ism", "$CodeLayout", $text);

		// Declare the format for [spoiler] layout
		$SpoilerLayout = '<blockquote class="spoiler">$1</blockquote>';

		// Check for [spoiler] text
		// handle nested quotes
		$endlessloop = 0;
		while ((strpos($text, "[/spoiler]") !== false) && (strpos($text, "[spoiler]") !== false) && (++$endlessloop < 20)) {
			$text = preg_replace("/\[spoiler\](.*?)\[\/spoiler\]/ism", "$SpoilerLayout", $text);
		}

		// Check for [spoiler=Author] text

		$t_wrote = L10n::t('$1 wrote:');

		// handle nested quotes
		$endlessloop = 0;
		while ((strpos($text, "[/spoiler]")!== false)  && (strpos($text, "[spoiler=") !== false) && (++$endlessloop < 20)) {
			$text = preg_replace("/\[spoiler=[\"\']*(.*?)[\"\']*\](.*?)\[\/spoiler\]/ism",
						 "<br /><strong class=".'"spoiler"'.">" . $t_wrote . "</strong><blockquote class=".'"spoiler"'.">$2</blockquote>",
						 $text);
		}

		// Declare the format for [quote] layout
		$QuoteLayout = '<blockquote>$1</blockquote>';

		// Check for [quote] text
		// handle nested quotes
		$endlessloop = 0;
		while ((strpos($text, "[/quote]") !== false) && (strpos($text, "[quote]") !== false) && (++$endlessloop < 20)) {
			$text = preg_replace("/\[quote\](.*?)\[\/quote\]/ism", "$QuoteLayout", $text);
		}

		// Check for [quote=Author] text

		$t_wrote = L10n::t('$1 wrote:');

		// handle nested quotes
		$endlessloop = 0;
		while ((strpos($text, "[/quote]")!== false)  && (strpos($text, "[quote=") !== false) && (++$endlessloop < 20)) {
			$text = preg_replace("/\[quote=[\"\']*(.*?)[\"\']*\](.*?)\[\/quote\]/ism",
						 "<p><strong class=".'"author"'.">" . $t_wrote . "</strong></p><blockquote>$2</blockquote>",
						 $text);
		}


		// [img=widthxheight]image source[/img]
		$text = preg_replace_callback(
			"/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism",
			function ($matches) {
				if (strpos($matches[3], "data:image/") === 0) {
					return $matches[0];
				}

				$matches[3] = proxy_url($matches[3]);
				return "[img=" . $matches[1] . "x" . $matches[2] . "]" . $matches[3] . "[/img]";
			},
			$text
		);

		$text = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '<img src="$3" style="width: $1px;" >', $text);
		$text = preg_replace("/\[zmg\=([0-9]*)x([0-9]*)\](.*?)\[\/zmg\]/ism", '<img class="zrl" src="$3" style="width: $1px;" >', $text);

		// Images
		// [img]pathtoimage[/img]
		$text = preg_replace_callback(
			"/\[img\](.*?)\[\/img\]/ism",
			function ($matches) {
				if (strpos($matches[1], "data:image/") === 0) {
					return $matches[0];
				}

				$matches[1] = proxy_url($matches[1]);
				return "[img]" . $matches[1] . "[/img]";
			},
			$text
		);

		$text = preg_replace("/\[img\](.*?)\[\/img\]/ism", '<img src="$1" alt="' . L10n::t('Image/photo') . '" />', $text);
		$text = preg_replace("/\[zmg\](.*?)\[\/zmg\]/ism", '<img src="$1" alt="' . L10n::t('Image/photo') . '" />', $text);

		// Shared content
		$text = preg_replace_callback("/(.*?)\[share(.*?)\](.*?)\[\/share\]/ism",
			function ($match) use ($simple_html) {
				return self::convertShare($match, $simple_html);
			}, $text);

		$text = preg_replace("/\[crypt\](.*?)\[\/crypt\]/ism", '<br/><img src="' .System::baseUrl() . '/images/lock_icon.gif" alt="' . L10n::t('Encrypted content') . '" title="' . L10n::t('Encrypted content') . '" /><br />', $text);
		$text = preg_replace("/\[crypt(.*?)\](.*?)\[\/crypt\]/ism", '<br/><img src="' .System::baseUrl() . '/images/lock_icon.gif" alt="' . L10n::t('Encrypted content') . '" title="' . '$1' . ' ' . L10n::t('Encrypted content') . '" /><br />', $text);
		//$Text = preg_replace("/\[crypt=(.*?)\](.*?)\[\/crypt\]/ism", '<br/><img src="' .System::baseUrl() . '/images/lock_icon.gif" alt="' . L10n::t('Encrypted content') . '" title="' . '$1' . ' ' . L10n::t('Encrypted content') . '" /><br />', $Text);

		// Try to Oembed
		if ($try_oembed) {
			$text = preg_replace("/\[video\](.*?\.(ogg|ogv|oga|ogm|webm|mp4))\[\/video\]/ism", '<video src="$1" controls="controls" width="' . $a->videowidth . '" height="' . $a->videoheight . '" loop="true"><a href="$1">$1</a></video>', $text);
			$text = preg_replace("/\[audio\](.*?\.(ogg|ogv|oga|ogm|webm|mp4|mp3))\[\/audio\]/ism", '<audio src="$1" controls="controls"><a href="$1">$1</a></audio>', $text);

			$text = preg_replace_callback("/\[video\](.*?)\[\/video\]/ism", $try_oembed_callback, $text);
			$text = preg_replace_callback("/\[audio\](.*?)\[\/audio\]/ism", $try_oembed_callback, $text);
		} else {
			$text = preg_replace("/\[video\](.*?)\[\/video\]/",
						'<a href="$1" target="_blank">$1</a>', $text);
			$text = preg_replace("/\[audio\](.*?)\[\/audio\]/",
						'<a href="$1" target="_blank">$1</a>', $text);
		}

		// html5 video and audio


		if ($try_oembed) {
			$text = preg_replace("/\[iframe\](.*?)\[\/iframe\]/ism", '<iframe src="$1" width="' . $a->videowidth . '" height="' . $a->videoheight . '"><a href="$1">$1</a></iframe>', $text);
		} else {
			$text = preg_replace("/\[iframe\](.*?)\[\/iframe\]/ism", '<a href="$1">$1</a>', $text);
		}

		// Youtube extensions
		if ($try_oembed) {
			$text = preg_replace_callback("/\[youtube\](https?:\/\/www.youtube.com\/watch\?v\=.*?)\[\/youtube\]/ism", $try_oembed_callback, $text);
			$text = preg_replace_callback("/\[youtube\](www.youtube.com\/watch\?v\=.*?)\[\/youtube\]/ism", $try_oembed_callback, $text);
			$text = preg_replace_callback("/\[youtube\](https?:\/\/youtu.be\/.*?)\[\/youtube\]/ism", $try_oembed_callback, $text);
		}

		$text = preg_replace("/\[youtube\]https?:\/\/www.youtube.com\/watch\?v\=(.*?)\[\/youtube\]/ism", '[youtube]$1[/youtube]', $text);
		$text = preg_replace("/\[youtube\]https?:\/\/www.youtube.com\/embed\/(.*?)\[\/youtube\]/ism", '[youtube]$1[/youtube]', $text);
		$text = preg_replace("/\[youtube\]https?:\/\/youtu.be\/(.*?)\[\/youtube\]/ism", '[youtube]$1[/youtube]', $text);

		if ($try_oembed) {
			$text = preg_replace("/\[youtube\]([A-Za-z0-9\-_=]+)(.*?)\[\/youtube\]/ism", '<iframe width="' . $a->videowidth . '" height="' . $a->videoheight . '" src="https://www.youtube.com/embed/$1" frameborder="0" ></iframe>', $text);
		} else {
			$text = preg_replace("/\[youtube\]([A-Za-z0-9\-_=]+)(.*?)\[\/youtube\]/ism",
						'<a href="https://www.youtube.com/watch?v=$1" target="_blank">https://www.youtube.com/watch?v=$1</a>', $text);
		}

		if ($try_oembed) {
			$text = preg_replace_callback("/\[vimeo\](https?:\/\/player.vimeo.com\/video\/[0-9]+).*?\[\/vimeo\]/ism", $try_oembed_callback, $text);
			$text = preg_replace_callback("/\[vimeo\](https?:\/\/vimeo.com\/[0-9]+).*?\[\/vimeo\]/ism", $try_oembed_callback, $text);
		}

		$text = preg_replace("/\[vimeo\]https?:\/\/player.vimeo.com\/video\/([0-9]+)(.*?)\[\/vimeo\]/ism", '[vimeo]$1[/vimeo]', $text);
		$text = preg_replace("/\[vimeo\]https?:\/\/vimeo.com\/([0-9]+)(.*?)\[\/vimeo\]/ism", '[vimeo]$1[/vimeo]', $text);

		if ($try_oembed) {
			$text = preg_replace("/\[vimeo\]([0-9]+)(.*?)\[\/vimeo\]/ism", '<iframe width="' . $a->videowidth . '" height="' . $a->videoheight . '" src="https://player.vimeo.com/video/$1" frameborder="0" ></iframe>', $text);
		} else {
			$text = preg_replace("/\[vimeo\]([0-9]+)(.*?)\[\/vimeo\]/ism",
						'<a href="https://vimeo.com/$1" target="_blank">https://vimeo.com/$1</a>', $text);
		}

		// oembed tag
		$text = OEmbed::BBCode2HTML($text);

		// Avoid triple linefeeds through oembed
		$text = str_replace("<br style='clear:left'></span><br /><br />", "<br style='clear:left'></span><br />", $text);

		// If we found an event earlier, strip out all the event code and replace with a reformatted version.
		// Replace the event-start section with the entire formatted event. The other bbcode is stripped.
		// Summary (e.g. title) is required, earlier revisions only required description (in addition to
		// start which is always required). Allow desc with a missing summary for compatibility.

		if ((x($ev, 'desc') || x($ev, 'summary')) && x($ev, 'start')) {
			$sub = Event::getHTML($ev, $simple_html);

			$text = preg_replace("/\[event\-summary\](.*?)\[\/event\-summary\]/ism", '', $text);
			$text = preg_replace("/\[event\-description\](.*?)\[\/event\-description\]/ism", '', $text);
			$text = preg_replace("/\[event\-start\](.*?)\[\/event\-start\]/ism", $sub, $text);
			$text = preg_replace("/\[event\-finish\](.*?)\[\/event\-finish\]/ism", '', $text);
			$text = preg_replace("/\[event\-location\](.*?)\[\/event\-location\]/ism", '', $text);
			$text = preg_replace("/\[event\-adjust\](.*?)\[\/event\-adjust\]/ism", '', $text);
			$text = preg_replace("/\[event\-id\](.*?)\[\/event\-id\]/ism", '', $text);
		}

		// Replace non graphical smilies for external posts
		if ($simple_html) {
			$text = Smilies::replace($text, false, true);
		}

		// Replace inline code blocks
		$text = preg_replace_callback("|(?!<br[^>]*>)<code>([^<]*)</code>(?!<br[^>]*>)|ism",
			function ($match) use ($simple_html) {
				$return = '<key>' . $match[1] . '</key>';
				// Use <code> for Diaspora inline code blocks
				if ($simple_html === 3) {
					$return = '<code>' . $match[1] . '</code>';
				}
				return $return;
			}
		, $text);

		// Unhide all [noparse] contained bbtags unspacefying them
		// and triming the [noparse] tag.

		$text = preg_replace_callback("/\[noparse\](.*?)\[\/noparse\]/ism", 'self::unescapeNoparseCallback', $text);
		$text = preg_replace_callback("/\[nobb\](.*?)\[\/nobb\]/ism", 'self::unescapeNoparseCallback', $text);
		$text = preg_replace_callback("/\[pre\](.*?)\[\/pre\]/ism", 'self::unescapeNoparseCallback', $text);


		$text = preg_replace('/\[\&amp\;([#a-z0-9]+)\;\]/', '&$1;', $text);
		$text = preg_replace('/\&\#039\;/', '\'', $text);
		$text = preg_replace('/\&quot\;/', '"', $text);

		// fix any escaped ampersands that may have been converted into links
		$text = preg_replace('/\<([^>]*?)(src|href)=(.*?)\&amp\;(.*?)\>/ism', '<$1$2=$3&$4>', $text);

		// sanitizes src attributes (http and redir URLs for displaying in a web page, cid used for inline images in emails)
		$allowed_src_protocols = ['http', 'redir', 'cid'];
		$text = preg_replace('#<([^>]*?)(src)="(?!' . implode('|', $allowed_src_protocols) . ')(.*?)"(.*?)>#ism',
					 '<$1$2=""$4 data-original-src="$3" class="invalid-src" title="' . L10n::t('Invalid source protocol') . '">', $text);

		// sanitize href attributes (only whitelisted protocols URLs)
		// default value for backward compatibility
		$allowed_link_protocols = Config::get('system', 'allowed_link_protocols', ['ftp', 'mailto', 'gopher', 'cid']);

		// Always allowed protocol even if config isn't set or not including it
		$allowed_link_protocols[] = 'http';
		$allowed_link_protocols[] = 'redir/';

		$regex = '#<([^>]*?)(href)="(?!' . implode('|', $allowed_link_protocols) . ')(.*?)"(.*?)>#ism';
		$text = preg_replace($regex, '<$1$2="javascript:void(0)"$4 data-original-href="$3" class="invalid-href" title="' . L10n::t('Invalid link protocol') . '">', $text);

		if ($saved_image) {
			$text = self::interpolateSavedImagesIntoItemBody($text, $saved_image);
		}

		// Clean up the HTML by loading and saving the HTML with the DOM.
		// Bad structured html can break a whole page.
		// For performance reasons do it only with ativated item cache or at export.
		if (!$try_oembed || (get_itemcachepath() != "")) {
			$doc = new DOMDocument();
			$doc->preserveWhiteSpace = false;

			$text = mb_convert_encoding($text, 'HTML-ENTITIES', "UTF-8");

			$doctype = '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">';
			$encoding = '<?xml encoding="UTF-8">';
			@$doc->loadHTML($encoding.$doctype."<html><body>".$text."</body></html>");
			$doc->encoding = 'UTF-8';
			$text = $doc->saveHTML();
			$text = str_replace(["<html><body>", "</body></html>", $doctype, $encoding], ["", "", "", ""], $text);

			$text = str_replace('<br></li>', '</li>', $text);

			//$Text = mb_convert_encoding($Text, "UTF-8", 'HTML-ENTITIES');
		}

		// Clean up some useless linebreaks in lists
		//$Text = str_replace('<br /><ul', '<ul ', $Text);
		//$Text = str_replace('</ul><br />', '</ul>', $Text);
		//$Text = str_replace('</li><br />', '</li>', $Text);
		//$Text = str_replace('<br /><li>', '<li>', $Text);
		//$Text = str_replace('<br /><ul', '<ul ', $Text);

		Addon::callHooks('bbcode', $text);

		return trim($text);
	}

	/**
	 * @brief Strips the "abstract" tag from the provided text
	 *
	 * @param string $text The text with BBCode
	 * @return string The same text - but without "abstract" element
	 */
	public static function stripAbstract($text)
	{
		$text = preg_replace("/[\s|\n]*\[abstract\].*?\[\/abstract\][\s|\n]*/ism", '', $text);
		$text = preg_replace("/[\s|\n]*\[abstract=.*?\].*?\[\/abstract][\s|\n]*/ism", '', $text);

		return $text;
	}

	/**
	 * @brief Returns the value of the "abstract" element
	 *
	 * @param string $text The text that maybe contains the element
	 * @param string $addon The addon for which the abstract is meant for
	 * @return string The abstract
	 */
	private static function getAbstract($text, $addon = "")
	{
		$abstract = "";
		$abstracts = [];
		$addon = strtolower($addon);

		if (preg_match_all("/\[abstract=(.*?)\](.*?)\[\/abstract\]/ism", $text, $results, PREG_SET_ORDER)) {
			foreach ($results AS $result) {
				$abstracts[strtolower($result[1])] = $result[2];
			}
		}

		if (isset($abstracts[$addon])) {
			$abstract = $abstracts[$addon];
		}

		if ($abstract == "" && preg_match("/\[abstract\](.*?)\[\/abstract\]/ism", $text, $result)) {
			$abstract = $result[1];
		}

		return $abstract;
	}

	/**
	 * @brief Callback function to replace a Friendica style mention in a mention for Diaspora
	 *
	 * @param array $match Matching values for the callback
	 * @return string Replaced mention
	 */
	private static function bbCodeMention2DiasporaCallback($match)
	{
		$contact = Contact::getDetailsByURL($match[3]);

		if (empty($contact['addr'])) {
			$contact = Probe::uri($match[3]);
		}

		if (empty($contact['addr'])) {
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
	 * @param string $text
	 * @param bool   $for_diaspora Diaspora requires more changes than Libertree
	 * @return string
	 */
	public static function toMarkdown($text, $for_diaspora = true)
	{
		$a = self::getApp();

		$original_text = $text;

		// Since Diaspora is creating a summary for links, this function removes them before posting
		if ($for_diaspora) {
			$text = self::removeShareInformation($text);
		}

		/**
		 * Transform #tags, strip off the [url] and replace spaces with underscore
		 */
		$url_search_string = "^\[\]";
		$text = preg_replace_callback("/#\[url\=([$url_search_string]*)\](.*?)\[\/url\]/i",
			function ($matches) {
				return '#' . str_replace(' ', '_', $matches[2]);
			},
			$text
		);

		// Converting images with size parameters to simple images. Markdown doesn't know it.
		$text = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $text);

		// Extracting multi-line code blocks before the whitespace processing/code highlighter in self::convert()
		$codeblocks = [];

		$text = preg_replace_callback("#\[code(?:=([^\]]*))?\](.*?)\[\/code\]#is",
			function ($matches) use (&$codeblocks) {
				$return = $matches[0];
				if (strpos($matches[2], "\n") !== false) {
					$return = '#codeblock-' . count($codeblocks) . '#';

					$prefix = '````' . $matches[1] . PHP_EOL;
					$codeblocks[] = $prefix . trim($matches[2]) . PHP_EOL . '````';
				}
				return $return;
			},
			$text
		);

		// Convert it to HTML - don't try oembed
		if ($for_diaspora) {
			$text = self::convert($text, false, 3);

			// Add all tags that maybe were removed
			if (preg_match_all("/#\[url\=([$url_search_string]*)\](.*?)\[\/url\]/ism", $original_text, $tags)) {
				$tagline = "";
				foreach ($tags[2] as $tag) {
					$tag = html_entity_decode($tag, ENT_QUOTES, 'UTF-8');
					if (!strpos(html_entity_decode($text, ENT_QUOTES, 'UTF-8'), '#' . $tag)) {
						$tagline .= '#' . $tag . ' ';
					}
				}
				$text = $text . " " . $tagline;
			}
		} else {
			$text = self::convert($text, false, 4);
		}

		// mask some special HTML chars from conversation to markdown
		$text = str_replace(['&lt;', '&gt;', '&amp;'], ['&_lt_;', '&_gt_;', '&_amp_;'], $text);

		// If a link is followed by a quote then there should be a newline before it
		// Maybe we should make this newline at every time before a quote.
		$text = str_replace(["</a><blockquote>"], ["</a><br><blockquote>"], $text);

		$stamp1 = microtime(true);

		// Now convert HTML to Markdown
		$converter = new HtmlConverter();
		$text = $converter->convert($text);

		// unmask the special chars back to HTML
		$text = str_replace(['&\_lt\_;', '&\_gt\_;', '&\_amp\_;'], ['&lt;', '&gt;', '&amp;'], $text);

		$a->save_timestamp($stamp1, "parser");

		// Libertree has a problem with escaped hashtags.
		$text = str_replace(['\#'], ['#'], $text);

		// Remove any leading or trailing whitespace, as this will mess up
		// the Diaspora signature verification and cause the item to disappear
		$text = trim($text);

		if ($for_diaspora) {
			$url_search_string = "^\[\]";
			$text = preg_replace_callback(
				"/([@]\[(.*?)\])\(([$url_search_string]*?)\)/ism",
				['self', 'bbCodeMention2DiasporaCallback'],
				$text
			);
		}

		// Restore code blocks
		$text = preg_replace_callback('/#codeblock-([0-9]+)#/iU',
			function ($matches) use ($codeblocks) {
				$return = '';
				if (isset($codeblocks[intval($matches[1])])) {
					$return = $codeblocks[$matches[1]];
				}
				return $return;
			},
			$text
		);

		Addon::callHooks('bb2diaspora', $text);

		return $text;
	}
}
