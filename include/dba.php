<?php
require_once("dbm.php");
require_once('include/datetime.php');

/**
 * @class MySQL database class
 *
 * For debugging, insert 'dbg(1);' anywhere in the program flow.
 * dbg(0); will turn it off. Logging is performed at LOGGER_DATA level.
 * When logging, all binary info is converted to text and html entities are escaped so that
 * the debugging stream is safe to view within both terminals and web pages.
 *
 */

class dba {

	private $debug = 0;
	private $db;
	private $result;
	private $driver;
	public  $connected = false;
	public  $error = false;

	function __construct($server, $user, $pass, $db, $install = false) {
		$a = get_app();

		$stamp1 = microtime(true);

		$server = trim($server);
		$user = trim($user);
		$pass = trim($pass);
		$db = trim($db);

		if (!(strlen($server) && strlen($user))) {
			$this->connected = false;
			$this->db = null;
			return;
		}

		if ($install) {
			if (strlen($server) && ($server !== 'localhost') && ($server !== '127.0.0.1')) {
				if (! dns_get_record($server, DNS_A + DNS_CNAME + DNS_PTR)) {
					$this->error = sprintf(t('Cannot locate DNS info for database server \'%s\''), $server);
					$this->connected = false;
					$this->db = null;
					return;
				}
			}
		}

		if (class_exists('\PDO') && in_array('mysql', PDO::getAvailableDrivers())) {
			$this->driver = 'pdo';
			$connect = "mysql:host=".$server.";dbname=".$db;
			if (isset($a->config["system"]["db_charset"])) {
				$connect .= ";charset=".$a->config["system"]["db_charset"];
			}
			$this->db = @new PDO($connect, $user, $pass);
			if (!$this->db->errorCode()) {
				$this->connected = true;
			}
		} elseif (class_exists('mysqli')) {
			$this->driver = 'mysqli';
			$this->db = @new mysqli($server,$user,$pass,$db);
			if (!mysqli_connect_errno()) {
				$this->connected = true;

				if (isset($a->config["system"]["db_charset"])) {
					$this->db->set_charset($a->config["system"]["db_charset"]);
				}
			}
		} elseif (function_exists('mysql_connect')) {
			$this->driver = 'mysql';
			$this->db = mysql_connect($server,$user,$pass);
			if ($this->db && mysql_select_db($db,$this->db)) {
				$this->connected = true;

				if (isset($a->config["system"]["db_charset"])) {
					mysql_set_charset($a->config["system"]["db_charset"], $this->db);
				}
			}
		} else {
			// No suitable SQL driver was found.
			if (!$install) {
				system_unavailable();
			}
		}

		if (!$this->connected) {
			$this->db = null;
			if (!$install) {
				system_unavailable();
			}
		}
		$a->save_timestamp($stamp1, "network");
	}

	/**
	 * @brief Returns the MySQL server version string
	 * 
	 * This function discriminate between the deprecated mysql API and the current
	 * object-oriented mysqli API. Example of returned string: 5.5.46-0+deb8u1
	 *
	 * @return string
	 */
	public function server_info() {
		switch ($this->driver) {
			case 'pdo':
				$version = $this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
				break;
			case 'mysqli':
				$version = $this->db->server_info;
				break;
			case 'mysql':
				$version = mysql_get_server_info($this->db);
				break;
		}
		return $version;
	}

	/**
	 * @brief Returns the selected database name
	 *
	 * @return string
	 */
	public function database_name() {
		$r = $this->q("SELECT DATABASE() AS `db`");

		return $r[0]['db'];
	}

	/**
	 * @brief Returns the number of rows
	 *
	 * @return integer
	 */
	public function num_rows() {
		if (!$this->result) {
			return 0;
		}

		switch ($this->driver) {
			case 'pdo':
				$rows = $this->result->rowCount();
				break;
			case 'mysqli':
				$rows = $this->result->num_rows;
				break;
			case 'mysql':
				$rows = mysql_num_rows($this->result);
				break;
		}
		return $rows;
	}

