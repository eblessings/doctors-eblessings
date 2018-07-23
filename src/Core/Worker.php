<?php
/**
 * @file src/Core/Worker.php
 */
namespace Friendica\Core;

use Friendica\Database\DBA;
use Friendica\Database\DBM;
use Friendica\Model\Process;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Network;

require_once 'include/dba.php';

/**
 * @file src/Core/Worker.php
 *
 * @brief Contains the class for the worker background job processing
 */

/**
 * @brief Worker methods
 */
class Worker
{
	private static $up_start;
	private static $db_duration;
	private static $last_update;
	private static $lock_duration;

	/**
	 * @brief Processes the tasks that are in the workerqueue table
	 *
	 * @param boolean $run_cron Should the cron processes be executed?
	 * @return void
	 */
	public static function processQueue($run_cron = true)
	{
		$a = get_app();

		self::$up_start = microtime(true);

		// At first check the maximum load. We shouldn't continue with a high load
		if ($a->isMaxLoadReached()) {
			logger('Pre check: maximum load reached, quitting.', LOGGER_DEBUG);
			return;
		}

		// We now start the process. This is done after the load check since this could increase the load.
		self::startProcess();

		// Kill stale processes every 5 minutes
		$last_cleanup = Config::get('system', 'worker_last_cleaned', 0);
		if (time() > ($last_cleanup + 300)) {
			Config::set('system', 'worker_last_cleaned', time());
			self::killStaleWorkers();
		}

		// Count active workers and compare them with a maximum value that depends on the load
		if (self::tooMuchWorkers()) {
			logger('Pre check: Active worker limit reached, quitting.', LOGGER_DEBUG);
			return;
		}

		// Do we have too few memory?
		if ($a->min_memory_reached()) {
			logger('Pre check: Memory limit reached, quitting.', LOGGER_DEBUG);
			return;
		}

		// Possibly there are too much database connections
		if (self::maxConnectionsReached()) {
			logger('Pre check: maximum connections reached, quitting.', LOGGER_DEBUG);
			return;
		}

		// Possibly there are too much database processes that block the system
		if ($a->isMaxProcessesReached()) {
			logger('Pre check: maximum processes reached, quitting.', LOGGER_DEBUG);
			return;
		}

		// Now we start additional cron processes if we should do so
		if ($run_cron) {
			self::runCron();
		}

		$starttime = time();

		// We fetch the next queue entry that is about to be executed
		while ($r = self::workerProcess($passing_slow)) {
			// When we are processing jobs with a lower priority, we don't refetch new jobs
			// Otherwise fast jobs could wait behind slow ones and could be blocked.
			$refetched = $passing_slow;

			foreach ($r as $entry) {
				// Assure that the priority is an integer value
				$entry['priority'] = (int)$entry['priority'];

				// The work will be done
				if (!self::execute($entry)) {
					logger('Process execution failed, quitting.', LOGGER_DEBUG);
					return;
				}

				// If possible we will fetch new jobs for this worker
				if (!$refetched && Lock::acquire('worker_process', 0)) {
					$stamp = (float)microtime(true);
					$refetched = self::findWorkerProcesses($passing_slow);
					self::$db_duration += (microtime(true) - $stamp);
					Lock::release('worker_process');
				}
			}

			// To avoid the quitting of multiple workers only one worker at a time will execute the check
			if (Lock::acquire('worker', 0)) {
				$stamp = (float)microtime(true);
				// Count active workers and compare them with a maximum value that depends on the load
				if (self::tooMuchWorkers()) {
					logger('Active worker limit reached, quitting.', LOGGER_DEBUG);
					return;
				}

				// Check free memory
				if ($a->min_memory_reached()) {
					logger('Memory limit reached, quitting.', LOGGER_DEBUG);
					return;
				}
				Lock::release('worker');
				self::$db_duration += (microtime(true) - $stamp);
			}

			// Quit the worker once every 5 minutes
			if (time() > ($starttime + 300)) {
				logger('Process lifetime reached, quitting.', LOGGER_DEBUG);
				return;
			}
		}

		// Cleaning up. Possibly not needed, but it doesn't harm anything.
		if (Config::get('system', 'worker_daemon_mode', false)) {
			self::IPCSetJobState(false);
		}
		logger("Couldn't select a workerqueue entry, quitting process " . getmypid() . ".", LOGGER_DEBUG);
	}

	/**
	 * @brief Returns the number of non executed entries in the worker queue
	 *
	 * @return integer Number of non executed entries in the worker queue
	 */
	private static function totalEntries()
	{
		return DBA::count('workerqueue', ["`executed` <= ? AND NOT `done`", NULL_DATE]);
	}

