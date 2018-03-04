<?php
/**
 * @file mod/babel.php
 */

use Friendica\Content\Text\BBCode;
use Friendica\Content\Text\Markdown;
use Friendica\Core\L10n;

require_once 'include/html2bbcode.php';

function visible_lf($s)
{
	return str_replace("\n", '<br />', $s);
}

function babel_content()
{
	$o = '<h1>Babel Diagnostic</h1>';

	$o .= '<form action="babel" method="post">';
	$o .= L10n::t("Source \x28bbcode\x29 text:") . EOL;
	$o .= '<textarea name="text" cols="80" rows="10">' . htmlspecialchars($_REQUEST['text']) . '</textarea>' . EOL;
	$o .= '<input type="submit" name="submit" value="Submit" /></form>';

	$o .= '<br /><br />';

	$o .= '<form action="babel" method="post">';
	$o .= L10n::t("Source \x28Diaspora\x29 text to convert to BBcode:") . EOL;
	$o .= '<textarea name="d2bbtext" cols="80" rows="10">' . htmlspecialchars($_REQUEST['d2bbtext']) . '</textarea>' . EOL;
	$o .= '<input type="submit" name="submit" value="Submit" /></form>';

	$o .= '<br /><br />';

	if (x($_REQUEST, 'text')) {
		$text = trim($_REQUEST['text']);
		$o .= '<h2>' . L10n::t('Source input: ') . '</h2>' . EOL . EOL;
		$o .= visible_lf($text) . EOL . EOL;

		$html = BBCode::convert($text);
		$o .= '<h2>' . L10n::t("bbcode \x28raw HTML\x28: ") . '</h2>' . EOL . EOL;
		$o .= htmlspecialchars($html) . EOL . EOL;

		$o .= '<h2>' . L10n::t('bbcode: ') . '</h2>' . EOL . EOL;
		$o .= $html . EOL . EOL;

		$bbcode = html2bbcode($html);
		$o .= '<h2>' . L10n::t('bbcode => html2bbcode: ') . '</h2>' . EOL . EOL;
		$o .= visible_lf($bbcode) . EOL . EOL;

		$diaspora = BBCode::toMarkdown($text);
		$o .= '<h2>' . L10n::t('BBCode::toMarkdown: ') . '</h2>' . EOL . EOL;
		$o .= visible_lf($diaspora) . EOL . EOL;

		$html = Markdown::convert($diaspora);
		$o .= '<h2>' . L10n::t('BBCode::toMarkdown =>  Markdown::convert: ') . '</h2>' . EOL . EOL;
		$o .= $html . EOL . EOL;

		$bbcode = Markdown::toBBCode($diaspora);
		$o .= '<h2>' . L10n::t('BBCode::toMarkdown => Markdown::toBBCode: ') . '</h2>' . EOL . EOL;
		$o .= visible_lf($bbcode) . EOL . EOL;

		$bbcode = html2bbcode($html);
		$o .= '<h2>' . L10n::t('bbcode => html2bbcode: ') . '</h2>' . EOL . EOL;
		$o .= visible_lf($bbcode) . EOL . EOL;
	}

	if (x($_REQUEST, 'd2bbtext')) {
		$d2bbtext = trim($_REQUEST['d2bbtext']);
		$o .= '<h2>' . L10n::t("Source input \x28Diaspora format\x29: ") . '</h2>' . EOL . EOL;
		$o .= '<pre>' . $d2bbtext . '</pre>' . EOL . EOL;

		$bb = Markdown::toBBCode($d2bbtext);
		$o .= '<h2>' . L10n::t('diaspora2bb: ') . '</h2>' . EOL . EOL;
		$o .= '<pre>' . $bb . '</pre>' . EOL . EOL;
	}

	return $o;
}
