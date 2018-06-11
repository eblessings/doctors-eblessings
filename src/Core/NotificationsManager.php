<?php
/**
 * @file src/Core/NotificationsManager.php
 * @brief Methods for read and write notifications from/to database
 *  or for formatting notifications
 */
namespace Friendica\Core;

use Friendica\BaseObject;
use Friendica\Content\Text\BBCode;
use Friendica\Content\Text\HTML;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\System;
use Friendica\Database\DBM;
use Friendica\Model\Contact;
use Friendica\Model\Profile;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Temporal;
use Friendica\Util\XML;

require_once 'include/dba.php';

/**
 * @brief Methods for read and write notifications from/to database
 *  or for formatting notifications
 */
class NotificationsManager extends BaseObject
{
	/**
	 * @brief set some extra note properties
	 *
	 * @param array $notes array of note arrays from db
	 * @return array Copy of input array with added properties
	 *
	 * Set some extra properties to note array from db:
	 *  - timestamp as int in default TZ
	 *  - date_rel : relative date string
	 *  - msg_html: message as html string
	 *  - msg_plain: message as plain text string
	 */
	private function _set_extra($notes)
	{
		$rets = [];
		foreach ($notes as $n) {
			$local_time = DateTimeFormat::local($n['date']);
			$n['timestamp'] = strtotime($local_time);
			$n['date_rel'] = Temporal::getRelativeDate($n['date']);
			$n['msg_html'] = BBCode::convert($n['msg'], false);
			$n['msg_plain'] = explode("\n", trim(HTML::toPlaintext($n['msg_html'], 0)))[0];

			$rets[] = $n;
		}
		return $rets;
	}

	/**
	 * @brief Get all notifications for local_user()
	 *
	 * @param array  $filter optional Array "column name"=>value: filter query by columns values
	 * @param string $order  optional Space separated list of column to sort by.
	 *                       Prepend name with "+" to sort ASC, "-" to sort DESC. Default to "-date"
	 * @param string $limit  optional Query limits
	 *
	 * @return array of results or false on errors
	 */
	public function getAll($filter = [], $order = "-date", $limit = "")
	{
		$filter_str = [];
		$filter_sql = "";
		foreach ($filter as $column => $value) {
			$filter_str[] = sprintf("`%s` = '%s'", $column, dbesc($value));
		}
		if (count($filter_str) > 0) {
			$filter_sql = "AND " . implode(" AND ", $filter_str);
		}

		$aOrder = explode(" ", $order);
		$asOrder = [];
		foreach ($aOrder as $o) {
			$dir = "asc";
			if ($o[0] === "-") {
				$dir = "desc";
				$o = substr($o, 1);
			}
			if ($o[0] === "+") {
				$dir = "asc";
				$o = substr($o, 1);
			}
			$asOrder[] = "$o $dir";
		}
		$order_sql = implode(", ", $asOrder);

		if ($limit != "") {
			$limit = " LIMIT " . $limit;
		}
		$r = q(
			"SELECT * FROM `notify` WHERE `uid` = %d $filter_sql ORDER BY $order_sql $limit",
			intval(local_user())
		);

		if (DBM::is_result($r)) {
			return $this->_set_extra($r);
		}

		return false;
	}

	/**
	 * @brief Get one note for local_user() by $id value
	 *
	 * @param int $id identity
	 * @return array note values or null if not found
	 */
	public function getByID($id)
	{
		$r = q(
			"SELECT * FROM `notify` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($id),
			intval(local_user())
		);
		if (DBM::is_result($r)) {
			return $this->_set_extra($r)[0];
		}
		return null;
	}

	/**
	 * @brief set seen state of $note of local_user()
	 *
	 * @param array $note note array
	 * @param bool  $seen optional true or false, default true
	 * @return bool true on success, false on errors
	 */
	public function setSeen($note, $seen = true)
	{
		return q(
			"UPDATE `notify` SET `seen` = %d WHERE (`link` = '%s' OR (`parent` != 0 AND `parent` = %d AND `otype` = '%s')) AND `uid` = %d",
			intval($seen),
			dbesc($note['link']),
			intval($note['parent']),
			dbesc($note['otype']),
			intval(local_user())
		);
	}

