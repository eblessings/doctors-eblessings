<?php
/**
 * @file include/plugin.php
 *
 * @brief Some functions to handle addons and themes.
 */

use Friendica\App;
use Friendica\Core\System;

/**
 * @brief uninstalls an addon.
 *
 * @param string $plugin name of the addon
 * @return boolean
 */
if (! function_exists('uninstall_plugin')){
function uninstall_plugin($plugin){
	logger("Addons: uninstalling " . $plugin);
	q("DELETE FROM `addon` WHERE `name` = '%s' ",
		dbesc($plugin)
	);

	@include_once('addon/' . $plugin . '/' . $plugin . '.php');
	if (function_exists($plugin . '_uninstall')) {
		$func = $plugin . '_uninstall';
		$func();
	}
}}

/**
 * @brief installs an addon.
 *
 * @param string $plugin name of the addon
 * @return bool
 */
if (! function_exists('install_plugin')){
function install_plugin($plugin) {
	// silently fail if plugin was removed

	if (! file_exists('addon/' . $plugin . '/' . $plugin . '.php'))
		return false;
	logger("Addons: installing " . $plugin);
	$t = @filemtime('addon/' . $plugin . '/' . $plugin . '.php');
	@include_once('addon/' . $plugin . '/' . $plugin . '.php');
	if (function_exists($plugin . '_install')) {
		$func = $plugin . '_install';
		$func();

		$plugin_admin = (function_exists($plugin."_plugin_admin") ? 1 : 0);

		dba::insert('addon', array('name' => $plugin, 'installed' => true,
					'timestamp' => $t, 'plugin_admin' => $plugin_admin));

		// we can add the following with the previous SQL
		// once most site tables have been updated.
		// This way the system won't fall over dead during the update.

		if (file_exists('addon/' . $plugin . '/.hidden')) {
			dba::update('addon', array('hidden' => true), array('name' => $plugin));
		}
		return true;
	}
	else {
		logger("Addons: FAILED installing " . $plugin);
		return false;
	}

}}

// reload all updated plugins

if (! function_exists('reload_plugins')) {
function reload_plugins() {
	$plugins = get_config('system','addon');
	if (strlen($plugins)) {

		$r = q("SELECT * FROM `addon` WHERE `installed` = 1");
		if (dbm::is_result($r))
			$installed = $r;
		else
			$installed = array();

		$parr = explode(',',$plugins);

		if (count($parr)) {
			foreach ($parr as $pl) {

				$pl = trim($pl);

				$fname = 'addon/' . $pl . '/' . $pl . '.php';

				if (file_exists($fname)) {
					$t = @filemtime($fname);
					foreach ($installed as $i) {
						if (($i['name'] == $pl) && ($i['timestamp'] != $t)) {
							logger('Reloading plugin: ' . $i['name']);
							@include_once($fname);

							if (function_exists($pl . '_uninstall')) {
								$func = $pl . '_uninstall';
								$func();
							}
							if (function_exists($pl . '_install')) {
								$func = $pl . '_install';
								$func();
							}
							dba::update('addon', array('timestamp' => $t), array('id' => $i['id']));
						}
					}
				}
			}
		}
	}

}}

/**
 * @brief check if addon is enabled
 *
 * @param string $plugin
 * @return boolean
 */
function plugin_enabled($plugin) {
	return dba::exists('addon', array('installed' => true, 'name' => $plugin));
}


/**
 * @brief registers a hook.
 *
 * @param string $hook the name of the hook
 * @param string $file the name of the file that hooks into
 * @param string $function the name of the function that the hook will call
 * @param int $priority A priority (defaults to 0)
 * @return mixed|bool
 */
if (! function_exists('register_hook')) {
function register_hook($hook,$file,$function,$priority=0) {

	$r = q("SELECT * FROM `hook` WHERE `hook` = '%s' AND `file` = '%s' AND `function` = '%s' LIMIT 1",
		dbesc($hook),
		dbesc($file),
		dbesc($function)
	);
	if (dbm::is_result($r))
		return true;

	$r = dba::insert('hook', array('hook' => $hook, 'file' => $file, 'function' => $function, 'priority' => $priority));

	return $r;
}}

/**
 * @brief unregisters a hook.
 *
 * @param string $hook the name of the hook
 * @param string $file the name of the file that hooks into
 * @param string $function the name of the function that the hook called
 * @return array
 */
if (! function_exists('unregister_hook')) {
function unregister_hook($hook,$file,$function) {

	$r = q("DELETE FROM `hook` WHERE `hook` = '%s' AND `file` = '%s' AND `function` = '%s'",
		dbesc($hook),
		dbesc($file),
		dbesc($function)
	);
	return $r;
}}


