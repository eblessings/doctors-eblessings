<?php
/**
 * @file mod/worker.php
 * @brief Module for running the poller as frontend process
 */
require_once("include/poller.php");

use \Friendica\Core\Config;
use \Friendica\Core\PConfig;

function worker_init($a){

	if (!Config::get("system", "frontend_worker") OR !Config::get("system", "worker")) {
		return;
	}

	// We don't need the following lines if we can execute background jobs
	if (function_exists("proc_open")) {
		return;
	}

	clear_worker_processes();

	$workers = q("SELECT COUNT(*) AS `processes` FROM `process` WHERE `command` = 'worker.php'");

	if ($workers[0]["processes"] > Config::get("system", "worker_queues", 4)) {
		return;
	}

	$a->start_process();

	logger("Front end worker started: ".getmypid());

	call_worker();

	if ($r = poller_worker_process()) {

		// On most configurations this parameter wouldn't have any effect.
		// But since it doesn't destroy anything, we just try to get more execution time in any way.
		set_time_limit(0);

		poller_execute($r[0]);
	}

	call_worker();

	$a->end_process();

	logger("Front end worker ended: ".getmypid());

	killme();
}
