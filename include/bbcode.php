<?php

require_once("include/oembed.php");
require_once('include/event.php');



function stripcode_br_cb($s) {
	return '[code]' . str_replace('<br />', '', $s[1]) . '[/code]';
}

function tryoembed($match){
	$url = ((count($match)==2)?$match[1]:$match[2]);
	
	$o = oembed_fetch_url($url);

	//echo "<pre>"; var_dump($match, $url, $o); killme();

	if ($o->type=="error") return $match[0];
	
	$html = oembed_format_object($o);
	
	return $html;
	
}



	// BBcode 2 HTML was written by WAY2WEB.net
	// extended to work with Mistpark/Friendika - Mike Macgirvin

function bbcode($Text,$preserve_nl = false) {

	// If we find any event code, turn it into an event.
	// After we're finished processing the bbcode we'll 
	// replace all of the event code with a reformatted version.

	$ev = bbtoevent($Text);


	// Replace any html brackets with HTML Entities to prevent executing HTML or script
	// Don't use strip_tags here because it breaks [url] search by replacing & with amp

	$Text = str_replace("<", "&lt;", $Text);
	$Text = str_replace(">", "&gt;", $Text);

	// Convert new line chars to html <br /> tags

	$Text = nl2br($Text);
	if($preserve_nl)
		$Text = str_replace(array("\n","\r"), array('',''),$Text);


	// Set up the parameters for a URL search string
	$URLSearchString = "^\[\]";
	// Set up the parameters for a MAIL search string
	$MAILSearchString = $URLSearchString;


	// Perform URL Search
	$Text = preg_replace("/([^\]\=]|^)(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)/ism", '$1<a href="$2" target="external-link">$2</a>', $Text);
	
	$Text = preg_replace_callback("/\[bookmark\=([^\]]*)\].*?\[\/bookmark\]/ism",'tryoembed',$Text);
	$Text = preg_replace("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/ism",'[url=$1]$2[/url]',$Text);

	$Text = preg_replace_callback("/\[url\]([$URLSearchString]*)\[\/url\]/ism",'tryoembed',$Text);
	$Text = preg_replace("/\[url\]([$URLSearchString]*)\[\/url\]/ism", '<a href="$1" target="external-link">$1</a>', $Text);
	$Text = preg_replace("/\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", '<a href="$1" target="external-link">$2</a>', $Text);
	//$Text = preg_replace("/\[url\=([$URLSearchString]*)\]([$URLSearchString]*)\[\/url\]/ism", '<a href="$1" target="_blank">$2</a>', $Text);


	// Perform MAIL Search
	$Text = preg_replace("/\[mail\]([$MAILSearchString]*)\[\/mail\]/", '<a href="mailto:$1">$1</a>', $Text);
	$Text = preg_replace("/\[mail\=([$MAILSearchString]*)\](.*?)\[\/mail\]/", '<a href="mailto:$1">$2</a>', $Text);
         
	// Check for bold text
	$Text = preg_replace("(\[b\](.*?)\[\/b\])ism",'<strong>$1</strong>',$Text);

	// Check for Italics text
	$Text = preg_replace("(\[i\](.*?)\[\/i\])ism",'<em>$1</em>',$Text);

	// Check for Underline text
	$Text = preg_replace("(\[u\](.*?)\[\/u\])ism",'<u>$1</u>',$Text);

	// Check for strike-through text
	$Text = preg_replace("(\[s\](.*?)\[\/s\])ism",'<strike>$1</strike>',$Text);

	// Check for over-line text
	$Text = preg_replace("(\[o\](.*?)\[\/o\])ism",'<span class="overline">$1</span>',$Text);

	// Check for colored text
	$Text = preg_replace("(\[color=(.*?)\](.*?)\[\/color\])ism","<span style=\"color: $1;\">$2</span>",$Text);

	// Check for sized text
	$Text = preg_replace("(\[size=(.*?)\](.*?)\[\/size\])ism","<span style=\"font-size: $1;\">$2</span>",$Text);

	// Check for list text
	$Text = preg_replace("/\[list\](.*?)\[\/list\]/ism", '<ul class="listbullet">$1</ul>' ,$Text);
	$Text = preg_replace("/\[list=1\](.*?)\[\/list\]/ism", '<ul class="listdecimal">$1</ul>' ,$Text);
	$Text = preg_replace("/\[list=i\](.*?)\[\/list\]/sm",'<ul class="listlowerroman">$1</ul>' ,$Text);
	$Text = preg_replace("/\[list=I\](.*?)\[\/list\]/sm", '<ul class="listupperroman">$1</ul>' ,$Text);
	$Text = preg_replace("/\[list=a\](.*?)\[\/list\]/sm", '<ul class="listloweralpha">$1</ul>' ,$Text);
	$Text = preg_replace("/\[list=A\](.*?)\[\/list\]/sm", '<ul class="listupperalpha">$1</ul>' ,$Text);
	$Text = preg_replace("/\[li\](.*?)\[\/li\]/sm", '<li>$1</li>' ,$Text);

	$Text = preg_replace("/\[td\](.*?)\[\/td\]/sm", '<td>$1</td>' ,$Text);
	$Text = preg_replace("/\[tr\](.*?)\[\/tr\]/sm", '<tr>$1</tr>' ,$Text);
	$Text = preg_replace("/\[table\](.*?)\[\/table\]/sm", '<table>$1</table>' ,$Text);

	$Text = preg_replace("/\[table border=1\](.*?)\[\/table\]/sm", '<table border="1" >$1</table>' ,$Text);
	$Text = preg_replace("/\[table border=0\](.*?)\[\/table\]/sm", '<table border="0" >$1</table>' ,$Text);

	
//	$Text = str_replace("[*]", "<li>", $Text);

	// Check for font change text
	$Text = preg_replace("/\[font=(.*?)\](.*?)\[\/font\]/sm","<span style=\"font-family: $1;\">$2</span>",$Text);

	// Declare the format for [code] layout

	$Text = preg_replace_callback("/\[code\](.*?)\[\/code\]/ism",'stripcode_br_cb',$Text);

	$CodeLayout = '<code>$1</code>';
	// Check for [code] text
	$Text = preg_replace("/\[code\](.*?)\[\/code\]/ism","$CodeLayout", $Text);




	// Declare the format for [quote] layout
	$QuoteLayout = '<blockquote>$1</blockquote>';                     
	// Check for [quote] text
	$Text = preg_replace("/\[quote\](.*?)\[\/quote\]/ism","$QuoteLayout", $Text);
         
	// [img=widthxheight]image source[/img]
	$Text = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '<img src="$3" style="height: $2px; width: $1px;" >', $Text);

	// Images
	// [img]pathtoimage[/img]
	$Text = preg_replace("/\[img\](.*?)\[\/img\]/ism", '<img src="$1" alt="' . t('Image/photo') . '" />', $Text);



	// Try to Oembed
	$Text = preg_replace_callback("/\[video\](.*?)\[\/video\]/ism", 'tryoembed', $Text);
	$Text = preg_replace_callback("/\[audio\](.*?)\[\/audio\]/ism", 'tryoembed', $Text);


	// html5 video and audio

	$Text = preg_replace("/\[video\](.*?)\[\/video\]/ism", '<video src="$1" controls="controls" width="425" height="350"><a href="$1">$1</a></video>', $Text);

	$Text = preg_replace("/\[audio\](.*?)\[\/audio\]/ism", '<audio src="$1" controls="controls"><a href="$1">$1</a></audio>', $Text);

	$Text = preg_replace("/\[iframe\](.*?)\[\/iframe\]/ism", '<iframe src="$1" width="425" height="350"><a href="$1">$1</a></iframe>', $Text);
         

	/*if (get_pconfig(local_user(), 'oembed', 'use_for_youtube' )==1){
		// use oembed for youtube links
		$Text = preg_replace("/\[youtube\]/",'[embed]',$Text); 
		$Text = preg_replace("/\[\/youtube\]/",'[/embed]',$Text); 
	} else {*/
		// Youtube extensions
        $Text = preg_replace_callback("/\[youtube\](https?:\/\/www.youtube.com\/watch\?v\=.*?)\[\/youtube\]/ism", 'tryoembed', $Text);        
        $Text = preg_replace_callback("/\[youtube\](https?:\/\/youtu.be\/.*?)\[\/youtube\]/ism",'tryoembed',$Text); 
        
        $Text = preg_replace("/\[youtube\]https?:\/\/www.youtube.com\/watch\?v\=(.*?)\[\/youtube\]/ism",'[youtube]$1[/youtube]',$Text); 
        $Text = preg_replace("/\[youtube\]https?:\/\/www.youtube.com\/embed\/(.*?)\[\/youtube\]/ism",'[youtube]$1[/youtube]',$Text); 
        $Text = preg_replace("/\[youtube\]https?:\/\/youtu.be\/(.*?)\[\/youtube\]/ism",'[youtube]$1[/youtube]',$Text); 
		
        
		$Text = preg_replace("/\[youtube\]([A-Za-z0-9\-_=]+)(.*?)\[\/youtube\]/ism", '<iframe width="425" height="350" src="http://www.youtube.com/embed/$1" frameborder="0" ></iframe>', $Text);
	//}

	$Text = preg_replace_callback("/\[vimeo\]https?:\/\/player.vimeo.com\/video\/([0-9]+)(.*?)\[\/vimeo\]/ism",'tryoembed',$Text); 
	$Text = preg_replace_callback("/\[vimeo\]https?:\/\/vimeo.com\/([0-9]+)(.*?)\[\/vimeo\]/ism",'tryoembed',$Text); 

	$Text = preg_replace("/\[vimeo\]https?:\/\/player.vimeo.com\/video\/([0-9]+)(.*?)\[\/vimeo\]/ism",'[vimeo]$1[/vimeo]',$Text); 
	$Text = preg_replace("/\[vimeo\]https?:\/\/vimeo.com\/([0-9]+)(.*?)\[\/vimeo\]/ism",'[vimeo]$1[/vimeo]',$Text); 
	$Text = preg_replace("/\[vimeo\]([0-9]+)(.*?)\[\/vimeo\]/ism", '<iframe width="425" height="350" src="http://player.vimeo.com/video/$1" frameborder="0" ></iframe>', $Text);