	/**
	 * @brief Returns the highest priority in the worker queue that isn't executed
	 *
	 * @return integer Number of active worker processes
	 */
	private static function highestPriority()
	{
		$condition = ["`executed` <= ? AND NOT `done`", NULL_DATE];
		$workerqueue = DBA::selectFirst('workerqueue', ['priority'], $condition, ['order' => ['priority']]);
		if (DBM::is_result($workerqueue)) {
			return $workerqueue["priority"];
		} else {
			return 0;
		}
	}

	/**
	 * @brief Returns if a process with the given priority is running
	 *
	 * @param integer $priority The priority that should be checked
	 *
	 * @return integer Is there a process running with that priority?
	 */
	private static function processWithPriorityActive($priority)
	{
		$condition = ["`priority` <= ? AND `executed` > ? AND NOT `done`", $priority, NULL_DATE];
		return DBA::exists('workerqueue', $condition);
	}

	/**
	 * @brief Execute a worker entry
	 *
	 * @param array $queue Workerqueue entry
	 *
	 * @return boolean "true" if further processing should be stopped
	 */
	public static function execute($queue)
	{
		$a = get_app();

		$mypid = getmypid();

		// Quit when in maintenance
		if (Config::get('system', 'maintenance', false, true)) {
			logger("Maintenance mode - quit process ".$mypid, LOGGER_DEBUG);
			return false;
		}

		// Constantly check the number of parallel database processes
		if ($a->isMaxProcessesReached()) {
			logger("Max processes reached for process ".$mypid, LOGGER_DEBUG);
			return false;
		}

		// Constantly check the number of available database connections to let the frontend be accessible at any time
		if (self::maxConnectionsReached()) {
			logger("Max connection reached for process ".$mypid, LOGGER_DEBUG);
			return false;
		}

		$argv = json_decode($queue["parameter"], true);

		// Check for existance and validity of the include file
		$include = $argv[0];

		if (method_exists(sprintf('Friendica\Worker\%s', $include), 'execute')) {
			// We constantly update the "executed" date every minute to avoid being killed too soon
			if (!isset(self::$last_update)) {
				self::$last_update = strtotime($queue["executed"]);
			}

			$age = (time() - self::$last_update) / 60;
			self::$last_update = time();

			if ($age > 1) {
				$stamp = (float)microtime(true);
				DBA::update('workerqueue', ['executed' => DateTimeFormat::utcNow()], ['pid' => $mypid, 'done' => false]);
				self::$db_duration += (microtime(true) - $stamp);
			}

			array_shift($argv);

			self::execFunction($queue, $include, $argv, true);

			$stamp = (float)microtime(true);
			if (DBA::update('workerqueue', ['done' => true], ['id' => $queue["id"]])) {
				Config::set('system', 'last_worker_execution', DateTimeFormat::utcNow());
			}
			self::$db_duration = (microtime(true) - $stamp);

			return true;
		}

		// The script could be provided as full path or only with the function name
		if ($include == basename($include)) {
			$include = "include/".$include.".php";
		}

		if (!validate_include($include)) {
			logger("Include file ".$argv[0]." is not valid!");
			DBA::delete('workerqueue', ['id' => $queue["id"]]);
			return true;
		}

		require_once $include;

		$funcname = str_replace(".php", "", basename($argv[0]))."_run";

		if (function_exists($funcname)) {
			// We constantly update the "executed" date every minute to avoid being killed too soon
			if (!isset(self::$last_update)) {
				self::$last_update = strtotime($queue["executed"]);
			}

			$age = (time() - self::$last_update) / 60;
			self::$last_update = time();

			if ($age > 1) {
				$stamp = (float)microtime(true);
				DBA::update('workerqueue', ['executed' => DateTimeFormat::utcNow()], ['pid' => $mypid, 'done' => false]);
				self::$db_duration += (microtime(true) - $stamp);
			}

			self::execFunction($queue, $funcname, $argv, false);

			$stamp = (float)microtime(true);
			if (DBA::update('workerqueue', ['done' => true], ['id' => $queue["id"]])) {
				Config::set('system', 'last_worker_execution', DateTimeFormat::utcNow());
			}
			self::$db_duration = (microtime(true) - $stamp);
		} else {
			logger("Function ".$funcname." does not exist");
			DBA::delete('workerqueue', ['id' => $queue["id"]]);
		}

		return true;
	}

