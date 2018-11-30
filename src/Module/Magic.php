<?php
/**
 * @file src/Module/Magic.php
 */
namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Util\HTTPSignature;
use Friendica\Util\Network;
use Friendica\Util\Strings;

/**
 * Magic Auth (remote authentication) module.
 *
 * Ported from Hubzilla: https://framagit.org/hubzilla/core/blob/master/Zotlabs/Module/Magic.php
 */
class Magic extends BaseModule
{
	public static function init()
	{
		$a = self::getApp();
		$ret = ['success' => false, 'url' => '', 'message' => ''];
		Logger::log('magic mdule: invoked', Logger::DEBUG);

		Logger::log('args: ' . print_r($_REQUEST, true), Logger::DATA);

		$addr = defaults($_REQUEST, 'addr', '');
		$dest = defaults($_REQUEST, 'dest', '');
		$test = (!empty($_REQUEST['test']) ? intval($_REQUEST['test']) : 0);
		$owa  = (!empty($_REQUEST['owa'])  ? intval($_REQUEST['owa'])  : 0);

		// NOTE: I guess $dest isn't just the profile url (could be also
		// other profile pages e.g. photo). We need to find a solution
		// to be able to redirct to other pages than the contact profile.
		$cid = Contact::getIdForURL($dest);

		if (!$cid && !empty($addr)) {
			$cid = Contact::getIdForURL($addr);
		}

		if (!$cid) {
			Logger::log('No contact record found: ' . print_r($_REQUEST, true), Logger::DEBUG);
			// @TODO Finding a more elegant possibility to redirect to either internal or external URL
			$a->redirect($dest);
		}
		$contact = DBA::selectFirst('contact', ['id', 'nurl', 'url'], ['id' => $cid]);

		// Redirect if the contact is already authenticated on this site.
		if (!empty($a->contact) && array_key_exists('id', $a->contact) && strpos($contact['nurl'], Strings::normaliseLink(self::getApp()->getBaseURL())) !== false) {
			if ($test) {
				$ret['success'] = true;
				$ret['message'] .= 'Local site - you are already authenticated.' . EOL;
				return $ret;
			}

			Logger::log('Contact is already authenticated', Logger::DEBUG);
			System::externalRedirect($dest);
		}

		if (local_user()) {
			$user = $a->user;

			// OpenWebAuth
			if ($owa) {
				// Extract the basepath
				// NOTE: we need another solution because this does only work
				// for friendica contacts :-/ . We should have the basepath
				// of a contact also in the contact table.
				$exp = explode('/profile/', $contact['url']);
				$basepath = $exp[0];

				$headers = [];
				$headers['Accept'] = 'application/x-dfrn+json';
				$headers['X-Open-Web-Auth'] = Strings::getRandomHex();

				// Create a header that is signed with the local users private key.
				$headers = HTTPSignature::createSig(
					$headers,
					$user['prvkey'],
					'acct:' . $user['nickname'] . '@' . $a->getHostName() . ($a->getURLPath() ? '/' . $a->getURLPath() : '')
				);

				// Try to get an authentication token from the other instance.
				$curlResult = Network::curl($basepath . '/owa', false, $redirects, ['headers' => $headers]);

				if ($curlResult->isSuccess()) {
					$j = json_decode($curlResult->getBody(), true);

					if ($j['success']) {
						$token = '';
						if ($j['encrypted_token']) {
							// The token is encrypted. If the local user is really the one the other instance
							// thinks he/she is, the token can be decrypted with the local users public key.
							openssl_private_decrypt(Strings::base64UrlDecode($j['encrypted_token']), $token, $user['prvkey']);
						} else {
							$token = $j['token'];
						}
						$x = strpbrk($dest, '?&');
						$args = (($x) ? '&owt=' . $token : '?f=&owt=' . $token);

						System::externalRedirect($dest . $args);
					}
				}
				System::externalRedirect($dest);
			}
		}

		if ($test) {
			$ret['message'] = 'Not authenticated or invalid arguments' . EOL;
			return $ret;
		}

		// @TODO Finding a more elegant possibility to redirect to either internal or external URL
		$a->redirect($dest);
	}
}