	/**
	 * @brief Analyze a database query and log this if some conditions are met.
	 *
	 * @param string $query The database query that will be analyzed
	 */
	public function log_index($query) {
		$a = get_app();

		if ($a->config["system"]["db_log_index"] == "") {
			return;
		}

		// Don't explain an explain statement
		if (strtolower(substr($query, 0, 7)) == "explain") {
			return;
		}

		// Only do the explain on "select", "update" and "delete"
		if (!in_array(strtolower(substr($query, 0, 6)), array("select", "update", "delete"))) {
			return;
		}

		$r = $this->q("EXPLAIN ".$query);
		if (!dbm::is_result($r)) {
			return;
		}

		$watchlist = explode(',', $a->config["system"]["db_log_index_watch"]);
		$blacklist = explode(',', $a->config["system"]["db_log_index_blacklist"]);

		foreach ($r AS $row) {
			if ((intval($a->config["system"]["db_loglimit_index"]) > 0)) {
				$log = (in_array($row['key'], $watchlist) AND
					($row['rows'] >= intval($a->config["system"]["db_loglimit_index"])));
			} else {
				$log = false;
			}

			if ((intval($a->config["system"]["db_loglimit_index_high"]) > 0) AND ($row['rows'] >= intval($a->config["system"]["db_loglimit_index_high"]))) {
				$log = true;
			}

			if (in_array($row['key'], $blacklist) OR ($row['key'] == "")) {
				$log = false;
			}

			if ($log) {
				$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
				@file_put_contents($a->config["system"]["db_log_index"], datetime_convert()."\t".
						$row['key']."\t".$row['rows']."\t".$row['Extra']."\t".
						basename($backtrace[1]["file"])."\t".
						$backtrace[1]["line"]."\t".$backtrace[2]["function"]."\t".
						substr($query, 0, 2000)."\n", FILE_APPEND);
			}
		}
	}