//	$Text = preg_replace("/\[youtube\](.*?)\[\/youtube\]/", '<object width="425" height="350" type="application/x-shockwave-flash" data="http://www.youtube.com/v/$1" ><param name="movie" value="http://www.youtube.com/v/$1"></param><!--[if IE]><embed src="http://www.youtube.com/v/$1" type="application/x-shockwave-flash" width="425" height="350" /><![endif]--></object>', $Text);


	// oembed tag
	$Text = oembed_bbcode2html($Text);

	// If we found an event earlier, strip out all the event code and replace with a reformatted version.

	if(x($ev,'desc') && x($ev,'start')) {
		$sub = format_event_html($ev);

		$Text = preg_replace("/\[event\-description\](.*?)\[\/event\-description\]/ism",$sub,$Text);
		$Text = preg_replace("/\[event\-start\](.*?)\[\/event\-start\]/ism",'',$Text);
		$Text = preg_replace("/\[event\-finish\](.*?)\[\/event\-finish\]/ism",'',$Text);
		$Text = preg_replace("/\[event\-location\](.*?)\[\/event\-location\]/ism",'',$Text);
		$Text = preg_replace("/\[event\-adjust\](.*?)\[\/event\-adjust\]/ism",'',$Text);
	}

	// fix any escaped ampersands that may have been converted into links
	$Text = preg_replace("/\<(.*?)(src|href)=(.*?)\&amp\;(.*?)\>/ism",'<$1$2=$3&$4>',$Text);
	
	call_hooks('bbcode',$Text);

	return $Text;
}
