<?php
/**
 * @file mod/api.php
 */
use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Database\DBM;
use Friendica\Module\Login;

require_once 'include/api.php';

function oauth_get_client($request)
{


	$params = $request->get_parameters();
	$token = $params['oauth_token'];

	$r = q("SELECT `clients`.*
			FROM `clients`, `tokens`
			WHERE `clients`.`client_id`=`tokens`.`client_id`
			AND `tokens`.`id`='%s' AND `tokens`.`scope`='request'", dbesc($token));

	if (!DBM::is_result($r)) {
		return null;
	}

	return $r[0];
}

function api_post(App $a)
{
	if (!local_user()) {
		notice(L10n::t('Permission denied.') . EOL);
		return;
	}

	if (count($a->user) && x($a->user, 'uid') && $a->user['uid'] != local_user()) {
		notice(L10n::t('Permission denied.') . EOL);
		return;
	}
}

function api_content(App $a)
{
	if ($a->cmd == 'api/oauth/authorize') {
		/*
		 * api/oauth/authorize interact with the user. return a standard page
		 */

		$a->page['template'] = "minimal";

		// get consumer/client from request token
		try {
			$request = OAuthRequest::from_request();
		} catch (Exception $e) {
			echo "<pre>";
			var_dump($e);
			killme();
		}

		if (x($_POST, 'oauth_yes')) {
			$app = oauth_get_client($request);
			if (is_null($app)) {
				return "Invalid request. Unknown token.";
			}
			$consumer = new OAuthConsumer($app['client_id'], $app['pw'], $app['redirect_uri']);

			$verifier = md5($app['secret'] . local_user());
			Config::set("oauth", $verifier, local_user());

			if ($consumer->callback_url != null) {
				$params = $request->get_parameters();
				$glue = "?";
				if (strstr($consumer->callback_url, $glue)) {
					$glue = "?";
				}
				goaway($consumer->callback_url . $glue . "oauth_token=" . OAuthUtil::urlencode_rfc3986($params['oauth_token']) . "&oauth_verifier=" . OAuthUtil::urlencode_rfc3986($verifier));
				killme();
			}

			$tpl = get_markup_template("oauth_authorize_done.tpl");
			$o = replace_macros($tpl, [
				'$title' => L10n::t('Authorize application connection'),
				'$info' => L10n::t('Return to your app and insert this Securty Code:'),
				'$code' => $verifier,
			]);

			return $o;
		}

		if (!local_user()) {
			/// @TODO We need login form to redirect to this page
			notice(L10n::t('Please login to continue.') . EOL);
			return Login::form($a->query_string, false, $request->get_parameters());
		}
		//FKOAuth1::loginUser(4);

		$app = oauth_get_client($request);
		if (is_null($app)) {
			return "Invalid request. Unknown token.";
		}

		$tpl = get_markup_template('oauth_authorize.tpl');
		$o = replace_macros($tpl, [
			'$title' => L10n::t('Authorize application connection'),
			'$app' => $app,
			'$authorize' => L10n::t('Do you want to authorize this application to access your posts and contacts, and/or create new posts for you?'),
			'$yes' => L10n::t('Yes'),
			'$no' => L10n::t('No'),
		]);

		return $o;
	}

	echo api_call($a);
	killme();
}