	/**
	 * @brief set seen state of all notifications of local_user()
	 *
	 * @param bool $seen optional true or false. default true
	 * @return bool true on success, false on error
	 */
	public function setAllSeen($seen = true)
	{
		return q(
			"UPDATE `notify` SET `seen` = %d WHERE `uid` = %d",
			intval($seen),
			intval(local_user())
		);
	}

	/**
	 * @brief List of pages for the Notifications TabBar
	 *
	 * @return array with with notifications TabBar data
	 */
	public function getTabs()
	{
		$tabs = [
			[
				'label' => L10n::t('System'),
				'url'   => 'notifications/system',
				'sel'   => ((self::getApp()->argv[1] == 'system') ? 'active' : ''),
				'id'    => 'system-tab',
				'accesskey' => 'y',
			],
			[
				'label' => L10n::t('Network'),
				'url'   => 'notifications/network',
				'sel'   => ((self::getApp()->argv[1] == 'network') ? 'active' : ''),
				'id'    => 'network-tab',
				'accesskey' => 'w',
			],
			[
				'label' => L10n::t('Personal'),
				'url'   => 'notifications/personal',
				'sel'   => ((self::getApp()->argv[1] == 'personal') ? 'active' : ''),
				'id'    => 'personal-tab',
				'accesskey' => 'r',
			],
			[
				'label' => L10n::t('Home'),
				'url'   => 'notifications/home',
				'sel'   => ((self::getApp()->argv[1] == 'home') ? 'active' : ''),
				'id'    => 'home-tab',
				'accesskey' => 'h',
			],
			[
				'label' => L10n::t('Introductions'),
				'url'   => 'notifications/intros',
				'sel'   => ((self::getApp()->argv[1] == 'intros') ? 'active' : ''),
				'id'    => 'intro-tab',
				'accesskey' => 'i',
			],
		];

		return $tabs;
	}

