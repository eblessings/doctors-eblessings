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

	$align = PConfig::get(local_user(), 'quattro', 'align' );
	$color = PConfig::get(local_user(), 'quattro', 'color' );
	$tfs = PConfig::get(local_user(),"quattro","tfs");
	$pfs = PConfig::get(local_user(),"quattro","pfs");

	return quattro_form($a,$align, $color, $tfs, $pfs);
}

function theme_post(App $a) {
	if (! local_user()) {
		return;
	}

	if (isset($_POST['quattro-settings-submit'])){
		PConfig::set(local_user(), 'quattro', 'align', $_POST['quattro_align']);
		PConfig::set(local_user(), 'quattro', 'color', $_POST['quattro_color']);
		PConfig::set(local_user(), 'quattro', 'tfs', $_POST['quattro_tfs']);
		PConfig::set(local_user(), 'quattro', 'pfs', $_POST['quattro_pfs']);
	}
}

function theme_admin(App $a) {
	$align = Config::get('quattro', 'align' );
	$color = Config::get('quattro', 'color' );
	$tfs = Config::get("quattro","tfs");
	$pfs = Config::get("quattro","pfs");

	return quattro_form($a,$align, $color, $tfs, $pfs);
}

function theme_admin_post(App $a) {
	if (isset($_POST['quattro-settings-submit'])){
		Config::set('quattro', 'align', $_POST['quattro_align']);
		Config::set('quattro', 'color', $_POST['quattro_color']);
		Config::set('quattro', 'tfs', $_POST['quattro_tfs']);
		Config::set('quattro', 'pfs', $_POST['quattro_pfs']);
	}
}

/// @TODO $a is no longer used here
function quattro_form(App $a, $align, $color, $tfs, $pfs) {
	$colors = [
		"dark"  => "Quattro",
		"lilac" => "Lilac",
		"green" => "Green",
	];

	if ($tfs === false) {
		$tfs = "20";
	}
	if ($pfs === false) {
		$pfs = "12";
	}

	$t = get_markup_template("theme_settings.tpl" );
	$o .= replace_macros($t, [
		'$submit'  => t('Submit'),
		'$baseurl' => System::baseUrl(),
		'$title'   => t("Theme settings"),
		'$align'   => ['quattro_align', t('Alignment'), $align, '', ['left'=>t('Left'), 'center'=>t('Center')]],
		'$color'   => ['quattro_color', t('Color scheme'), $color, '', $colors],
		'$pfs'     => ['quattro_pfs', t('Posts font size'), $pfs],
		'$tfs'     => ['quattro_tfs', t('Textareas font size'), $tfs],
	]);
	return $o;
}
