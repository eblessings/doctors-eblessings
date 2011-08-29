<?php

require_once('include/config.php');
require_once('include/network.php');
require_once('include/plugin.php');
require_once('include/text.php');
require_once("include/pgettext.php");


define ( 'FRIENDIKA_VERSION',      '2.2.1086' );
define ( 'DFRN_PROTOCOL_VERSION',  '2.21'    );
define ( 'DB_UPDATE_VERSION',      1084      );

define ( 'EOL',                    "<br />\r\n"     );
define ( 'ATOM_TIME',              'Y-m-d\TH:i:s\Z' );


/**
 *
 * Image storage quality. Lower numbers save space at cost of image detail.
 * For ease of upgrade, please do not change here. Change jpeg quality with 
 * set_config('system','jpeg_quality',n) in .htconfig.php
 * where n is netween 1 and 100, and with very poor results below about 50 
 *
 */

define ( 'JPEG_QUALITY',            100  );         

/**
 * SSL redirection policies
 */

define ( 'SSL_POLICY_NONE',         0 );
define ( 'SSL_POLICY_FULL',         1 );
define ( 'SSL_POLICY_SELFSIGN',     2 );


/**
 * log levels
 */

define ( 'LOGGER_NORMAL',          0 );
define ( 'LOGGER_TRACE',           1 );
define ( 'LOGGER_DEBUG',           2 );
define ( 'LOGGER_DATA',            3 );
define ( 'LOGGER_ALL',             4 );

/**
 * registration policies
 */

define ( 'REGISTER_CLOSED',        0 );
define ( 'REGISTER_APPROVE',       1 );
define ( 'REGISTER_OPEN',          2 );

/**
 * relationship types
 */

define ( 'CONTACT_IS_FOLLOWER', 1);
define ( 'CONTACT_IS_SHARING',  2);
define ( 'CONTACT_IS_FRIEND',   3);


/**
 * Hook array order
 */
 
define ( 'HOOK_HOOK',      0);
define ( 'HOOK_FILE',      1);
define ( 'HOOK_FUNCTION',  2);

/**
 *
 * page/profile types
 *
 * PAGE_NORMAL is a typical personal profile account
 * PAGE_SOAPBOX automatically approves all friend requests as CONTACT_IS_SHARING, (readonly)
 * PAGE_COMMUNITY automatically approves all friend requests as CONTACT_IS_SHARING, but with 
 *      write access to wall and comments (no email and not included in page owner's ACL lists)
 * PAGE_FREELOVE automatically approves all friend requests as full friends (CONTACT_IS_FRIEND). 
 *
 */

define ( 'PAGE_NORMAL',            0 );
define ( 'PAGE_SOAPBOX',           1 );
define ( 'PAGE_COMMUNITY',         2 );
define ( 'PAGE_FREELOVE',          3 );

/**
 * Network and protocol family types 
 */

define ( 'NETWORK_ZOT',              'zot!');    // Zot!
define ( 'NETWORK_DFRN',             'dfrn');    // Friendika, Mistpark, other DFRN implementations
define ( 'NETWORK_OSTATUS',          'stat');    // status.net, identi.ca, GNU-social, other OStatus implementations
define ( 'NETWORK_FEED',             'feed');    // RSS/Atom feeds with no known "post/notify" protocol
define ( 'NETWORK_DIASPORA',         'dspr');    // Diaspora
define ( 'NETWORK_MAIL',             'mail');    // IMAP/POP
define ( 'NETWORK_FACEBOOK',         'face');    // Facebook API     


/**
 * Maximum number of "people who like (or don't like) this"  that we will list by name
 */

define ( 'MAX_LIKERS',    75);

/**
 * Communication timeout
 */

define ( 'ZCURL_TIMEOUT' , (-1));


/**
 * email notification options
 */

define ( 'NOTIFY_INTRO',   0x0001 );
define ( 'NOTIFY_CONFIRM', 0x0002 );
define ( 'NOTIFY_WALL',    0x0004 );
define ( 'NOTIFY_COMMENT', 0x0008 );
define ( 'NOTIFY_MAIL',    0x0010 );

/**
 * various namespaces we may need to parse
 */

