<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Content\Text\BBCode;
use Friendica\Core\Config;
use Friendica\Core\Hook;
use Friendica\Core\L10n;
use Friendica\Core\L10n\L10n as L10nClass;
use Friendica\Core\Logger;
use Friendica\Core\Renderer;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model;
use Friendica\Util\Strings;

/**
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
class Register extends BaseModule
{
	const CLOSED  = 0;
	const APPROVE = 1;
	const OPEN    = 2;

	/**
	 * @brief Module GET method to display any content
	 *
	 * Extend this method if the module is supposed to return any display
	 * through a GET request. It can be an HTML page through templating or a
	 * XML feed or a JSON output.
	 *
	 * @return string
	 */
	public static function content(array $parameters = [])
	{
		// logged in users can register others (people/pages/groups)
		// even with closed registrations, unless specifically prohibited by site policy.
		// 'block_extended_register' blocks all registrations, period.
		$block = Config::get('system', 'block_extended_register');

		if (local_user() && $block) {
			notice(L10n::t('Permission denied.'));
			return '';
		}

		if (local_user()) {
			$user = DBA::selectFirst('user', ['parent-uid'], ['uid' => local_user()]);
			if (!empty($user['parent-uid'])) {
				notice(L10n::t('Only parent users can create additional accounts.'));
				return '';
			}
		}

		if (!local_user() && (intval(Config::get('config', 'register_policy')) === self::CLOSED)) {
			notice(L10n::t('Permission denied.'));
			return '';
		}

		$max_dailies = intval(Config::get('system', 'max_daily_registrations'));
		if ($max_dailies) {
			$count = DBA::count('user', ['`register_date` > UTC_TIMESTAMP - INTERVAL 1 day']);
			if ($count >= $max_dailies) {
				Logger::log('max daily registrations exceeded.');
				notice(L10n::t('This site has exceeded the number of allowed daily account registrations. Please try again tomorrow.'));
				return '';
			}
		}

		$username   = $_REQUEST['username']   ?? '';
		$email      = $_REQUEST['email']      ?? '';
		$openid_url = $_REQUEST['openid_url'] ?? '';
		$nickname   = $_REQUEST['nickname']   ?? '';
		$photo      = $_REQUEST['photo']      ?? '';
		$invite_id  = $_REQUEST['invite_id']  ?? '';

		if (local_user() || Config::get('system', 'no_openid')) {
			$fillwith = '';
			$fillext  = '';
			$oidlabel = '';
		} else {
			$fillwith = L10n::t('You may (optionally) fill in this form via OpenID by supplying your OpenID and clicking "Register".');
			$fillext  = L10n::t('If you are not familiar with OpenID, please leave that field blank and fill in the rest of the items.');
			$oidlabel = L10n::t('Your OpenID (optional): ');
		}

		if (Config::get('system', 'publish_all')) {
			$profile_publish = '<input type="hidden" name="profile_publish_reg" value="1" />';
		} else {
			$publish_tpl = Renderer::getMarkupTemplate('profile_publish.tpl');
			$profile_publish = Renderer::replaceMacros($publish_tpl, [
				'$instance'     => 'reg',
				'$pubdesc'      => L10n::t('Include your profile in member directory?'),
				'$yes_selected' => '',
				'$no_selected'  => ' checked="checked"',
				'$str_yes'      => L10n::t('Yes'),
				'$str_no'       => L10n::t('No'),
			]);
		}

		$ask_password = !DBA::count('contact');

		$tpl = Renderer::getMarkupTemplate('register.tpl');

		$arr = ['template' => $tpl];

		Hook::callAll('register_form', $arr);

		$tpl = $arr['template'];

		$tos = new Tos();

		$o = Renderer::replaceMacros($tpl, [
			'$invitations'  => Config::get('system', 'invitation_only'),
			'$permonly'     => intval(Config::get('config', 'register_policy')) === self::APPROVE,
			'$permonlybox'  => ['permonlybox', L10n::t('Note for the admin'), '', L10n::t('Leave a message for the admin, why you want to join this node'), 'required'],
			'$invite_desc'  => L10n::t('Membership on this site is by invitation only.'),
			'$invite_label' => L10n::t('Your invitation code: '),
			'$invite_id'    => $invite_id,
			'$regtitle'     => L10n::t('Registration'),
			'$registertext' => BBCode::convert(Config::get('config', 'register_text', '')),
			'$fillwith'     => $fillwith,
			'$fillext'      => $fillext,
			'$oidlabel'     => $oidlabel,
			'$openid'       => $openid_url,
			'$namelabel'    => L10n::t('Your Full Name (e.g. Joe Smith, real or real-looking): '),
			'$addrlabel'    => L10n::t('Your Email Address: (Initial information will be send there, so this has to be an existing address.)'),
			'$addrlabel2'   => L10n::t('Please repeat your e-mail address:'),
			'$ask_password' => $ask_password,
			'$password1'    => ['password1', L10n::t('New Password:'), '', L10n::t('Leave empty for an auto generated password.')],
			'$password2'    => ['confirm', L10n::t('Confirm:'), '', ''],
			'$nickdesc'     => L10n::t('Choose a profile nickname. This must begin with a text character. Your profile address on this site will then be "<strong>nickname@%s</strong>".', DI::baseUrl()->getHostname()),
			'$nicklabel'    => L10n::t('Choose a nickname: '),
			'$photo'        => $photo,
			'$publish'      => $profile_publish,
			'$regbutt'      => L10n::t('Register'),
			'$username'     => $username,
			'$email'        => $email,
			'$nickname'     => $nickname,
			'$sitename'     => DI::baseUrl()->getHostname(),
			'$importh'      => L10n::t('Import'),
			'$importt'      => L10n::t('Import your profile to this friendica instance'),
			'$showtoslink'  => Config::get('system', 'tosdisplay'),
			'$tostext'      => L10n::t('Terms of Service'),
			'$showprivstatement' => Config::get('system', 'tosprivstatement'),
			'$privstatement'=> $tos->privacy_complete,
			'$form_security_token' => BaseModule::getFormSecurityToken('register'),
			'$explicit_content' => Config::get('system', 'explicit_content', false),
			'$explicit_content_note' => L10n::t('Note: This node explicitly contains adult content'),
			'$additional'   => !empty(local_user()),
			'$parent_password' => ['parent_password', L10n::t('Parent Password:'), '', L10n::t('Please enter the password of the parent account to legitimize your request.')]

		]);

		return $o;
	}

	/**
	 * @brief Module POST method to process submitted data
	 *
	 * Extend this method if the module is supposed to process POST requests.
	 * Doesn't display any content
	 */
	public static function post(array $parameters = [])
	{
		BaseModule::checkFormSecurityTokenRedirectOnError('/register', 'register');

		$a = DI::app();

		$arr = ['post' => $_POST];
		Hook::callAll('register_post', $arr);

		$additional_account = false;

		if (!local_user() && !empty($arr['post']['parent_password'])) {
			notice(L10n::t('Permission denied.'));
			return;
		} elseif (local_user() && !empty($arr['post']['parent_password'])) {
			try {
				Model\User::getIdFromPasswordAuthentication(local_user(), $arr['post']['parent_password']);
			} catch (\Exception $ex) {
				notice(L10n::t("Password doesn't match."));
				$regdata = ['nickname' => $arr['post']['nickname'], 'username' => $arr['post']['username']];
				DI::baseUrl()->redirect('register?' . http_build_query($regdata));
			}
			$additional_account = true;
		} elseif (local_user()) {
			notice(L10n::t('Please enter your password.'));
			$regdata = ['nickname' => $arr['post']['nickname'], 'username' => $arr['post']['username']];
			DI::baseUrl()->redirect('register?' . http_build_query($regdata));
		}

		$max_dailies = intval(Config::get('system', 'max_daily_registrations'));
		if ($max_dailies) {
			$count = DBA::count('user', ['`register_date` > UTC_TIMESTAMP - INTERVAL 1 day']);
			if ($count >= $max_dailies) {
				return;
			}
		}

		switch (Config::get('config', 'register_policy')) {
			case self::OPEN:
				$blocked = 0;
				$verified = 1;
				break;

			case self::APPROVE:
				$blocked = 1;
				$verified = 0;
				break;

			case self::CLOSED:
			default:
				if (empty($_SESSION['authenticated']) && empty($_SESSION['administrator'])) {
					notice(L10n::t('Permission denied.'));
					return;
				}
				$blocked = 1;
				$verified = 0;
				break;
		}

		$netpublish = !empty($_POST['profile_publish_reg']);

		$arr = $_POST;

		// Is there text in the tar pit?
		if (!empty($arr['email'])) {
			Logger::info('Tar pit', $arr);
			notice(L10n::t('You have entered too much information.'));
			DI::baseUrl()->redirect('register/');
		}


		// Overwriting the "tar pit" field with the real one
		$arr['email'] = $arr['field1'];

		if ($additional_account) {
			$user = DBA::selectFirst('user', ['email'], ['uid' => local_user()]);
			if (!DBA::isResult($user)) {
				notice(L10n::t('User not found.'));
				DI::baseUrl()->redirect('register');
			}

			$blocked = 0;
			$verified = 1;

			$arr['password1'] = $arr['confirm'] = $arr['parent_password'];
			$arr['repeat'] = $arr['email'] = $user['email'];
		}

		if ($arr['email'] != $arr['repeat']) {
			Logger::info('Mail mismatch', $arr);
			notice(L10n::t('Please enter the identical mail address in the second field.'));
			$regdata = ['email' => $arr['email'], 'nickname' => $arr['nickname'], 'username' => $arr['username']];
			DI::baseUrl()->redirect('register?' . http_build_query($regdata));
		}

		$arr['blocked'] = $blocked;
		$arr['verified'] = $verified;
		$arr['language'] = L10nClass::detectLanguage($_SERVER, $_GET, DI::config()->get('system', 'language'));

		try {
			$result = Model\User::create($arr);
		} catch (\Exception $e) {
			notice($e->getMessage());
			return;
		}

		$user = $result['user'];

		$base_url = DI::baseUrl()->get();

		if ($netpublish && intval(Config::get('config', 'register_policy')) !== self::APPROVE) {
			$url = $base_url . '/profile/' . $user['nickname'];
			Worker::add(PRIORITY_LOW, 'Directory', $url);
		}

		if ($additional_account) {
			DBA::update('user', ['parent-uid' => local_user()], ['uid' => $user['uid']]);
			info(L10n::t('The additional account was created.'));
			DI::baseUrl()->redirect('delegation');
		}

		$using_invites = Config::get('system', 'invitation_only');
		$num_invites   = Config::get('system', 'number_invites');
		$invite_id = (!empty($_POST['invite_id']) ? Strings::escapeTags(trim($_POST['invite_id'])) : '');

		if (intval(Config::get('config', 'register_policy')) === self::OPEN) {
			if ($using_invites && $invite_id) {
				Model\Register::deleteByHash($invite_id);
				DI::pConfig()->set($user['uid'], 'system', 'invites_remaining', $num_invites);
			}

			// Only send a password mail when the password wasn't manually provided
			if (empty($_POST['password1']) || empty($_POST['confirm'])) {
				$res = Model\User::sendRegisterOpenEmail(
					L10n::withLang($arr['language']),
					$user,
					Config::get('config', 'sitename'),
					$base_url,
					$result['password']
				);

				if ($res) {
					info(L10n::t('Registration successful. Please check your email for further instructions.'));
					DI::baseUrl()->redirect();
				} else {
					notice(
						L10n::t('Failed to send email message. Here your accout details:<br> login: %s<br> password: %s<br><br>You can change your password after login.',
							$user['email'],
							$result['password'])
					);
				}
			} else {
				info(L10n::t('Registration successful.'));
				DI::baseUrl()->redirect();
			}
		} elseif (intval(Config::get('config', 'register_policy')) === self::APPROVE) {
			if (!strlen(Config::get('config', 'admin_email'))) {
				notice(L10n::t('Your registration can not be processed.'));
				DI::baseUrl()->redirect();
			}

			// Check if the note to the admin is actually filled out
			if (empty($_POST['permonlybox'])) {
				notice(L10n::t('You have to leave a request note for the admin.')
					. L10n::t('Your registration can not be processed.'));

				DI::baseUrl()->redirect('register/');
			}

			Model\Register::createForApproval($user['uid'], Config::get('system', 'language'), $_POST['permonlybox']);

			// invite system
			if ($using_invites && $invite_id) {
				Model\Register::deleteByHash($invite_id);
				DI::pConfig()->set($user['uid'], 'system', 'invites_remaining', $num_invites);
			}

			// send email to admins
			$admins_stmt = DBA::select(
				'user',
				['uid', 'language', 'email'],
				['email' => explode(',', str_replace(' ', '', Config::get('config', 'admin_email')))]
			);

			// send notification to admins
			while ($admin = DBA::fetch($admins_stmt)) {
				\notification([
					'type'         => NOTIFY_SYSTEM,
					'event'        => 'SYSTEM_REGISTER_REQUEST',
					'source_name'  => $user['username'],
					'source_mail'  => $user['email'],
					'source_nick'  => $user['nickname'],
					'source_link'  => $base_url . '/admin/users/',
					'link'         => $base_url . '/admin/users/',
					'source_photo' => $base_url . '/photo/avatar/' . $user['uid'] . '.jpg',
					'to_email'     => $admin['email'],
					'uid'          => $admin['uid'],
					'language'     => ($admin['language'] ?? '') ?: 'en',
					'show_in_notification_page' => false
				]);
			}
			DBA::close($admins_stmt);

			// send notification to the user, that the registration is pending
			Model\User::sendRegisterPendingEmail(
				$user,
				Config::get('config', 'sitename'),
				$base_url,
				$result['password']
			);

			info(L10n::t('Your registration is pending approval by the site owner.'));
			DI::baseUrl()->redirect();
		}

		return;
	}
}