	public function q($sql, $onlyquery = false) {
		$a = get_app();

		if (!$this->db || !$this->connected) {
			return false;
		}

		$this->error = '';

		$connstr = ($this->connected() ? "Connected" : "Disonnected");

		$stamp1 = microtime(true);

		$orig_sql = $sql;

		if (x($a->config,'system') && x($a->config['system'], 'db_callstack')) {
			$sql = "/*".$a->callstack()." */ ".$sql;
		}

		$columns = 0;

		switch ($this->driver) {
			case 'pdo':
				$result = @$this->db->query($sql);
				// Is used to separate between queries that returning data - or not
				if (!is_bool($result)) {
					$columns = $result->columnCount();
				}
				break;
			case 'mysqli':
				$result = @$this->db->query($sql);
				break;
			case 'mysql':
				$result = @mysql_query($sql,$this->db);
				break;
		}
		$stamp2 = microtime(true);
		$duration = (float)($stamp2-$stamp1);

		$a->save_timestamp($stamp1, "database");

		if (strtolower(substr($orig_sql, 0, 6)) != "select") {
			$a->save_timestamp($stamp1, "database_write");
		}
		if (x($a->config,'system') && x($a->config['system'],'db_log')) {
			if (($duration > $a->config["system"]["db_loglimit"])) {
				$duration = round($duration, 3);
				$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
				@file_put_contents($a->config["system"]["db_log"], datetime_convert()."\t".$duration."\t".
						basename($backtrace[1]["file"])."\t".
						$backtrace[1]["line"]."\t".$backtrace[2]["function"]."\t".
						substr($sql, 0, 2000)."\n", FILE_APPEND);
			}
		}

		switch ($this->driver) {
			case 'pdo':
				$errorInfo = $this->db->errorInfo();
				if ($errorInfo) {
					$this->error = $errorInfo[2];
					$this->errorno = $errorInfo[1];
				}
				break;
			case 'mysqli':
				if ($this->db->errno) {
					$this->error = $this->db->error;
					$this->errorno = $this->db->errno;
				}
				break;
			case 'mysql':
				if (mysql_errno($this->db)) {
					$this->error = mysql_error($this->db);
					$this->errorno = mysql_errno($this->db);
				}
				break;
		}
		if (strlen($this->error)) {
			logger('DB Error ('.$connstr.') '.$this->errorno.': '.$this->error);
		}

		if ($this->debug) {

			$mesg = '';

			if ($result === false) {
				$mesg = 'false';
			} elseif ($result === true) {
				$mesg = 'true';
			} else {
				switch ($this->driver) {
					case 'pdo':
						$mesg = $result->rowCount().' results'.EOL;
						break;
					case 'mysqli':
						$mesg = $result->num_rows.' results'.EOL;
						break;
					case 'mysql':
						$mesg = mysql_num_rows($result).' results'.EOL;
						break;
				}
			}

			$str =  'SQL = ' . printable($sql) . EOL . 'SQL returned ' . $mesg
				. (($this->error) ? ' error: ' . $this->error : '')
				. EOL;

			logger('dba: ' . $str );
		}

		/**
		 * If dbfail.out exists, we will write any failed calls directly to it,
		 * regardless of any logging that may or may nor be in effect.
		 * These usually indicate SQL syntax errors that need to be resolved.
		 */

		if ($result === false) {
			logger('dba: ' . printable($sql) . ' returned false.' . "\n" . $this->error);
			if (file_exists('dbfail.out')) {
				file_put_contents('dbfail.out', datetime_convert() . "\n" . printable($sql) . ' returned false' . "\n" . $this->error . "\n", FILE_APPEND);
			}
		}

		if (is_bool($result)) {
			return $result;
		}
		if ($onlyquery) {
			$this->result = $result;
			return true;
		}

		$r = array();
		switch ($this->driver) {
			case 'pdo':
				while ($x = $result->fetch(PDO::FETCH_ASSOC)) {
					$r[] = $x;
				}
				$result->closeCursor();
				break;
			case 'mysqli':
				while ($x = $result->fetch_array(MYSQLI_ASSOC)) {
					$r[] = $x;
				}
				$result->free_result();
				break;
			case 'mysql':
				while ($x = mysql_fetch_array($result, MYSQL_ASSOC)) {
					$r[] = $x;
				}
				mysql_free_result($result);
				break;
		}

		// PDO doesn't return "true" on successful operations - like mysqli does
		// Emulate this behaviour by checking if the query returned data and had columns
		// This should be reliable enough
		if (($this->driver == 'pdo') AND (count($r) == 0) AND ($columns == 0)) {
			return true;
		}

		//$a->save_timestamp($stamp1, "database");

		if ($this->debug) {
			logger('dba: ' . printable(print_r($r, true)));
		}
		return($r);
	}

	public function qfetch() {
		$x = false;

		if ($this->result) {
			switch ($this->driver) {
				case 'pdo':
					$x = $this->result->fetch(PDO::FETCH_ASSOC);
					break;
				case 'mysqli':
					$x = $this->result->fetch_array(MYSQLI_ASSOC);
					break;
				case 'mysql':
					$x = mysql_fetch_array($this->result, MYSQL_ASSOC);
					break;
			}
		}
		return($x);
	}

	public function qclose() {
		if ($this->result) {
			switch ($this->driver) {
				case 'pdo':
					$this->result->closeCursor();
					break;
				case 'mysqli':
					$this->result->free_result();
					break;
				case 'mysql':
					mysql_free_result($this->result);
					break;
			}
		}
	}

	public function dbg($dbg) {
		$this->debug = $dbg;
	}

	public function escape($str) {
		if ($this->db && $this->connected) {
			switch ($this->driver) {
				case 'pdo':
					return substr(@$this->db->quote($str, PDO::PARAM_STR), 1, -1);
				case 'mysqli':
					return @$this->db->real_escape_string($str);
				case 'mysql':
					return @mysql_real_escape_string($str,$this->db);
			}
		}
	}

