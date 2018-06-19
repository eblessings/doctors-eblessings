<?php
/**
 * @file src/Module/Owa.php
 */
namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Core\System;
use Friendica\Database\DBM;
use Friendica\Model\Verify;
use Friendica\Network\Probe;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\HTTPSig;

use dba;

/**
 * @brief OpenWebAuth verifier and token generator
 * 
 * See https://macgirvin.com/wiki/mike/OpenWebAuth/Home
 * Requests to this endpoint should be signed using HTTP Signatures
 * using the 'Authorization: Signature' authentication method
 * If the signature verifies a token is returned.
 *
 * This token may be exchanged for an authenticated cookie.
 * 
 * Ported from Hubzilla: https://framagit.org/hubzilla/core/blob/master/Zotlabs/Module/Owa.php
 */
class Owa extends BaseModule
{
	public static function init()
	{

		$ret = [ 'success' => false ];

		foreach (['REDIRECT_REMOTE_USER', 'HTTP_AUTHORIZATION'] as $head) {
			if (array_key_exists($head, $_SERVER) && substr(trim($_SERVER[$head]), 0, 9) === 'Signature') {
				if ($head !== 'HTTP_AUTHORIZATION') {
					$_SERVER['HTTP_AUTHORIZATION'] = $_SERVER[$head];
					continue;
				}

				$sigblock = HTTPSig::parseSigheader($_SERVER[$head]);
				if ($sigblock) {
					$keyId = $sigblock['keyId'];

					if ($keyId) {
						// Try to find the public contact entry of the handle.
						$handle = str_replace("acct:", "", $keyId);
						$fields = ["id", "url", "addr", "pubkey"];
						$condition = ["addr" => $handle, "uid" => 0];

						$contact = dba::selectFirst("contact", $fields, $condition);

						// Not found? Try to probe with the handle.
						if(!DBM::is_result($contact)) {
							Probe::uri($handle, '', -1, true, true);
							$contact = dba::selectFirst("contact", $fields, $condition);
						}

						if (DBM::is_result($contact)) {
							// Try to verify the signed header with the public key of the contact record
							// we have found.
							$verified = HTTPSig::verify('', $contact['pubkey']);

							if ($verified && $verified['header_signed'] && $verified['header_valid']) {
								logger('OWA header: ' . print_r($verified, true), LOGGER_DATA);
								logger('OWA success: ' . $contact['addr'], LOGGER_DATA);

								$ret['success'] = true;
								$token = random_string(32);

								// Store the generated token in the databe.
								Verify::create('owt', 0, $token, $contact['addr']);

								$result = '';

								// Encrypt the token with the public contacts publik key.
								// Only the specific public contact will be able to encrypt it.
								// At a later time, we will compare weather the token we're getting
								// is really the same token we have stored in the database.
								openssl_public_encrypt($token, $result, $contact['pubkey']);
								$ret['encrypted_token'] = base64url_encode($result);
							} else {
								logger('OWA fail: ' . $contact['id'] . ' ' . $contact['addr'] . ' ' . $contact['url'], LOGGER_DEBUG);
							}
						} else {
							logger('Contact not found: ' . $handle, LOGGER_DEBUG);
						}
					}
				}
			}
		}
		System::jsonExit($ret, 'application/x-dfrn+json');
	}
}