function load_hooks() {
	$a = get_app();
	$a->hooks = array();
	$r = dba::select('hook', array('hook', 'file', 'function'), array(), array('order' => array('priority' => 'desc', 'file')));

	while ($rr = dba::fetch($r)) {
		if (! array_key_exists($rr['hook'],$a->hooks)) {
			$a->hooks[$rr['hook']] = array();
		}
		$a->hooks[$rr['hook']][] = array($rr['file'],$rr['function']);
	}
	dba::close($r);
}

/**
 * @brief Calls a hook.
 *
 * Use this function when you want to be able to allow a hook to manipulate
 * the provided data.
 *
 * @param string $name of the hook to call
 * @param string|array &$data to transmit to the callback handler
 */
function call_hooks($name, &$data = null) {
	$stamp1 = microtime(true);

	$a = get_app();

	if (is_array($a->hooks) && array_key_exists($name, $a->hooks))
		foreach ($a->hooks[$name] as $hook)
			call_single_hook($a, $name, $hook, $data);
}

/**
 * @brief Calls a single hook.
 *
 * @param string $name of the hook to call
 * @param array $hook Hook data
 * @param string|array &$data to transmit to the callback handler
 */
function call_single_hook($a, $name, $hook, &$data = null) {
	// Don't run a theme's hook if the user isn't using the theme
	if (strpos($hook[0], 'view/theme/') !== false && strpos($hook[0], 'view/theme/'.current_theme()) === false)
		return;

	@include_once($hook[0]);
	if (function_exists($hook[1])) {
		$func = $hook[1];
		$func($a, $data);
	} else {
		// remove orphan hooks
		q("DELETE FROM `hook` WHERE `hook` = '%s' AND `file` = '%s' AND `function` = '%s'",
			dbesc($name),
			dbesc($hook[0]),
			dbesc($hook[1])
		);
	}
}

//check if an app_menu hook exist for plugin $name.
//Return true if the plugin is an app
if (! function_exists('plugin_is_app')) {
function plugin_is_app($name) {
	$a = get_app();

	if (is_array($a->hooks) && (array_key_exists('app_menu',$a->hooks))) {
		foreach ($a->hooks['app_menu'] as $hook) {
			if ($hook[0] == 'addon/'.$name.'/'.$name.'.php')
				return true;
		}
	}

	return false;
}}

/**
 * @brief Parse plugin comment in search of plugin infos.
 *
 * like
 * \code
 *...* Name: Plugin
 *   * Description: A plugin which plugs in
 * . * Version: 1.2.3
 *   * Author: John <profile url>
 *   * Author: Jane <email>
 *   *
 *  *\endcode
 * @param string $plugin the name of the plugin
 * @return array with the plugin information
 */

if (! function_exists('get_plugin_info')){
function get_plugin_info($plugin){

	$a = get_app();

	$info=Array(
		'name' => $plugin,
		'description' => "",
		'author' => array(),
		'version' => "",
		'status' => ""
	);

	if (!is_file("addon/$plugin/$plugin.php")) return $info;

	$stamp1 = microtime(true);
	$f = file_get_contents("addon/$plugin/$plugin.php");
	$a->save_timestamp($stamp1, "file");

	$r = preg_match("|/\*.*\*/|msU", $f, $m);

	if ($r){
		$ll = explode("\n", $m[0]);
		foreach ( $ll as $l ) {
			$l = trim($l,"\t\n\r */");
			if ($l!=""){
				list($k,$v) = array_map("trim", explode(":",$l,2));
				$k= strtolower($k);
				if ($k=="author"){
					$r=preg_match("|([^<]+)<([^>]+)>|", $v, $m);
					if ($r) {
						$info['author'][] = array('name'=>$m[1], 'link'=>$m[2]);
					} else {
						$info['author'][] = array('name'=>$v);
					}
				} else {
					if (array_key_exists($k,$info)){
						$info[$k]=$v;
					}
				}

			}
		}

	}
	return $info;
}}


/**
 * @brief Parse theme comment in search of theme infos.
 *
 * like
 * \code
 * ..* Name: My Theme
 *   * Description: My Cool Theme
 * . * Version: 1.2.3
 *   * Author: John <profile url>
 *   * Maintainer: Jane <profile url>
 *   *
 * \endcode
 * @param string $theme the name of the theme
 * @return array
 */

