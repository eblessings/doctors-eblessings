<?php

namespace Friendica\Api\Mastodon;

use Friendica\Content\Text\BBCode;
use Friendica\Database\DBA;
use Friendica\Util\DateTimeFormat;

/**
 * Class Account
 *
 * @see https://docs.joinmastodon.org/api/entities/#account
 */
class Account
{
	/** @var string */
	var $id;
	/** @var string */
	var $username;
	/** @var string */
	var $acct;
	/** @var string */
	var $display_name;
	/** @var bool */
	var $locked;
	/** @var string (Datetime) */
	var $created_at;
	/** @var int */
	var $followers_count;
	/** @var int */
	var $following_count;
	/** @var int */
	var $statuses_count;
	/** @var string */
	var $note;
	/** @var string (URL)*/
	var $url;
	/** @var string (URL) */
	var $avatar;
	/** @var string (URL) */
	var $avatar_static;
	/** @var string (URL) */
	var $header;
	/** @var string (URL) */
	var $header_static;
	/** @var Emoji[] */
	var $emojis;
	/** @var Account|null */
	var $moved = null;
	/** @var Field[]|null */
	var $fields = null;
	/** @var bool|null */
	var $bot = null;

	/**
	 * Creates an account record from a contact record. Expects all contact table fields to be set
	 *
	 * @param array $contact
	 * @return Account
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function createFromContact(array $contact) {
		$account = new Account();
		$account->id = $contact['id'];
		$account->username = $contact['nick'];
		$account->acct = $contact['nick'];
		$account->display_name = $contact['name'];
		$account->locked = $contact['blocked'];
		$account->created_at = DateTimeFormat::utc($contact['created'], DateTimeFormat::ATOM);
		// No data is available from contact
		$account->followers_count = 0;
		$account->following_count = 0;
		$account->statuses_count = 0;
		$account->note = BBCode::convert($contact['about']);
		$account->url = $contact['url'];
		$account->avatar = $contact['avatar'];
		$account->avatar_static = $contact['avatar'];
		// No header picture in Friendica
		$account->header = '';
		$account->header_static = '';
		// No custom emojis per account in Friendica
		$account->emojis = [];

		return $account;
	}
}
