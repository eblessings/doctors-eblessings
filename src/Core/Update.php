<?php

namespace Friendica\Core;

use Friendica\Database\DBStructure;

class Update
{
	const SUCCESS = 0;
	const FAILED  = 1;

	/**
	 * @brief Function to check if the Database structure needs an update.
	 *
	 * @param boolean $via_worker boolean Is the check run via the worker?
	 */
	public static function check($via_worker)
	{
		$build = Config::get('system', 'build');

		if (empty($build)) {
			Config::set('system', 'build', DB_UPDATE_VERSION - 1);
			$build = DB_UPDATE_VERSION - 1;
		}

		// We don't support upgrading from very old versions anymore
		if ($build < NEW_UPDATE_ROUTINE_VERSION) {
			die('You try to update from a version prior to database version 1170. The direct upgrade path is not supported. Please update to version 3.5.4 before updating to this version.');
		}

		if ($build < DB_UPDATE_VERSION) {
			// When we cannot execute the database update via the worker, we will do it directly
			if (!Worker::add(PRIORITY_CRITICAL, 'DBUpdate') && $via_worker) {
				self::run();
			}
		}
	}

	/**
	 * Automatic database updates
	 */
	public static function run()
	{
		$build = Config::get('system', 'build');

		if (empty($build) || ($build > DB_UPDATE_VERSION)) {
			$build = DB_UPDATE_VERSION - 1;
			Config::set('system', 'build', $build);
		}

		if ($build != DB_UPDATE_VERSION) {
			require_once 'update.php';

			$stored = intval($build);
			$current = intval(DB_UPDATE_VERSION);
			if ($stored < $current) {
				Config::load('database');

				// Compare the current structure with the defined structure
				if (Lock::acquire('dbupdate')) {

					// run the pre_update_nnnn functions in update.php
					for ($x = $stored + 1; $x <= $current; $x++) {
						$r = self::runUpdateFunction($x, 'pre_update');
						if (!$r) {
							break;
						}
					}

					// update the structure in one call
					$retval = DBStructure::update(false, true);
					if ($retval) {
						DBStructure::updateFail(
							DB_UPDATE_VERSION,
							$retval
						);
						Lock::release('dbupdate');
						return;
					} else {
						Config::set('database', 'last_successful_update', $current);
						Config::set('database', 'last_successful_update_time', time());
					}

					// run the update_nnnn functions in update.php
					for ($x = $stored + 1; $x <= $current; $x++) {
						$r = self::runUpdateFunction($x, 'update');
						if (!$r) {
							break;
						}
					}

					Lock::release('dbupdate');
				}
			}
		}
	}

	/**
	 * Executes a specific update function
	 *
	 * @param int $x the DB version number of the function
	 * @param string $prefix the prefix of the function (update, pre_update)
	 *
	 * @return bool true, if the update function worked
	 */
	public static function runUpdateFunction($x, $prefix)
	{
		$funcname = $prefix . '_' . $x;

		if (function_exists($funcname)) {
			// There could be a lot of processes running or about to run.
			// We want exactly one process to run the update command.
			// So store the fact that we're taking responsibility
			// after first checking to see if somebody else already has.
			// If the update fails or times-out completely you may need to
			// delete the config entry to try again.

			if (Lock::acquire('dbupdate_function')) {

				// call the specific update
				$retval = $funcname();

				if ($retval) {
					//send the administrator an e-mail
					DBStructure::updateFail(
						$x,
						L10n::t('Update %s failed. See error logs.', $x)
					);
					Lock::release('dbupdate_function');
					return false;
				} else {
					Config::set('database', 'last_successful_update_function', $funcname);
					Config::set('database', 'last_successful_update_function_time', time());

					if ($prefix == 'update') {
						Config::set('system', 'build', $x);
					}

					Lock::release('dbupdate_function');
					return true;
				}
			}
		} else {
			logger('Skipping \'' . $funcname . '\' without executing', LOGGER_DEBUG);

			Config::set('database', 'last_successful_update_function', $funcname);
			Config::set('database', 'last_successful_update_function_time', time());

			if ($prefix == 'update') {
				Config::set('system', 'build', $x);
			}

			return true;
		}
	}
}
