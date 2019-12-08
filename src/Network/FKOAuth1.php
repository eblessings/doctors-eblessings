<?php
/**
 * @file src/Network/FKOAuth1.php
 */
namespace Friendica\Network;

use Friendica\BaseObject;
use Friendica\App\Authentication;
use Friendica\Core\Logger;
use Friendica\Core\Session;
use Friendica\Database\DBA;
use OAuthServer;
use OAuthSignatureMethod_HMAC_SHA1;
use OAuthSignatureMethod_PLAINTEXT;

/**
 * @brief OAuth protocol
 */
class FKOAuth1 extends OAuthServer
{
	/**
	 * @brief Constructor
	 */
	public function __construct()
	{
		parent::__construct(new FKOAuthDataStore());
		$this->add_signature_method(new OAuthSignatureMethod_PLAINTEXT());
		$this->add_signature_method(new OAuthSignatureMethod_HMAC_SHA1());
	}

	/**
	 * @param string $uid user id
	 * @return void
	 * @throws HTTPException\ForbiddenException
	 * @throws HTTPException\InternalServerErrorException
	 */
	public function loginUser($uid)
	{
		Logger::log("FKOAuth1::loginUser $uid");
		$a = BaseObject::getApp();
		$record = DBA::selectFirst('user', [], ['uid' => $uid, 'blocked' => 0, 'account_expired' => 0, 'account_removed' => 0, 'verified' => 1]);

		if (!DBA::isResult($record)) {
			Logger::log('FKOAuth1::loginUser failure: ' . print_r($_SERVER, true), Logger::DEBUG);
			header('HTTP/1.0 401 Unauthorized');
			die('This api requires login');
		}

		/** @var Authentication $authentication */
		$authentication = BaseObject::getClass(Authentication::class);
		$authentication->setForUser($a, $record, true);
	}
}