define ( 'NAMESPACE_ZOT',             'http://purl.org/macgirvin/zot' );
define ( 'NAMESPACE_DFRN' ,           'http://purl.org/macgirvin/dfrn/1.0' ); 
define ( 'NAMESPACE_THREAD' ,         'http://purl.org/syndication/thread/1.0' );
define ( 'NAMESPACE_TOMB' ,           'http://purl.org/atompub/tombstones/1.0' );
define ( 'NAMESPACE_ACTIVITY',        'http://activitystrea.ms/spec/1.0/' );
define ( 'NAMESPACE_ACTIVITY_SCHEMA', 'http://activitystrea.ms/schema/1.0/' );
define ( 'NAMESPACE_MEDIA',           'http://purl.org/syndication/atommedia' );
define ( 'NAMESPACE_SALMON_ME',       'http://salmon-protocol.org/ns/magic-env' );
define ( 'NAMESPACE_OSTATUSSUB',      'http://ostatus.org/schema/1.0/subscribe' );
define ( 'NAMESPACE_GEORSS',          'http://www.georss.org/georss' );
define ( 'NAMESPACE_POCO',            'http://portablecontacts.net/spec/1.0' );
define ( 'NAMESPACE_FEED',            'http://schemas.google.com/g/2010#updates-from' );
define ( 'NAMESPACE_OSTATUS',         'http://ostatus.org/schema/1.0' );
define ( 'NAMESPACE_STATUSNET',       'http://status.net/schema/api/1/' );
define ( 'NAMESPACE_ATOM1',           'http://www.w3.org/2005/Atom' );
/**
 * activity stream defines
 */

define ( 'ACTIVITY_LIKE',        NAMESPACE_ACTIVITY_SCHEMA . 'like' );
define ( 'ACTIVITY_DISLIKE',     NAMESPACE_DFRN            . '/dislike' );
define ( 'ACTIVITY_OBJ_HEART',   NAMESPACE_DFRN            . '/heart' );

define ( 'ACTIVITY_FRIEND',      NAMESPACE_ACTIVITY_SCHEMA . 'make-friend' );
define ( 'ACTIVITY_FOLLOW',      NAMESPACE_ACTIVITY_SCHEMA . 'follow' );
define ( 'ACTIVITY_UNFOLLOW',    NAMESPACE_ACTIVITY_SCHEMA . 'stop-following' );
define ( 'ACTIVITY_POST',        NAMESPACE_ACTIVITY_SCHEMA . 'post' );
define ( 'ACTIVITY_UPDATE',      NAMESPACE_ACTIVITY_SCHEMA . 'update' );
define ( 'ACTIVITY_TAG',         NAMESPACE_ACTIVITY_SCHEMA . 'tag' );

define ( 'ACTIVITY_OBJ_COMMENT', NAMESPACE_ACTIVITY_SCHEMA . 'comment' );
define ( 'ACTIVITY_OBJ_NOTE',    NAMESPACE_ACTIVITY_SCHEMA . 'note' );
define ( 'ACTIVITY_OBJ_PERSON',  NAMESPACE_ACTIVITY_SCHEMA . 'person' );
define ( 'ACTIVITY_OBJ_PHOTO',   NAMESPACE_ACTIVITY_SCHEMA . 'photo' );
define ( 'ACTIVITY_OBJ_P_PHOTO', NAMESPACE_ACTIVITY_SCHEMA . 'profile-photo' );
define ( 'ACTIVITY_OBJ_ALBUM',   NAMESPACE_ACTIVITY_SCHEMA . 'photo-album' );
define ( 'ACTIVITY_OBJ_EVENT',   NAMESPACE_ACTIVITY_SCHEMA . 'event' );

/**
 * item weight for query ordering
 */

define ( 'GRAVITY_PARENT',       0);
define ( 'GRAVITY_LIKE',         3);
define ( 'GRAVITY_COMMENT',      6);

/**
 *
 * Reverse the effect of magic_quotes_gpc if it is enabled.
 * Please disable magic_quotes_gpc so we don't have to do this.
 * See http://php.net/manual/en/security.magicquotes.disabling.php
 *
 */

function startup() {
	error_reporting(E_ERROR | E_WARNING | E_PARSE);
	set_time_limit(0);
	ini_set('pcre.backtrack_limit', 250000);


	if (get_magic_quotes_gpc()) {
    	$process = array(&$_GET, &$_POST, &$_COOKIE, &$_REQUEST);
	    while (list($key, $val) = each($process)) {
    	    foreach ($val as $k => $v) {
        	    unset($process[$key][$k]);
            	if (is_array($v)) {
                	$process[$key][stripslashes($k)] = $v;
	                $process[] = &$process[$key][stripslashes($k)];
    	        } else {
        	        $process[$key][stripslashes($k)] = stripslashes($v);
            	}
	        }
    	}
	    unset($process);
	}

}

/**
 *
 * class: App
 *
 * Our main application structure for the life of this page
 * Primarily deals with the URL that got us here
 * and tries to make some sense of it, and 
 * stores our page contents and config storage
 * and anything else that might need to be passed around 
 * before we spit the page out. 
 *
 */