	/**
	 * @brief Format the notification query in an usable array
	 *
	 * @param array  $notifs The array from the db query
	 * @param string $ident  The notifications identifier (e.g. network)
	 * @return array
	 * 	string 'label' => The type of the notification
	 * 	string 'link' => URL to the source
	 * 	string 'image' => The avatar image
	 * 	string 'url' => The profile url of the contact
	 * 	string 'text' => The notification text
	 * 	string 'when' => The date of the notification
	 * 	string 'ago' => T relative date of the notification
	 * 	bool 'seen' => Is the notification marked as "seen"
	 */
	private function formatNotifs($notifs, $ident = "")
	{
		$notif = [];
		$arr = [];

		if (DBM::is_result($notifs)) {
			foreach ($notifs as $it) {
				// Because we use different db tables for the notification query
				// we have sometimes $it['unseen'] and sometimes $it['seen].
				// So we will have to transform $it['unseen']
				if (array_key_exists('unseen', $it)) {
					$it['seen'] = ($it['unseen'] > 0 ? false : true);
				}

				// Depending on the identifier of the notification we need to use different defaults
				switch ($ident) {
					case 'system':
						$default_item_label = 'notify';
						$default_item_link = System::baseUrl(true) . '/notify/view/' . $it['id'];
						$default_item_image = proxy_url($it['photo'], false, PROXY_SIZE_MICRO);
						$default_item_url = $it['url'];
						$default_item_text = strip_tags(BBCode::convert($it['msg']));
						$default_item_when = DateTimeFormat::local($it['date'], 'r');
						$default_item_ago = Temporal::getRelativeDate($it['date']);
						break;

					case 'home':
						$default_item_label = 'comment';
						$default_item_link = System::baseUrl(true) . '/display/' . $it['pguid'];
						$default_item_image = proxy_url($it['author-avatar'], false, PROXY_SIZE_MICRO);
						$default_item_url = $it['author-link'];
						$default_item_text = L10n::t("%s commented on %s's post", $it['author-name'], $it['pname']);
						$default_item_when = DateTimeFormat::local($it['created'], 'r');
						$default_item_ago = Temporal::getRelativeDate($it['created']);
						break;

					default:
						$default_item_label = (($it['id'] == $it['parent']) ? 'post' : 'comment');
						$default_item_link = System::baseUrl(true) . '/display/' . $it['pguid'];
						$default_item_image = proxy_url($it['author-avatar'], false, PROXY_SIZE_MICRO);
						$default_item_url = $it['author-link'];
						$default_item_text = (($it['id'] == $it['parent'])
									? L10n::t("%s created a new post", $it['author-name'])
									: L10n::t("%s commented on %s's post", $it['author-name'], $it['pname']));
						$default_item_when = DateTimeFormat::local($it['created'], 'r');
						$default_item_ago = Temporal::getRelativeDate($it['created']);
				}

				// Transform the different types of notification in an usable array
				switch ($it['verb']) {
					case ACTIVITY_LIKE:
						$notif = [
							'label' => 'like',
							'link' => System::baseUrl(true) . '/display/' . $it['pguid'],
							'image' => proxy_url($it['author-avatar'], false, PROXY_SIZE_MICRO),
							'url' => $it['author-link'],
							'text' => L10n::t("%s liked %s's post", $it['author-name'], $it['pname']),
							'when' => $default_item_when,
							'ago' => $default_item_ago,
							'seen' => $it['seen']
						];
						break;

					case ACTIVITY_DISLIKE:
						$notif = [
							'label' => 'dislike',
							'link' => System::baseUrl(true) . '/display/' . $it['pguid'],
							'image' => proxy_url($it['author-avatar'], false, PROXY_SIZE_MICRO),
							'url' => $it['author-link'],
							'text' => L10n::t("%s disliked %s's post", $it['author-name'], $it['pname']),
							'when' => $default_item_when,
							'ago' => $default_item_ago,
							'seen' => $it['seen']
						];
						break;

					case ACTIVITY_ATTEND:
						$notif = [
							'label' => 'attend',
							'link' => System::baseUrl(true) . '/display/' . $it['pguid'],
							'image' => proxy_url($it['author-avatar'], false, PROXY_SIZE_MICRO),
							'url' => $it['author-link'],
							'text' => L10n::t("%s is attending %s's event", $it['author-name'], $it['pname']),
							'when' => $default_item_when,
							'ago' => $default_item_ago,
							'seen' => $it['seen']
						];
						break;

					case ACTIVITY_ATTENDNO:
						$notif = [
							'label' => 'attendno',
							'link' => System::baseUrl(true) . '/display/' . $it['pguid'],
							'image' => proxy_url($it['author-avatar'], false, PROXY_SIZE_MICRO),
							'url' => $it['author-link'],
							'text' => L10n::t("%s is not attending %s's event", $it['author-name'], $it['pname']),
							'when' => $default_item_when,
							'ago' => $default_item_ago,
							'seen' => $it['seen']
						];
						break;

					case ACTIVITY_ATTENDMAYBE:
						$notif = [
							'label' => 'attendmaybe',
							'link' => System::baseUrl(true) . '/display/' . $it['pguid'],
							'image' => proxy_url($it['author-avatar'], false, PROXY_SIZE_MICRO),
							'url' => $it['author-link'],
							'text' => L10n::t("%s may attend %s's event", $it['author-name'], $it['pname']),
							'when' => $default_item_when,
							'ago' => $default_item_ago,
							'seen' => $it['seen']
						];
						break;

					case ACTIVITY_FRIEND:
						$xmlhead = "<" . "?xml version='1.0' encoding='UTF-8' ?" . ">";
						$obj = XML::parseString($xmlhead . $it['object']);
						$it['fname'] = $obj->title;

						$notif = [
							'label' => 'friend',
							'link' => System::baseUrl(true) . '/display/' . $it['pguid'],
							'image' => proxy_url($it['author-avatar'], false, PROXY_SIZE_MICRO),
							'url' => $it['author-link'],
							'text' => L10n::t("%s is now friends with %s", $it['author-name'], $it['fname']),
							'when' => $default_item_when,
							'ago' => $default_item_ago,
							'seen' => $it['seen']
						];
						break;

					default:
						$notif = [
							'label' => $default_item_label,
							'link' => $default_item_link,
							'image' => $default_item_image,
							'url' => $default_item_url,
							'text' => $default_item_text,
							'when' => $default_item_when,
							'ago' => $default_item_ago,
							'seen' => $it['seen']
						];
				}

				$arr[] = $notif;
			}
		}

		return $arr;
	}

