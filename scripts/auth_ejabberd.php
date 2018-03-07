#!/usr/bin/env php
<?php
/*
 * ejabberd extauth script for the integration with friendica
 *
 * Originally written for joomla by Dalibor Karlovic <dado@krizevci.info>
 * modified for Friendica by Michael Vogel <icarus@dabo.de>
 * published under GPL
 *
 * Latest version of the original script for joomla is available at:
 * http://87.230.15.86/~dado/ejabberd/joomla-login
 *
 * Installation:
 *
 * 	- Change it's owner to whichever user is running the server, ie. ejabberd
 * 	  $ chown ejabberd:ejabberd /path/to/friendica/scripts/auth_ejabberd.php
 *
 * 	- Change the access mode so it is readable only to the user ejabberd and has exec
 * 	  $ chmod 700 /path/to/friendica/scripts/auth_ejabberd.php
 *
 * 	- Edit your ejabberd.cfg file, comment out your auth_method and add:
 * 	  {auth_method, external}.
 * 	  {extauth_program, "/path/to/friendica/script/auth_ejabberd.php"}.
 *
 * 	- Restart your ejabberd service, you should be able to login with your friendica auth info
 *
 * Other hints:
 * 	- if your users have a space or a @ in their nickname, they'll run into trouble
 * 	  registering with any client so they should be instructed to replace these chars
 * 	  " " (space) is replaced with "%20"
 * 	  "@" is replaced with "(a)"
 *
 */

use Friendica\App;
use Friendica\BaseObject;
use Friendica\Core\Config;
use Friendica\Util\ExAuth;

if (sizeof($_SERVER["argv"]) == 0) {
	die();
}

$directory = dirname($_SERVER["argv"][0]);

if (substr($directory, 0, 1) != DIRECTORY_SEPARATOR) {
	$directory = $_SERVER["PWD"] . DIRECTORY_SEPARATOR . $directory;
}

$directory = realpath($directory . DIRECTORY_SEPARATOR . "..");

chdir($directory);

require_once "boot.php";
require_once "include/dba.php";

$a = new App(dirname(__DIR__));
BaseObject::setApp($a);

@include ".htconfig.php";
dba::connect($db_host, $db_user, $db_pass, $db_data);
unset($db_host, $db_user, $db_pass, $db_data);

Config::load();

$oAuth = new ExAuth();

$oAuth->readStdin();
