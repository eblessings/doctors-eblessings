<?php

namespace Friendica\Module\Item;

use Friendica\BaseModule;
use Friendica\Content\Feature;
use Friendica\Core\Config;
use Friendica\Core\Hook;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\FileTag;
use Friendica\Model\Group;
use Friendica\Model\Item;
use Friendica\Model\User;
use Friendica\Module\Login;
use Friendica\Network\HTTPException\NotImplementedException;
use Friendica\Util\Crypto;

class Compose extends BaseModule
{
	public static function post()
	{
		if (!empty($_REQUEST['body'])) {
			$_REQUEST['return'] = 'network';
			require_once 'mod/item.php';
			item_post(self::getApp());
		} else {
			notice(L10n::t('Please enter a post body.'));
		}
	}

	public static function content()
	{
		if (!local_user()) {
			return Login::form('compose', false);
		}

		$a = self::getApp();

		if ($a->getCurrentTheme() !== 'frio') {
			throw new NotImplementedException(L10n::t('This feature is only available with the frio theme.'));
		}

		/// @TODO Retrieve parameter from router
		$posttype = $a->argv[1] ?? Item::PT_ARTICLE;
		if (!in_array($posttype, [Item::PT_ARTICLE, Item::PT_PERSONAL_NOTE])) {
			switch ($posttype) {
				case 'note': $posttype = Item::PT_PERSONAL_NOTE; break;
				default: $posttype = Item::PT_ARTICLE; break;
			}
		}

		$user = User::getById(local_user(), ['allow_cid', 'allow_gid', 'deny_cid', 'deny_gid', 'hidewall', 'default-location']);

		switch ($posttype) {
			case Item::PT_PERSONAL_NOTE:
				$compose_title = L10n::t('Compose new personal note');
				$type = 'note';
				$doesFederate = false;
				$contact_allow = $a->contact['id'];
				$group_allow = '';
				break;
			default:
				$compose_title = L10n::t('Compose new post');
				$type = 'post';
				$doesFederate = true;
				$contact_allow = implode(',', expand_acl($user['allow_cid']));
				$group_allow = implode(',', expand_acl($user['allow_gid'])) ?: Group::FOLLOWERS;
				break;
		}

		$title         = $_REQUEST['title']         ?? '';
		$category      = $_REQUEST['category']      ?? '';
		$body          = $_REQUEST['body']          ?? '';
		$location      = $_REQUEST['location']      ?? $user['default-location'];
		$wall          = $_REQUEST['wall']          ?? $type == 'post';
		$contact_allow = $_REQUEST['contact_allow'] ?? $contact_allow;
		$group_allow   = $_REQUEST['group_allow']   ?? $group_allow;
		$contact_deny  = $_REQUEST['contact_deny']  ?? implode(',', expand_acl($user['deny_cid']));
		$group_deny    = $_REQUEST['group_deny']    ?? implode(',', expand_acl($user['deny_gid']));
		$visibility    = ($contact_allow . $user['allow_gid'] . $user['deny_cid'] . $user['deny_gid']) ? 'custom' : 'public';

		$acl_contacts = Contact::select(['id', 'name', 'addr', 'micro'], ['uid' => local_user(), 'pending' => false, 'rel' => [Contact::FOLLOWER, Contact::FRIEND]]);
		array_walk($acl_contacts, function (&$value) {
			$value['type'] = 'contact';
		});

		$acl_groups = [
			[
				'id' => Group::FOLLOWERS,
				'name' => L10n::t('Followers'),
				'addr' => '',
				'micro' => 'images/twopeople.png',
				'type' => 'group',
			],
			[
				'id' => Group::MUTUALS,
				'name' => L10n::t('Mutuals'),
				'addr' => '',
				'micro' => 'images/twopeople.png',
				'type' => 'group',
			]
		];
		foreach (Group::getByUserId(local_user()) as $group) {
			$acl_groups[] = [
				'id' => $group['id'],
				'name' => $group['name'],
				'addr' => '',
				'micro' => 'images/twopeople.png',
				'type' => 'group',
			];
		}

		$acl = array_merge($acl_groups, $acl_contacts);

		$jotnets_fields = [];
		$mail_enabled = false;
		$pubmail_enabled = false;
		if (function_exists('imap_open') && !Config::get('system', 'imap_disabled')) {
			$mailacct = DBA::selectFirst('mailacct', ['pubmail'], ['`uid` = ? AND `server` != ""', local_user()]);
			if (DBA::isResult($mailacct)) {
				$mail_enabled = true;
				$pubmail_enabled = !empty($mailacct['pubmail']);
			}
		}

		if (empty($user['hidewall'])) {
			if ($mail_enabled) {
				$jotnets_fields[] = [
					'type' => 'checkbox',
					'field' => [
						'pubmail_enable',
						L10n::t('Post to Email'),
						$pubmail_enabled
					]
				];
			}

			Hook::callAll('jot_networks', $jotnets_fields);
		}

		$jotplugins = '';
		Hook::callAll('jot_tool', $jotplugins);

		// Output

		$a->registerFooterScript('view/js/ajaxupload.js');
		$a->registerFooterScript('view/js/linkPreview.js');
		$a->registerFooterScript('view/asset/typeahead.js/dist/typeahead.bundle.js');
		$a->registerFooterScript('view/theme/frio/frameworks/friendica-tagsinput/friendica-tagsinput.js');
		$a->registerStylesheet('view/theme/frio/frameworks/friendica-tagsinput/friendica-tagsinput.css');
		$a->registerStylesheet('view/theme/frio/frameworks/friendica-tagsinput/friendica-tagsinput-typeahead.css');

		$tpl = Renderer::getMarkupTemplate('item/compose-footer.tpl');
		$a->page['footer'] .= Renderer::replaceMacros($tpl, [
			'$acl_contacts' => $acl_contacts,
			'$acl_groups' => $acl_groups,
			'$acl' => $acl,
		]);

		$tpl = Renderer::getMarkupTemplate('item/compose.tpl');
		return Renderer::replaceMacros($tpl, [
			'$compose_title'=> $compose_title,
			'$id'           => 0,
			'$posttype'     => $posttype,
			'$type'         => $type,
			'$wall'         => $wall,
			'$default'      => L10n::t(''),
			'$mylink'       => $a->removeBaseURL($a->contact['url']),
			'$mytitle'      => L10n::t('This is you'),
			'$myphoto'      => $a->removeBaseURL($a->contact['thumb']),
			'$submit'       => L10n::t('Submit'),
			'$edbold'       => L10n::t('Bold'),
			'$editalic'     => L10n::t('Italic'),
			'$eduline'      => L10n::t('Underline'),
			'$edquote'      => L10n::t('Quote'),
			'$edcode'       => L10n::t('Code'),
			'$edimg'        => L10n::t('Image'),
			'$edurl'        => L10n::t('Link'),
			'$edattach'     => L10n::t('Link or Media'),
			'$prompttext'   => L10n::t('Please enter a image/video/audio/webpage URL:'),
			'$preview'      => L10n::t('Preview'),
			'$location_set' => L10n::t('Set your location'),
			'$location_clear' => L10n::t('Clear the location'),
			'$location_unavailable' => L10n::t('Location services are unavailable on your device'),
			'$location_disabled' => L10n::t('Location services are disabled. Please check the website\'s permissions on your device'),
			'$wait'         => L10n::t('Please wait'),
			'$placeholdertitle' => L10n::t('Set title'),
			'$placeholdercategory' => (Feature::isEnabled(local_user(),'categories') ? L10n::t('Categories (comma-separated list)') : ''),
			'$public_title'  => L10n::t('Public'),
			'$public_desc'  => L10n::t('This post will be sent to all your followers and can be seen in the community pages and by anyone with its link.'),
			'$custom_title' => L10n::t('Custom'),
			'$custom_desc'  => L10n::t('This post will be sent only to the people in the first box, to the exception of the people mentioned in the second box. It won\'t be visible in the community pages nor with its link, and can\'t be sent to connectors (Twitter, Pump.io, etc...).'),
			'$emailcc'      => L10n::t('CC: email addresses'),
			'$title'        => $title,
			'$category'     => $category,
			'$body'         => $body,
			'$location'     => $location,
			'$visibility'   => $visibility,
			'$contact_allow'=> $contact_allow,
			'$group_allow'  => $group_allow,
			'$contact_deny' => $contact_deny,
			'$group_deny'   => $group_deny,
			'$jotplugins'   => $jotplugins,
			'$doesFederate' => $doesFederate,
			'$jotnets_fields'=> $jotnets_fields,
			'$sourceapp'    => L10n::t($a->sourcename),
			'$rand_num'     => Crypto::randomDigits(12)
		]);
	}
}