	/**
	 * @brief Total number of network notifications
	 * @param int|string $seen If 0 only include notifications into the query
	 * 	                       which aren't marked as "seen"
	 *
	 * @return int Number of network notifications
	 */
	private function networkTotal($seen = 0)
	{
		$sql_seen = "";
		$index_hint = "";

		if ($seen === 0) {
			$sql_seen = " AND `item`.`unseen` ";
			$index_hint = "USE INDEX (`uid_unseen_contactid`)";
		}

		$r = q(
			"SELECT COUNT(*) AS `total`
				FROM `item` $index_hint STRAIGHT_JOIN `item` AS `pitem` ON `pitem`.`id`=`item`.`parent`
				WHERE `item`.`visible` AND `pitem`.`parent` != 0 AND
				NOT `item`.`deleted` AND `item`.`uid` = %d AND NOT `item`.`wall`
				$sql_seen",
			intval(local_user())
		);
		if (DBM::is_result($r)) {
			return $r[0]['total'];
		}

		return 0;
	}

	/**
	 * @brief Get network notifications
	 *
	 * @param int|string $seen  If 0 only include notifications into the query
	 * 	                        which aren't marked as "seen"
	 * @param int        $start Start the query at this point
	 * @param int        $limit Maximum number of query results
	 *
	 * @return array with
	 * 	string 'ident' => Notification identifier
	 * 	int 'total' => Total number of available network notifications
	 * 	array 'notifications' => Network notifications
	 */
	public function networkNotifs($seen = 0, $start = 0, $limit = 80)
	{
		$ident = 'network';
		$total = $this->networkTotal($seen);
		$notifs = [];
		$sql_seen = "";
		$index_hint = "";

		if ($seen === 0) {
			$sql_seen = " AND `item`.`unseen` ";
			$index_hint = "USE INDEX (`uid_unseen_contactid`)";
		}

		$r = q(
			"SELECT `item`.`id`,`item`.`parent`, `item`.`verb`, `item`.`author-name`, `item`.`unseen`,
				`item`.`author-link`, `item`.`author-avatar`, `item`.`created`, `item`.`object` AS `object`,
				`pitem`.`author-name` AS `pname`, `pitem`.`author-link` AS `plink`, `pitem`.`guid` AS `pguid`
			FROM `item` $index_hint STRAIGHT_JOIN `item` AS `pitem` ON `pitem`.`id`=`item`.`parent`
			WHERE `item`.`visible` AND `pitem`.`parent` != 0 AND
				NOT `item`.`deleted` AND `item`.`uid` = %d AND NOT `item`.`wall`
				$sql_seen
			ORDER BY `item`.`created` DESC LIMIT %d, %d ",
			intval(local_user()),
			intval($start),
			intval($limit)
		);
		if (DBM::is_result($r)) {
			$notifs = $this->formatNotifs($r, $ident);
		}

		$arr = [
			'notifications' => $notifs,
			'ident' => $ident,
			'total' => $total,
		];

		return $arr;
	}

