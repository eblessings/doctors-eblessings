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
use Friendica\Collection\Api\Mastodon\Fields;
use Friendica\Model\APContact;
use Friendica\Model\Contact;
use Friendica\Network\HTTPException;
use Friendica\Profile\ProfileField\Depository\ProfileField as ProfileFieldDepository;
use ImagickException;
use Psr\Log\LoggerInterface;

class Account extends BaseFactory
{
	/** @var BaseURL */
	private $baseUrl;
	/** @var ProfileFieldDepository */
	private $profileFieldDepo;
	/** @var Field */
	private $mstdnFieldFactory;

	public function __construct(LoggerInterface $logger, BaseURL $baseURL, ProfileFieldDepository $profileFieldDepo, Field $mstdnFieldFactory)
	{
		parent::__construct($logger);

		$this->baseUrl           = $baseURL;
		$this->profileFieldDepo  = $profileFieldDepo;
		$this->mstdnFieldFactory = $mstdnFieldFactory;
	}

	/**
	 * @param int $contactId
	 * @param int $uid        Public contact (=0) or owner user id
	 *
	 * @return \Friendica\Object\Api\Mastodon\Account
	 * @throws HTTPException\InternalServerErrorException
	 * @throws ImagickException|HTTPException\NotFoundException
	 */
	public function createFromContactId(int $contactId, $uid = 0): \Friendica\Object\Api\Mastodon\Account
	{
		$cdata = Contact::getPublicAndUserContactID($contactId, $uid);
		if (!empty($cdata)) {
			$publicContact = Contact::getById($cdata['public']);
			$userContact   = Contact::getById($cdata['user']);
		} else {
			$publicContact = Contact::getById($contactId);
			$userContact   = [];
		}

		if (empty($publicContact)) {
			throw new HTTPException\NotFoundException('Contact ' . $contactId . ' not found');
		}

		$apcontact = APContact::getByURL($publicContact['url'], false);

		$self_contact = Contact::selectFirst(['uid'], ['nurl' => $publicContact['nurl'], 'self' => true]);
		if (!empty($self_contact['uid'])) {
			$profileFields = $this->profileFieldDepo->selectPublicFieldsByUserId($self_contact['uid']);
			$fields        = $this->mstdnFieldFactory->createFromProfileFields($profileFields);
		} else {
			$fields = new Fields();
		}

		return new \Friendica\Object\Api\Mastodon\Account($this->baseUrl, $publicContact, $fields, $apcontact, $userContact);
	}

	/**
	 * @param int $userId
	 * @return \Friendica\Object\Api\Mastodon\Account
	 * @throws ImagickException|HTTPException\InternalServerErrorException
	 */
	public function createFromUserId(int $userId): \Friendica\Object\Api\Mastodon\Account
	{
		$publicContact = Contact::selectFirst([], ['uid' => $userId, 'self' => true]);

		$profileFields = $this->profileFieldDepo->selectPublicFieldsByUserId($userId);
		$fields        = $this->mstdnFieldFactory->createFromProfileFields($profileFields);

		$apContact = APContact::getByURL($publicContact['url'], false);

		return new \Friendica\Object\Api\Mastodon\Account($this->baseUrl, $publicContact, $fields, $apContact);
	}
}
