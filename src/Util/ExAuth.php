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

namespace Friendica\Util;

use Friendica\Core\Config;
use Friendica\Core\PConfig;
use Friendica\Database\DBM;

require_once 'include/dba.php';

class ExAuth
{
	private $bDebug;

	/**
	 * @brief Create the class
	 *
	 * @param boolean $bDebug Debug mode
	 */
	public function __construct()
	{
		$this->bDebug = (int) Config::get('jabber', 'debug');

		openlog('auth_ejabberd', LOG_PID, LOG_USER);

		$this->writeLog(LOG_NOTICE, 'start');
	}

	/**
	 * @brief Standard input reading function, executes the auth with the provided
	 * parameters
	 *
	 * @return null
	 */
	public function readStdin()
	{
		while (!feof(STDIN)) {
			// Quit if the database connection went down
			if (!dba::connected()) {
				$this->writeLog(LOG_ERR, 'the database connection went down');
				return;
			}

			$iHeader = fgets(STDIN, 3);
			$aLength = unpack('n', $iHeader);
			$iLength = $aLength['1'];

			// No data? Then quit
			if ($iLength == 0) {
				$this->writeLog(LOG_ERR, 'we got no data, quitting');
				return;
			}

			// Fetching the data
			$sData = fgets(STDIN, $iLength + 1);
			$this->writeLog(LOG_DEBUG, 'received data: ' . $sData);
			$aCommand = explode(':', $sData);
			if (is_array($aCommand)) {
				switch ($aCommand[0]) {
					case 'isuser':
						// Check the existance of a given username
						$this->isUser($aCommand);
						break;
					case 'auth':
						// Check if the givven password is correct
						$this->auth($aCommand);
						break;
					case 'setpass':
						// We don't accept the setting of passwords here
						$this->writeLog(LOG_NOTICE, 'setpass command disabled');
						fwrite(STDOUT, pack('nn', 2, 0));
						break;
					default:
						// We don't know the given command
						$this->writeLog(LOG_NOTICE, 'unknown command ' . $aCommand[0]);
						fwrite(STDOUT, pack('nn', 2, 0));
						break;
				}
			} else {
				$this->writeLog(LOG_NOTICE, 'invalid command string ' . $sData);
				fwrite(STDOUT, pack('nn', 2, 0));
			}
		}
	}

	/**
	 * @brief Check if the given username exists
	 *
	 * @param array $aCommand The command array
	 */
	private function isUser(array $aCommand)
	{
		$a = get_app();

		// Check if there is a username
		if (!isset($aCommand[1])) {
			$this->writeLog(LOG_NOTICE, 'invalid isuser command, no username given');
			fwrite(STDOUT, pack('nn', 2, 0));
			return;
		}

		// Now we check if the given user is valid
		$sUser = str_replace(array('%20', '(a)'), array(' ', '@'), $aCommand[1]);

		// Does the hostname match? So we try directly
		if ($a->get_hostname() == $aCommand[2]) {
			$this->writeLog(LOG_INFO, 'internal user check for ' . $sUser . '@' . $aCommand[2]);
			$found = dba::exists('user', ['nickname' => $sUser]);
		} else {
			$found = false;
		}

		// If the hostnames doesn't match or there is some failure, we try to check remotely
		if (!$found) {
			$found = $this->checkUser($aCommand[2], $aCommand[1], true);
		}

		if ($found) {
			// The user is okay
			$this->writeLog(LOG_NOTICE, 'valid user: ' . $sUser);
			fwrite(STDOUT, pack('nn', 2, 1));
		} else {
			// The user isn't okay
			$this->writeLog(LOG_WARNING, 'invalid user: ' . $sUser);
			fwrite(STDOUT, pack('nn', 2, 0));
		}
	}

