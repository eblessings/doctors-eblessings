<?php
function oembed_replacecb($matches){
  $embedurl=$matches[1];
  $ourl = "http://oohembed.com/oohembed/?url=".urlencode($embedurl);  
  $txt = fetch_url($ourl);
  $j = json_decode($txt);
  $ret="<span class='oembed'>";
  switch ($j->type) {
    case "video": {
       if (isset($j->thumbnail_url)) {
         $tw = (isset($j->thumbnail_width)) ? $j->thumbnail_width:200;
         $th = (isset($j->thumbnail_height)) ? $j->thumbnail_height:180;
         $ret = "<a href='".$embedurl."' onclick='this.innerHTML=unescape(\"".urlencode($j->html)."\").replace(/\+/g,\" \"); return false;' >";
         $ret.= "<img width='$tw' height='$th' src='".$j->thumbnail_url."'>";
         $ret.= "</a>";
       } else {
         $ret=$j->html;
       }
       $ret.="<br>";
    }; break;
    case "photo": {
      $ret = "<img width='".$j->width."' height='".$j->height."' src='".$j->url."'>";
      $ret.="<br>";
    }; break;  
    case "link": {
      //$ret = "<a href='".$embedurl."'>".$j->title."</a>";
    }; break;  
    case "rich": {
      // not so safe.. 
      $ret = "<blockquote>".$j->html."</blockquote>";
    }; break;
  }
  
  $embedlink = (isset($j->title))?$j->title:$embedurl;
  $ret .= "<a href='$embedurl' rel='oembed'>$embedlink</a>";
  if (isset($j->author_name)) $ret.=" by ".$j->author_name;
  if (isset($j->provider_name)) $ret.=" on ".$j->provider_name;
  $ret.="</span>";
  return $ret;
}

function oembed_bbcode2html($text){
	$stopoembed = get_config("system","no_oembed");
	if ($stopoembed == true){
		return preg_replace("/\[embed\](.+?)\[\/embed\]/is", "<!-- oembed $1 --><i>". t('Embedding disabled') ." : $1</i><!-- /oembed $1 -->" ,$text);
	}
	return preg_replace_callback("/\[embed\](.+?)\[\/embed\]/is", oembed_replacecb ,$text);
}


function oe_build_xpath($attr, $value){
	// http://westhoffswelt.de/blog/0036_xpath_to_select_html_by_class.html
	return "contains( normalize-space( @$attr ), ' $value ' ) or substring( normalize-space( @$attr ), 1, string-length( '$value' ) + 1 ) = '$value ' or substring( normalize-space( @$attr ), string-length( @$attr ) - string-length( '$value' ) ) = ' $value' or @$attr = '$value'";
}

function oe_get_inner_html( $node ) {
    $innerHTML= '';
    $children = $node->childNodes;
    foreach ($children as $child) {
        $innerHTML .= $child->ownerDocument->saveXML( $child );
    }
    return $innerHTML;
} 

/**
 * Find <span class='oembed'>..<a href='url' rel='oembed'>..</a></span>
 * and replace it with [embed]url[/embed]
 */
function oembed_html2bbcode($text) {
	$dom = DOMDocument::loadHTML($text);
	$xpath = new DOMXPath($dom);
	$attr = "oembed";
	
	$xattr = oe_build_xpath("class","oembed");
	$entries = $xpath->query("//span[$xattr]");
	
	$xattr = oe_build_xpath("rel","oembed");
	foreach($entries as $e) {
		$href = $xpath->evaluate("a[$xattr]/@href", $e)->item(0)->nodeValue;
		if(!is_null($href)) $e->parentNode->replaceChild(new DOMText("[embed]".$href."[embed]"), $e);
	}
	return oe_get_inner_html( $dom->getElementsByTagName("body")->item(0) ); 
}

?>