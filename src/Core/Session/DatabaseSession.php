<?php

namespace Friendica\Core\Session;

use Friendica\Core\Config\Configuration;
use Friendica\Core\Session;
use Friendica\Database\Database;
use Friendica\Model\User\Cookie;
use Psr\Log\LoggerInterface;
use SessionHandlerInterface;

/**
 * SessionHandler using database
 *
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
final class DatabaseSession extends NativeSession implements SessionHandlerInterface
{
	/** @var Database */
	private $dba;
	/** @var LoggerInterface */
	private $logger;
	/** @var array The $_SERVER variable */
	private $server;

	/**
	 * DatabaseSessionHandler constructor.
	 *
	 * @param Database        $dba
	 * @param LoggerInterface $logger
	 * @param array           $server
	 */
	public function __construct(Configuration $config, Cookie $cookie, Database $dba, LoggerInterface $logger, array $server)
	{
		parent::__construct($config, $cookie);

		$this->dba    = $dba;
		$this->logger = $logger;
		$this->server = $server;

		session_set_save_handler($this);
	}

	public function open($save_path, $session_name)
	{
		return true;
	}

	public function read($session_id)
	{
		if (empty($session_id)) {
			return '';
		}

		$session = $this->dba->selectFirst('session', ['data'], ['sid' => $session_id]);
		if ($this->dba->isResult($session)) {
			Session::$exists = true;
			return $session['data'];
		}

		$this->logger->notice('no data for session', ['session_id' => $session_id, 'uri' => $this->server['REQUEST_URI'] ?? '']);

		return '';
	}

	/**
	 * @brief Standard PHP session write callback
	 *
	 * This callback updates the DB-stored session data and/or the expiration depending
	 * on the case. Uses the Session::expire global for existing session, 5 minutes
	 * for newly created session.
	 *
	 * @param string $session_id   Session ID with format: [a-z0-9]{26}
	 * @param string $session_data Serialized session data
	 *
	 * @return boolean Returns false if parameters are missing, true otherwise
	 * @throws \Exception
	 */
	public function write($session_id, $session_data)
	{
		if (!$session_id) {
			return false;
		}

		if (!$session_data) {
			return true;
		}

		$expire         = time() + Session::$expire;
		$default_expire = time() + 300;

		if (Session::$exists) {
			$fields    = ['data' => $session_data, 'expire' => $expire];
			$condition = ["`sid` = ? AND (`data` != ? OR `expire` != ?)", $session_id, $session_data, $expire];
			$this->dba->update('session', $fields, $condition);
		} else {
			$fields = ['sid' => $session_id, 'expire' => $default_expire, 'data' => $session_data];
			$this->dba->insert('session', $fields);
		}

		return true;
	}

	public function close()
	{
		return true;
	}

	public function destroy($id)
	{
		return $this->dba->delete('session', ['sid' => $id]);
	}

	public function gc($maxlifetime)
	{
		return $this->dba->delete('session', ["`expire` < ?", time()]);
	}
}
