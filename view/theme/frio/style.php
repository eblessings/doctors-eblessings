<?php
require_once 'view/theme/frio/php/PHPColors/Color.php';

use Friendica\Core\PConfig;

$schemecss = "";
$schemecssfile = false;
$scheme_modified = 0;

if (! $a->install) {
	// Get the UID of the profile owner.
	$uid = get_theme_uid();
	if ($uid) {
		PConfig::load($uid, 'frio');

		// Load the profile owners pconfig.
		$schema           = PConfig::get($uid, "frio", "schema");
		$nav_bg           = PConfig::get($uid, "frio", "nav_bg");
		$nav_icon_color   = PConfig::get($uid, "frio", "nav_icon_color");
		$link_color       = PConfig::get($uid, "frio", "link_color");
		$bgcolor          = PConfig::get($uid, "frio", "background_color");
		$contentbg_transp = PConfig::get($uid, "frio", "contentbg_transp");
		$background_image = PConfig::get($uid, "frio", "background_image");
		$bg_image_option  = PConfig::get($uid, "frio", "bg_image_option");
		$modified         = PConfig::get($uid, "frio", "css_modified");

		// There is maybe the case that the user did never modify the theme settings.
		// In this case we store the present time.
		if (empty($modified)) {
			PConfig::set($uid, 'frio', 'css_modified', time());
		}
	}
}

// Now load the scheme.  If a value is changed above, we'll keep the settings
// If not, we'll keep those defined by the schema
// Setting $schema to '' wasn't working for some reason, so we'll check it's
// not --- like the mobile theme does instead.
// Allow layouts to over-ride the schema.
if ($_REQUEST['schema']) {
	$schema = $_REQUEST['schema'];
}
if (($schema) && ($schema != '---')) {
	if (file_exists('view/theme/frio/schema/' . $schema . '.php')) {
		$schemefile = 'view/theme/frio/schema/' . $schema . '.php';
		require_once $schemefile;
	}
	if (file_exists('view/theme/frio/schema/' . $schema . '.css')) {
		$schemecssfile = 'view/theme/frio/schema/' . $schema . '.css';
	}
}

// If we haven't got a schema, load the default.  We shouldn't touch this - we
// should leave it for admins to define for themselves.
// default.php and default.css MUST be symlinks to existing schema files.
if (! $schema) {
	if(file_exists('view/theme/frio/schema/default.php')) {
		$schemefile = 'view/theme/frio/schema/default.php';
		require_once $schemefile;
	}
	if(file_exists('view/theme/frio/schema/default.css')) {
		$schemecssfile = 'view/theme/frio/schema/default.css';
	}
}

//Set some defaults - we have to do this after pulling owner settings, and we have to check for each setting
//individually.  If we don't, we'll have problems if a user has set one, but not all options.
$nav_bg           = (empty($nav_bg)           ? "#708fa0"      : $nav_bg);
$nav_icon_color   = (empty($nav_icon_color)   ? "#fff"         : $nav_icon_color);
$link_color       = (empty($link_color)       ? "#6fdbe8"      : $link_color);
$bgcolor          = (empty($bgcolor)          ? "#ededed"      : $bgcolor);
// The background image can not be empty. So we use a dummy jpg if no image was set.
$background_image = (empty($background_image) ? 'img/none.jpg' : $background_image);
$modified         = (empty($modified)         ? time()         :$modified);

$contentbg_transp = ((isset($contentbg_transp) && $contentbg_transp != "") ?  $contentbg_transp : 100);

// Calculate some colors in dependance of existing colors.
// Some colors are calculated to don't have too many selection
// fields in the theme settings.
if (! $menu_background_hover_color) {
	$mbhc = new Color($nav_bg);
	$mcolor = $mbhc->getHex();

	if ($mbhc->isLight($mcolor, 75)) {
		$menu_is = 'light';
		$menu_background_hover_color = '#' . $mbhc->darken(5);
	} else {
		$menu_is = 'dark';
		$menu_background_hover_color = '#' . $mbhc->lighten(5);
	}
}
if (! $nav_icon_hover_color) {
	$nihc = new Color($nav_bg);

	if ($nihc->isLight()) {
		$nav_icon_hover_color = '#' . $nihc->darken(10);
	} else {
		$nav_icon_hover_color = '#' . $nihc->lighten(10);
	}
}
if (! $link_hover_color) {
	$lhc = new Color($link_color);
	$lcolor = $lhc->getHex();

	if ($lhc->isLight($lcolor, 75)) {
		$link_hover_color = '#' . $lhc->darken(5);
	} else {
		$link_hover_color = '#' . $lhc->lighten(5);
	}

}

// Convert $bg_image_options into css.
switch ($bg_image_option) {
	case "stretch":
		$background_size_img = "100%";
		break;
	case "cover":
		$background_size_img ="cover";
		break;
	case "repeat":
		$background_size_img = "auto";
		break;
	case "contain":
		$background_size_img = "contain";
		break;

	default:
		$background_size_img = "auto";
		break;
}

// Convert transparency level from percentage to opacity value.
$contentbg_transp = $contentbg_transp / 100;

$options = array (
	'$nav_bg'                      => $nav_bg,
	'$nav_icon_color'              => $nav_icon_color,
	'$nav_icon_hover_color'        => $nav_icon_hover_color,
	'$link_color'                  => $link_color,
	'$link_hover_color'            => $link_hover_color,
	'$menu_background_hover_color' => $menu_background_hover_color,
	'$btn_primary_color'           => $nav_icon_color,
	'$btn_primary_hover_color'     => $menu_background_hover_color,
	'$bgcolor'                     => $bgcolor,
	'$contentbg_transp'            => $contentbg_transp,
	'$background_image'            => $background_image,
	'$background_size_img'         => $background_size_img,
);

$css_tpl = file_get_contents('view/theme/frio/css/style.css');

// Get the content of the scheme css file and the time of the last file change.
if ($schemecssfile) {
	$css_tpl .= file_get_contents($schemecssfile);
	$scheme_modified = filemtime($schemecssfile);
}

// We need to check which is the most recent css data.
// We will use this time later to decide if we load the cached or fresh css data.
if ($scheme_modified > $modified) {
	$modified = $scheme_modified;
}
// Apply the settings to the css template.
$css = str_replace(array_keys($options), array_values($options), $css_tpl);

$modified = gmdate('r', $modified);

$etag = md5($css);

// Set a header for caching.
header('Cache-Control: public');
header('ETag: "'.$etag.'"');
header('Last-Modified: '.$modified);

// Only send the CSS file if it was changed.
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
	$cached_modified = gmdate('r', strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']));
	$cached_etag = str_replace(array('"', "-gzip"), array('', ''),
				stripslashes($_SERVER['HTTP_IF_NONE_MATCH']));

	if (($cached_modified == $modified) && ($cached_etag == $etag)) {
		header('HTTP/1.1 304 Not Modified');
		exit();
	}
}

echo $css;
