<?php
/**
 * @file src/Model/User.php
 * @brief This file includes the User class with user related database functions
 */
namespace Friendica\Model;

use DivineOmega\PasswordExposed\PasswordStatus;
use Exception;
use Friendica\Core\Addon;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\Object\Image;
use Friendica\Util\Crypto;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Network;
use LightOpenID;
use function password_exposed;

require_once 'boot.php';
require_once 'include/dba.php';
require_once 'include/enotify.php';
require_once 'include/text.php';
/**
 * @brief This class handles User related functions
 */
class User
{
	/**
	 * @brief Get owner data by user id
	 *
	 * @param int $uid
	 * @return boolean|array
	 */
	public static function getOwnerDataById($uid) {
		$r = DBA::fetchFirst("SELECT
			`contact`.*,
			`user`.`prvkey` AS `uprvkey`,
			`user`.`timezone`,
			`user`.`nickname`,
			`user`.`sprvkey`,
			`user`.`spubkey`,
			`user`.`page-flags`,
			`user`.`account-type`,
			`user`.`prvnets`
			FROM `contact`
			INNER JOIN `user`
				ON `user`.`uid` = `contact`.`uid`
			WHERE `contact`.`uid` = ?
			AND `contact`.`self`
			LIMIT 1",
			$uid
		);
		if (!DBA::isResult($r)) {
			return false;
		}
		return $r;
	}

	/**
	 * @brief Get owner data by nick name
	 *
	 * @param int $nick
	 * @return boolean|array
	 */
	public static function getOwnerDataByNick($nick)
	{
		$user = DBA::selectFirst('user', ['uid'], ['nickname' => $nick]);

		if (!DBA::isResult($user)) {
			return false;
		}

		return self::getOwnerDataById($user['uid']);
	}

	/**
	 * @brief Returns the default group for a given user and network
	 *
	 * @param int $uid User id
	 * @param string $network network name
	 *
	 * @return int group id
	 */
	public static function getDefaultGroup($uid, $network = '')
	{
		$default_group = 0;

		if ($network == Protocol::OSTATUS) {
			$default_group = PConfig::get($uid, "ostatus", "default_group");
		}

		if ($default_group != 0) {
			return $default_group;
		}

		$user = DBA::selectFirst('user', ['def_gid'], ['uid' => $uid]);

		if (DBA::isResult($user)) {
			$default_group = $user["def_gid"];
		}

		return $default_group;
	}


	/**
	 * Authenticate a user with a clear text password
	 *
	 * @brief Authenticate a user with a clear text password
	 * @param mixed $user_info
	 * @param string $password
	 * @return int|boolean
	 * @deprecated since version 3.6
	 * @see User::getIdFromPasswordAuthentication()
	 */
	public static function authenticate($user_info, $password)
	{
		try {
			return self::getIdFromPasswordAuthentication($user_info, $password);
		} catch (Exception $ex) {
			return false;
		}
	}

	/**
	 * Returns the user id associated with a successful password authentication
	 *
	 * @brief Authenticate a user with a clear text password
	 * @param mixed $user_info
	 * @param string $password
	 * @return int User Id if authentication is successful
	 * @throws Exception
	 */
	public static function getIdFromPasswordAuthentication($user_info, $password)
	{
		$user = self::getAuthenticationInfo($user_info);

		if (strpos($user['password'], '$') === false) {
			//Legacy hash that has not been replaced by a new hash yet
			if (self::hashPasswordLegacy($password) === $user['password']) {
				self::updatePassword($user['uid'], $password);

				return $user['uid'];
			}
		} elseif (!empty($user['legacy_password'])) {
			//Legacy hash that has been double-hashed and not replaced by a new hash yet
			//Warning: `legacy_password` is not necessary in sync with the content of `password`
			if (password_verify(self::hashPasswordLegacy($password), $user['password'])) {
				self::updatePassword($user['uid'], $password);

				return $user['uid'];
			}
		} elseif (password_verify($password, $user['password'])) {
			//New password hash
			if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
				self::updatePassword($user['uid'], $password);
			}

			return $user['uid'];
		}