if (! function_exists('get_theme_info')){
function get_theme_info($theme){
	$info=Array(
		'name' => $theme,
		'description' => "",
		'author' => array(),
		'maintainer' => array(),
		'version' => "",
		'credits' => "",
		'experimental' => false,
		'unsupported' => false
	);

	if (file_exists("view/theme/$theme/experimental"))
		$info['experimental'] = true;
	if (file_exists("view/theme/$theme/unsupported"))
		$info['unsupported'] = true;

	if (!is_file("view/theme/$theme/theme.php")) return $info;

	$a = get_app();
	$stamp1 = microtime(true);
	$f = file_get_contents("view/theme/$theme/theme.php");
	$a->save_timestamp($stamp1, "file");

	$r = preg_match("|/\*.*\*/|msU", $f, $m);

	if ($r){
		$ll = explode("\n", $m[0]);
		foreach ( $ll as $l ) {
			$l = trim($l,"\t\n\r */");
			if ($l!=""){
				list($k,$v) = array_map("trim", explode(":",$l,2));
				$k= strtolower($k);
				if ($k=="author"){

					$r=preg_match("|([^<]+)<([^>]+)>|", $v, $m);
					if ($r) {
						$info['author'][] = array('name'=>$m[1], 'link'=>$m[2]);
					} else {
						$info['author'][] = array('name'=>$v);
					}
				}
				elseif ($k=="maintainer"){
					$r=preg_match("|([^<]+)<([^>]+)>|", $v, $m);
					if ($r) {
						$info['maintainer'][] = array('name'=>$m[1], 'link'=>$m[2]);
					} else {
						$info['maintainer'][] = array('name'=>$v);
					}
				} else {
					if (array_key_exists($k,$info)){
						$info[$k]=$v;
					}
				}

			}
		}

	}
	return $info;
}}

/**
 * @brief Returns the theme's screenshot.
 *
 * The screenshot is expected as view/theme/$theme/screenshot.[png|jpg].
 *
 * @param sring $theme The name of the theme
 * @return string
 */
function get_theme_screenshot($theme) {
	$exts = array('.png','.jpg');
	foreach ($exts as $ext) {
		if (file_exists('view/theme/' . $theme . '/screenshot' . $ext)) {
			return(System::baseUrl() . '/view/theme/' . $theme . '/screenshot' . $ext);
		}
	}
	return(System::baseUrl() . '/images/blank.png');
}

// install and uninstall theme
if (! function_exists('uninstall_theme')){
function uninstall_theme($theme){
	logger("Addons: uninstalling theme " . $theme);

	include_once("view/theme/$theme/theme.php");
	if (function_exists("{$theme}_uninstall")) {
		$func = "{$theme}_uninstall";
		$func();
	}
}}

if (! function_exists('install_theme')){
function install_theme($theme) {
	// silently fail if theme was removed

	if (! file_exists("view/theme/$theme/theme.php")) {
		return false;
	}

	logger("Addons: installing theme $theme");

	include_once("view/theme/$theme/theme.php");

	if (function_exists("{$theme}_install")) {
		$func = "{$theme}_install";
		$func();
		return true;
	} else {
		logger("Addons: FAILED installing theme $theme");
		return false;
	}

}}

/**
 * @brief Get the full path to relevant theme files by filename
 *
 * This function search in the theme directory (and if not present in global theme directory)
 * if there is a directory with the file extension and  for a file with the given
 * filename.
 *
 * @param string $file Filename
 * @param string $root Full root path
 * @return string Path to the file or empty string if the file isn't found
 */
function theme_include($file, $root = '') {
	$file = basename($file);

	// Make sure $root ends with a slash / if it's not blank
	if ($root !== '' && $root[strlen($root)-1] !== '/') {
		$root = $root . '/';
	}
	$theme_info = get_app()->theme_info;
	if (is_array($theme_info) && array_key_exists('extends',$theme_info)) {
		$parent = $theme_info['extends'];
	} else {
		$parent = 'NOPATH';
	}
	$theme = current_theme();
	$thname = $theme;
	$ext = substr($file,strrpos($file,'.')+1);
	$paths = array(
		"{$root}view/theme/$thname/$ext/$file",
		"{$root}view/theme/$parent/$ext/$file",
		"{$root}view/$ext/$file",
	);
	foreach ($paths as $p) {
		// strpos() is faster than strstr when checking if one string is in another (http://php.net/manual/en/function.strstr.php)
		if (strpos($p,'NOPATH') !== false) {
			continue;
		} elseif (file_exists($p)) {
			return $p;
		}
	}
	return '';
}
