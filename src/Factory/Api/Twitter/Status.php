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

namespace Friendica\Factory\Api\Twitter;

use Friendica\BaseFactory;
use Friendica\Content\Text\BBCode;
use Friendica\Content\Text\HTML;
use Friendica\Database\Database;
use Friendica\Factory\Api\Friendica\Activities;
use Friendica\Factory\Api\Twitter\User as TwitterUser;
use Friendica\Model\Post;
use Friendica\Model\Verb;
use Friendica\Network\HTTPException;
use Friendica\Protocol\Activity;
use ImagickException;
use Psr\Log\LoggerInterface;

class Status extends BaseFactory
{
	/** @var Database */
	private $dba;
	/** @var twitterUser entity */
	private $twitterUser;
	/** @var Hashtag entity */
	private $hashtag;
	/** @var Media entity */
	private $media;
	/** @var Url entity */
	private $url;
	/** @var Mention entity */
	private $mention;
	/** @var Activities entity */
	private $activities;
	/** @var Activities entity */
	private $attachment;

	public function __construct(LoggerInterface $logger, Database $dba, TwitterUser $twitteruser, Hashtag $hashtag, Media $media, Url $url, Mention $mention, Activities $activities, Attachment $attachment)
	{
		parent::__construct($logger);
		$this->dba         = $dba;
		$this->twitterUser = $twitteruser;
		$this->hashtag     = $hashtag;
		$this->media       = $media;
		$this->url         = $url;
		$this->mention     = $mention;
		$this->activities  = $activities;
		$this->attachment  = $attachment;
	}

	/**
	 * @param int $uriId Uri-ID of the item
	 * @param int $uid   Item user
	 *
	 * @return \Friendica\Object\Api\Mastodon\Status
	 * @throws HTTPException\InternalServerErrorException
	 * @throws ImagickException|HTTPException\NotFoundException
	 */
	public function createFromItemId(int $id): \Friendica\Object\Api\Twitter\Status
	{
		$item = Post::selectFirst([], ['id' => $id], ['order' => ['uid' => true]]);
		if (!$item) {
			throw new HTTPException\NotFoundException('Item with ID ' . $id . ' not found.');
		}
		return $this->createFromArray($item);
	}

		/**
	 * @param int $uriId Uri-ID of the item
	 * @param int $uid   Item user
	 *
	 * @return \Friendica\Object\Api\Mastodon\Status
	 * @throws HTTPException\InternalServerErrorException
	 * @throws ImagickException|HTTPException\NotFoundException
	 */
	public function createFromUriId(int $uriId, $uid = 0): \Friendica\Object\Api\Twitter\Status
	{
		$item = Post::selectFirst([], ['uri-id' => $uriId, 'uid' => [0, $uid]], ['order' => ['uid' => true]]);
		if (!$item) {
			throw new HTTPException\NotFoundException('Item with URI ID ' . $uriId . ' not found' . ($uid ? ' for user ' . $uid : '.'));
		}
		return $this->createFromArray($item);
	}

	/**
	 * @param array $item item array
	 * @param int   $uid  Item user
	 *
	 * @return \Friendica\Object\Api\Mastodon\Status
	 * @throws HTTPException\InternalServerErrorException
	 * @throws ImagickException|HTTPException\NotFoundException
	 */
	public function createFromArray(array $item, $uid = 0): \Friendica\Object\Api\Twitter\Status
	{
		$author = $this->twitterUser->createFromContactId($item['author-id'], $item['uid']);
		$owner  = $this->twitterUser->createFromContactId($item['owner-id'], $item['uid']);

		$friendica_comments = Post::countPosts(['thr-parent-id' => $item['uri-id'], 'deleted' => false, 'gravity' => GRAVITY_COMMENT]);

		$text = trim(HTML::toPlaintext(BBCode::convertForUriId($item['uri-id'], $item['body'], BBCode::API), 0));

		$geo = [];

		if ($item['coord'] != '') {
			$coords = explode(' ', $item["coord"]);
			if (count($coords) == 2) {
				$geo = [
					'type'        => 'Point',
					'coordinates' => [(float) $coords[0], (float) $coords[1]]
				];
			}
		}

		$hashtags = $this->hashtag->createFromUriId($item['uri-id'], $text);
		$medias   = $this->media->createFromUriId($item['uri-id'], $text);
		$urls     = $this->url->createFromUriId($item['uri-id'], $text);
		$mentions = $this->mention->createFromUriId($item['uri-id'], $text);

		$friendica_activities = $this->activities->createFromUriId($item['uri-id'], $uid);
		$attachments          = $this->attachment->createFromUriId($item['uri-id'], $text);

		$shared = BBCode::fetchShareAttributes($item['body']);
		if (!empty($shared['guid'])) {
			$shared_item = Post::selectFirst(['uri-id', 'plink'], ['guid' => $shared['guid']]);

			$shared_uri_id = $shared_item['uri-id'] ?? 0;

			$hashtags    = array_merge($hashtags, $this->hashtag->createFromUriId($shared_uri_id, $text));
			$medias      = array_merge($medias, $this->media->createFromUriId($shared_uri_id, $text));
			$urls        = array_merge($urls, $this->url->createFromUriId($shared_uri_id, $text));
			$mentions    = array_merge($mentions, $this->mention->createFromUriId($shared_uri_id, $text));
			$attachments = array_merge($attachments, $this->attachment->createFromUriId($item['uri-id'], $text));
		}

		if ($item['vid'] == Verb::getID(Activity::ANNOUNCE)) {
			$retweeted      = $this->createFromUriId($item['thr-parent-id'], $uid)->toArray();
			$retweeted_item = Post::selectFirst(['title', 'body', 'author-id'], ['uri-id' => $item['thr-parent-id'],'uid' => [0, $uid]]);
			$item['title']  = $retweeted_item['title'] ?? $item['title'];
			$item['body']   = $retweeted_item['body']  ?? $item['body'];
			$author         = $this->twitterUser->createFromContactId($retweeted_item['author-id'], $item['uid']);
		} else {
			$retweeted = [];
		}

		$quoted = []; // @todo

		$entities = ['hashtags' => $hashtags, 'media' => $medias, 'urls' => $urls, 'user_mentions' => $mentions];

		// Attachments are currently deactivated for testing purposes
		$attachments = [];

		return new \Friendica\Object\Api\Twitter\Status($text, $item, $author, $owner, $retweeted, $quoted, $geo, $friendica_activities, $entities, $attachments,  $friendica_comments);
	}
}
