<?php
/**
 * Theme settings
 */

use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\DI;

function theme_content(App $a) {
	if (!local_user()) {
		return;
	}

	$align = DI::pConfig()->get(local_user(), 'quattro', 'align' );
	$color = DI::pConfig()->get(local_user(), 'quattro', 'color' );
	$tfs = DI::pConfig()->get(local_user(),"quattro","tfs");
	$pfs = DI::pConfig()->get(local_user(),"quattro","pfs");

	return quattro_form($a,$align, $color, $tfs, $pfs);
}

function theme_post(App $a) {
	if (! local_user()) {
		return;
	}

	if (isset($_POST['quattro-settings-submit'])){
		DI::pConfig()->set(local_user(), 'quattro', 'align', $_POST['quattro_align']);
		DI::pConfig()->set(local_user(), 'quattro', 'color', $_POST['quattro_color']);
		DI::pConfig()->set(local_user(), 'quattro', 'tfs', $_POST['quattro_tfs']);
		DI::pConfig()->set(local_user(), 'quattro', 'pfs', $_POST['quattro_pfs']);
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

	$t = Renderer::getMarkupTemplate("theme_settings.tpl" );
	$o = Renderer::replaceMacros($t, [
		'$submit'  => L10n::t('Submit'),
		'$title'   => L10n::t("Theme settings"),
		'$align'   => ['quattro_align', L10n::t('Alignment'), $align, '', ['left' => L10n::t('Left'), 'center' => L10n::t('Center')]],
		'$color'   => ['quattro_color', L10n::t('Color scheme'), $color, '', $colors],
		'$pfs'     => ['quattro_pfs', L10n::t('Posts font size'), $pfs],
		'$tfs'     => ['quattro_tfs', L10n::t('Textareas font size'), $tfs],
	]);
	return $o;
}
