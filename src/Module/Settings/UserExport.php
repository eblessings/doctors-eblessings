<?php
/**
 * @file src/Modules/Settings/UserExport.php
 */

namespace Friendica\Module\Settings;

use Friendica\App;
use Friendica\App\Arguments;
use Friendica\BaseModule;
use Friendica\Core\Hook;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Database\DBStructure;
use Friendica\Module\BaseSettingsModule;

/**
 * Module to export user data
 **/
class UserExport extends BaseSettingsModule
{
	/**
	 * Handle the request to export data.
	 * At the moment one can export two different data set
	 * 1. The profile data that can be used by uimport to resettle
	 *    to a different Friendica instance
	 * 2. The entire data-set, profile plus postings
	 *
	 * If there is an action required through the URL / path, react
	 * accordingly and export the requested data.
	 **/
	public static function content()
	{
		parent::content();

		/**
		 * options shown on "Export personal data" page
		 * list of array( 'link url', 'link text', 'help text' )
		 */
		$options = [
			['settings/uexport/account', L10n::t('Export account'), L10n::t('Export your account info and contacts. Use this to make a backup of your account and/or to move it to another server.')],
			['settings/uexport/backup', L10n::t('Export all'), L10n::t("Export your accout info, contacts and all your items as json. Could be a very big file, and could take a lot of time. Use this to make a full backup of your account \x28photos are not exported\x29")],
		];
		Hook::callAll('uexport_options', $options);

		$tpl = Renderer::getMarkupTemplate("settings/userexport.tpl");
		return Renderer::replaceMacros($tpl, [
			'$title' => L10n::t('Export personal data'),
			'$options' => $options
		]);
	}
	/**
	 * raw content generated for the different choices made
	 * by the user. At the moment this returns a JSON file
	 * to the browser which then offers a save / open dialog
	 * to the user.
	 **/
	public static function rawContent()
	{
		$args = self::getClass(Arguments::class);
		if ($args->getArgc() == 3) {
			// @TODO Replace with router-provided arguments
			$action = $args->get(2);
			$user = self::getApp()->user;
			header("Content-type: application/json");
			header('Content-Disposition: attachment; filename="' . $user['nickname'] . '.' . $action . '"');
			switch ($action) {
				case "backup":
					self::exportAll(self::getApp());
					exit();
					break;
				case "account":
					self::exportAccount(self::getApp());
					exit();
					break;
				default:
					exit();
			}
		}
	}
	private static function exportMultiRow(string $query)
	{
		$dbStructure = DBStructure::definition(self::getApp()->getBasePath(), false);

		preg_match("/\s+from\s+`?([a-z\d_]+)`?/i", $query, $match);
		$table = $match[1];

		$result = [];
		$r = q($query);
		if (DBA::isResult($r)) {
			foreach ($r as $rr) {
				$p = [];
				foreach ($rr as $k => $v) {
					switch ($dbStructure[$table]['fields'][$k]['type']) {
						case 'datetime':
							$p[$k] = $v ?? DBA::NULL_DATETIME;
							break;
						default:
							$p[$k] = $v;
							break;
					}
				}
				$result[] = $p;
			}
		}
		return $result;
	}

	private static function exportRow(string $query)
	{
		$dbStructure = DBStructure::definition(self::getApp()->getBasePath(), false);

		preg_match("/\s+from\s+`?([a-z\d_]+)`?/i", $query, $match);
		$table = $match[1];

		$result = [];
		$r = q($query);
		if (DBA::isResult($r)) {

			foreach ($r as $rr) {
				foreach ($rr as $k => $v) {
					switch ($dbStructure[$table]['fields'][$k]['type']) {
						case 'datetime':
							$result[$k] = $v ?? DBA::NULL_DATETIME;
							break;
						default:
							$result[$k] = $v;
							break;
					}
				}
			}
		}
		return $result;
	}

	private static function exportAccount(App $a)
	{
		$user = self::exportRow(
			sprintf("SELECT * FROM `user` WHERE `uid` = %d LIMIT 1", intval(local_user()))
		);

		$contact = self::exportMultiRow(
			sprintf("SELECT * FROM `contact` WHERE `uid` = %d ", intval(local_user()))
		);


		$profile = self::exportMultiRow(
			sprintf("SELECT * FROM `profile` WHERE `uid` = %d ", intval(local_user()))
		);

		$photo = self::exportMultiRow(
			sprintf("SELECT * FROM `photo` WHERE uid = %d AND profile = 1", intval(local_user()))
		);
		foreach ($photo as &$p) {
			$p['data'] = bin2hex($p['data']);
		}

		$pconfig = self::exportMultiRow(
			sprintf("SELECT * FROM `pconfig` WHERE uid = %d", intval(local_user()))
		);

		$group = self::exportMultiRow(
			sprintf("SELECT * FROM `group` WHERE uid = %d", intval(local_user()))
		);

		$group_member = self::exportMultiRow(
			sprintf("SELECT `group_member`.`gid`, `group_member`.`contact-id` FROM `group_member` INNER JOIN `group` ON `group`.`id` = `group_member`.`gid` WHERE `group`.`uid` = %d", intval(local_user()))
		);

		$output = [
			'version' => FRIENDICA_VERSION,
			'schema' => DB_UPDATE_VERSION,
			'baseurl' => System::baseUrl(),
			'user' => $user,
			'contact' => $contact,
			'profile' => $profile,
			'photo' => $photo,
			'pconfig' => $pconfig,
			'group' => $group,
			'group_member' => $group_member,
		];

		echo json_encode($output, JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	/**
	 * echoes account data and items as separated json, one per line
	 *
	 * @param App $a
	 * @throws Exception
	 */
	private static function exportAll(App $a)
	{
		self::exportAccount($a);
		echo "\n";

		$total = DBA::count('item', ['uid' => local_user()]);
		// chunk the output to avoid exhausting memory

		for ($x = 0; $x < $total; $x += 500) {
			$r = q("SELECT * FROM `item` WHERE `uid` = %d LIMIT %d, %d",
				intval(local_user()),
				intval($x),
				intval(500)
			);

			$output = ['item' => $r];
			echo json_encode($output, JSON_PARTIAL_OUTPUT_ON_ERROR). "\n";
		}
	}
}
