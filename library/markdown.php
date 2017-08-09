<?php

/**
 * @file library/markdown.php
 *
 * @brief Parser for Markdown files
 */

require_once "library/php-markdown/Michelf/MarkdownExtra.inc.php";
use \Michelf\MarkdownExtra;

/**
 * @brief This function parses a text using php-markdown library to render Markdown syntax to HTML
 *
 * This function is using the php-markdown library by Michel Fortin to parse a 
 * string ($text).It returns the rendered HTML code from that text. The optional 
 * $hardbreak parameter is used to switch between inserting hard breaks after
 * every linefeed, which is required for Diaspora compatibility, or not. The
 * later is used for parsing documentation and README.md files.
 *
 * @param string $text
 * @param boolean $hardbreak
 * @returns string
 */

function Markdown($text, $hardbreak=true) {
	$a = get_app();

	$stamp1 = microtime(true);

	$MarkdownParser = new MarkdownExtra();
	if ($hardbreak) {
		$MarkdownParser->hard_wrap = true;
	} else {
		$MarkdownParser->hard_wrap = false;
	}
	$html = $MarkdownParser->transform($text);

	$a->save_timestamp($stamp1, "parser");

	return $html;
}