	function connected() {
		switch ($this->driver) {
			case 'pdo':
				// Not sure if this really is working like expected
				$connected = ($this->db->getAttribute(PDO::ATTR_CONNECTION_STATUS) != "");
				break;
			case 'mysqli':
				$connected = $this->db->ping();
				break;
			case 'mysql':
				$connected = mysql_ping($this->db);
				break;
		}
		return $connected;
	}

	function insert_id() {
		switch ($this->driver) {
			case 'pdo':
				$id = $this->db->lastInsertId();
				break;
			case 'mysqli':
				$id = $this->db->insert_id;
				break;
			case 'mysql':
				$id = mysql_insert_id($this->db);
				break;
		}
		return $id;
	}

	function __destruct() {
		if ($this->db) {
			switch ($this->driver) {
				case 'pdo':
					$this->db = null;
					break;
				case 'mysqli':
					$this->db->close();
					break;
				case 'mysql':
					mysql_close($this->db);
					break;
			}
		}
	}
}

function printable($s) {
	$s = preg_replace("~([\x01-\x08\x0E-\x0F\x10-\x1F\x7F-\xFF])~",".", $s);
	$s = str_replace("\x00",'.',$s);
	if (x($_SERVER,'SERVER_NAME')) {
		$s = escape_tags($s);
	}
	return $s;
}

// Procedural functions
function dbg($state) {
	global $db;

	if ($db) {
		$db->dbg($state);
	}
}

function dbesc($str) {
	global $db;

	if ($db && $db->connected) {
		return($db->escape($str));
	} else {
		return(str_replace("'","\\'",$str));
	}
}

// Function: q($sql,$args);
// Description: execute SQL query with printf style args.
// Example: $r = q("SELECT * FROM `%s` WHERE `uid` = %d",
//                   'user', 1);
function q($sql) {
	global $db;
	$args = func_get_args();
	unset($args[0]);

	if ($db && $db->connected) {
		$stmt = @vsprintf($sql,$args); // Disabled warnings
		//logger("dba: q: $stmt", LOGGER_ALL);
		if ($stmt === false)
			logger('dba: vsprintf error: ' . print_r(debug_backtrace(),true), LOGGER_DEBUG);

		$db->log_index($stmt);

		return $db->q($stmt);
	}

	/**
	 *
	 * This will happen occasionally trying to store the
	 * session data after abnormal program termination
	 *
	 */
	logger('dba: no database: ' . print_r($args,true));
	return false;
}

/**
 * @brief Performs a query with "dirty reads"
 *
 * By doing dirty reads (reading uncommitted data) no locks are performed
 * This function can be used to fetch data that doesn't need to be reliable.
 *
 * @param $args Query parameters (1 to N parameters of different types)
 * @return array Query array
 */
function qu($sql) {
	global $db;

	$args = func_get_args();
	unset($args[0]);

	if ($db && $db->connected) {
		$stmt = @vsprintf($sql,$args); // Disabled warnings
		if ($stmt === false)
			logger('dba: vsprintf error: ' . print_r(debug_backtrace(),true), LOGGER_DEBUG);

		$db->log_index($stmt);

		$db->q("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;");
		$retval = $db->q($stmt);
		$db->q("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;");
		return $retval;
	}

	/**
	 *
	 * This will happen occasionally trying to store the
	 * session data after abnormal program termination
	 *
	 */
	logger('dba: no database: ' . print_r($args,true));
	return false;
}

/**
 *
 * Raw db query, no arguments
 *
 */
function dbq($sql) {
	global $db;

	if ($db && $db->connected) {
		$ret = $db->q($sql);
	} else {
		$ret = false;
	}
	return $ret;
}

// Caller is responsible for ensuring that any integer arguments to
// dbesc_array are actually integers and not malformed strings containing
// SQL injection vectors. All integer array elements should be specifically
// cast to int to avoid trouble.
function dbesc_array_cb(&$item, $key) {
	if (is_string($item))
		$item = dbesc($item);
}

function dbesc_array(&$arr) {
	if (is_array($arr) && count($arr)) {
		array_walk($arr,'dbesc_array_cb');
	}
}

function dba_timer() {
	return microtime(true);
}
