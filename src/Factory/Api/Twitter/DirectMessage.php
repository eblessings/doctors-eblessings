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
use Friendica\Database\DBA;
use Friendica\Factory\Api\Friendica\Activities;
use Friendica\Factory\Api\Twitter\User as TwitterUser;
use Friendica\Model\Contact;
use Friendica\Network\HTTPException;
use Psr\Log\LoggerInterface;

class DirectMessage extends BaseFactory
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
	 * Create a direct message from a given mail id
	 *
	 * @todo Processing of "getUserObjects" (true/false) and "getText" (html/plain)
	 *
	 * @param int    $id        Mail id
	 * @param int    $uid       Mail user
	 * @param string $text_mode Either empty, "html" or "text"
	 *
	 * @return \Friendica\Object\Api\Twitter\DirectMessage
	 */
	public function createFromMailId(int $id, int $uid, string $text_mode = ''): \Friendica\Object\Api\Twitter\DirectMessage
	{
		$mail = DBA::selectFirst('mail', [], ['id' => $id, 'uid' => $uid]);
		if (!$mail) {
			throw new HTTPException\NotFoundException('Direct message with ID ' . $mail . ' not found.');
		}

		if (!empty($text_mode)) {
			$title = $mail['title'];
			if ($text_mode == 'html') {
				$text = BBCode::convertForUriId($mail['uri-id'], $mail['body'], BBCode::API);
			} elseif ($text_mode == 'plain') {
				$text = HTML::toPlaintext(BBCode::convertForUriId($mail['uri-id'], $mail['body'], BBCode::API), 0);
			}
		} else {
			$title = '';
			$text  = $mail['title'] . "\n" . HTML::toPlaintext(BBCode::convertForUriId($mail['uri-id'], $mail['body'], BBCode::API), 0);
		}

		$pcid = Contact::getPublicIdByUserId($uid);

		if ($mail['author-id'] == $pcid) {
			$sender    = $this->twitterUser->createFromUserId($uid, true);
			$recipient = $this->twitterUser->createFromContactId($mail['contact-id'], $uid, true);
		} else {
			$sender    = $this->twitterUser->createFromContactId($mail['author-id'], $uid, true);
			$recipient = $this->twitterUser->createFromUserId($uid, true);
		}

		return new \Friendica\Object\Api\Twitter\DirectMessage($mail, $sender, $recipient, $text, $title);
	}
}