		throw new Exception(L10n::t('Login failed'));
	}

	/**
	 * Returns authentication info from various parameters types
	 *
	 * User info can be any of the following:
	 * - User DB object
	 * - User Id
	 * - User email or username or nickname
	 * - User array with at least the uid and the hashed password
	 *
	 * @param mixed $user_info
	 * @return array
	 * @throws Exception
	 */
	private static function getAuthenticationInfo($user_info)
	{
		$user = null;

		if (is_object($user_info) || is_array($user_info)) {
			if (is_object($user_info)) {
				$user = (array) $user_info;
			} else {
				$user = $user_info;
			}

			if (!isset($user['uid'])
				|| !isset($user['password'])
				|| !isset($user['legacy_password'])
			) {
				throw new Exception(L10n::t('Not enough information to authenticate'));
			}
		} elseif (is_int($user_info) || is_string($user_info)) {
			if (is_int($user_info)) {
				$user = DBA::selectFirst('user', ['uid', 'password', 'legacy_password'],
					[
						'uid' => $user_info,
						'blocked' => 0,
						'account_expired' => 0,
						'account_removed' => 0,
						'verified' => 1
					]
				);
			} else {
				$fields = ['uid', 'password', 'legacy_password'];
				$condition = ["(`email` = ? OR `username` = ? OR `nickname` = ?)
					AND NOT `blocked` AND NOT `account_expired` AND NOT `account_removed` AND `verified`",
					$user_info, $user_info, $user_info];
				$user = DBA::selectFirst('user', $fields, $condition);
			}

			if (!DBA::isResult($user)) {
				throw new Exception(L10n::t('User not found'));
			}
		}

		return $user;
	}

	/**
	 * Generates a human-readable random password
	 *
	 * @return string
	 */
	public static function generateNewPassword()
	{
		return autoname(6) . mt_rand(100, 9999);
	}

	/**
	 * Checks if the provided plaintext password has been exposed or not
	 *
	 * @param string $password
	 * @return bool
	 */
	public static function isPasswordExposed($password)
	{
		return password_exposed($password) === PasswordStatus::EXPOSED;
	}

	/**
	 * Legacy hashing function, kept for password migration purposes
	 *
	 * @param string $password
	 * @return string
	 */
	private static function hashPasswordLegacy($password)
	{
		return hash('whirlpool', $password);
	}

	/**
	 * Global user password hashing function
	 *
	 * @param string $password
	 * @return string
	 */
	public static function hashPassword($password)
	{
		if (!trim($password)) {
			throw new Exception(L10n::t('Password can\'t be empty'));
		}

		return password_hash($password, PASSWORD_DEFAULT);
	}

	/**
	 * Updates a user row with a new plaintext password
	 *
	 * @param int    $uid
	 * @param string $password
	 * @return bool
	 */
	public static function updatePassword($uid, $password)
	{
		return self::updatePasswordHashed($uid, self::hashPassword($password));
	}

	/**
	 * Updates a user row with a new hashed password.
	 * Empties the password reset token field just in case.
	 *
	 * @param int    $uid
	 * @param string $pasword_hashed
	 * @return bool
	 */
	private static function updatePasswordHashed($uid, $pasword_hashed)
	{
		$fields = [
			'password' => $pasword_hashed,
			'pwdreset' => null,
			'pwdreset_time' => null,
			'legacy_password' => false
		];
		return DBA::update('user', $fields, ['uid' => $uid]);
	}

	/**
	 * @brief Checks if a nickname is in the list of the forbidden nicknames
	 *
	 * Check if a nickname is forbidden from registration on the node by the
	 * admin. Forbidden nicknames (e.g. role namess) can be configured in the
	 * admin panel.
	 *
	 * @param string $nickname The nickname that should be checked
	 * @return boolean True is the nickname is blocked on the node
	 */
	public static function isNicknameBlocked($nickname)
	{
		$forbidden_nicknames = Config::get('system', 'forbidden_nicknames', '');

		// if the config variable is empty return false
		if (empty($forbidden_nicknames)) {
			return false;
		}

		// check if the nickname is in the list of blocked nicknames
		$forbidden = explode(',', $forbidden_nicknames);
		$forbidden = array_map('trim', $forbidden);
		if (in_array(strtolower($nickname), $forbidden)) {
			return true;
		}

		// else return false
		return false;
	}

	/**
	 * @brief Catch-all user creation function
	 *
	 * Creates a user from the provided data array, either form fields or OpenID.
	 * Required: { username, nickname, email } or { openid_url }
	 *
	 * Performs the following:
	 * - Sends to the OpenId auth URL (if relevant)
	 * - Creates new key pairs for crypto
	 * - Create self-contact
	 * - Create profile image
	 *
	 * @param array $data
	 * @return string
	 * @throw Exception
	 */
	public static function create(array $data)
	{
		$a = get_app();
		$return = ['user' => null, 'password' => ''];

		$using_invites = Config::get('system', 'invitation_only');
		$num_invites   = Config::get('system', 'number_invites');

		$invite_id  = !empty($data['invite_id'])  ? notags(trim($data['invite_id']))  : '';
		$username   = !empty($data['username'])   ? notags(trim($data['username']))   : '';
		$nickname   = !empty($data['nickname'])   ? notags(trim($data['nickname']))   : '';
		$email      = !empty($data['email'])      ? notags(trim($data['email']))      : '';
		$openid_url = !empty($data['openid_url']) ? notags(trim($data['openid_url'])) : '';
		$photo      = !empty($data['photo'])      ? notags(trim($data['photo']))      : '';
		$password   = !empty($data['password'])   ? trim($data['password'])           : '';
		$password1  = !empty($data['password1'])  ? trim($data['password1'])          : '';
		$confirm    = !empty($data['confirm'])    ? trim($data['confirm'])            : '';
		$blocked    = !empty($data['blocked'])    ? intval($data['blocked'])          : 0;
		$verified   = !empty($data['verified'])   ? intval($data['verified'])         : 0;
		$language   = !empty($data['language'])   ? notags(trim($data['language']))   : 'en';

		$publish = !empty($data['profile_publish_reg']) && intval($data['profile_publish_reg']) ? 1 : 0;
		$netpublish = strlen(Config::get('system', 'directory')) ? $publish : 0;

		if ($password1 != $confirm) {
			throw new Exception(L10n::t('Passwords do not match. Password unchanged.'));
		} elseif ($password1 != '') {
			$password = $password1;
		}

		if ($using_invites) {
			if (!$invite_id) {
				throw new Exception(L10n::t('An invitation is required.'));
			}

			if (!DBA::exists('register', ['hash' => $invite_id])) {
				throw new Exception(L10n::t('Invitation could not be verified.'));
			}
		}

		if (empty($username) || empty($email) || empty($nickname)) {
			if ($openid_url) {
				if (!Network::isUrlValid($openid_url)) {
					throw new Exception(L10n::t('Invalid OpenID url'));
				}
				$_SESSION['register'] = 1;
				$_SESSION['openid'] = $openid_url;

				$openid = new LightOpenID($a->get_hostname());
				$openid->identity = $openid_url;
				$openid->returnUrl = System::baseUrl() . '/openid';
				$openid->required = ['namePerson/friendly', 'contact/email', 'namePerson'];
				$openid->optional = ['namePerson/first', 'media/image/aspect11', 'media/image/default'];
				try {
					$authurl = $openid->authUrl();
				} catch (Exception $e) {
					throw new Exception(L10n::t('We encountered a problem while logging in with the OpenID you provided. Please check the correct spelling of the ID.') . EOL . EOL . L10n::t('The error message was:') . $e->getMessage(), 0, $e);
				}
				goaway($authurl);
				// NOTREACHED
			}

			throw new Exception(L10n::t('Please enter the required information.'));
		}

		if (!Network::isUrlValid($openid_url)) {
			$openid_url = '';
		}

		$err = '';

		// collapse multiple spaces in name
		$username = preg_replace('/ +/', ' ', $username);

		if (mb_strlen($username) > 48) {
			throw new Exception(L10n::t('Please use a shorter name.'));
		}
		if (mb_strlen($username) < 3) {
			throw new Exception(L10n::t('Name too short.'));
		}

		// So now we are just looking for a space in the full name.
		$loose_reg = Config::get('system', 'no_regfullname');
		if (!$loose_reg) {
			$username = mb_convert_case($username, MB_CASE_TITLE, 'UTF-8');
			if (!strpos($username, ' ')) {
				throw new Exception(L10n::t("That doesn't appear to be your full \x28First Last\x29 name."));
			}
		}

		if (!Network::isEmailDomainAllowed($email)) {
			throw new Exception(L10n::t('Your email domain is not among those allowed on this site.'));
		}

		if (!valid_email($email) || !Network::isEmailDomainValid($email)) {
			throw new Exception(L10n::t('Not a valid email address.'));
		}
		if (self::isNicknameBlocked($nickname)) {
			throw new Exception(L10n::t('The nickname was blocked from registration by the nodes admin.'));
		}

		if (Config::get('system', 'block_extended_register', false) && DBA::exists('user', ['email' => $email])) {
			throw new Exception(L10n::t('Cannot use that email.'));
		}

		// Disallow somebody creating an account using openid that uses the admin email address,
		// since openid bypasses email verification. We'll allow it if there is not yet an admin account.
		if (Config::get('config', 'admin_email') && strlen($openid_url)) {
			$adminlist = explode(',', str_replace(' ', '', strtolower(Config::get('config', 'admin_email'))));
			if (in_array(strtolower($email), $adminlist)) {
				throw new Exception(L10n::t('Cannot use that email.'));
			}
		}

		$nickname = $data['nickname'] = strtolower($nickname);

		if (!preg_match('/^[a-z0-9][a-z0-9\_]*$/', $nickname)) {
			throw new Exception(L10n::t('Your nickname can only contain a-z, 0-9 and _.'));
		}

		// Check existing and deleted accounts for this nickname.
		if (DBA::exists('user', ['nickname' => $nickname])
			|| DBA::exists('userd', ['username' => $nickname])
		) {
			throw new Exception(L10n::t('Nickname is already registered. Please choose another.'));
		}

		$new_password = strlen($password) ? $password : User::generateNewPassword();
		$new_password_encoded = self::hashPassword($new_password);

		$return['password'] = $new_password;

		$keys = Crypto::newKeypair(4096);
		if ($keys === false) {
			throw new Exception(L10n::t('SERIOUS ERROR: Generation of security keys failed.'));
		}

		$prvkey = $keys['prvkey'];
		$pubkey = $keys['pubkey'];

		// Create another keypair for signing/verifying salmon protocol messages.
		$sres = Crypto::newKeypair(512);
		$sprvkey = $sres['prvkey'];
		$spubkey = $sres['pubkey'];

		$insert_result = DBA::insert('user', [
			'guid'     => System::createGUID(32),
			'username' => $username,
			'password' => $new_password_encoded,
			'email'    => $email,
			'openid'   => $openid_url,
			'nickname' => $nickname,
			'pubkey'   => $pubkey,
			'prvkey'   => $prvkey,
			'spubkey'  => $spubkey,
			'sprvkey'  => $sprvkey,
			'verified' => $verified,
			'blocked'  => $blocked,
			'language' => $language,
			'timezone' => 'UTC',
			'register_date' => DateTimeFormat::utcNow(),
			'default-location' => ''
		]);

		if ($insert_result) {
			$uid = DBA::lastInsertId();
			$user = DBA::selectFirst('user', [], ['uid' => $uid]);
		} else {
			throw new Exception(L10n::t('An error occurred during registration. Please try again.'));
		}

		if (!$uid) {
			throw new Exception(L10n::t('An error occurred during registration. Please try again.'));
		}

		// if somebody clicked submit twice very quickly, they could end up with two accounts
		// due to race condition. Remove this one.
		$user_count = DBA::count('user', ['nickname' => $nickname]);
		if ($user_count > 1) {
			DBA::delete('user', ['uid' => $uid]);

			throw new Exception(L10n::t('Nickname is already registered. Please choose another.'));
		}

		$insert_result = DBA::insert('profile', [
			'uid' => $uid,
			'name' => $username,
			'photo' => System::baseUrl() . "/photo/profile/{$uid}.jpg",
			'thumb' => System::baseUrl() . "/photo/avatar/{$uid}.jpg",
			'publish' => $publish,
			'is-default' => 1,
			'net-publish' => $netpublish,
			'profile-name' => L10n::t('default')
		]);
		if (!$insert_result) {
			DBA::delete('user', ['uid' => $uid]);

			throw new Exception(L10n::t('An error occurred creating your default profile. Please try again.'));
		}

		// Create the self contact
		if (!Contact::createSelfFromUserId($uid)) {
			DBA::delete('user', ['uid' => $uid]);

			throw new Exception(L10n::t('An error occurred creating your self contact. Please try again.'));
		}

		// Create a group with no members. This allows somebody to use it
		// right away as a default group for new contacts.
		$def_gid = Group::create($uid, L10n::t('Friends'));
		if (!$def_gid) {
			DBA::delete('user', ['uid' => $uid]);

			throw new Exception(L10n::t('An error occurred creating your default contact group. Please try again.'));
		}

		$fields = ['def_gid' => $def_gid];
		if (Config::get('system', 'newuser_private') && $def_gid) {
			$fields['allow_gid'] = '<' . $def_gid . '>';
		}

		DBA::update('user', $fields, ['uid' => $uid]);

		// if we have no OpenID photo try to look up an avatar
		if (!strlen($photo)) {
			$photo = Network::lookupAvatarByEmail($email);
		}

		// unless there is no avatar-addon loaded
		if (strlen($photo)) {
			$photo_failure = false;

			$filename = basename($photo);
			$img_str = Network::fetchUrl($photo, true);
			// guess mimetype from headers or filename
			$type = Image::guessType($photo, true);

			$Image = new Image($img_str, $type);
			if ($Image->isValid()) {
				$Image->scaleToSquare(175);

				$hash = Photo::newResource();

				$r = Photo::store($Image, $uid, 0, $hash, $filename, L10n::t('Profile Photos'), 4);

				if ($r === false) {
					$photo_failure = true;
				}

				$Image->scaleDown(80);

				$r = Photo::store($Image, $uid, 0, $hash, $filename, L10n::t('Profile Photos'), 5);

				if ($r === false) {
					$photo_failure = true;
				}

				$Image->scaleDown(48);

				$r = Photo::store($Image, $uid, 0, $hash, $filename, L10n::t('Profile Photos'), 6);

				if ($r === false) {
					$photo_failure = true;
				}

				if (!$photo_failure) {
					DBA::update('photo', ['profile' => 1], ['resource-id' => $hash]);
				}
			}
		}

		Addon::callHooks('register_account', $uid);

		$return['user'] = $user;
		return $return;
	}

	/**
	 * @brief Sends pending registration confiŕmation email
	 *
	 * @param string $email
	 * @param string $sitename
	 * @param string $username
	 * @return NULL|boolean from notification() and email() inherited
	 */
	public static function sendRegisterPendingEmail($email, $sitename, $username)
	{
		$body = deindent(L10n::t('
			Dear %1$s,
				Thank you for registering at %2$s. Your account is pending for approval by the administrator.
		'));

		$body = sprintf($body, $username, $sitename);

		return notification([
			'type' => SYSTEM_EMAIL,
			'to_email' => $email,
			'subject'=> L10n::t('Registration at %s', $sitename),
			'body' => $body]);
	}

	/**
	 * @brief Sends registration confirmation
	 *
	 * It's here as a function because the mail is sent from different parts
	 *
	 * @param string $email
	 * @param string $sitename
	 * @param string $siteurl
	 * @param string $username
	 * @param string $password
	 * @return NULL|boolean from notification() and email() inherited
	 */
	public static function sendRegisterOpenEmail($email, $sitename, $siteurl, $username, $password, $user)
	{
		$preamble = deindent(L10n::t('
			Dear %1$s,
				Thank you for registering at %2$s. Your account has been created.
		'));
		$body = deindent(L10n::t('
			The login details are as follows:

			Site Location:	%3$s
			Login Name:		%1$s
			Password:		%5$s

			You may change your password from your account "Settings" page after logging
			in.

			Please take a few moments to review the other account settings on that page.

			You may also wish to add some basic information to your default profile
			' . "\x28" . 'on the "Profiles" page' . "\x29" . ' so that other people can easily find you.

			We recommend setting your full name, adding a profile photo,
			adding some profile "keywords" ' . "\x28" . 'very useful in making new friends' . "\x29" . ' - and
			perhaps what country you live in; if you do not wish to be more specific
			than that.

			We fully respect your right to privacy, and none of these items are necessary.
			If you are new and do not know anybody here, they may help
			you to make some new and interesting friends.

			If you ever want to delete your account, you can do so at %3$s/removeme

			Thank you and welcome to %2$s.'));

		$preamble = sprintf($preamble, $username, $sitename);
		$body = sprintf($body, $email, $sitename, $siteurl, $username, $password);

		return notification([
			'uid' => $user['uid'],
			'language' => $user['language'],
			'type' => SYSTEM_EMAIL,
			'to_email' => $email,
			'subject'=> L10n::t('Registration details for %s', $sitename),
			'preamble'=> $preamble,
			'body' => $body]);
	}

	/**
	 * @param object $uid user to remove
	 * @return void
	 */
	public static function remove($uid)
	{
		if (!$uid) {
			return;
		}

		logger('Removing user: ' . $uid);

		$user = DBA::selectFirst('user', [], ['uid' => $uid]);

		Addon::callHooks('remove_user', $user);

		// save username (actually the nickname as it is guaranteed
		// unique), so it cannot be re-registered in the future.
		DBA::insert('userd', ['username' => $user['nickname']]);

		// The user and related data will be deleted in "cron_expire_and_remove_users" (cronjobs.php)
		DBA::update('user', ['account_removed' => true, 'account_expires_on' => DateTimeFormat::utc(DateTimeFormat::utcNow() . " + 7 day")], ['uid' => $uid]);
		Worker::add(PRIORITY_HIGH, "Notifier", "removeme", $uid);

		// Send an update to the directory
		$self = DBA::selectFirst('contact', ['url'], ['uid' => $uid, 'self' => true]);
		Worker::add(PRIORITY_LOW, "Directory", $self['url']);

		// Remove the user relevant data
		Worker::add(PRIORITY_LOW, "RemoveUser", $uid);

		if ($uid == local_user()) {
			unset($_SESSION['authenticated']);
			unset($_SESSION['uid']);
			goaway(System::baseUrl());
		}
	}
}