	/**
	 * @brief Execute a function from the queue
	 *
	 * @param array   $queue       Workerqueue entry
	 * @param string  $funcname    name of the function
	 * @param array   $argv        Array of values to be passed to the function
	 * @param boolean $method_call boolean
	 * @return void
	 */
	private static function execFunction($queue, $funcname, $argv, $method_call)
	{
		$a = get_app();

		$mypid = getmypid();

		$argc = count($argv);

		$new_process_id = System::processID("wrk");

		logger("Process ".$mypid." - Prio ".$queue["priority"]." - ID ".$queue["id"].": ".$funcname." ".$queue["parameter"]." - Process PID: ".$new_process_id);

		$stamp = (float)microtime(true);

		// We use the callstack here to analyze the performance of executed worker entries.
		// For this reason the variables have to be initialized.
		if (Config::get("system", "profiler")) {
			$a->performance["start"] = microtime(true);
			$a->performance["database"] = 0;
			$a->performance["database_write"] = 0;
			$a->performance["cache"] = 0;
			$a->performance["cache_write"] = 0;
			$a->performance["network"] = 0;
			$a->performance["file"] = 0;
			$a->performance["rendering"] = 0;
			$a->performance["parser"] = 0;
			$a->performance["marktime"] = 0;
			$a->performance["markstart"] = microtime(true);
			$a->callstack = [];
		}

		// For better logging create a new process id for every worker call
		// But preserve the old one for the worker
		$old_process_id = $a->process_id;
		$a->process_id = $new_process_id;
		$a->queue = $queue;

		$up_duration = number_format(microtime(true) - self::$up_start, 3);

		// Reset global data to avoid interferences
		unset($_SESSION);

		if ($method_call) {
			call_user_func_array(sprintf('Friendica\Worker\%s::execute', $funcname), $argv);
		} else {
			$funcname($argv, $argc);
		}

		$a->process_id = $old_process_id;
		unset($a->queue);

		$duration = (microtime(true) - $stamp);

		self::$up_start = microtime(true);

		/* With these values we can analyze how effective the worker is.
		 * The database and rest time should be low since this is the unproductive time.
		 * The execution time is the productive time.
		 * By changing parameters like the maximum number of workers we can check the effectivness.
		*/
		logger(
			'DB: '.number_format(self::$db_duration, 2).
			' - Lock: '.number_format(self::$lock_duration, 2).
			' - Rest: '.number_format($up_duration - self::$db_duration - self::$lock_duration, 2).
			' - Execution: '.number_format($duration, 2),
			LOGGER_DEBUG
		);

		self::$lock_duration = 0;

		if ($duration > 3600) {
			logger("Prio ".$queue["priority"].": ".$queue["parameter"]." - longer than 1 hour (".round($duration/60, 3).")", LOGGER_DEBUG);
		} elseif ($duration > 600) {
			logger("Prio ".$queue["priority"].": ".$queue["parameter"]." - longer than 10 minutes (".round($duration/60, 3).")", LOGGER_DEBUG);
		} elseif ($duration > 300) {
			logger("Prio ".$queue["priority"].": ".$queue["parameter"]." - longer than 5 minutes (".round($duration/60, 3).")", LOGGER_DEBUG);
		} elseif ($duration > 120) {
			logger("Prio ".$queue["priority"].": ".$queue["parameter"]." - longer than 2 minutes (".round($duration/60, 3).")", LOGGER_DEBUG);
		}

		logger("Process ".$mypid." - Prio ".$queue["priority"]." - ID ".$queue["id"].": ".$funcname." - done in ".$duration." seconds. Process PID: ".$new_process_id);

		// Write down the performance values into the log
		if (Config::get("system", "profiler")) {
			$duration = microtime(true)-$a->performance["start"];

			$o = '';
			if (Config::get("rendertime", "callstack")) {
				if (isset($a->callstack["database"])) {
					$o .= "\nDatabase Read:\n";
					foreach ($a->callstack["database"] as $func => $time) {
						$time = round($time, 3);
						if ($time > 0) {
							$o .= $func.": ".$time."\n";
						}
					}
				}
				if (isset($a->callstack["database_write"])) {
					$o .= "\nDatabase Write:\n";
					foreach ($a->callstack["database_write"] as $func => $time) {
						$time = round($time, 3);
						if ($time > 0) {
							$o .= $func.": ".$time."\n";
						}
					}
				}
				if (isset($a->callstack["dache"])) {
					$o .= "\nCache Read:\n";
					foreach ($a->callstack["dache"] as $func => $time) {
						$time = round($time, 3);
						if ($time > 0) {
							$o .= $func.": ".$time."\n";
						}
					}
				}
				if (isset($a->callstack["dache_write"])) {
					$o .= "\nCache Write:\n";
					foreach ($a->callstack["dache_write"] as $func => $time) {
						$time = round($time, 3);
						if ($time > 0) {
							$o .= $func.": ".$time."\n";
						}
					}
				}
				if (isset($a->callstack["network"])) {
					$o .= "\nNetwork:\n";
					foreach ($a->callstack["network"] as $func => $time) {
						$time = round($time, 3);
						if ($time > 0) {
							$o .= $func.": ".$time."\n";
						}
					}
				}
			}

			logger(
				"ID ".$queue["id"].": ".$funcname.": ".sprintf(
					"DB: %s/%s, Cache: %s/%s, Net: %s, I/O: %s, Other: %s, Total: %s".$o,
					number_format($a->performance["database"] - $a->performance["database_write"], 2),
					number_format($a->performance["database_write"], 2),
					number_format($a->performance["cache"], 2),
					number_format($a->performance["cache_write"], 2),
					number_format($a->performance["network"], 2),
					number_format($a->performance["file"], 2),
					number_format($duration - ($a->performance["database"]
						+ $a->performance["cache"] + $a->performance["cache_write"]
						+ $a->performance["network"] + $a->performance["file"]), 2),
					number_format($duration, 2)
				),
				LOGGER_DEBUG
			);
		}

		$cooldown = Config::get("system", "worker_cooldown", 0);

		if ($cooldown > 0) {
			logger("Process ".$mypid." - Prio ".$queue["priority"]." - ID ".$queue["id"].": ".$funcname." - in cooldown for ".$cooldown." seconds");
			sleep($cooldown);
		}
	}

