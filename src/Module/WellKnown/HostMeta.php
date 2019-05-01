<?php

namespace Friendica\Module\WellKnown;

use Friendica\BaseModule;
use Friendica\Core\Renderer;
use Friendica\Protocol\Salmon;
use Friendica\Util\Crypto;

/**
 * Prints the metadata for describing this host
 * @see https://tools.ietf.org/html/rfc6415
 */
class HostMeta extends BaseModule
{
	public static function rawContent()
	{
		$app = self::getApp();
		$config = $app->getConfig();

		header("Content-type: text/xml");

		if (!$config->get('system', 'site_pubkey', false)) {
			$res = Crypto::newKeypair(1024);

			$config->set('system','site_prvkey', $res['prvkey']);
			$config->set('system','site_pubkey', $res['pubkey']);
		}

		$tpl = Renderer::getMarkupTemplate('xrd_host.tpl');
		echo Renderer::replaceMacros($tpl, [
				'$zhost' => $app->getHostName(),
				'$zroot' => $app->getBaseURL(),
				'$domain' => $app->getBaseURL(),
				'$bigkey' => Salmon::salmonKey($config->get('system', 'site_pubkey'))]
		);

		exit();
	}
}
