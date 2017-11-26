<?php

/**
 * Theme settings
 */

use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\PConfig;
use Friendica\Core\System;

function theme_content(App $a) {
	if (!local_user()) {
		return;
	}

	if (!function_exists('get_vier_config')) {
		return;
	}

	$style = PConfig::get(local_user(), 'vier', 'style');

	if ($style == "") {
		$style = Config::get('vier', 'style');
	}

	if ($style == "") {
		$style = "plus";
	}

	$show_pages = get_vier_config('show_pages', true);
	$show_profiles = get_vier_config('show_profiles', true);
	$show_helpers = get_vier_config('show_helpers', true);
	$show_services = get_vier_config('show_services', true);
	$show_friends = get_vier_config('show_friends', true);
	$show_lastusers = get_vier_config('show_lastusers', true);

	return vier_form($a,$style, $show_pages, $show_profiles, $show_helpers,
			$show_services, $show_friends, $show_lastusers);
}

function theme_post(App $a) {
	if (! local_user()) {
		return;
	}

	if (isset($_POST['vier-settings-submit'])){
		PConfig::set(local_user(), 'vier', 'style', $_POST['vier_style']);
		PConfig::set(local_user(), 'vier', 'show_pages', $_POST['vier_show_pages']);
		PConfig::set(local_user(), 'vier', 'show_profiles', $_POST['vier_show_profiles']);
		PConfig::set(local_user(), 'vier', 'show_helpers', $_POST['vier_show_helpers']);
		PConfig::set(local_user(), 'vier', 'show_services', $_POST['vier_show_services']);
		PConfig::set(local_user(), 'vier', 'show_friends', $_POST['vier_show_friends']);
		PConfig::set(local_user(), 'vier', 'show_lastusers', $_POST['vier_show_lastusers']);
	}
}


function theme_admin(App $a) {

	if (!function_exists('get_vier_config'))
		return;

	$style = Config::get('vier', 'style');

	$helperlist = Config::get('vier', 'helperlist');

	if ($helperlist == "")
		$helperlist = "https://forum.friendi.ca/profile/helpers";

	$t = get_markup_template("theme_admin_settings.tpl");
	$o .= replace_macros($t, array(
		'$helperlist' => array('vier_helperlist', t('Comma separated list of helper forums'), $helperlist, '', ''),
		));

	$show_pages = get_vier_config('show_pages', true, true);
	$show_profiles = get_vier_config('show_profiles', true, true);
	$show_helpers = get_vier_config('show_helpers', true, true);
	$show_services = get_vier_config('show_services', true, true);
	$show_friends = get_vier_config('show_friends', true, true);
	$show_lastusers = get_vier_config('show_lastusers', true, true);
	$o .= vier_form($a,$style, $show_pages, $show_profiles, $show_helpers, $show_services,
			$show_friends, $show_lastusers);

	return $o;
}

function theme_admin_post(App $a) {
	if (isset($_POST['vier-settings-submit'])){
		Config::set('vier', 'style', $_POST['vier_style']);
		Config::set('vier', 'show_pages', $_POST['vier_show_pages']);
		Config::set('vier', 'show_profiles', $_POST['vier_show_profiles']);
		Config::set('vier', 'show_helpers', $_POST['vier_show_helpers']);
		Config::set('vier', 'show_services', $_POST['vier_show_services']);
		Config::set('vier', 'show_friends', $_POST['vier_show_friends']);
		Config::set('vier', 'show_lastusers', $_POST['vier_show_lastusers']);
		Config::set('vier', 'helperlist', $_POST['vier_helperlist']);
	}
}

/// @TODO $a is no longer used
function vier_form(App $a, $style, $show_pages, $show_profiles, $show_helpers, $show_services, $show_friends, $show_lastusers) {
	$styles = array(
		"breathe"=>"Breathe",
		"netcolour"=>"Coloured Networks",
		"dark"=>"Dark",
		"flat"=>"Flat",
		"plus"=>"Plus",
		"plusminus"=>"Plus Minus",
		"shadow"=>"Shadow"
	);

	$show_or_not = array('0'=>t("don't show"),     '1'=>t("show"),);

	$t = get_markup_template("theme_settings.tpl");
	$o .= replace_macros($t, array(
		'$submit' => t('Submit'),
		'$baseurl' => System::baseUrl(),
		'$title' => t("Theme settings"),
		'$style' => array('vier_style',t ('Set style'),$style,'',$styles),
		'$show_pages' => array('vier_show_pages', t('Community Pages'), $show_pages, '', $show_or_not),
		'$show_profiles' => array('vier_show_profiles', t('Community Profiles'), $show_profiles, '', $show_or_not),
		'$show_helpers' => array('vier_show_helpers', t('Help or @NewHere ?'), $show_helpers, '', $show_or_not),
		'$show_services' => array('vier_show_services', t('Connect Services'), $show_services, '', $show_or_not),
		'$show_friends' => array('vier_show_friends', t('Find Friends'), $show_friends, '', $show_or_not),
		'$show_lastusers' => array('vier_show_lastusers', t('Last users'), $show_lastusers, '', $show_or_not)
	));
	return $o;
}