	/**
	 * @brief Checks if the number of database connections has reached a critical limit.
	 *
	 * @return bool Are more than 3/4 of the maximum connections used?
	 */
	private static function maxConnectionsReached()
	{
		// Fetch the max value from the config. This is needed when the system cannot detect the correct value by itself.
		$max = Config::get("system", "max_connections");

		// Fetch the percentage level where the worker will get active
		$maxlevel = Config::get("system", "max_connections_level", 75);

		if ($max == 0) {
			// the maximum number of possible user connections can be a system variable
			$r = DBA::fetchFirst("SHOW VARIABLES WHERE `variable_name` = 'max_user_connections'");
			if (DBM::is_result($r)) {
				$max = $r["Value"];
			}
			// Or it can be granted. This overrides the system variable
			$r = DBA::p('SHOW GRANTS');
			while ($grants = DBA::fetch($r)) {
				$grant = array_pop($grants);
				if (stristr($grant, "GRANT USAGE ON")) {
					if (preg_match("/WITH MAX_USER_CONNECTIONS (\d*)/", $grant, $match)) {
						$max = $match[1];
					}
				}
			}
			DBA::close($r);
		}

		// If $max is set we will use the processlist to determine the current number of connections
		// The processlist only shows entries of the current user
		if ($max != 0) {
			$r = DBA::p('SHOW PROCESSLIST');
			$used = DBA::numRows($r);
			DBA::close($r);

			logger("Connection usage (user values): ".$used."/".$max, LOGGER_DEBUG);

			$level = ($used / $max) * 100;

			if ($level >= $maxlevel) {
				logger("Maximum level (".$maxlevel."%) of user connections reached: ".$used."/".$max);
				return true;
			}
		}

		// We will now check for the system values.
		// This limit could be reached although the user limits are fine.
		$r = DBA::fetchFirst("SHOW VARIABLES WHERE `variable_name` = 'max_connections'");
		if (!DBM::is_result($r)) {
			return false;
		}
		$max = intval($r["Value"]);
		if ($max == 0) {
			return false;
		}
		$r = DBA::fetchFirst("SHOW STATUS WHERE `variable_name` = 'Threads_connected'");
		if (!DBM::is_result($r)) {
			return false;
		}
		$used = intval($r["Value"]);
		if ($used == 0) {
			return false;
		}
		logger("Connection usage (system values): ".$used."/".$max, LOGGER_DEBUG);

		$level = $used / $max * 100;

		if ($level < $maxlevel) {
			return false;
		}
		logger("Maximum level (".$level."%) of system connections reached: ".$used."/".$max);
		return true;
	}

