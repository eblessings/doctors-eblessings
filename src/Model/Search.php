<?php

namespace Friendica\Model;

use Friendica\BaseObject;
use Friendica\Core\Protocol;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\Network\Probe;
use Friendica\Object\Search\Result;
use Friendica\Object\Search\ResultList;
use Friendica\Protocol\PortableContact;
use Friendica\Util\Network;
use Friendica\Util\Strings;

/**
 * Model for searches
 */
class Search extends BaseObject
{
	const DEFAULT_DIRECTORY = 'https://dir.friendica.social';

	/**
	 * Returns the list of user defined tags (e.g. #Friendica)
	 *
	 * @return array
	 *
	 * @throws \Exception
	 */
	public static function getUserTags()
	{
		$termsStmt = DBA::p("SELECT DISTINCT(`term`) FROM `search`");

		$tags = [];

		while ($term = DBA::fetch($termsStmt)) {
			$tags[] = trim($term['term'], '#');
		}

		return $tags;
	}

	/**
	 * Search a user based on his/her profile address
	 * pattern: @username@domain.tld
	 *
	 * @param string $user The user to search for
	 *
	 * @return ResultList|null
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function searchUser($user)
	{
		if ((filter_var($user, FILTER_VALIDATE_EMAIL) && Network::isEmailDomainValid($user)) ||
		    (substr(Strings::normaliseLink($user), 0, 7) == "http://")) {

			$user_data = Probe::uri($user);
			if (empty($user_data)) {
				return null;
			}

			if (!(in_array($user_data["network"], [Protocol::ACTIVITYPUB, Protocol::DFRN, Protocol::OSTATUS, Protocol::DIASPORA]))) {
				return null;
			}

			$contactDetails = Contact::getDetailsByURL(defaults($user_data, 'url', ''), local_user());
			$itemurl = (($contactDetails["addr"] != "") ? $contactDetails["addr"] : defaults($user_data, 'url', ''));

			$result = new Result(
				defaults($user_data, 'name', ''),
				defaults($user_data, 'addr', ''),
				$itemurl,
				defaults($user_data, 'url', ''),
				defaults($user_data, 'photo', ''),
				defaults($user_data, 'network', ''),
				defaults($contactDetails, 'cid', 0),
				0,
				defaults($user_data, 'tags', '')
			);

			return new ResultList(1, 1, 1, [$result]);

		} else {
			return null;
		}
	}

	/**
	 * Search in the global directory for occurrences of the search string
	 * This is mainly based on the JSON results of https://dir.friendica.social
	 *
	 * @param string $search
	 * @param int    $page
	 *
	 * @return ResultList|null
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function searchDirectory($search, $page = 1)
	{
		$config = self::getApp()->getConfig();
		$server = $config->get('system', 'directory', self::DEFAULT_DIRECTORY);

		$searchUrl = $server . '/search?q=' . urlencode($search);

		if ($page > 1) {
			$searchUrl .= '&page=' . $page;
		}

		$red = 0;
		$resultJson = Network::fetchUrl($searchUrl, false,$red, 0, 'application/json');

		$results    = json_decode($resultJson, true);

		$resultList = new ResultList(
			defaults($results, 'page', 1),
			defaults($results, 'count', 1),
			defaults($results, 'itemsperpage', 1)
		);

		$profiles = defaults($results, 'profiles', []);

		foreach ($profiles as $profile) {
			$contactDetails = Contact::getDetailsByURL(defaults($profile, 'profile_url', ''), local_user());
			$itemurl = (!empty($contactDetails['addr']) ? $contactDetails['addr'] : defaults($profile, 'profile_url', ''));

			$result = new Result(
				defaults($profile, 'name', ''),
				defaults($profile, 'addr', ''),
				$itemurl,
				defaults($profile, 'profile_url', ''),
				defaults($profile, 'photo', ''),
				Protocol::DFRN,
				defaults($contactDetails, 'cid', 0),
				0,
				defaults($profile, 'tags', ''));

			$resultList->addResult($result);
		}

		return $resultList;
	}

	/**
	 * Search in the local database for occurrences of the search string
	 *
	 * @param string $search
	 * @param int    $start
	 * @param int    $itemPage
	 * @param bool   $community
	 *
	 * @return ResultList|null
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function searchLocal($search, $start = 0, $itemPage = 80, $community = false)
	{
		$config = self::getApp()->getConfig();

		$diaspora = $config->get('system', 'diaspora_enabled') ? Protocol::DIASPORA : Protocol::DFRN;
		$ostatus  = !$config->get('system', 'ostatus_disabled') ? Protocol::OSTATUS : Protocol::DFRN;

		$wildcard = Strings::escapeHtml('%' . $search . '%');

		$count = DBA::count('gcontact', [
			'NOT `hide`
			AND `network` IN (?, ?, ?, ?)
			AND ((`last_contact` >= `last_failure`) OR (`updated` >= `last_failure`))
			AND (`url` LIKE ? OR `name` LIKE ? OR `location` LIKE ? 
				OR `addr` LIKE ? OR `about` LIKE ? OR `keywords` LIKE ?)
			AND `community` = ?',
			Protocol::ACTIVITYPUB, Protocol::DFRN, $ostatus, $diaspora,
			$wildcard, $wildcard, $wildcard,
			$wildcard, $wildcard, $wildcard,
			$community,
		]);

		if (empty($count)) {
			return null;
		}

		$data = DBA::select('gcontact', ['nurl'], [
			'NOT `hide`
			AND `network` IN (?, ?, ?, ?)
			AND ((`last_contact` >= `last_failure`) OR (`updated` >= `last_failure`))
			AND (`url` LIKE ? OR `name` LIKE ? OR `location` LIKE ? 
				OR `addr` LIKE ? OR `about` LIKE ? OR `keywords` LIKE ?)
			AND `community` = ?',
			Protocol::ACTIVITYPUB, Protocol::DFRN, $ostatus, $diaspora,
			$wildcard, $wildcard, $wildcard,
			$wildcard, $wildcard, $wildcard,
			$community,
		], [
			'group_by' => ['nurl', 'updated'],
			'limit' => [$start, $itemPage],
			'order' => ['updated' => 'DESC']
		]);

		if (!DBA::isResult($data)) {
			return null;
		}

		$resultList = new ResultList($start, $itemPage, $count);

		while ($row = DBA::fetch($data)) {
			if (PortableContact::alternateOStatusUrl($row["nurl"])) {
				continue;
			}

			$urlparts = parse_url($row["nurl"]);

			// Ignore results that look strange.
			// For historic reasons the gcontact table does contain some garbage.
			if (!empty($urlparts['query']) || !empty($urlparts['fragment'])) {
				continue;
			}

			$contact = Contact::getDetailsByURL($row["nurl"], local_user());

			if ($contact["name"] == "") {
				$contact["name"] = end(explode("/", $urlparts["path"]));
			}

			$result = new Result(
				$contact["name"],
				$contact["addr"],
				$contact["addr"],
				$contact["url"],
				$contact["photo"],
				$contact["network"],
				$contact["cid"],
				$contact["zid"],
				$contact["keywords"]
			);

			$resultList->addResult($result);
		}

		DBA::close($data);

		// Add found profiles from the global directory to the local directory
		Worker::add(PRIORITY_LOW, 'DiscoverPoCo', "dirsearch", urlencode($search));

		return $resultList;
	}
}
