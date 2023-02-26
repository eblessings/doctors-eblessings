<?php
/**
 * @copyright Copyright (C) 2010-2023, the Friendica project
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

use Friendica\BaseFactory;
use Friendica\Content\ContactSelector;
use Friendica\Content\Item as ContentItem;
use Friendica\Content\Text\BBCode;
use Friendica\Core\Logger;
use Friendica\Database\Database;
use Friendica\Database\DBA;
use Friendica\Model\Item;
use Friendica\Model\Post;
use Friendica\Model\Tag as TagModel;
use Friendica\Model\Verb;
use Friendica\Network\HTTPException;
use Friendica\Object\Api\Mastodon\Status\FriendicaDeliveryData;
use Friendica\Object\Api\Mastodon\Status\FriendicaExtension;
use Friendica\Protocol\Activity;
use Friendica\Protocol\ActivityPub;
use ImagickException;
use Psr\Log\LoggerInterface;

class Status extends BaseFactory
{
	/** @var Database */
	private $dba;
	/** @var Account */
	private $mstdnAccountFactory;
	/** @var Mention */
	private $mstdnMentionFactory;
	/** @var Tag */
	private $mstdnTagFactory;
	/** @var Card */
	private $mstdnCardFactory;
	/** @var Attachment */
	private $mstdnAttachementFactory;
	/** @var Error */
	private $mstdnErrorFactory;
	/** @var Poll */
	private $mstdnPollFactory;
	/** @var ContentItem */
	private $contentItem;

	public function __construct(
		LoggerInterface $logger,
		Database $dba,
		Account $mstdnAccountFactory,
		Mention $mstdnMentionFactory,
		Tag $mstdnTagFactory,
		Card $mstdnCardFactory,
		Attachment $mstdnAttachementFactory,
		Error $mstdnErrorFactory,
		Poll $mstdnPollFactory,
		ContentItem $contentItem
	) {
		parent::__construct($logger);
		$this->dba                     = $dba;
		$this->mstdnAccountFactory     = $mstdnAccountFactory;
		$this->mstdnMentionFactory     = $mstdnMentionFactory;
		$this->mstdnTagFactory         = $mstdnTagFactory;
		$this->mstdnCardFactory        = $mstdnCardFactory;
		$this->mstdnAttachementFactory = $mstdnAttachementFactory;
		$this->mstdnErrorFactory       = $mstdnErrorFactory;
		$this->mstdnPollFactory        = $mstdnPollFactory;
		$this->contentItem             = $contentItem;
	}

	/**
	 * @param int  $uriId           Uri-ID of the item
	 * @param int  $uid             Item user
	 * @param bool $display_quote   Display quoted posts
	 * @param bool $reblog          Check for reblogged post
	 * @param bool $in_reply_status Add an "in_reply_status" element
	 *
	 * @return \Friendica\Object\Api\Mastodon\Status
	 * @throws HTTPException\InternalServerErrorException
	 * @throws ImagickException|HTTPException\NotFoundException
	 */
	public function createFromUriId(int $uriId, int $uid = 0, bool $display_quote = false, bool $reblog = true, bool $in_reply_status = true): \Friendica\Object\Api\Mastodon\Status
	{
		$fields = ['uri-id', 'uid', 'author-id', 'causer-id', 'author-uri-id', 'author-link', 'causer-uri-id', 'post-reason', 'starred', 'app', 'title', 'body', 'raw-body', 'content-warning', 'question-id',
			'created', 'network', 'thr-parent-id', 'parent-author-id', 'language', 'uri', 'plink', 'private', 'vid', 'gravity', 'featured', 'has-media', 'quote-uri-id',
			'delivery_queue_count', 'delivery_queue_done','delivery_queue_failed'];
		$item = Post::selectFirst($fields, ['uri-id' => $uriId, 'uid' => [0, $uid]], ['order' => ['uid' => true]]);
		if (!$item) {
			$mail = DBA::selectFirst('mail', ['id'], ['uri-id' => $uriId, 'uid' => $uid]);
			if ($mail) {
				return $this->createFromMailId($mail['id']);
			}
			throw new HTTPException\NotFoundException('Item with URI ID ' . $uriId . ' not found' . ($uid ? ' for user ' . $uid : '.'));
		}

		$activity_fields = ['uri-id', 'thr-parent-id', 'uri', 'author-id', 'author-uri-id', 'author-link', 'app', 'created', 'network', 'parent-author-id', 'private'];

		if (($item['gravity'] == Item::GRAVITY_ACTIVITY) && ($item['vid'] == Verb::getID(Activity::ANNOUNCE))) {
			$is_reshare = true;
			$account    = $this->mstdnAccountFactory->createFromUriId($item['author-uri-id'], $uid);
			$uriId      = $item['thr-parent-id'];
			$activity   = $item;
			$item       = Post::selectFirst($fields, ['uri-id' => $uriId, 'uid' => [0, $uid]], ['order' => ['uid' => true]]);
			if (!$item) {
				throw new HTTPException\NotFoundException('Item with URI ID ' . $uriId . ' not found' . ($uid ? ' for user ' . $uid : '.'));
			}
			foreach ($activity_fields as $field) {
				$item[$field] = $activity[$field];
			}
		} else {
			$is_reshare = $reblog && !is_null($item['causer-uri-id']) && ($item['causer-id'] != $item['author-id']) && ($item['post-reason'] == Item::PR_ANNOUNCEMENT);
			$account    = $this->mstdnAccountFactory->createFromUriId($is_reshare ? $item['causer-uri-id'] : $item['author-uri-id'], $uid);
			if ($is_reshare) {
				$activity = Post::selectFirstPost($activity_fields, ['thr-parent-id' => $item['uri-id'], 'author-id' => $item['causer-id'], 'verb' => Activity::ANNOUNCE]);
				if ($activity) {
					$item = array_merge($item, $activity);
				}
			}
		}

		$count_announce = Post::countPosts([
			'thr-parent-id' => $uriId,
			'gravity'       => Item::GRAVITY_ACTIVITY,
			'vid'           => Verb::getID(Activity::ANNOUNCE),
			'deleted'       => false
		]) + Post::countPosts([
			'quote-uri-id' => $uriId,
			'body'         => '',
			'deleted'      => false
		]);

		$count_like = Post::countPosts([
			'thr-parent-id' => $uriId,
			'gravity'       => Item::GRAVITY_ACTIVITY,
			'vid'           => Verb::getID(Activity::LIKE),
			'deleted'       => false
		]);

		$count_dislike = Post::countPosts([
			'thr-parent-id' => $uriId,
			'gravity'       => Item::GRAVITY_ACTIVITY,
			'vid'           => Verb::getID(Activity::DISLIKE),
			'deleted'       => false
		]);

		$counts = new \Friendica\Object\Api\Mastodon\Status\Counts(
			Post::countPosts(['thr-parent-id' => $uriId, 'gravity' => Item::GRAVITY_COMMENT, 'deleted' => false], []),
			$count_announce,
			$count_like,
			$count_dislike
		);

		$origin_like = ($count_like == 0) ? false : Post::exists([
			'thr-parent-id' => $uriId,
			'uid'           => $uid,
			'origin'        => true,
			'gravity'       => Item::GRAVITY_ACTIVITY,
			'vid'           => Verb::getID(Activity::LIKE),
			'deleted'     => false
		]);
		$origin_announce = ($count_announce == 0) ? false : Post::exists([
			'thr-parent-id' => $uriId,
			'uid'           => $uid,
			'origin'        => true,
			'gravity'       => Item::GRAVITY_ACTIVITY,
			'vid'           => Verb::getID(Activity::ANNOUNCE),
			'deleted'       => false
		]) || Post::exists([
			'quote-uri-id' => $uriId,
			'uid'           => $uid,
			'origin'        => true,
			'body'          => '',
			'deleted'       => false
		]);
		$userAttributes = new \Friendica\Object\Api\Mastodon\Status\UserAttributes(
			$origin_like,
			$origin_announce,
			Post\ThreadUser::getIgnored($uriId, $uid),
			(bool)($item['starred'] && ($item['gravity'] == Item::GRAVITY_PARENT)),
			$item['featured']
		);

		$sensitive   = $this->dba->exists('tag-view', ['uri-id' => $uriId, 'name' => 'nsfw', 'type' => TagModel::HASHTAG]);
		$application = new \Friendica\Object\Api\Mastodon\Application($item['app'] ?: ContactSelector::networkToName($item['network'], $item['author-link']));

		$mentions    = $this->mstdnMentionFactory->createFromUriId($uriId)->getArrayCopy();
		$tags        = $this->mstdnTagFactory->createFromUriId($uriId);
		if ($item['has-media']) {
			$card        = $this->mstdnCardFactory->createFromUriId($uriId);
			$attachments = $this->mstdnAttachementFactory->createFromUriId($uriId);
		} else {
			$card        = new \Friendica\Object\Api\Mastodon\Card([]);
			$attachments = [];
		}

		if (!empty($item['question-id'])) {
			$poll = $this->mstdnPollFactory->createFromId($item['question-id'], $uid)->toArray();
		} else {
			$poll = null;
		}

		if ($display_quote) {
			$quote = self::createQuote($item, $uid);

			$item['body'] = BBCode::removeSharedData($item['body']);

			if (!is_null($item['raw-body'])) {
				$item['raw-body'] = BBCode::removeSharedData($item['raw-body']);
			}
		} else {
			// We can always safely add attached activities. Real quotes are added to the body via "addSharedPost".
			if (empty($item['quote-uri-id'])) {
				$quote = self::createQuote($item, $uid);
			} else {
				$quote = [];
			}

			$shared = $this->contentItem->getSharedPost($item, ['uri-id']);
			if (!empty($shared)) {
				$shared_uri_id = $shared['post']['uri-id'];

				foreach ($this->mstdnMentionFactory->createFromUriId($shared_uri_id)->getArrayCopy() as $mention) {
					if (!in_array($mention, $mentions)) {
						$mentions[] = $mention;
					}
				}

				foreach ($this->mstdnTagFactory->createFromUriId($shared_uri_id) as $tag) {
					if (!in_array($tag, $tags)) {
						$tags[] = $tag;
					}
				}

				foreach ($this->mstdnAttachementFactory->createFromUriId($shared_uri_id) as $attachment) {
					if (!in_array($attachment, $attachments)) {
						$attachments[] = $attachment;
					}
				}

				if (empty($card->toArray())) {
					$card = $this->mstdnCardFactory->createFromUriId($shared_uri_id);
				}
			}

			$item['body'] = $this->contentItem->addSharedPost($item);

			if (!is_null($item['raw-body'])) {
				$item['raw-body'] = $this->contentItem->addSharedPost($item, $item['raw-body']);
			}
		}

		if ($is_reshare) {
			try {
				$reshare = $this->createFromUriId($uriId, $uid, $display_quote, false, false)->toArray();
			} catch (\Throwable $th) {
				Logger::info('Reshare not fetchable', ['uri-id' => $item['uri-id'], 'uid' => $uid, 'error' => $th]);
				$reshare = [];
			}
		} else {
			$reshare = [];
		}

		if ($in_reply_status && ($item['gravity'] == Item::GRAVITY_COMMENT)) {
			try {
				$in_reply = $this->createFromUriId($item['thr-parent-id'], $uid, $display_quote, false, false)->toArray();
			} catch (\Throwable $th) {
				Logger::info('Reply post not fetchable', ['uri-id' => $item['uri-id'], 'uid' => $uid, 'error' => $th]);
				$in_reply = [];
			}
		} else {
			$in_reply = [];
		}

		$delivery_data = new FriendicaDeliveryData($item['delivery_queue_count'] ?? 0, $item['delivery_queue_done'] ?? 0, $item['delivery_queue_failed'] ?? 0);
		$friendica     = new FriendicaExtension($item['title'], $counts->dislikes, $delivery_data);

		return new \Friendica\Object\Api\Mastodon\Status($item, $account, $counts, $userAttributes, $sensitive, $application, $mentions, $tags, $card, $attachments, $in_reply, $reshare, $friendica, $quote, $poll);
	}

	/**
	 * Create a quote status object
	 *
	 * @param array $item
	 * @param integer $uid
	 * @return array
	 */
	private function createQuote(array $item, int $uid): array
	{
		if (empty($item['quote-uri-id'])) {
			$media = Post\Media::getByURIId($item['uri-id'], [Post\Media::ACTIVITY]);
			if (!empty($media) && $shared_item = Post::selectFirst(['uri-id'], ['plink' => $media[0]['url'], 'uid' => [$uid, 0]])) {
				$quote_id = $shared_item['uri-id'];
			}
		} else {
			$quote_id = $item['quote-uri-id'];
		}

		if (!empty($quote_id)) {
			try {
				$quote = $this->createFromUriId($quote_id, $uid, false, false, false)->toArray();
			} catch (\Throwable $th) {
				Logger::info('Quote not fetchable', ['uri-id' => $item['uri-id'], 'uid' => $uid, 'error' => $th]);
				$quote = [];
			}
		} else {
			$quote = [];
		}
		return $quote;
	}

	/**
	 * @param int $uriId id of the mail
	 *
	 * @return \Friendica\Object\Api\Mastodon\Status
	 * @throws HTTPException\InternalServerErrorException
	 * @throws ImagickException|HTTPException\NotFoundException
	 */
	public function createFromMailId(int $id): \Friendica\Object\Api\Mastodon\Status
	{
		$item = ActivityPub\Transmitter::getItemArrayFromMail($id, true);
		if (empty($item)) {
			$this->mstdnErrorFactory->RecordNotFound();
		}

		$account = $this->mstdnAccountFactory->createFromContactId($item['author-id']);

		$replies = $this->dba->count('mail', ['thr-parent-id' => $item['uri-id'], 'reply' => true]);

		$counts = new \Friendica\Object\Api\Mastodon\Status\Counts($replies, 0, 0, 0);

		$userAttributes = new \Friendica\Object\Api\Mastodon\Status\UserAttributes(false, false, false, false, false);

		$sensitive   = false;
		$application = new \Friendica\Object\Api\Mastodon\Application('');
		$mentions    = [];
		$tags        = [];
		$card        = new \Friendica\Object\Api\Mastodon\Card([]);
		$attachments = [];
		$in_reply    = [];
		$reshare     = [];
		$friendica   = new FriendicaExtension('', 0, new FriendicaDeliveryData(0, 0, 0));

		return new \Friendica\Object\Api\Mastodon\Status($item, $account, $counts, $userAttributes, $sensitive, $application, $mentions, $tags, $card, $attachments, $in_reply, $reshare, $friendica);
	}
}