	/**
	 * @brief fix the queue entry if the worker process died
	 * @return void
	 */
	private static function killStaleWorkers()
	{
		$entries = DBA::select(
			'workerqueue',
			['id', 'pid', 'executed', 'priority', 'parameter'],
			['`executed` > ? AND NOT `done` AND `pid` != 0', NULL_DATE],
			['order' => ['priority', 'created']]
		);

		while ($entry = DBA::fetch($entries)) {
			if (!posix_kill($entry["pid"], 0)) {
				DBA::update(
					'workerqueue',
					['executed' => NULL_DATE, 'pid' => 0],
					['id' => $entry["id"]]
				);
			} else {
				// Kill long running processes
				// Check if the priority is in a valid range
				if (!in_array($entry["priority"], [PRIORITY_CRITICAL, PRIORITY_HIGH, PRIORITY_MEDIUM, PRIORITY_LOW, PRIORITY_NEGLIGIBLE])) {
					$entry["priority"] = PRIORITY_MEDIUM;
				}

				// Define the maximum durations
				$max_duration_defaults = [PRIORITY_CRITICAL => 720, PRIORITY_HIGH => 10, PRIORITY_MEDIUM => 60, PRIORITY_LOW => 180, PRIORITY_NEGLIGIBLE => 720];
				$max_duration = $max_duration_defaults[$entry["priority"]];

				$argv = json_decode($entry["parameter"], true);
				$argv[0] = basename($argv[0]);

				// How long is the process already running?
				$duration = (time() - strtotime($entry["executed"])) / 60;
				if ($duration > $max_duration) {
					logger("Worker process ".$entry["pid"]." (".substr(json_encode($argv), 0, 50).") took more than ".$max_duration." minutes. It will be killed now.");
					posix_kill($entry["pid"], SIGTERM);

					// We killed the stale process.
					// To avoid a blocking situation we reschedule the process at the beginning of the queue.
					// Additionally we are lowering the priority. (But not PRIORITY_CRITICAL)
					$new_priority = $entry["priority"];
					if ($entry["priority"] == PRIORITY_HIGH) {
						$new_priority = PRIORITY_MEDIUM;
					} elseif ($entry["priority"] == PRIORITY_MEDIUM) {
						$new_priority = PRIORITY_LOW;
					} elseif ($entry["priority"] != PRIORITY_CRITICAL) {
						$new_priority = PRIORITY_NEGLIGIBLE;
					}
					DBA::update(
						'workerqueue',
						['executed' => NULL_DATE, 'created' => DateTimeFormat::utcNow(), 'priority' => $new_priority, 'pid' => 0],
						['id' => $entry["id"]]
					);
				} else {
					logger("Worker process ".$entry["pid"]." (".substr(json_encode($argv), 0, 50).") now runs for ".round($duration)." of ".$max_duration." allowed minutes. That's okay.", LOGGER_DEBUG);
				}
			}
		}
	}

	/**
	 * @brief Checks if the number of active workers exceeds the given limits
	 *
	 * @return bool Are there too much workers running?
	 */
	public static function tooMuchWorkers()
	{
		$queues = Config::get("system", "worker_queues", 4);

		$maxqueues = $queues;

		$active = self::activeWorkers();

		// Decrease the number of workers at higher load
		$load = current_load();
		if ($load) {
			$maxsysload = intval(Config::get("system", "maxloadavg", 50));

			/* Default exponent 3 causes queues to rapidly decrease as load increases.
			 * If you have 20 max queues at idle, then you get only 5 queues at 37.1% of $maxsysload.
			 * For some environments, this rapid decrease is not needed.
			 * With exponent 1, you could have 20 max queues at idle and 13 at 37% of $maxsysload.
			 */
			$exponent = intval(Config::get('system', 'worker_load_exponent', 3));
			$slope = pow(max(0, $maxsysload - $load) / $maxsysload, $exponent);
			$queues = intval(ceil($slope * $maxqueues));

			$processlist = '';

			if (Config::get('system', 'worker_debug')) {
				// Create a list of queue entries grouped by their priority
				$listitem = [];

				// Adding all processes with no workerqueue entry
				$processes = DBA::p(
					"SELECT COUNT(*) AS `running` FROM `process` WHERE NOT EXISTS
							(SELECT id FROM `workerqueue`
							WHERE `workerqueue`.`pid` = `process`.`pid` AND NOT `done` AND `pid` != ?)",
					getmypid()
				);

				if ($process = DBA::fetch($processes)) {
					$listitem[0] = "0:".$process["running"];
				}
				DBA::close($processes);

				// Now adding all processes with workerqueue entries
				$entries = DBA::p("SELECT COUNT(*) AS `entries`, `priority` FROM `workerqueue` WHERE NOT `done` GROUP BY `priority`");
				while ($entry = DBA::fetch($entries)) {
					$processes = DBA::p("SELECT COUNT(*) AS `running` FROM `process` INNER JOIN `workerqueue` ON `workerqueue`.`pid` = `process`.`pid` AND NOT `done` WHERE `priority` = ?", $entry["priority"]);
					if ($process = DBA::fetch($processes)) {
						$listitem[$entry["priority"]] = $entry["priority"].":".$process["running"]."/".$entry["entries"];
					}
					DBA::close($processes);
				}
				DBA::close($entries);

				$intervals = [1, 10, 60];
				$jobs_per_minute = [];
				foreach ($intervals as $interval) {
					$jobs = DBA::p("SELECT COUNT(*) AS `jobs` FROM `workerqueue` WHERE `done` AND `executed` > UTC_TIMESTAMP() - INTERVAL ".intval($interval)." MINUTE");
					if ($job = DBA::fetch($jobs)) {
						$jobs_per_minute[$interval] = number_format($job['jobs'] / $interval, 0);
					}
					DBA::close($jobs);
				}
				$processlist = ' - jpm: '.implode('/', $jobs_per_minute).' ('.implode(', ', $listitem).')';
			}

			$entries = self::totalEntries();

			if (Config::get("system", "worker_fastlane", false) && ($queues > 0) && ($entries > 0) && ($active >= $queues)) {
				$top_priority = self::highestPriority();
				$high_running = self::processWithPriorityActive($top_priority);

				if (!$high_running && ($top_priority > PRIORITY_UNDEFINED) && ($top_priority < PRIORITY_NEGLIGIBLE)) {
					logger("There are jobs with priority ".$top_priority." waiting but none is executed. Open a fastlane.", LOGGER_DEBUG);
					$queues = $active + 1;
				}
			}

			logger("Load: ".$load."/".$maxsysload." - processes: ".$active."/".$entries.$processlist." - maximum: ".$queues."/".$maxqueues, LOGGER_DEBUG);

			// Are there fewer workers running as possible? Then fork a new one.
			if (!Config::get("system", "worker_dont_fork", false) && ($queues > ($active + 1)) && ($entries > 1)) {
				logger("Active workers: ".$active."/".$queues." Fork a new worker.", LOGGER_DEBUG);
				if (Config::get('system', 'worker_daemon_mode', false)) {
					self::IPCSetJobState(true);
				} else {
					self::spawnWorker();
				}
			}
		}

		// if there are too much worker, we don't spawn a new one.
		if (Config::get('system', 'worker_daemon_mode', false) && ($active > $queues)) {
			self::IPCSetJobState(false);
		}

		return $active > $queues;
	}

