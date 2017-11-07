<?php

	use Friendica\Core\Config;
	use Friendica\Core\PConfig;

	$uid = get_theme_uid();

	$color=false;
	$quattro_align=false;
	$site_color = Config::get("quattro","color");
	$site_quattro_align = Config::get("quattro", "align" );
	
	if ($uid) {
		$color = PConfig::get( $uid, "quattro","color");
		$quattro_align = PConfig::get( $uid, 'quattro', 'align' );
	}
	
	if ($color===false) $color=$site_color;
	if ($color===false) $color="dark";
	if ($quattro_align===false) $quattro_align=$site_quattro_align;
	
		
	if (file_exists("$THEMEPATH/$color/style.css")){
		echo file_get_contents("$THEMEPATH/$color/style.css");
	}


	if($quattro_align=="center"){
		echo "
			html { width: 100%; margin:0px; padding:0px; }
			body {
				margin: 50px auto;
				width: 900px;
			}
		";
	}

    

    $textarea_font_size = false;
    $post_font_size = false;
    
    $site_textarea_font_size = Config::get("quattro","tfs");
    $site_post_font_size = Config::get("quattro","pfs");
    if ($site_textarea_font_size===false) $site_textarea_font_size="20";
    if ($site_post_font_size===false) $site_post_font_size="12";
    
   	if ($uid) {
        $textarea_font_size = PConfig::get( $uid, "quattro","tfs");
        $post_font_size = PConfig::get( $uid, "quattro","pfs");    
	} 
    
    if ($textarea_font_size===false) $textarea_font_size = $site_textarea_font_size;
    if ($post_font_size===false) $post_font_size = $site_post_font_size;

    echo "
        textarea { font-size: ${textarea_font_size}px; }
        .wall-item-comment-wrapper .comment-edit-text-full { font-size: ${textarea_font_size}px; }
        #jot .profile-jot-text:focus { font-size: ${textarea_font_size}px; }
        .wall-item-container .wall-item-content  { font-size: ${post_font_size}px; }
    ";