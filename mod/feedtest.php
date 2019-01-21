<?php

/**
 * @file_tag_list_to_file mod/feedtest.php
 */

use Friendica\App;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Protocol\Feed;
use Friendica\Util\Network;

function feedtest_content(App $a)
{
	if (!local_user()) {
		info(L10n::t('You must be logged in to use this module'));
		return;
	};

	$result = [];
	if (!empty($_REQUEST['url'])) {
		$url = $_REQUEST['url'];

		$importer = DBA::selectFirst('user', [], ['uid' => local_user()]);

		$contact_id = Contact::getIdForURL($url, local_user(), true);

		$contact = DBA::selectFirst('contact', [], ['id' => $contact_id]);

		$xml = Network::fetchUrl($contact['poll']);

		$dummy = null;
		$import_result = Feed::import($xml, $importer, $contact, $dummy, true);

		$result = [
			'input' => $xml,
			'output' => var_export($import_result, true),
		];
	}

	$tpl = Renderer::getMarkupTemplate('feedtest.tpl');
	$o = Renderer::replaceMacros($tpl, [
		'$url'    => ['url', L10n::t('Source URL'), defaults($_REQUEST, 'url', ''), ''],
		'$result' => $result
	]);

	return $o;
}