	/**
	 * @brief Returns the number of active worker processes
	 *
	 * @return integer Number of active worker processes
	 */
	private static function activeWorkers()
	{
		return DBA::count('process', ['command' => 'Worker.php']);
	}

	/**
	 * @brief Check if we should pass some slow processes
	 *
	 * When the active processes of the highest priority are using more than 2/3
	 * of all processes, we let pass slower processes.
	 *
	 * @param string $highest_priority Returns the currently highest priority
	 * @return bool We let pass a slower process than $highest_priority
	 */
	private static function passingSlow(&$highest_priority)
	{
		$highest_priority = 0;

		$r = DBA::p(
			"SELECT `priority`
				FROM `process`
				INNER JOIN `workerqueue` ON `workerqueue`.`pid` = `process`.`pid` AND NOT `done`"
		);

		// No active processes at all? Fine
		if (!DBM::is_result($r)) {
			return false;
		}
		$priorities = [];
		while ($line = DBA::fetch($r)) {
			$priorities[] = $line["priority"];
		}
		DBA::close($r);

		// Should not happen
		if (count($priorities) == 0) {
			return false;
		}
		$highest_priority = min($priorities);

		// The highest process is already the slowest one?
		// Then we quit
		if ($highest_priority == PRIORITY_NEGLIGIBLE) {
			return false;
		}
		$high = 0;
		foreach ($priorities as $priority) {
			if ($priority == $highest_priority) {
				++$high;
			}
		}
		logger("Highest priority: ".$highest_priority." Total processes: ".count($priorities)." Count high priority processes: ".$high, LOGGER_DEBUG);
		$passing_slow = (($high/count($priorities)) > (2/3));

		if ($passing_slow) {
			logger("Passing slower processes than priority ".$highest_priority, LOGGER_DEBUG);
		}
		return $passing_slow;
	}

