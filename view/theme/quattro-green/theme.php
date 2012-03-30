<?php
/*
 * Name: quattro-green
 * Description: Green variation of the quattro theme
 * Version: 1.0
 * Author: Fabio Communi <fabrix.xm@gmail.com>
 * Maintainer: Tobias Diekershoff
 */
$a->theme_info = array(
  'extends' => 'quattro',
);

$a->hooks[] = array('plugin_settings', 'view/theme/quattro-green/theme.php', 'quattro_green_settings');
$a->hooks[] = array('plugin_settings_post', 'view/theme/quattro-green/theme.php', 'quattro_green_settings_post');


function quattro_green_settings(&$a, &$o){
	if(!local_user())
		return;		
	
	$align = get_pconfig(local_user(), 'quattro', 'align' );
	
	$t = file_get_contents( dirname(__file__). "/theme_settings.tpl" );
	$o .= replace_macros($t, array(
		'$submit' => t('Submit'),
		'$baseurl' => $a->get_baseurl(),
		'$title' => t("Theme settings"),
		'$align' => array('quattro_align', t('Alignment'), $align, '', array('left'=>t('Left'), 'center'=>t('Center'))),
	));
}

function quattro_green_settings_post(&$a){
	if(! local_user())
		return;
	if (isset($_POST['quattro-settings-submit'])){
		set_pconfig(local_user(), 'quattro', 'align', $_POST['quattro_align']);
	}
	goaway($a->get_baseurl()."/settings/addon");
}


$quattro_align = get_pconfig(local_user(), 'quattro', 'align' );

if(local_user() && $quattro_align=="center"){
	
	$a->page['htmlhead'].="
	<style>
		html { width: 100%; margin:0px; padding:0px; }
		body {
			margin: 50px auto;
			width: 900px;
		}
	</style>
	";
	
}
