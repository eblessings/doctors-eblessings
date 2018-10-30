<?php
/**
 * @file src/Model/Queue.php
 */
namespace Friendica\Model;

use Friendica\Core\Config;
use Friendica\Core\Logger;
use Friendica\Database\DBA;
use Friendica\Util\DateTimeFormat;

require_once 'include/dba.php';

class Queue
{
	/**
	 * @param string $id id
	 */
	public static function updateTime($id)
	{
		Logger::log('queue: requeue item ' . $id);
		$queue = DBA::selectFirst('queue', ['retrial'], ['id' => $id]);
		if (!DBA::isResult($queue)) {
			return;
		}

		$retrial = $queue['retrial'];

		if ($retrial > 14) {
			self::removeItem($id);
		}

		// Calculate the delay until the next trial
		$delay = (($retrial + 3) ** 4) + (rand(1, 30) * ($retrial + 1));
		$next = DateTimeFormat::utc('now + ' . $delay . ' seconds');

		DBA::update('queue', ['last' => DateTimeFormat::utcNow(), 'retrial' => $retrial + 1, 'next' => $next], ['id' => $id]);
	}

	/**
	 * @param string $id id
	 */
	public static function removeItem($id)
	{
		Logger::log('queue: remove queue item ' . $id);
		DBA::delete('queue', ['id' => $id]);
	}

	/**
	 * @brief Checks if the communication with a given contact had problems recently
	 *
	 * @param int $cid Contact id
	 *
	 * @return bool The communication with this contact has currently problems
	 */
	public static function wasDelayed($cid)
	{
		// Are there queue entries that were recently added?
		$r = q("SELECT `id` FROM `queue` WHERE `cid` = %d
			AND `last` > UTC_TIMESTAMP() - INTERVAL 15 MINUTE LIMIT 1",
			intval($cid)
		);

		$was_delayed = DBA::isResult($r);

		// We set "term-date" to a current date if the communication has problems.
		// If the communication works again we reset this value.
		if ($was_delayed) {
			$r = q("SELECT `term-date` FROM `contact` WHERE `id` = %d AND `term-date` <= '1000-01-01' LIMIT 1",
				intval($cid)
			);
			$was_delayed = !DBA::isResult($r);
		}

		return $was_delayed;
	}

	/**
	 * @param string  $cid     cid
	 * @param string  $network network
	 * @param string  $msg     message
	 * @param boolean $batch   batch, default false
	 */
	public static function add($cid, $network, $msg, $batch = false, $guid = '')
	{

		$max_queue = Config::get('system', 'max_contact_queue');
		if ($max_queue < 1) {
			$max_queue = 500;
		}

		$batch_queue = Config::get('system', 'max_batch_queue');
		if ($batch_queue < 1) {
			$batch_queue = 1000;
		}

		$r = q("SELECT COUNT(*) AS `total` FROM `queue` INNER JOIN `contact` ON `queue`.`cid` = `contact`.`id`
			WHERE `queue`.`cid` = %d AND `contact`.`self` = 0 ",
			intval($cid)
		);

		if (DBA::isResult($r)) {
			if ($batch &&  ($r[0]['total'] > $batch_queue)) {
				Logger::log('too many queued items for batch server ' . $cid . ' - discarding message');
				return;
			} elseif ((! $batch) && ($r[0]['total'] > $max_queue)) {
				Logger::log('too many queued items for contact ' . $cid . ' - discarding message');
				return;
			}
		}

		DBA::insert('queue', [
			'cid'     => $cid,
			'network' => $network,
			'guid'     => $guid,
			'created' => DateTimeFormat::utcNow(),
			'last'    => DateTimeFormat::utcNow(),
			'content' => $msg,
			'batch'   =>($batch) ? 1 : 0
		]);
		Logger::log('Added item ' . $guid . ' for ' . $cid);
	}
}