	/**
	 * @brief Find and claim the next worker process for us
	 *
	 * @param boolean $passing_slow Returns if we had passed low priority processes
	 * @return boolean Have we found something?
	 */
	private static function findWorkerProcesses(&$passing_slow)
	{
		$mypid = getmypid();

		// Check if we should pass some low priority process
		$highest_priority = 0;
		$found = false;
		$passing_slow = false;

		// The higher the number of parallel workers, the more we prefetch to prevent concurring access
		// We decrease the limit with the number of entries left in the queue
		$worker_queues = Config::get("system", "worker_queues", 4);
		$queue_length = Config::get('system', 'worker_fetch_limit', 1);
		$lower_job_limit = $worker_queues * $queue_length * 2;
		$jobs = self::totalEntries();

		// Now do some magic
		$exponent = 2;
		$slope = $queue_length / pow($lower_job_limit, $exponent);
		$limit = min($queue_length, ceil($slope * pow($jobs, $exponent)));

		logger('Total: '.$jobs.' - Maximum: '.$queue_length.' - jobs per queue: '.$limit, LOGGER_DEBUG);
		$ids = [];
		if (self::passingSlow($highest_priority)) {
			// Are there waiting processes with a higher priority than the currently highest?
			$result = DBA::select(
				'workerqueue',
				['id'],
				["`executed` <= ? AND `priority` < ? AND NOT `done`", NULL_DATE, $highest_priority],
				['limit' => $limit, 'order' => ['priority', 'created']]
			);

			while ($id = DBA::fetch($result)) {
				$ids[] = $id["id"];
			}
			DBA::close($result);

			$found = (count($ids) > 0);

			if (!$found) {
				// Give slower processes some processing time
				$result = DBA::select(
					'workerqueue',
					['id'],
					["`executed` <= ? AND `priority` > ? AND NOT `done`", NULL_DATE, $highest_priority],
					['limit' => $limit, 'order' => ['priority', 'created']]
				);

				while ($id = DBA::fetch($result)) {
					$ids[] = $id["id"];
				}
				DBA::close($result);

				$found = (count($ids) > 0);
				$passing_slow = $found;
			}
		}

		// If there is no result (or we shouldn't pass lower processes) we check without priority limit
		if (!$found) {
			$result = DBA::select(
				'workerqueue',
				['id'],
				["`executed` <= ? AND NOT `done`", NULL_DATE],
				['limit' => $limit, 'order' => ['priority', 'created']]
			);

			while ($id = DBA::fetch($result)) {
				$ids[] = $id["id"];
			}
			DBA::close($result);

			$found = (count($ids) > 0);
		}

		if ($found) {
			$condition = "`id` IN (".substr(str_repeat("?, ", count($ids)), 0, -2).") AND `pid` = 0 AND NOT `done`";
			array_unshift($ids, $condition);
			DBA::update('workerqueue', ['executed' => DateTimeFormat::utcNow(), 'pid' => $mypid], $ids);
		}

		return $found;
	}

	/**
	 * @brief Returns the next worker process
	 *
	 * @param boolean $passing_slow Returns if we had passed low priority processes
	 * @return string SQL statement
	 */
	public static function workerProcess(&$passing_slow)
	{
		$stamp = (float)microtime(true);

		// There can already be jobs for us in the queue.
		$r = DBA::select('workerqueue', [], ['pid' => getmypid(), 'done' => false]);
		if (DBM::is_result($r)) {
			self::$db_duration += (microtime(true) - $stamp);
			return DBA::toArray($r);
		}
		DBA::close($r);

		$stamp = (float)microtime(true);
		if (!Lock::acquire('worker_process')) {
			return false;
		}
		self::$lock_duration = (microtime(true) - $stamp);

		$stamp = (float)microtime(true);
		$found = self::findWorkerProcesses($passing_slow);
		self::$db_duration += (microtime(true) - $stamp);

		Lock::release('worker_process');

		if ($found) {
			$r = DBA::select('workerqueue', [], ['pid' => getmypid(), 'done' => false]);
			return DBA::toArray($r);
		}
		return false;
	}

	/**
	 * @brief Removes a workerqueue entry from the current process
	 * @return void
	 */
	public static function unclaimProcess()
	{
		$mypid = getmypid();

		DBA::update('workerqueue', ['executed' => NULL_DATE, 'pid' => 0], ['pid' => $mypid, 'done' => false]);
	}

	/**
	 * @brief Call the front end worker
	 * @return void
	 */
	public static function callWorker()
	{
		if (!Config::get("system", "frontend_worker")) {
			return;
		}

		$url = System::baseUrl()."/worker";
		Network::fetchUrl($url, false, $redirects, 1);
	}

	/**
	 * @brief Call the front end worker if there aren't any active
	 * @return void
	 */
	public static function executeIfIdle()
	{
		if (!Config::get("system", "frontend_worker")) {
			return;
		}

		// Do we have "proc_open"? Then we can fork the worker
		if (function_exists("proc_open")) {
			// When was the last time that we called the worker?
			// Less than one minute? Then we quit
			if ((time() - Config::get("system", "worker_started")) < 60) {
				return;
			}

			Config::set("system", "worker_started", time());

			// Do we have enough running workers? Then we quit here.
			if (self::tooMuchWorkers()) {
				// Cleaning dead processes
				self::killStaleWorkers();
				Process::deleteInactive();

				return;
			}

			self::runCron();

			logger('Call worker', LOGGER_DEBUG);
			self::spawnWorker();
			return;
		}

		// We cannot execute background processes.
		// We now run the processes from the frontend.
		// This won't work with long running processes.
		self::runCron();

		self::clearProcesses();

		$workers = self::activeWorkers();

		if ($workers == 0) {
			self::callWorker();
		}
	}

	/**
	 * @brief Removes long running worker processes
	 * @return void
	 */
	public static function clearProcesses()
	{
		$timeout = Config::get("system", "frontend_worker_timeout", 10);

		/// @todo We should clean up the corresponding workerqueue entries as well
		$condition = ["`created` < ? AND `command` = 'worker.php'",
				DateTimeFormat::utc("now - ".$timeout." minutes")];
		DBA::delete('process', $condition);
	}

