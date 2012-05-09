<?php
/**
 * Theme settings
 */



function theme_content(&$a){
	if(!local_user())
		return;		
	
	$font_size = get_pconfig(local_user(), 'diabook', 'font_size' );
	$line_height = get_pconfig(local_user(), 'diabook', 'line_height' );
	$resolution = get_pconfig(local_user(), 'diabook', 'resolution' );
	$color = get_pconfig(local_user(), 'diabook', 'color' );
	$TSearchTerm = get_pconfig(local_user(), 'diabook', 'TSearchTerm' );
	$ELZoom = get_pconfig(local_user(), 'diabook', 'ELZoom' );
	$ELPosX = get_pconfig(local_user(), 'diabook', 'ELPosX' );
	$ELPosY = get_pconfig(local_user(), 'diabook', 'ELPosY' );
	$close_pages = get_pconfig(local_user(), 'diabook', 'close_pages' );
	$close_mapquery = get_pconfig(local_user(), 'diabook', 'close_mapquery' );
	$close_profiles = get_pconfig(local_user(), 'diabook', 'close_profiles' );
	$close_helpers = get_pconfig(local_user(), 'diabook', 'close_helpers' );
	$close_services = get_pconfig(local_user(), 'diabook', 'close_services' );
	$close_friends = get_pconfig(local_user(), 'diabook', 'close_friends' );
	$close_twitter = get_pconfig(local_user(), 'diabook', 'close_twitter' );
	$close_lastusers = get_pconfig(local_user(), 'diabook', 'close_lastusers' );
	$close_lastphotos = get_pconfig(local_user(), 'diabook', 'close_lastphotos' );
	$close_lastlikes = get_pconfig(local_user(), 'diabook', 'close_lastlikes' );
	
	
	return diabook_form($a,$font_size, $line_height, $resolution, $color, $TSearchTerm, $ELZoom, $ELPosX, $ELPosY, $close_pages, $close_mapquery, $close_profiles, $close_helpers, $close_services, $close_friends, $close_twitter, $close_lastusers, $close_lastphotos, $close_lastlikes);
}

function theme_post(&$a){
	if(! local_user())
		return;
	
	if (isset($_POST['diabook-settings-submit'])){
		set_pconfig(local_user(), 'diabook', 'font_size', $_POST['diabook_font_size']);
		set_pconfig(local_user(), 'diabook', 'line_height', $_POST['diabook_line_height']);
		set_pconfig(local_user(), 'diabook', 'resolution', $_POST['diabook_resolution']);
		set_pconfig(local_user(), 'diabook', 'color', $_POST['diabook_color']);	
		set_pconfig(local_user(), 'diabook', 'TSearchTerm', $_POST['diabook_TSearchTerm']);	
		set_pconfig(local_user(), 'diabook', 'ELZoom', $_POST['diabook_ELZoom']);	
		set_pconfig(local_user(), 'diabook', 'ELPosX', $_POST['diabook_ELPosX']);	
		set_pconfig(local_user(), 'diabook', 'ELPosY', $_POST['diabook_ELPosY']);	
		set_pconfig(local_user(), 'diabook', 'ELPosY', $_POST['diabook_ELPosY']);
		set_pconfig(local_user(), 'diabook', 'close_pages', $_POST['diabook_close_pages']);
		set_pconfig(local_user(), 'diabook', 'close_mapquery', $_POST['diabook_close_mapquery']);
		set_pconfig(local_user(), 'diabook', 'close_profiles', $_POST['diabook_close_profiles']);
		set_pconfig(local_user(), 'diabook', 'close_helpers', $_POST['diabook_close_helpers']);
		set_pconfig(local_user(), 'diabook', 'close_services', $_POST['diabook_close_services']);
		set_pconfig(local_user(), 'diabook', 'close_friends', $_POST['diabook_close_friends']);
		set_pconfig(local_user(), 'diabook', 'close_twitter', $_POST['diabook_close_twitter']);
		set_pconfig(local_user(), 'diabook', 'close_lastusers', $_POST['diabook_close_lastusers']);
		set_pconfig(local_user(), 'diabook', 'close_lastphotos', $_POST['diabook_close_lastphotos']);
		set_pconfig(local_user(), 'diabook', 'close_lastlikes', $_POST['diabook_close_lastlikes']);
			
	}
}