	/**
	 * @brief Check remote user existance via HTTP(S)
	 *
	 * @param string $host The hostname
	 * @param string $user Username
	 * @param boolean $ssl Should the check be done via SSL?
	 *
	 * @return boolean Was the user found?
	 */
	private function checkUser($host, $user, $ssl)
	{
		$this->writeLog(LOG_INFO, 'external user check for ' . $user . '@' . $host);

		$url = ($ssl ? 'https' : 'http') . '://' . $host . '/noscrape/' . $user;

		$data = z_fetch_url($url);

		if (!is_array($data)) {
			return false;
		}

		if ($data['return_code'] != '200') {
			return false;
		}

		$json = @json_decode($data['body']);
		if (!is_object($json)) {
			return false;
		}

		return $json->nick == $user;
	}

	/**
	 * @brief Authenticate the given user and password
	 *
	 * @param array $aCommand The command array
	 */
	private function auth(array $aCommand)
	{
		$a = get_app();

		// check user authentication
		if (sizeof($aCommand) != 4) {
			$this->writeLog(LOG_NOTICE, 'invalid auth command, data missing');
			fwrite(STDOUT, pack('nn', 2, 0));
			return;
		}

		// We now check if the password match
		$sUser = str_replace(array('%20', '(a)'), array(' ', '@'), $aCommand[1]);

		// Does the hostname match? So we try directly
		if ($a->get_hostname() == $aCommand[2]) {
			$this->writeLog(LOG_INFO, 'internal auth for ' . $sUser . '@' . $aCommand[2]);

			$aUser = dba::select('user', ['uid', 'password'], ['nickname' => $sUser], ['limit' => 1]);
			if (DBM::is_result($aUser)) {
				$uid = $aUser['uid'];
				$Error = $aUser['password'] != hash('whirlpool', $aCommand[3]);
			} else {
				$this->writeLog(LOG_WARNING, 'user not found: ' . $sUser);
				$Error = true;
				$uid = -1;
			}
			if ($Error) {
				$this->writeLog(LOG_INFO, 'check against alternate password for ' . $sUser . '@' . $aCommand[2]);
				$sPassword = PConfig::get($uid, 'xmpp', 'password');
				$Error = ($aCommand[3] != $sPassword);
			}
		} else {
			$Error = true;
		}

		// If the hostnames doesn't match or there is some failure, we try to check remotely
		if ($Error) {
			$Error = !$this->checkCredentials($aCommand[2], $aCommand[1], $aCommand[3], true);
		}

		if ($Error) {
			$this->writeLog(LOG_WARNING, 'authentification failed for user ' . $sUser . '@' . $aCommand[2]);
			fwrite(STDOUT, pack('nn', 2, 0));
		} else {
			$this->writeLog(LOG_NOTICE, 'authentificated user ' . $sUser . '@' . $aCommand[2]);
			fwrite(STDOUT, pack('nn', 2, 1));
		}
	}

	/**
	 * @brief Check remote credentials via HTTP(S)
	 *
	 * @param string $host The hostname
	 * @param string $user Username
	 * @param string $password Password
	 * @param boolean $ssl Should the check be done via SSL?
	 *
	 * @return boolean Are the credentials okay?
	 */
	private function checkCredentials($host, $user, $password, $ssl)
	{
		$url = ($ssl ? 'https' : 'http') . '://' . $host . '/api/account/verify_credentials.json';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $password);

		curl_exec($ch);
		$curl_info = @curl_getinfo($ch);
		$http_code = $curl_info['http_code'];
		curl_close($ch);

		$this->writeLog(LOG_INFO, 'external auth for ' . $user . '@' . $host . ' returned ' . $http_code);

		return $http_code == 200;
	}

	/**
	 * @brief write data to the syslog
	 *
	 * @param integer $loglevel The syslog loglevel
	 * @param string $sMessage The syslog message
	 */
	private function writeLog($loglevel, $sMessage)
	{
		if (!$this->bDebug && ($loglevel >= LOG_DEBUG)) {
			return;
		}
		syslog($loglevel, $sMessage);
	}

	/**
	 * @brief destroy the class, close the syslog connection.
	 */
	public function __destruct()
	{
		$this->writeLog(LOG_NOTICE, 'stop');
		closelog();
	}
}
