<?php
/**
 * @copyright Copyright (C) 2010-2021, the Friendica project
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

namespace Friendica\Factory\Api\Mastodon;

use Friendica\App\BaseURL;
use Friendica\BaseFactory;
use Friendica\Content\ContactSelector;
use Friendica\Content\Text\BBCode;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Post;
use Friendica\Model\Verb;
use Friendica\Network\HTTPException;
use Friendica\Protocol\Activity;
use Friendica\Protocol\ActivityPub;
use Friendica\Repository\ProfileField;
use Psr\Log\LoggerInterface;

class Status extends BaseFactory
{
	/** @var BaseURL */
	protected $baseUrl;
	/** @var ProfileField */
	protected $profileField;
	/** @var Field */
	protected $mstdnField;

	public function __construct(LoggerInterface $logger, BaseURL $baseURL, ProfileField $profileField, Field $mstdnField)
	{
		parent::__construct($logger);

		$this->baseUrl = $baseURL;
		$this->profileField = $profileField;
		$this->mstdnField = $mstdnField;
	}

	/**
	 * @param int $uriId Uri-ID of the item
	 * @param int $uid   Item user
	 * @return \Friendica\Object\Api\Mastodon\Status
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public function createFromUriId(int $uriId, $uid = 0)
	{
		$fields = ['uri-id', 'uid', 'author-id', 'author-link', 'starred', 'app', 'title', 'body', 'raw-body', 'created', 'network',
			'thr-parent-id', 'parent-author-id', 'language', 'uri', 'plink', 'private', 'vid', 'gravity'];
		$item = Post::selectFirst($fields, ['uri-id' => $uriId, 'uid' => [0, $uid]], ['order' => ['uid' => true]]);
		if (!$item) {
			throw new HTTPException\NotFoundException('Item with URI ID ' . $uriId . 'not found' . ($uid ? ' for user ' . $uid : '.'));
		}

		$account = DI::mstdnAccount()->createFromContactId($item['author-id']);

		$counts = new \Friendica\Object\Api\Mastodon\Status\Counts(
			Post::count(['thr-parent-id' => $uriId, 'gravity' => GRAVITY_COMMENT, 'deleted' => false], [], false),
			Post::count(['thr-parent-id' => $uriId, 'gravity' => GRAVITY_ACTIVITY, 'vid' => Verb::getID(Activity::ANNOUNCE), 'deleted' => false], [], false),
			Post::count(['thr-parent-id' => $uriId, 'gravity' => GRAVITY_ACTIVITY, 'vid' => Verb::getID(Activity::LIKE), 'deleted' => false], [], false)
		);

		$userAttributes = new \Friendica\Object\Api\Mastodon\Status\UserAttributes(
			Post::exists(['thr-parent-id' => $uriId, 'uid' => $uid, 'origin' => true, 'gravity' => GRAVITY_ACTIVITY, 'vid' => Verb::getID(Activity::LIKE), 'deleted' => false]),
			Post::exists(['thr-parent-id' => $uriId, 'uid' => $uid, 'origin' => true, 'gravity' => GRAVITY_ACTIVITY, 'vid' => Verb::getID(Activity::ANNOUNCE), 'deleted' => false]),
			Post\ThreadUser::getIgnored($uriId, $uid),
			(bool)($item['starred'] && ($item['gravity'] == GRAVITY_PARENT)),
			Post\ThreadUser::getPinned($uriId, $uid)
		);

		$sensitive = DBA::exists('tag-view', ['uri-id' => $uriId, 'name' => 'nsfw']);
		$application = new \Friendica\Object\Api\Mastodon\Application($item['app'] ?: ContactSelector::networkToName($item['network'], $item['author-link']));

		$mentions    = DI::mstdnMention()->createFromUriId($uriId);
		$tags        = DI::mstdnTag()->createFromUriId($uriId);
		$card        = DI::mstdnCard()->createFromUriId($uriId);
		$attachments = DI::mstdnAttachment()->createFromUriId($uriId);

		$shared = BBCode::fetchShareAttributes($item['body']);
		if (!empty($shared['guid'])) {
			$shared_item = Post::selectFirst(['uri-id', 'plink'], ['guid' => $shared['guid']]);

			$shared_uri_id = $shared_item['uri-id'] ?? 0;

			$mentions    = array_merge($mentions, DI::mstdnMention()->createFromUriId($shared_uri_id));
			$tags        = array_merge($tags, DI::mstdnTag()->createFromUriId($shared_uri_id));
			$attachments = array_merge($attachments, DI::mstdnAttachment()->createFromUriId($shared_uri_id));

			if (empty($card->toArray())) {
				$card = DI::mstdnCard()->createFromUriId($shared_uri_id);
			}
		}


		if ($item['vid'] == Verb::getID(Activity::ANNOUNCE)) {
			$reshare = $this->createFromUriId($item['thr-parent-id'], $uid)->toArray();
			$reshared_item = Post::selectFirst(['title', 'body'], ['uri-id' => $item['thr-parent-id'], 'uid' => [0, $uid]]);
			$item['title'] = $reshared_item['title'] ?? $item['title'];
			$item['body'] = $reshared_item['body'] ?? $item['body'];
		} else {
			$reshare = [];
		}

		return new \Friendica\Object\Api\Mastodon\Status($item, $account, $counts, $userAttributes, $sensitive, $application, $mentions, $tags, $card, $attachments, $reshare);
	}

	/**
	 * @param int $uriId id of the mail
	 * @return \Friendica\Object\Api\Mastodon\Status
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public function createFromMailId(int $id)
	{
		$item = ActivityPub\Transmitter::ItemArrayFromMail($id, true);
		if (empty($item)) {
			DI::mstdnError()->RecordNotFound();
		}

		$account = DI::mstdnAccount()->createFromContactId($item['author-id']);

		$replies = DBA::count('mail', ['thr-parent-id' => $item['uri-id'], 'reply' => true]);

		$counts = new \Friendica\Object\Api\Mastodon\Status\Counts($replies, 0, 0);

		$userAttributes = new \Friendica\Object\Api\Mastodon\Status\UserAttributes(false, false, false, false, false);

		$sensitive   = false;
		$application = new \Friendica\Object\Api\Mastodon\Application('');
		$mentions    = [];
		$tags        = [];
		$card        = new \Friendica\Object\Api\Mastodon\Card([]);
		$attachments = [];
		$reshare     = [];

		return new \Friendica\Object\Api\Mastodon\Status($item, $account, $counts, $userAttributes, $sensitive, $application, $mentions, $tags, $card, $attachments, $reshare);
	}
}