	/**
	 * @brief Total number of system notifications
	 * @param int|string $seen If 0 only include notifications into the query
	 * 	                       which aren't marked as "seen"
	 *
	 * @return int Number of system notifications
	 */
	private function systemTotal($seen = 0)
	{
		$sql_seen = "";

		if ($seen === 0) {
			$sql_seen = " AND NOT `seen` ";
		}

		$r = q(
			"SELECT COUNT(*) AS `total` FROM `notify` WHERE `uid` = %d $sql_seen",
			intval(local_user())
		);
		if (DBM::is_result($r)) {
			return $r[0]['total'];
		}

		return 0;
	}

	/**
	 * @brief Get system notifications
	 *
	 * @param int|string $seen  If 0 only include notifications into the query
	 * 	                        which aren't marked as "seen"
	 * @param int        $start Start the query at this point
	 * @param int        $limit Maximum number of query results
	 *
	 * @return array with
	 * 	string 'ident' => Notification identifier
	 * 	int 'total' => Total number of available system notifications
	 * 	array 'notifications' => System notifications
	 */
	public function systemNotifs($seen = 0, $start = 0, $limit = 80)
	{
		$ident = 'system';
		$total = $this->systemTotal($seen);
		$notifs = [];
		$sql_seen = "";

		if ($seen === 0) {
			$sql_seen = " AND NOT `seen` ";
		}

		$r = q(
			"SELECT `id`, `url`, `photo`, `msg`, `date`, `seen` FROM `notify`
				WHERE `uid` = %d $sql_seen ORDER BY `date` DESC LIMIT %d, %d ",
			intval(local_user()),
			intval($start),
			intval($limit)
		);
		if (DBM::is_result($r)) {
			$notifs = $this->formatNotifs($r, $ident);
		}

		$arr = [
			'notifications' => $notifs,
			'ident' => $ident,
			'total' => $total,
		];

		return $arr;
	}

	/**
	 * @brief Additional SQL query string for the personal notifications
	 *
	 * @return string The additional SQL query
	 */
	private function personalSqlExtra()
	{
		$myurl = System::baseUrl(true) . '/profile/' . self::getApp()->user['nickname'];
		$myurl = substr($myurl, strpos($myurl, '://') + 3);
		$myurl = str_replace(['www.', '.'], ['', '\\.'], $myurl);
		$diasp_url = str_replace('/profile/', '/u/', $myurl);
		$sql_extra = sprintf(
			" AND (`item`.`author-link` REGEXP '%s' OR `item`.`tag` REGEXP '%s' OR `item`.`tag` REGEXP '%s') ",
			dbesc($myurl . '$'),
			dbesc($myurl . '\\]'),
			dbesc($diasp_url . '\\]')
		);

		return $sql_extra;
	}

	/**
	 * @brief Total number of personal notifications
	 * @param int|string $seen If 0 only include notifications into the query
	 * 	                       which aren't marked as "seen"
	 *
	 * @return int Number of personal notifications
	 */
	private function personalTotal($seen = 0)
	{
		$sql_seen = "";
		$index_hint = "";
		$sql_extra = $this->personalSqlExtra();

		if ($seen === 0) {
			$sql_seen = " AND `item`.`unseen` ";
			$index_hint = "USE INDEX (`uid_unseen_contactid`)";
		}

		$r = q(
			"SELECT COUNT(*) AS `total`
				FROM `item` $index_hint
				WHERE `item`.`visible`
				$sql_extra
				$sql_seen
				AND NOT `item`.`deleted` AND `item`.`uid` = %d AND NOT `item`.`wall`",
			intval(local_user())
		);
		if (DBM::is_result($r)) {
			return $r[0]['total'];
		}

		return 0;
	}