	/**
	 * @brief Runs the cron processes
	 * @return void
	 */
	private static function runCron()
	{
		logger('Add cron entries', LOGGER_DEBUG);

		// Check for spooled items
		self::add(PRIORITY_HIGH, "SpoolPost");

		// Run the cron job that calls all other jobs
		self::add(PRIORITY_MEDIUM, "Cron");

		// Cleaning dead processes
		self::killStaleWorkers();
	}

	/**
	 * @brief Spawns a new worker
	 * @return void
	 */
	public static function spawnWorker($do_cron = false)
	{
		$command = 'bin/worker.php';

		$args = [ 'cron' => $do_cron ];

		get_app()->proc_run($command, $args);

		// after spawning we have to remove the flag.
		if (Config::get('system', 'worker_daemon_mode', false)) {
			self::IPCSetJobState(false);
		}
	}

	/**
	 * @brief Adds tasks to the worker queue
	 *
	 * @param (integer|array) priority or parameter array, strings are deprecated and are ignored
	 *
	 * next args are passed as $cmd command line
	 * or: Worker::add(PRIORITY_HIGH, "Notifier", "drop", $drop_id);
	 * or: Worker::add(array('priority' => PRIORITY_HIGH, 'dont_fork' => true), "CreateShadowEntry", $post_id);
	 *
	 * @note $cmd and string args are surrounded with ""
	 *
	 * @hooks 'proc_run'
	 * 	array $arr
	 *
	 * @return boolean "false" if proc_run couldn't be executed
	 */
	public static function add($cmd)
	{
		$args = func_get_args();

		if (!count($args)) {
			return false;
		}

		$arr = ['args' => $args, 'run_cmd' => true];

		Addon::callHooks("proc_run", $arr);
		if (!$arr['run_cmd'] || !count($args)) {
			return true;
		}

		$priority = PRIORITY_MEDIUM;
		$dont_fork = Config::get("system", "worker_dont_fork", false);
		$created = DateTimeFormat::utcNow();

		$run_parameter = array_shift($args);

		if (is_int($run_parameter)) {
			$priority = $run_parameter;
		} elseif (is_array($run_parameter)) {
			if (isset($run_parameter['priority'])) {
				$priority = $run_parameter['priority'];
			}
			if (isset($run_parameter['created'])) {
				$created = $run_parameter['created'];
			}
			if (isset($run_parameter['dont_fork'])) {
				$dont_fork = $run_parameter['dont_fork'];
			}
		}

		$parameters = json_encode($args);
		$found = DBA::exists('workerqueue', ['parameter' => $parameters, 'done' => false]);

		// Quit if there was a database error - a precaution for the update process to 3.5.3
		if (DBA::errorNo() != 0) {
			return false;
		}

		if (!$found) {
			DBA::insert('workerqueue', ['parameter' => $parameters, 'created' => $created, 'priority' => $priority]);
		}

		// Should we quit and wait for the worker to be called as a cronjob?
		if ($dont_fork) {
			return true;
		}

		// If there is a lock then we don't have to check for too much worker
		if (!Lock::acquire('worker', 0)) {
			return true;
		}

		// If there are already enough workers running, don't fork another one
		$quit = self::tooMuchWorkers();
		Lock::release('worker');

		if ($quit) {
			return true;
		}

		// We tell the daemon that a new job entry exists
		if (Config::get('system', 'worker_daemon_mode', false)) {
			// We don't have to set the IPC flag - this is done in "tooMuchWorkers"
			return true;
		}

		// Now call the worker to execute the jobs that we just added to the queue
		self::spawnWorker();

		return true;
	}

	/**
	 * Log active processes into the "process" table
	 *
	 * @brief Log active processes into the "process" table
	 */
	public static function startProcess()
	{
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);

		$command = basename($trace[0]['file']);

		Process::deleteInactive();

		Process::insert($command);
	}

	/**
	 * Remove the active process from the "process" table
	 *
	 * @brief Remove the active process from the "process" table
	 * @return bool
	 */
	public static function endProcess()
	{
		return Process::deleteByPid();
	}

	/**
	 * Set the flag if some job is waiting
	 *
	 * @brief Set the flag if some job is waiting
	 * @param boolean $jobs Is there a waiting job?
	 */
	public static function IPCSetJobState($jobs)
	{
		DBA::update('worker-ipc', ['jobs' => $jobs], ['key' => 1], true);
	}

	/**
	 * Checks if some worker job waits to be executed
	 *
	 * @brief Checks if some worker job waits to be executed
	 * @return bool
	 */
	public static function IPCJobsExists()
	{
		$row = DBA::selectFirst('worker-ipc', ['jobs'], ['key' => 1]);

		// When we don't have a row, no job is running
		if (!DBM::is_result($row)) {
			return false;
		}

		return (bool)$row['jobs'];
	}
}
