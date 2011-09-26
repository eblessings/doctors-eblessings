<?php

require_once('library/HTML5/Parser.php');
require_once('library/HTMLPurifier.auto.php');

function arr_add_hashes(&$item,$k) {
	$item = '#' . $item;
}

function parse_url_content(&$a) {

	$text = null;
	$str_tags = '';

	if(x($_GET,'binurl'))
		$url = trim(hex2bin($_GET['binurl']));
	else
		$url = trim($_GET['url']);

	if($_GET['title'])
		$title = strip_tags(trim($_GET['title']));

	if($_GET['description'])
		$text = strip_tags(trim($_GET['description']));

	if($_GET['tags']) {
		$arr_tags = str_getcsv($_GET['tags']);
		if(count($arr_tags)) {
			array_walk($arr_tags,'arr_add_hashes');
			$str_tags = '<br />' . implode(' ',$arr_tags) . '<br />'; 		
		}
	}

	logger('parse_url: ' . $url);


	$template = "<br /><a class=\"bookmark\" href=\"%s\" >%s</a>%s<br />";


	$arr = array('url' => $url, 'text' => '');

	call_hooks('parse_link', $arr);

	if(strlen($arr['text'])) {
		echo $arr['text'];
		killme();
	}

	if($url && $title && $text) {

		$text = '<br /><br /><blockquote>' . $text . '</blockquote><br />';
		$title = str_replace(array("\r","\n"),array('',''),$title);

		$result = sprintf($template,$url,($title) ? $title : $url,$text) . $str_tags;

		logger('parse_url (unparsed): returns: ' . $result); 

		echo $result;
		killme();
	}


	if($url) {
		$s = fetch_url($url);
	} else {
		echo '';
		killme();
	}

	logger('parse_url: data: ' . $s, LOGGER_DATA);

	if(! $s) {
		echo sprintf($template,$url,$url,'') . $str_tags;
		killme();
	}

	if(! $title) {
		if(strpos($s,'<title>')) {
			$title = substr($s,strpos($s,'<title>')+7,64);
			if(strpos($title,'<') !== false)
				$title = strip_tags(substr($title,0,strpos($title,'<')));
		}
	}

	$config = HTMLPurifier_Config::createDefault();
	$config->set('Cache.DefinitionImpl', null);

	$purifier = new HTMLPurifier($config);
	$s = $purifier->purify($s);

//	logger('parse_url: purified: ' . $s, LOGGER_DATA);

	$dom = @HTML5_Parser::parse($s);

	if(! $dom) {
		echo sprintf($template,$url,$url,'') . $str_tags;
		killme();
	}

	$items = $dom->getElementsByTagName('title');

	if($items) {
		foreach($items as $item) {
			$title = trim($item->textContent);
			break;
		}
	}


	if(! $text) {
		$divs = $dom->getElementsByTagName('div');
		if($divs) {
			foreach($divs as $div) {
				$class = $div->getAttribute('class');
				if($class && (stristr($class,'article') || stristr($class,'content'))) {
					$items = $div->getElementsByTagName('p');
					if($items) {
						foreach($items as $item) {
							$text = $item->textContent;
							if(stristr($text,'<script')) {
								$text = '';
								continue;
							}
							$text = strip_tags($text);
							if(strlen($text) < 100) {
								$text = '';
								continue;
							}
							$text = substr($text,0,250) . '...' ;
							break;
						}
					}
				}
				if($text)
					break;
			}
		}

		if(! $text) {
			$items = $dom->getElementsByTagName('p');
			if($items) {
				foreach($items as $item) {
					$text = $item->textContent;
					if(stristr($text,'<script'))
						continue;
					$text = strip_tags($text);
					if(strlen($text) < 100) {
						$text = '';
						continue;
					}
					$text = substr($text,0,250) . '...' ;
					break;
				}
			}
		}
	}

	if(strlen($text)) {
		$text = '<br /><br /><blockquote>' . $text . '</blockquote><br />';
	}

	$title = str_replace(array("\r","\n"),array('',''),$title);

	$result = sprintf($template,$url,($title) ? $title : $url,$text) . $str_tags;

	logger('parse_url: returns: ' . $result); 

	echo $result;
	killme();
}