function theme_admin(&$a){
	$font_size = get_config('diabook', 'font_size' );
	$line_height = get_config('diabook', 'line_height' );
	$resolution = get_config('diabook', 'resolution' );
	$color = get_config('diabook', 'color' );	
	$TSearchTerm = get_config('diabook', 'TSearchTerm' );	
	$ELZoom = get_config('diabook', 'ELZoom' );
	$ELPosX = get_config('diabook', 'ELPosX' );
	$ELPosY = get_config('diabook', 'ELPosY' );
	$close_pages = get_config('diabook', 'close_pages' );
	$close_mapquery = get_config('diabook', 'close_mapquery' );
	$close_profiles = get_config('diabook', 'close_profiles' );
	$close_helpers = get_config('diabook', 'close_helpers' );
	$close_services = get_config('diabook', 'close_services' );
	$close_friends = get_config('diabook', 'close_friends' );
	$close_twitter = get_config('diabook', 'close_twitter' );
	$close_lastusers = get_config('diabook', 'close_lastusers' );
	$close_lastphotos = get_config('diabook', 'close_lastphotos' );
	$close_lastlikes = get_config('diabook', 'close_lastlikes' );
	
	return diabook_form($a,$font_size, $line_height, $resolution, $color, $TSearchTerm, $ELZoom, $ELPosX, $ELPosY, $close_pages, $close_mapquery, $close_profiles, $close_helpers, $close_services, $close_friends, $close_twitter, $close_lastusers, $close_lastphotos, $close_lastlikes);
}

function theme_admin_post(&$a){
	if (isset($_POST['diabook-settings-submit'])){
		set_config('diabook', 'font_size', $_POST['diabook_font_size']);
		set_config('diabook', 'line_height', $_POST['diabook_line_height']);
		set_config('diabook', 'resolution', $_POST['diabook_resolution']);
		set_config('diabook', 'color', $_POST['diabook_color']);
		set_config('diabook', 'TSearchTerm', $_POST['diabook_TSearchTerm']);
		set_config('diabook', 'ELZoom', $_POST['diabook_ELZoom']);
		set_config('diabook', 'ELPosX', $_POST['diabook_ELPosX']);
		set_config('diabook', 'close_pages', $_POST['diabook_close_pages']);
		set_config('diabook', 'close_mapquery', $_POST['diabook_close_mapquery']);
		set_config('diabook', 'close_profiles', $_POST['diabook_close_profiles']);
		set_config('diabook', 'close_helpers', $_POST['diabook_close_helpers']);
		set_config('diabook', 'close_services', $_POST['diabook_close_services']);
		set_config('diabook', 'close_friends', $_POST['diabook_close_friends']);
		set_config('diabook', 'close_twitter', $_POST['diabook_close_twitter']);
		set_config('diabook', 'close_lastusers', $_POST['diabook_close_lastusers']);
		set_config('diabook', 'close_lastphotos', $_POST['diabook_close_lastphotos']);
		set_config('diabook', 'close_lastlikes', $_POST['diabook_close_lastlikes']);
		
	}
}