	/**
	 * @brief Get personal notifications
	 *
	 * @param int|string $seen  If 0 only include notifications into the query
	 * 	                        which aren't marked as "seen"
	 * @param int        $start Start the query at this point
	 * @param int        $limit Maximum number of query results
	 *
	 * @return array with
	 * 	string 'ident' => Notification identifier
	 * 	int 'total' => Total number of available personal notifications
	 * 	array 'notifications' => Personal notifications
	 */
	public function personalNotifs($seen = 0, $start = 0, $limit = 80)
	{
		$ident = 'personal';
		$total = $this->personalTotal($seen);
		$sql_extra = $this->personalSqlExtra();
		$notifs = [];
		$sql_seen = "";
		$index_hint = "";

		if ($seen === 0) {
			$sql_seen = " AND `item`.`unseen` ";
			$index_hint = "USE INDEX (`uid_unseen_contactid`)";
		}

		$r = q(
			"SELECT `item`.`id`,`item`.`parent`, `item`.`verb`, `item`.`author-name`, `item`.`unseen`,
				`item`.`author-link`, `item`.`author-avatar`, `item`.`created`, `item`.`object` AS `object`,
				`pitem`.`author-name` AS `pname`, `pitem`.`author-link` AS `plink`, `pitem`.`guid` AS `pguid`
			FROM `item` $index_hint STRAIGHT_JOIN `item` AS `pitem` ON `pitem`.`id`=`item`.`parent`
			WHERE `item`.`visible`
				$sql_extra
				$sql_seen
				AND NOT `item`.`deleted` AND `item`.`uid` = %d AND NOT `item`.`wall`
			ORDER BY `item`.`created` DESC LIMIT %d, %d ",
			intval(local_user()),
			intval($start),
			intval($limit)
		);
		if (DBM::is_result($r)) {
			$notifs = $this->formatNotifs($r, $ident);
		}

		$arr = [
			'notifications' => $notifs,
			'ident' => $ident,
			'total' => $total,
		];

		return $arr;
	}

	/**
	 * @brief Total number of home notifications
	 * @param int|string $seen If 0 only include notifications into the query
	 * 	                       which aren't marked as "seen"
	 *
	 * @return int Number of home notifications
	 */
	private function homeTotal($seen = 0)
	{
		$sql_seen = "";
		$index_hint = "";

		if ($seen === 0) {
			$sql_seen = " AND `item`.`unseen` ";
			$index_hint = "USE INDEX (`uid_unseen_contactid`)";
		}

		$r = q(
			"SELECT COUNT(*) AS `total` FROM `item` $index_hint
				WHERE `item`.`visible` = 1 AND
				 `item`.`deleted` = 0 AND `item`.`uid` = %d AND `item`.`wall` = 1
				$sql_seen",
			intval(local_user())
		);
		if (DBM::is_result($r)) {
			return $r[0]['total'];
		}

		return 0;
	}

	/**
	 * @brief Get home notifications
	 *
	 * @param int|string $seen  If 0 only include notifications into the query
	 * 	                        which aren't marked as "seen"
	 * @param int        $start Start the query at this point
	 * @param int        $limit Maximum number of query results
	 *
	 * @return array with
	 * 	string 'ident' => Notification identifier
	 * 	int 'total' => Total number of available home notifications
	 * 	array 'notifications' => Home notifications
	 */
	public function homeNotifs($seen = 0, $start = 0, $limit = 80)
	{
		$ident = 'home';
		$total = $this->homeTotal($seen);
		$notifs = [];
		$sql_seen = "";
		$index_hint = "";

		if ($seen === 0) {
			$sql_seen = " AND `item`.`unseen` ";
			$index_hint = "USE INDEX (`uid_unseen_contactid`)";
		}

		$r = q(
			"SELECT `item`.`id`,`item`.`parent`, `item`.`verb`, `item`.`author-name`, `item`.`unseen`,
				`item`.`author-link`, `item`.`author-avatar`, `item`.`created`, `item`.`object` AS `object`,
				`pitem`.`author-name` AS `pname`, `pitem`.`author-link` AS `plink`, `pitem`.`guid` AS `pguid`
			FROM `item` $index_hint STRAIGHT_JOIN `item` AS `pitem` ON `pitem`.`id`=`item`.`parent`
			WHERE `item`.`visible` AND
				NOT `item`.`deleted` AND `item`.`uid` = %d AND `item`.`wall`
				$sql_seen
			ORDER BY `item`.`created` DESC LIMIT %d, %d ",
			intval(local_user()),
			intval($start),
			intval($limit)
		);
		if (DBM::is_result($r)) {
			$notifs = $this->formatNotifs($r, $ident);
		}

		$arr = [
			'notifications' => $notifs,
			'ident' => $ident,
			'total' => $total,
		];

		return $arr;
	}

