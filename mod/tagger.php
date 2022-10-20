<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

use Friendica\App;
use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\Session;
use Friendica\Core\System;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Contact;
use Friendica\Model\Item;
use Friendica\Model\Post;
use Friendica\Model\Tag;
use Friendica\Protocol\Activity;
use Friendica\Util\XML;
use Friendica\Worker\Delivery;

function tagger_content(App $a)
{
	if (!DI::userSession()->isAuthenticated()) {
		return;
	}

	$term = trim($_GET['term'] ?? '');
	// no commas allowed
	$term = str_replace([',',' ', '<', '>'],['','_', '', ''], $term);

	if (!$term) {
		return;
	}

	$item_id = ((DI::args()->getArgc() > 1) ? trim(DI::args()->getArgv()[1]) : 0);

	Logger::info('tagger: tag', ['term' =>  $term, 'item' => $item_id]);


	$item = Post::selectFirst([], ['id' => $item_id]);

	if (!$item_id || !DBA::isResult($item)) {
		Logger::notice('tagger: no item ' . $item_id);
		return;
	}

	$owner_uid = $item['uid'];

	if (DI::userSession()->getLocalUserId() != $owner_uid) {
		return;
	}

	$contact = Contact::selectFirst([], ['self' => true, 'uid' => DI::userSession()->getLocalUserId()]);
	if (!DBA::isResult($contact)) {
		Logger::warning('Self contact not found.', ['uid' => DI::userSession()->getLocalUserId()]);
		return;
	}

	$uri = Item::newURI();
	$xterm = XML::escape($term);
	$post_type = (($item['resource-id']) ? DI::l10n()->t('photo') : DI::l10n()->t('status'));
	$targettype = (($item['resource-id']) ? Activity\ObjectType::IMAGE : Activity\ObjectType::NOTE );
	$href = DI::baseUrl() . '/display/' . $item['guid'];

	$link = XML::escape('<link rel="alternate" type="text/html" href="'. $href . '" />' . "\n");

	$body = XML::escape($item['body']);

	$target = <<< EOT
	<target>
		<type>$targettype</type>
		<local>1</local>
		<id>{$item['uri']}</id>
		<link>$link</link>
		<title></title>
		<content>$body</content>
	</target>
EOT;

	$tagid = DI::baseUrl() . '/search?tag=' . $xterm;
	$objtype = Activity\ObjectType::TAGTERM;

	$obj = <<< EOT
	<object>
		<type>$objtype</type>
		<local>1</local>
		<id>$tagid</id>
		<link>$tagid</link>
		<title>$xterm</title>
		<content>$xterm</content>
	</object>
EOT;

	$bodyverb = DI::l10n()->t('%1$s tagged %2$s\'s %3$s with %4$s');

	if (!isset($bodyverb)) {
		return;
	}

	$termlink = html_entity_decode('&#x2317;') . '[url=' . DI::baseUrl() . '/search?tag=' . $term . ']'. $term . '[/url]';

	$ulink = '[url=' . $contact['url'] . ']' . $contact['name'] . '[/url]';
	$alink = '[url=' . $item['author-link'] . ']' . $item['author-name'] . '[/url]';
	$plink = '[url=' . $item['plink'] . ']' . $post_type . '[/url]';

	$arr = [
		'guid'          => System::createUUID(),
		'uri'           => $uri,
		'uid'           => $owner_uid,
		'contact-id'    => $contact['id'],
		'wall'          => $item['wall'],
		'gravity'       => Item::GRAVITY_COMMENT,
		'parent'        => $item['id'],
		'thr-parent'    => $item['uri'],
		'owner-name'    => $item['author-name'],
		'owner-link'    => $item['author-link'],
		'owner-avatar'  => $item['author-avatar'],
		'author-name'   => $contact['name'],
		'author-link'   => $contact['url'],
		'author-avatar' => $contact['thumb'],
		'body'          => sprintf($bodyverb, $ulink, $alink, $plink, $termlink),
		'verb'          => Activity::TAG,
		'target-type'   => $targettype,
		'target'        => $target,
		'object-type'   => $objtype,
		'object'        => $obj,
		'private'       => $item['private'],
		'allow_cid'     => $item['allow_cid'],
		'allow_gid'     => $item['allow_gid'],
		'deny_cid'      => $item['deny_cid'],
		'deny_gid'      => $item['deny_gid'],
		'visible'       => 1,
		'unseen'        => 1,
		'origin'        => 1,
	];


	$post_id = Item::insert($arr);

	if (!$item['visible']) {
		Item::update(['visible' => true], ['id' => $item['id']]);
	}

	Tag::store($item['uri-id'], Tag::HASHTAG, $term);

	$arr['id'] = $post_id;

	Hook::callAll('post_local_end', $arr);

	$post = Post::selectFirst(['uri-id', 'uid'], ['id' => $post_id]);

	Worker::add(Worker::PRIORITY_HIGH, "Notifier", Delivery::POST, $post['uri-id'], $post['uid']);
	System::exit();
}