function diabook_form(&$a, $font_size, $line_height, $resolution, $color, $TSearchTerm, $ELZoom, $ELPosX, $ELPosY, $close_pages, $close_mapquery, $close_profiles, $close_helpers, $close_services, $close_friends, $close_twitter, $close_lastusers, $close_lastphotos, $close_lastlikes){
	$line_heights = array(
		"1.3"=>"1.3",
		"---"=>"---",
		"1.6"=>"1.6",				
		"1.5"=>"1.5",		
		"1.4"=>"1.4",
		"1.2"=>"1.2",
		"1.1"=>"1.1",
	);
	
	$font_sizes = array(
		'14'=>'14',
		"---"=>"---",
		"16"=>"16",		
		"15"=>"15",
		'13.5'=>'13.5',
		'13'=>'13',		
		'12.5'=>'12.5',
		'12'=>'12',
		);
	$resolutions = array(
		'normal'=>'normal',
		'wide'=>'wide',		
		);
	$colors = array(
		'diabook'=>'diabook',
		'aerith'=>'aerith',		
		'blue'=>'blue',		
		'green'=>'green',
		'pink'=>'pink',	
		'red'=>'red',
		'dark'=>'dark',						
		);
	$close_pagesC = array(
		'1'=>'hide',	
		'0'=>'show',							
		);
	$close_mapqueryC = array(
		'1'=>'hide',	
		'0'=>'show',									
		);
	$close_profilesC = array(
		'0'=>'show',	
		'1'=>'hide',								
		);
	$close_helpersC = array(
	   '0'=>'show',	
		'1'=>'hide',									
		);
	$close_servicesC = array(
		'0'=>'show',	
		'1'=>'hide',							
		);
	$close_friendsC = array(
		'0'=>'show',	
		'1'=>'hide',								
		);
	$close_twitterC = array(
		'1'=>'hide',	
		'0'=>'show',								
		);
	$close_lastusersC = array(
		'0'=>'show',	
		'1'=>'hide',									
		);
	$close_lastphotosC = array(
		'0'=>'show',	
		'1'=>'hide',								
		);
	$close_lastlikesC = array(
		'0'=>'show',	
		'1'=>'hide',								
		);
		
	
	
	$t = file_get_contents( dirname(__file__). "/theme_settings.tpl" );
	$o .= replace_macros($t, array(
		'$submit' => t('Submit'),
		'$baseurl' => $a->get_baseurl(),
		'$title' => t("Theme settings"),
		'$font_size' => array('diabook_font_size', t('Set font-size for posts and comments'), $font_size, '', $font_sizes),
		'$line_height' => array('diabook_line_height', t('Set line-height for posts and comments'), $line_height, '', $line_heights),
		'$resolution' => array('diabook_resolution', t('Set resolution for middle column'), $resolution, '', $resolutions),
		'$color' => array('diabook_color', t('Set color scheme'), $color, '', $colors),	
		'$TSearchTerm' => array('diabook_TSearchTerm', t('Set twitter search term'), $TSearchTerm, '', $TSearchTerm),	
		'$ELZoom' => array('diabook_ELZoom', t('Set zoomfactor for Earth Layer'), $ELZoom, '', $ELZoom),	
		'$ELPosX' => array('diabook_ELPosX', t('Set longitude (X) for Earth Layer'), $ELPosX, '', $ELPosX),	
		'$ELPosY' => array('diabook_ELPosY', t('Set latitude (Y) for Earth Layer'), $ELPosY, '', $ELPosY),	
		'$close_pages' => array('diabook_close_pages', t('Show "Cummunity Pages" at right-hand coloumn?'), $close_pages, '', $close_pagesC),	
		'$close_mapquery' => array('diabook_close_mapquery', t('Show "Earth Layers" at right-hand coloumn?'), $close_mapquery, '', $close_mapqueryC),		
		'$close_profiles' => array('diabook_close_profiles', t('Show "Cummunity Profiles" at right-hand coloumn?'), $close_profiles, '', $close_profilesC),		
		'$close_helpers' => array('diabook_close_helpers', t('Show "Help or @NewHere" at right-hand coloumn?'), $close_helpers, '', $close_helpersC),		
		'$close_services' => array('diabook_close_services', t('Show "Connect Services" at right-hand coloumn?'), $close_services, '', $close_servicesC),			
		'$close_friends' => array('diabook_close_friends', t('Show "Find Friends" at right-hand coloumn?'), $close_friends, '', $close_friendsC),				
		'$close_twitter' => array('diabook_close_twitter', t('Show "Last Tweets" at right-hand coloumn?'), $close_twitter, '', $close_twitterC),				
		'$close_lastusers' => array('diabook_close_lastusers', t('Show "Last Users" at right-hand coloumn?'), $close_lastusers, '', $close_lastusersC),				
		'$close_lastphotos' => array('diabook_close_lastphotos', t('Show "Last Photos" at right-hand coloumn?'), $close_lastphotos, '', $close_lastphotosC),				
		'$close_lastlikes' => array('diabook_close_lastlikes', t('Show "Last Likes" at right-hand coloumn?'), $close_lastlikes, '', $close_lastlikesC),				
	));
	return $o;
}