	/**
	 * @brief Total number of introductions
	 * @param bool $all If false only include introductions into the query
	 * 	                which aren't marked as ignored
	 *
	 * @return int Number of introductions
	 */
	private function introTotal($all = false)
	{
		$sql_extra = "";

		if (!$all) {
			$sql_extra = " AND `ignore` = 0 ";
		}

		$r = q(
			"SELECT COUNT(*) AS `total` FROM `intro`
			WHERE `intro`.`uid` = %d $sql_extra AND `intro`.`blocked` = 0 ",
			intval($_SESSION['uid'])
		);

		if (DBM::is_result($r)) {
			return $r[0]['total'];
		}

		return 0;
	}

	/**
	 * @brief Get introductions
	 *
	 * @param bool $all   If false only include introductions into the query
	 * 	                  which aren't marked as ignored
	 * @param int  $start Start the query at this point
	 * @param int  $limit Maximum number of query results
	 *
	 * @return array with
	 *	string 'ident' => Notification identifier
	 *	int 'total' => Total number of available introductions
	 *	array 'notifications' => Introductions
	 */
	public function introNotifs($all = false, $start = 0, $limit = 80)
	{
		$ident = 'introductions';
		$total = $this->introTotal($all);
		$notifs = [];
		$sql_extra = "";

		if (!$all) {
			$sql_extra = " AND `ignore` = 0 ";
		}

		/// @todo Fetch contact details by "Contact::getDetailsByUrl" instead of queries to contact, fcontact and gcontact
		$r = q(
			"SELECT `intro`.`id` AS `intro_id`, `intro`.*, `contact`.*,
				`fcontact`.`name` AS `fname`, `fcontact`.`url` AS `furl`,
				`fcontact`.`photo` AS `fphoto`, `fcontact`.`request` AS `frequest`,
				`gcontact`.`location` AS `glocation`, `gcontact`.`about` AS `gabout`,
				`gcontact`.`keywords` AS `gkeywords`, `gcontact`.`gender` AS `ggender`,
				`gcontact`.`network` AS `gnetwork`, `gcontact`.`addr` AS `gaddr`
			FROM `intro`
				LEFT JOIN `contact` ON `contact`.`id` = `intro`.`contact-id`
				LEFT JOIN `gcontact` ON `gcontact`.`nurl` = `contact`.`nurl`
				LEFT JOIN `fcontact` ON `intro`.`fid` = `fcontact`.`id`
			WHERE `intro`.`uid` = %d $sql_extra AND `intro`.`blocked` = 0
			LIMIT %d, %d",
			intval($_SESSION['uid']),
			intval($start),
			intval($limit)
		);
		if (DBM::is_result($r)) {
			$notifs = $this->formatIntros($r);
		}

		$arr = [
			'ident' => $ident,
			'total' => $total,
			'notifications' => $notifs,
		];

		return $arr;
	}

