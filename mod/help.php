<?php

use Friendica\App;
use Friendica\Core\System;

require_once('library/markdown.php');

if (!function_exists('load_doc_file')) {

	function load_doc_file($s) {
		global $lang;
		if (!isset($lang))
			$lang = 'en';
		$b = basename($s);
		$d = dirname($s);
		if (file_exists("$d/$lang/$b"))
			return file_get_contents("$d/$lang/$b");
		if (file_exists($s))
			return file_get_contents($s);
		return '';
	}

}

function help_content(App $a) {

	nav_set_selected('help');

	global $lang;

	$text = '';

	if ($a->argc > 1) {
		$path = '';
		// looping through the argv keys bigger than 0 to build
		// a path relative to /help
		for($x = 1; $x < argc(); $x ++) {
			if(strlen($path))
				$path .= '/';
			$path .= argv($x);
		}
		$title = basename($path);
		$filename = $path;
		$text = load_doc_file('doc/' . $path . '.md');
		$a->page['title'] = t('Help:') . ' ' . str_replace('-', ' ', notags($title));
	}
	$home = load_doc_file('doc/Home.md');
	if (!$text) {
		$text = $home;
		$filename = "Home";
		$a->page['title'] = t('Help');
	} else {
		$a->page['aside'] = Markdown($home, false);
	}

	if (!strlen($text)) {
		header($_SERVER["SERVER_PROTOCOL"] . ' 404 ' . t('Not Found'));
		$tpl = get_markup_template("404.tpl");
		return replace_macros($tpl, array(
					'$message' => t('Page not found.')
				));
	}

	$html = Markdown($text, false);

	if ($filename !== "Home") {
		// create TOC but not for home
		$lines = explode("\n", $html);
		$toc="<style>aside ul {padding-left: 1em;}aside h1{font-size:2em}</style><h2>TOC</h2><ul id='toc'>";
		$lastlevel=1;
		$idnum = array(0,0,0,0,0,0,0);
		foreach($lines as &$line){
			if (substr($line,0,2)=="<h") {
				$level = substr($line,2,1);
				if ($level!="r") {
					$level = intval($level);
					if ($level<$lastlevel) {
						for($k=$level;$k<$lastlevel; $k++) $toc.="</ul>";
						for($k=$level+1;$k<count($idnum);$k++) $idnum[$k]=0;
					}
					if ($level>$lastlevel) $toc.="<ul>";
					$idnum[$level]++;
					$id = implode("_", array_slice($idnum,1,$level));
					$href = System::baseUrl()."/help/{$filename}#{$id}";
					$toc .= "<li><a href='{$href}'>".strip_tags($line)."</a></li>";
					$line = "<a name='{$id}'></a>".$line;
					$lastlevel = $level;
				}
			}
		}
		for($k=0;$k<$lastlevel; $k++) $toc.="</ul>";
		$html = implode("\n",$lines);

		$a->page['aside'] = '<section class="help-aside-wrapper">' . $toc . $a->page['aside'] . '</section>';
	}

	$html = "
		<style>
		.md_warning {
			padding: 1em; border: #ff0000 solid 2px;
			background-color: #f9a3a3; color: #ffffff;
		}
		</style>".$html;
	return $html;

}
