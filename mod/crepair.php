<?php
/**
 * @file mod/crepair.php
 */

use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Core\Renderer;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model;
use Friendica\Module;
use Friendica\Util\Strings;

function crepair_init(App $a)
{
	if (!local_user()) {
		return;
	}
}

function crepair_post(App $a)
{
	if (!local_user()) {
		return;
	}

	$cid = (($a->argc > 1) ? intval($a->argv[1]) : 0);

	$contact = null;
	if ($cid) {
		$contact = DBA::selectFirst('contact', [], ['id' => $cid, 'uid' => local_user()]);
	}

	if (!DBA::isResult($contact)) {
		return;
	}

	$name        = ($_POST['name']        ?? '') ?: $contact['name'];
	$nick        =  $_POST['nick']        ?? '';
	$url         =  $_POST['url']         ?? '';
	$alias       =  $_POST['alias']       ?? '';
	$request     =  $_POST['request']     ?? '';
	$confirm     =  $_POST['confirm']     ?? '';
	$notify      =  $_POST['notify']      ?? '';
	$poll        =  $_POST['poll']        ?? '';
	$attag       =  $_POST['attag']       ?? '';
	$photo       =  $_POST['photo']       ?? '';
	$remote_self =  $_POST['remote_self'] ?? false;
	$nurl        = Strings::normaliseLink($url);

	$r = DBA::update(
		'contact',
		[
			'name'        => $name,
			'nick'        => $nick,
			'url'         => $url,
			'nurl'        => $nurl,
			'alias'       => $alias,
			'request'     => $request,
			'confirm'     => $confirm,
			'notify'      => $notify,
			'poll'        => $poll,
			'attag'       => $attag,
			'remote_self' => $remote_self,
		],
		['id' => $contact['id'], 'uid' => local_user()]
	);

	if ($photo) {
		Logger::log('mod-crepair: updating photo from ' . $photo);

		Model\Contact::updateAvatar($photo, local_user(), $contact['id']);
	}

	if ($r) {
		info(DI::l10n()->t('Contact settings applied.') . EOL);
	} else {
		notice(DI::l10n()->t('Contact update failed.') . EOL);
	}

	return;
}

function crepair_content(App $a)
{
	if (!local_user()) {
		notice(DI::l10n()->t('Permission denied.') . EOL);
		return;
	}

	$cid = (($a->argc > 1) ? intval($a->argv[1]) : 0);

	$contact = null;
	if ($cid) {
		$contact = DBA::selectFirst('contact', [], ['id' => $cid, 'uid' => local_user()]);
	}

	if (!DBA::isResult($contact)) {
		notice(DI::l10n()->t('Contact not found.') . EOL);
		return;
	}

	if (empty(DI::page()['aside'])) {
		DI::page()['aside'] = '';
	}

	if (DBA::isResult($contact)) {
		$a->data['contact'] = $contact;
		Model\Profile::load($a, "", 0, Model\Contact::getDetailsByURL($contact["url"]));
	}

	$warning = DI::l10n()->t('<strong>WARNING: This is highly advanced</strong> and if you enter incorrect information your communications with this contact may stop working.');
	$info = DI::l10n()->t('Please use your browser \'Back\' button <strong>now</strong> if you are uncertain what to do on this page.');

	$returnaddr = "contact/$cid";

	$allow_remote_self = Config::get('system', 'allow_users_remote_self');

	// Disable remote self for everything except feeds.
	// There is an issue when you repeat an item from maybe twitter and you got comments from friendica and twitter
	// Problem is, you couldn't reply to both networks.
	if (!in_array($contact['network'], [Protocol::FEED, Protocol::DFRN, Protocol::DIASPORA, Protocol::TWITTER])) {
		$allow_remote_self = false;
	}

	if ($contact['network'] == Protocol::FEED) {
		$remote_self_options = ['0' => DI::l10n()->t('No mirroring'), '1' => DI::l10n()->t('Mirror as forwarded posting'), '2' => DI::l10n()->t('Mirror as my own posting')];
	} else {
		$remote_self_options = ['0' => DI::l10n()->t('No mirroring'), '2' => DI::l10n()->t('Mirror as my own posting')];
	}

	$update_profile = in_array($contact['network'], Protocol::FEDERATED);

	$tab_str = Module\Contact::getTabsHTML($a, $contact, 6);

	$tpl = Renderer::getMarkupTemplate('crepair.tpl');
	$o = Renderer::replaceMacros($tpl, [
		'$tab_str'        => $tab_str,
		'$warning'        => $warning,
		'$info'           => $info,
		'$returnaddr'     => $returnaddr,
		'$return'         => DI::l10n()->t('Return to contact editor'),
		'$update_profile' => $update_profile,
		'$udprofilenow'   => DI::l10n()->t('Refetch contact data'),
		'$contact_id'     => $contact['id'],
		'$lbl_submit'     => DI::l10n()->t('Submit'),
		'$label_remote_self' => DI::l10n()->t('Remote Self'),
		'$allow_remote_self' => $allow_remote_self,
		'$remote_self' => ['remote_self',
			DI::l10n()->t('Mirror postings from this contact'),
			$contact['remote_self'],
			DI::l10n()->t('Mark this contact as remote_self, this will cause friendica to repost new entries from this contact.'),
			$remote_self_options
		],

		'$name'		=> ['name', DI::l10n()->t('Name') , $contact['name']],
		'$nick'		=> ['nick', DI::l10n()->t('Account Nickname'), $contact['nick']],
		'$attag'	=> ['attag', DI::l10n()->t('@Tagname - overrides Name/Nickname'), $contact['attag']],
		'$url'		=> ['url', DI::l10n()->t('Account URL'), $contact['url']],
		'$alias'	=> ['alias', DI::l10n()->t('Account URL Alias'), $contact['alias']],
		'$request'	=> ['request', DI::l10n()->t('Friend Request URL'), $contact['request']],
		'confirm'	=> ['confirm', DI::l10n()->t('Friend Confirm URL'), $contact['confirm']],
		'notify'	=> ['notify', DI::l10n()->t('Notification Endpoint URL'), $contact['notify']],
		'poll'		=> ['poll', DI::l10n()->t('Poll/Feed URL'), $contact['poll']],
		'photo'		=> ['photo', DI::l10n()->t('New photo from this URL'), ''],
	]);

	return $o;
}