if(! class_exists('App')) {
class App {

	public  $module_loaded = false;
	public  $query_string;
	public  $config;
	public  $page;
	public  $profile;
	public  $user;
	public  $cid;
	public  $contact;
	public  $contacts;
	public  $page_contact;
	public  $content;
	public  $data;
	public  $error = false;
	public  $cmd;
	public  $argv;
	public  $argc;
	public  $module;
	public  $pager;
	public  $strings;   
	public  $path;
	public  $hooks;
	public  $timezone;
	public  $interactive = true;
	public  $plugins;
	public  $apps;
	public  $identities;

	private $scheme;
	private $hostname;
	private $baseurl;
	private $db;

	private $curl_code;
	private $curl_headers;

	function __construct() {

		$this->config = array();
		$this->page = array();
		$this->pager= array();

		$this->query_string = '';

		startup();

		$this->scheme = ((isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS']))	?  'https' : 'http' );

		if(x($_SERVER,'SERVER_NAME')) {
			$this->hostname = $_SERVER['SERVER_NAME'];
			if(x($_SERVER,'SERVER_PORT') && $_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443)
				$this->hostname .= ':' . $_SERVER['SERVER_PORT'];
			/** 
			 * Figure out if we are running at the top of a domain
			 * or in a sub-directory and adjust accordingly
			 */

			$path = trim(dirname($_SERVER['SCRIPT_NAME']),'/\\');
			if(isset($path) && strlen($path) && ($path != $this->path))
				$this->path = $path;
		}

		set_include_path(
			"include/$this->hostname" . PATH_SEPARATOR 
			. 'include' . PATH_SEPARATOR 
			. 'library' . PATH_SEPARATOR 
			. 'library/phpsec' . PATH_SEPARATOR 
			. '.' );

		if((x($_SERVER,'QUERY_STRING')) && substr($_SERVER['QUERY_STRING'],0,2) === "q=")
			$this->query_string = substr($_SERVER['QUERY_STRING'],2);
		if(x($_GET,'q'))
			$this->cmd = trim($_GET['q'],'/\\');



		/**
		 *
		 * Break the URL path into C style argc/argv style arguments for our
		 * modules. Given "http://example.com/module/arg1/arg2", $this->argc
		 * will be 3 (integer) and $this->argv will contain:
		 *   [0] => 'module'
		 *   [1] => 'arg1'
		 *   [2] => 'arg2'
		 *
		 *
		 * There will always be one argument. If provided a naked domain
		 * URL, $this->argv[0] is set to "home".
		 *
		 */

		$this->argv = explode('/',$this->cmd);
		$this->argc = count($this->argv);
		if((array_key_exists('0',$this->argv)) && strlen($this->argv[0])) {
			$this->module = str_replace(".", "_", $this->argv[0]);
		}
		else {
			$this->argc = 1;
			$this->argv = array('home');
			$this->module = 'home';
		}

		/**
		 * Special handling for the webfinger/lrdd host XRD file
		 */

		if($this->cmd === '.well-known/host-meta') {
			$this->argc = 1;
			$this->argv = array('hostxrd');
			$this->module = 'hostxrd';
		}

		/**
		 * See if there is any page number information, and initialise 
		 * pagination
		 */

		$this->pager['page'] = ((x($_GET,'page')) ? $_GET['page'] : 1);
		$this->pager['itemspage'] = 50;
		$this->pager['start'] = ($this->pager['page'] * $this->pager['itemspage']) - $this->pager['itemspage'];
		$this->pager['total'] = 0;
	}

	function get_baseurl($ssl = false) {

		$scheme = $this->scheme;

		if(x($this->config,'ssl_policy')) {
			if(($ssl) || ($this->config['ssl_policy'] == SSL_POLICY_FULL)) 
				$scheme = 'https';
			if(($this->config['ssl_policy'] == SSL_POLICY_SELFSIGN) && (local_user() || x($_POST,'auth-params')))
				$scheme = 'https';
		}

		$this->baseurl = $scheme . "://" . $this->hostname . ((isset($this->path) && strlen($this->path)) ? '/' . $this->path : '' );
		return $this->baseurl;
	}

	function set_baseurl($url) {
		$parsed = @parse_url($url);

		$this->baseurl = $url;

		if($parsed) {		
			$this->scheme = $parsed['scheme'];

			$this->hostname = $parsed['host'];
			if(x($parsed,'port'))
				$this->hostname .= ':' . $parsed['port'];
			if(x($parsed,'path'))
				$this->path = trim($parsed['path'],'\\/');
		}

	}

	function get_hostname() {
		return $this->hostname;
	}

	function set_hostname($h) {
		$this->hostname = $h;
	}

	function set_path($p) {
		$this->path = trim(trim($p),'/');
	} 

	function get_path() {
		return $this->path;
	}

	function set_pager_total($n) {
		$this->pager['total'] = intval($n);
	}

	function set_pager_itemspage($n) {
		$this->pager['itemspage'] = intval($n);
		$this->pager['start'] = ($this->pager['page'] * $this->pager['itemspage']) - $this->pager['itemspage'];

	} 

	function init_pagehead() {
		$this->page['title'] = $this->config['sitename'];
		$tpl = file_get_contents('view/head.tpl');
		$this->page['htmlhead'] = replace_macros($tpl,array(
			'$baseurl' => $this->get_baseurl(), // FIXME for z_path!!!!
			'$generator' => 'Friendika' . ' ' . FRIENDIKA_VERSION,
			'$delitem' => t('Delete this item?'),
			'$comment' => t('Comment')
		));
	}

	function set_curl_code($code) {
		$this->curl_code = $code;
	}

	function get_curl_code() {
		return $this->curl_code;
	}

	function set_curl_headers($headers) {
		$this->curl_headers = $headers;
	}

	function get_curl_headers() {
		return $this->curl_headers;
	}


}}

// retrieve the App structure
// useful in functions which require it but don't get it passed to them

if(! function_exists('get_app')) {
function get_app() {
	global $a;
	return $a;
}};


// Multi-purpose function to check variable state.
// Usage: x($var) or $x($array,'key')
// returns false if variable/key is not set
// if variable is set, returns 1 if has 'non-zero' value, otherwise returns 0.
// e.g. x('') or x(0) returns 0;

if(! function_exists('x')) {
function x($s,$k = NULL) {
	if($k != NULL) {
		if((is_array($s)) && (array_key_exists($k,$s))) {
			if($s[$k])
				return (int) 1;
			return (int) 0;
		}
		return false;
	}
	else {		
		if(isset($s)) {
			if($s) {
				return (int) 1;
			}
			return (int) 0;
		}
		return false;
	}
}}

// called from db initialisation if db is dead.

if(! function_exists('system_unavailable')) {
function system_unavailable() {
	include('system_unavailable.php');
	system_down();
	killme();
}}



function clean_urls() {
	global $a;
//	if($a->config['system']['clean_urls'])
		return true;
//	return false;
}

function z_path() {
	global $a;
	$base = $a->get_baseurl();
	if(! clean_urls())
		$base .= '/?q=';
	return $base;
}

function z_root() {
	global $a;
	return $a->get_baseurl();
}

function absurl($path) {
	if(strpos($path,'/') === 0)
		return z_path() . $path;
	return $path;
}


// Primarily involved with database upgrade, but also sets the 
// base url for use in cmdline programs which don't have
// $_SERVER variables, and synchronising the state of installed plugins.


if(! function_exists('check_config')) {
function check_config(&$a) {

	$build = get_config('system','build');
	if(! x($build))
		$build = set_config('system','build',DB_UPDATE_VERSION);

	$url = get_config('system','url');

	// if the url isn't set or the stored url is radically different 
	// than the currently visited url, store the current value accordingly.
	// "Radically different" ignores common variations such as http vs https 
	// and www.example.com vs example.com.

	if((! x($url)) || (! link_compare($url,$a->get_baseurl())))
		$url = set_config('system','url',$a->get_baseurl());

	if($build != DB_UPDATE_VERSION) {
		$stored = intval($build);
		$current = intval(DB_UPDATE_VERSION);
		if(($stored < $current) && file_exists('update.php')) {

			// We're reporting a different version than what is currently installed.
			// Run any existing update scripts to bring the database up to current.

			require_once('update.php');

			// make sure that boot.php and update.php are the same release, we might be
			// updating right this very second and the correct version of the update.php
			// file may not be here yet. This can happen on a very busy site.

			if(DB_UPDATE_VERSION == UPDATE_VERSION) {

				for($x = $stored; $x < $current; $x ++) {
					if(function_exists('update_' . $x)) {
						$func = 'update_' . $x;
						$func($a);
					}
				}
				set_config('system','build', DB_UPDATE_VERSION);
			}
		}
	}

	/**
	 *
	 * Synchronise plugins:
	 *
	 * $a->config['system']['addon'] contains a comma-separated list of names
	 * of plugins/addons which are used on this system. 
	 * Go through the database list of already installed addons, and if we have
	 * an entry, but it isn't in the config list, call the uninstall procedure
	 * and mark it uninstalled in the database (for now we'll remove it).
	 * Then go through the config list and if we have a plugin that isn't installed,
	 * call the install procedure and add it to the database.
	 *
	 */

	$r = q("SELECT * FROM `addon` WHERE `installed` = 1");
	if(count($r))
		$installed = $r;
	else
		$installed = array();

	$plugins = get_config('system','addon');
	$plugins_arr = array();

	if($plugins)
		$plugins_arr = explode(',',str_replace(' ', '',$plugins));

	$a->plugins = $plugins_arr;

	$installed_arr = array();

	if(count($installed)) {
		foreach($installed as $i) {
			if(! in_array($i['name'],$plugins_arr)) {
				uninstall_plugin($i['name']);
			}
			else
				$installed_arr[] = $i['name'];
		}
	}

	if(count($plugins_arr)) {
		foreach($plugins_arr as $p) {
			if(! in_array($p,$installed_arr)) {
				install_plugin($p);
			}
		}
	}


	load_hooks();

	return;
}}


function get_guid($size=16) {
	$exists = true; // assume by default that we don't have a unique guid
	do {
		$s = random_string($size);
		$r = q("select id from guid where guid = '%s' limit 1", dbesc($s));
		if(! count($r))
			$exists = false;
	} while($exists);
	q("insert into guid ( guid ) values ( '%s' ) ", dbesc($s));
	return $s;
}


// wrapper for adding a login box. If $register == true provide a registration
// link. This will most always depend on the value of $a->config['register_policy'].
// returns the complete html for inserting into the page

if(! function_exists('login')) {
function login($register = false) {
	$o = "";
	$register_tpl = (($register) ? get_markup_template("register-link.tpl") : "");
	
	$register_html = replace_macros($register_tpl,array(
		'$title' => t('Create a New Account'),
		'$desc' => t('Register')
	));

	$noid = get_config('system','no_openid');
	if($noid) {
		$classname = 'no-openid';
		$namelabel = t('Nickname or Email address: ');
		$passlabel = t('Password: ');
		$login     = t('Login');
	}
	else {
		$classname = 'openid';
		$namelabel = t('Nickname/Email/OpenID: ');
		$passlabel = t("Password \x28if not OpenID\x29: ");
		$login     = t('Login');
	}
	$lostpass = t('Forgot your password?');
	$lostlink = t('Password Reset');

	if(local_user()) {
		$tpl = get_markup_template("logout.tpl");
	}
	else {
		$tpl = get_markup_template("login.tpl");

	}

	$o = '<script type="text/javascript"> $(document).ready(function() { $("#login-name").focus();} );</script>';	

	$o .= replace_macros($tpl,array(
		'$logout'        => t('Logout'),
		'$register_html' => $register_html, 
		'$classname'     => $classname,
		'$namelabel'     => $namelabel,
		'$passlabel'     => $passlabel,
		'$login'         => $login,
		'$lostpass'      => $lostpass,
		'$lostlink'      => $lostlink 
	));

	return $o;
}}

// Used to end the current process, after saving session state. 

if(! function_exists('killme')) {
function killme() {
	session_write_close();
	exit;
}}

// redirect to another URL and terminate this process.

if(! function_exists('goaway')) {
function goaway($s) {
	header("Location: $s");
	killme();
}}


// Returns the uid of locally logged in user or false.

if(! function_exists('local_user')) {
function local_user() {
	if((x($_SESSION,'authenticated')) && (x($_SESSION,'uid')))
		return intval($_SESSION['uid']);
	return false;
}}

// Returns contact id of authenticated site visitor or false

if(! function_exists('remote_user')) {
function remote_user() {
	if((x($_SESSION,'authenticated')) && (x($_SESSION,'visitor_id')))
		return intval($_SESSION['visitor_id']);
	return false;
}}

// contents of $s are displayed prominently on the page the next time
// a page is loaded. Usually used for errors or alerts.

if(! function_exists('notice')) {
function notice($s) {
	$a = get_app();
	if($a->interactive)
		$_SESSION['sysmsg'] .= $s;
}}
if(! function_exists('info')) {
function info($s) {
	$a = get_app();
	if($a->interactive)
		$_SESSION['sysmsg_info'] .= $s;
}}


// wrapper around config to limit the text length of an incoming message

if(! function_exists('get_max_import_size')) {
function get_max_import_size() {
	global $a;
	return ((x($a->config,'max_import_size')) ? $a->config['max_import_size'] : 0 );
}}



/**
 *
 * Function : profile_load
 * @parameter App    $a
 * @parameter string $nickname
 * @parameter int    $profile
 *
 * Summary: Loads a profile into the page sidebar. 
 * The function requires a writeable copy of the main App structure, and the nickname
 * of a registered local account.
 *
 * If the viewer is an authenticated remote viewer, the profile displayed is the
 * one that has been configured for his/her viewing in the Contact manager.
 * Passing a non-zero profile ID can also allow a preview of a selected profile
 * by the owner.
 *
 * Profile information is placed in the App structure for later retrieval.
 * Honours the owner's chosen theme for display. 
 *
 */

if(! function_exists('profile_load')) {
function profile_load(&$a, $nickname, $profile = 0) {
	if(remote_user()) {
		$r = q("SELECT `profile-id` FROM `contact` WHERE `id` = %d LIMIT 1",
			intval($_SESSION['visitor_id']));
		if(count($r))
			$profile = $r[0]['profile-id'];
	} 

	$r = null;

	if($profile) {
		$profile_int = intval($profile);
		$r = q("SELECT `profile`.`uid` AS `profile_uid`, `profile`.* , `user`.* FROM `profile` 
			LEFT JOIN `user` ON `profile`.`uid` = `user`.`uid`
			WHERE `user`.`nickname` = '%s' AND `profile`.`id` = %d LIMIT 1",
			dbesc($nickname),
			intval($profile_int)
		);
	}
	if(! count($r)) {	
		$r = q("SELECT `profile`.`uid` AS `profile_uid`, `profile`.* , `user`.* FROM `profile` 
			LEFT JOIN `user` ON `profile`.`uid` = `user`.`uid`
			WHERE `user`.`nickname` = '%s' AND `profile`.`is-default` = 1 LIMIT 1",
			dbesc($nickname)
		);
	}

	if(($r === false) || (! count($r))) {
		notice( t('No profile') . EOL );
		$a->error = 404;
		return;
	}

	$a->profile = $r[0];


	$a->page['title'] = $a->profile['name'] . " @ " . $a->config['sitename'];
	$_SESSION['theme'] = $a->profile['theme'];

	if(! (x($a->page,'aside')))
		$a->page['aside'] = '';

	$block = (((get_config('system','block_public')) && (! local_user()) && (! remote_user())) ? true : false);

	$a->page['aside'] .= profile_sidebar($a->profile, $block);

	if(! $block)
		$a->page['aside'] .= contact_block();

	return;
}}


/**
 *
 * Function: profile_sidebar
 *
 * Formats a profile for display in the sidebar.
 * It is very difficult to templatise the HTML completely
 * because of all the conditional logic.
 *
 * @parameter: array $profile
 *
 * Returns HTML string stuitable for sidebar inclusion
 * Exceptions: Returns empty string if passed $profile is wrong type or not populated
 *
 */


if(! function_exists('profile_sidebar')) {
function profile_sidebar($profile, $block = 0) {

	$a = get_app();

	$o = '';
	$location = '';
	$address = false;

	if((! is_array($profile)) && (! count($profile)))
		return $o;

	call_hooks('profile_sidebar_enter', $profile);

	$fullname = '<div class="fn">' . $profile['name'] . '</div>';

	$pdesc = '<div class="title">' . $profile['pdesc'] . '</div>';

	$tabs = '';

	$photo = '<div id="profile-photo-wrapper"><img class="photo" width="175" height="175" src="' . $profile['photo'] . '" alt="' . $profile['name'] . '" /></div>';

	// don't show connect link to yourself
	$connect = (($profile['uid'] != local_user()) ? '<li><a id="dfrn-request-link" href="dfrn_request/' . $profile['nickname'] . '">' . t('Connect') . '</a></li>' : '');

	// don't show connect link to authenticated visitors either

	if((remote_user()) && ($_SESSION['visitor_visiting'] == $profile['uid']))
		$connect = ''; 

	if((x($profile,'address') == 1) 
		|| (x($profile,'locality') == 1) 
		|| (x($profile,'region') == 1) 
		|| (x($profile,'postal-code') == 1) 
		|| (x($profile,'country-name') == 1))
		$address = true;

	if($address) {
		$location .= '<div class="location"><span class="location-label">' . t('Location:') . '</span> <div class="adr">';
		$location .= ((x($profile,'address') == 1) ? '<div class="street-address">' . $profile['address'] . '</div>' : '');
		$location .= (((x($profile,'locality') == 1) || (x($profile,'region') == 1) || (x($profile,'postal-code') == 1)) 
			? '<span class="city-state-zip"><span class="locality">' . $profile['locality'] . '</span>' 
			. ((x($profile['locality']) == 1) ? t(', ') : '') 
			. '<span class="region">' . $profile['region'] . '</span>'
			. ' <span class="postal-code">' . $profile['postal-code'] . '</span></span>' : '');
		$location .= ((x($profile,'country-name') == 1) ? ' <span class="country-name">' . $profile['country-name'] . '</span>' : '');  
		$location .= '</div></div><div class="profile-clear"></div>';

	}


	$gender = ((x($profile,'gender') == 1) ? '<div class="mf"><span class="gender-label">' . t('Gender:') . '</span> <span class="x-gender">' . $profile['gender'] . '</span></div><div class="profile-clear"></div>' : '');

	$pubkey = ((x($profile,'pubkey') == 1) ? '<div class="key" style="display:none;">' . $profile['pubkey'] . '</div>' : '');

	$marital = ((x($profile,'marital') == 1) ? '<div class="marital"><span class="marital-label"><span class="heart">&hearts;</span> ' . t('Status:') . ' </span><span class="marital-text">' . $profile['marital'] . '</span></div><div class="profile-clear"></div>' : '');

	$homepage = ((x($profile,'homepage') == 1) ? '<div class="homepage"><span class="homepage-label">' . t('Homepage:') . ' </span><span class="homepage-url">' . linkify($profile['homepage']) . '</span></div><div class="profile-clear"></div>' : '');

	if(($profile['hidewall'] || $block) && (! local_user()) && (! remote_user())) {
		$location = $pdesc = $connect = $gender = $marital = $homepage = '';
	}

	$podloc = $a->get_baseurl();
	$searchable = (($profile['publish'] && $profile['net-publish']) ? 'true' : 'false' );
	$nickname = $profile['nickname'];
	$photo300 = $a->get_baseurl() . '/photo/custom/300/' . $profile['uid'] . '.jpg';
	$photo100 = $a->get_baseurl() . '/photo/custom/100/' . $profile['uid'] . '.jpg';
	$photo50  = $a->get_baseurl() . '/photo/custom/50/'  . $profile['uid'] . '.jpg';

	$diaspora_vcard = <<< EOT

<div style="display:none;">
<dl class='entity_nickname'>
<dt>Nickname</dt>
<dd>
<a class="nickname url uid" href="$podloc/" rel="me">$nickname</a>
</dd>
</dl>
<dl class='entity_fn'>
<dt>Full name</dt>
<dd>
<span class='fn'>$fullname</span>
</dd>
</dl>
<dl class="entity_url">
<dt>URL</dt>
<dd>
<a class="url" href="$podloc/" id="pod_location" rel="me">$podloc/</a>
</dd>
</dl>
<dl class="entity_photo">
<dt>Photo</dt>
<dd>
<img class="photo avatar" height="300px" width="300px" src="$photo300">
</dd>
</dl>
<dl class="entity_photo_medium">
<dt>Photo</dt>
<dd> 
<img class="photo avatar" height="100px" width="100px" src="$photo100">
</dd>
</dl>
<dl class="entity_photo_small">
<dt>Photo</dt>
<dd>
<img class="photo avatar" height="50px" width="50px" src="$photo50">
</dd>
</dl>
<dl class="entity_searchable">
<dt>Searchable</dt>
<dd>
<span class="searchable">$searchable</span>
</dd>
</dl>
</div>
EOT;

	$tpl = get_markup_template('profile_vcard.tpl');

	$o .= replace_macros($tpl, array(
		'$fullname' => $fullname,
		'$pdesc'    => $pdesc,
		'$tabs'     => $tabs,
		'$photo'    => $photo,
		'$connect'  => $connect,		
		'$location' => $location,
		'$gender'   => $gender,
		'$pubkey'   => $pubkey,
		'$marital'  => $marital,
		'$homepage' => $homepage,
		'$diaspora' => $diaspora_vcard
	));


	$arr = array('profile' => &$profile, 'entry' => &$o);

	call_hooks('profile_sidebar', $arr);

	return $o;
}}


if(! function_exists('get_birthdays')) {
function get_birthdays() {

	$a = get_app();
	$o = '';

	if(! local_user())
		return $o;

	$bd_format = t('g A l F d') ; // 8 AM Friday January 18

	$r = q("SELECT `event`.*, `event`.`id` AS `eid`, `contact`.* FROM `event` 
		LEFT JOIN `contact` ON `contact`.`id` = `event`.`cid` 
		WHERE `event`.`uid` = %d AND `type` = 'birthday' AND `start` < '%s' AND `finish` > '%s' 
		ORDER BY `start` DESC ",
		intval(local_user()),
		dbesc(datetime_convert('UTC','UTC','now + 6 days')),
		dbesc(datetime_convert('UTC','UTC','now'))
	);

	if($r && count($r)) {
		$total = 0;
		foreach($r as $rr)
			if(strlen($rr['name']))
				$total ++;

		if($total) {
			$o .= '<div id="birthday-notice" class="birthday-notice fakelink" onclick=openClose(\'birthday-wrapper\'); >' . t('Birthday Reminders') . ' ' . '(' . $total . ')' . '</div>'; 
			$o .= '<div id="birthday-wrapper" style="display: none;" ><div id="birthday-title">' . t('Birthdays this week:') . '</div>'; 
			$o .= '<div id="birthday-adjust">' . t("\x28Adjusted for local time\x29") . '</div>';
			$o .= '<div id="birthday-title-end"></div>';

			foreach($r as $rr) {
				if(! strlen($rr['name']))
					continue;
				$now = strtotime('now');
				$today = (((strtotime($rr['start'] . ' +00:00') < $now) && (strtotime($rr['finish'] . ' +00:00') > $now)) ? true : false); 
	
				$o .= '<div class="birthday-list" id="birthday-' . $rr['eid'] . '"><a class="sparkle" href="' 
				. $a->get_baseurl() . '/redir/'  . $rr['cid'] . '">' . $rr['name'] . '</a> ' 
				. day_translate(datetime_convert('UTC', $a->timezone, $rr['start'], $bd_format)) . (($today) ?  ' ' . t('[today]') : '')
				. '</div>' ;
			}
			$o .= '</div></div>';
		}
	}
	return $o;
}}


/**
 * 
 * Wrap calls to proc_close(proc_open()) and call hook
 * so plugins can take part in process :)
 * 
 * args:
 * $cmd program to run
 *  next args are passed as $cmd command line
 * 
 * e.g.: proc_run("ls","-la","/tmp");
 * 
 * $cmd and string args are surrounded with ""
 */

if(! function_exists('proc_run')) {
function proc_run($cmd){

	$a = get_app();

	$args = func_get_args();
	$arr = array('args' => $args, 'run_cmd' => true);

	call_hooks("proc_run", $arr);
	if(! $arr['run_cmd'])
		return;

	if(count($args) && $args[0] === 'php')
        $args[0] = ((x($a->config,'php_path')) && (strlen($a->config['php_path'])) ? $a->config['php_path'] : 'php');
	foreach ($args as $arg){
		$arg = escapeshellarg($arg);
	}
	$cmdline = implode($args," ");
	proc_close(proc_open($cmdline." &",array(),$foo));
}}

if(! function_exists('current_theme')) {
function current_theme(){
	$app_base_themes = array('duepuntozero', 'loozah');
	
	$a = get_app();
	
	$system_theme = ((isset($a->config['system']['theme'])) ? $a->config['system']['theme'] : '');
	$theme_name = ((is_array($_SESSION) && x($_SESSION,'theme')) ? $_SESSION['theme'] : $system_theme);
	
	if($theme_name && file_exists('view/theme/' . $theme_name . '/style.css'))
		return($theme_name);
	
	foreach($app_base_themes as $t) {
		if(file_exists('view/theme/' . $t . '/style.css'))
			return($t);
	}
	
	$fallback = glob('view/theme/*/style.css');
	if(count($fallback))
		return (str_replace('view/theme/','', str_replace("/style.css","",$fallback[0])));

}}

/*
* Return full URL to theme which is currently in effect.
* Provide a sane default if nothing is chosen or the specified theme does not exist.
*/
if(! function_exists('current_theme_url')) {
function current_theme_url() {
	global $a;
	$t = current_theme();
	return($a->get_baseurl() . '/view/theme/' . $t . '/style.css');
}}

if(! function_exists('feed_birthday')) {
function feed_birthday($uid,$tz) {

	/**
	 *
	 * Determine the next birthday, but only if the birthday is published
	 * in the default profile. We _could_ also look for a private profile that the
	 * recipient can see, but somebody could get mad at us if they start getting
	 * public birthday greetings when they haven't made this info public. 
	 *
	 * Assuming we are able to publish this info, we are then going to convert
	 * the start time from the owner's timezone to UTC. 
	 *
	 * This will potentially solve the problem found with some social networks
	 * where birthdays are converted to the viewer's timezone and salutations from
	 * elsewhere in the world show up on the wrong day. We will convert it to the
	 * viewer's timezone also, but first we are going to convert it from the birthday
	 * person's timezone to GMT - so the viewer may find the birthday starting at
	 * 6:00PM the day before, but that will correspond to midnight to the birthday person.
	 *
	 */

	$birthday = '';

	$p = q("SELECT `dob` FROM `profile` WHERE `is-default` = 1 AND `uid` = %d LIMIT 1",
		intval($uid)
	);

	if($p && count($p)) {
		$tmp_dob = substr($p[0]['dob'],5);
		if(intval($tmp_dob)) {
			$y = datetime_convert($tz,$tz,'now','Y');
			$bd = $y . '-' . $tmp_dob . ' 00:00';
			$t_dob = strtotime($bd);
			$now = strtotime(datetime_convert($tz,$tz,'now'));
			if($t_dob < $now)
				$bd = $y + 1 . '-' . $tmp_dob . ' 00:00';
			$birthday = datetime_convert($tz,'UTC',$bd,ATOM_TIME); 
		}
	}

	return $birthday;
}}

if(! function_exists('is_site_admin')) {
function is_site_admin() {
	$a = get_app();
	if(local_user() && x($a->user,'email') && x($a->config,'admin_email') && ($a->user['email'] === $a->config['admin_email']))
		return true;
	return false;
}}


if(! function_exists('load_contact_links')) {
function load_contact_links($uid) {

	$a = get_app();

	$ret = array();

	if(! $uid || x($a->contacts,'empty'))
		return;

	$r = q("SELECT `id`,`network`,`url`,`thumb` FROM `contact` WHERE `uid` = %d AND `self` = 0 AND `blocked` = 0 ",
			intval($uid)
	);
	if(count($r)) {
		foreach($r as $rr){
			$url = normalise_link($rr['url']);
			$ret[$url] = $rr;
		}
	}
	else 
		$ret['empty'] = true;	
	$a->contacts = $ret;
	return;		
}}