	/**
	 * @brief Format the notification query in an usable array
	 *
	 * @param array $intros The array from the db query
	 * @return array with the introductions
	 */
	private function formatIntros($intros)
	{
		$knowyou = '';

		foreach ($intros as $it) {
			// There are two kind of introduction. Contacts suggested by other contacts and normal connection requests.
			// We have to distinguish between these two because they use different data.
			// Contact suggestions
			if ($it['fid']) {
				$return_addr = bin2hex(self::getApp()->user['nickname'] . '@' . self::getApp()->get_hostname() . ((self::getApp()->path) ? '/' . self::getApp()->path : ''));

				$intro = [
					'label' => 'friend_suggestion',
					'notify_type' => L10n::t('Friend Suggestion'),
					'intro_id' => $it['intro_id'],
					'madeby' => $it['name'],
					'contact_id' => $it['contact-id'],
					'photo' => ((x($it, 'fphoto')) ? proxy_url($it['fphoto'], false, PROXY_SIZE_SMALL) : "images/person-175.jpg"),
					'name' => $it['fname'],
					'url' => Contact::magicLink($it['furl']),
					'hidden' => $it['hidden'] == 1,
					'post_newfriend' => (intval(PConfig::get(local_user(), 'system', 'post_newfriend')) ? '1' : 0),
					'knowyou' => $knowyou,
					'note' => $it['note'],
					'request' => $it['frequest'] . '?addr=' . $return_addr,
				];

				// Normal connection requests
			} else {
				$it = $this->getMissingIntroData($it);

				// Don't show these data until you are connected. Diaspora is doing the same.
				if ($it['gnetwork'] === NETWORK_DIASPORA) {
					$it['glocation'] = "";
					$it['gabout'] = "";
					$it['ggender'] = "";
				}
				$intro = [
					'label' => (($it['network'] !== NETWORK_OSTATUS) ? 'friend_request' : 'follower'),
					'notify_type' => (($it['network'] !== NETWORK_OSTATUS) ? L10n::t('Friend/Connect Request') : L10n::t('New Follower')),
					'dfrn_id' => $it['issued-id'],
					'uid' => $_SESSION['uid'],
					'intro_id' => $it['intro_id'],
					'contact_id' => $it['contact-id'],
					'photo' => ((x($it, 'photo')) ? proxy_url($it['photo'], false, PROXY_SIZE_SMALL) : "images/person-175.jpg"),
					'name' => $it['name'],
					'location' => BBCode::convert($it['glocation'], false),
					'about' => BBCode::convert($it['gabout'], false),
					'keywords' => $it['gkeywords'],
					'gender' => $it['ggender'],
					'hidden' => $it['hidden'] == 1,
					'post_newfriend' => (intval(PConfig::get(local_user(), 'system', 'post_newfriend')) ? '1' : 0),
					'url' => $it['url'],
					'zrl' => Contact::magicLink($it['url']),
					'addr' => $it['gaddr'],
					'network' => $it['gnetwork'],
					'knowyou' => $it['knowyou'],
					'note' => $it['note'],
				];
			}

			$arr[] = $intro;
		}

		return $arr;
	}

	/**
	 * @brief Check for missing contact data and try to fetch the data from
	 *     from other sources
	 *
	 * @param array $arr The input array with the intro data
	 *
	 * @return array The array with the intro data
	 */
	private function getMissingIntroData($arr)
	{
		// If the network and the addr isn't available from the gcontact
		// table entry, take the one of the contact table entry
		if ($arr['gnetwork'] == "") {
			$arr['gnetwork'] = $arr['network'];
		}
		if ($arr['gaddr'] == "") {
			$arr['gaddr'] = $arr['addr'];
		}

		// If the network and addr is still not available
		// get the missing data data from other sources
		if ($arr['gnetwork'] == "" || $arr['gaddr'] == "") {
			$ret = Contact::getDetailsByURL($arr['url']);

			if ($arr['gnetwork'] == "" && $ret['network'] != "") {
				$arr['gnetwork'] = $ret['network'];
			}
			if ($arr['gaddr'] == "" && $ret['addr'] != "") {
				$arr['gaddr'] = $ret['addr'];
			}
		}

		return $arr;
	}
}
