<?php
/**
 *
 * Arbitrary configuration storage
 * Note:
 * Please do not store booleans - convert to 0/1 integer values
 * The get_?config() functions return boolean false for keys that are unset,
 * and this could lead to subtle bugs.
 *
 * There are a few places in the code (such as the admin panel) where boolean
 * configurations need to be fixed as of 10/08/2011.
 *
 * @package config;
 */


/**
 * retrieve a "family" of config variables
 * from database to cached storage
 */
if(! function_exists('load_config')) {
	function load_config($family) {
		global $a;
		$r = q("SELECT * FROM `config` WHERE `cat` = '%s'",
				dbesc($family)
		);
		if(count($r)) {
			foreach($r as $rr) {
				$k = $rr['k'];
				if ($rr['cat'] === 'config') {
					$a->config[$k] = $rr['v'];
				} else {
					$a->config[$family][$k] = $rr['v'];
				}
			}
		}
	}
}

/**
 * get a particular config variable given the family name
 * and key. Returns false if not set.
 *
 * If a key is found in the DB but doesn't exist in
 * local config cache, pull it into the cache so we don't have
 *to hit the DB again for this item.
 */
if(! function_exists('get_config')) {
	function get_config($family, $key) {

		global $a;


		if(isset($a->config[$family][$key])) {
			if($a->config[$family][$key] === '!<unset>!') {
				return false;
			}
			return $a->config[$family][$key];
		}
		$ret = q("SELECT `v` FROM `config` WHERE `cat` = '%s' AND `k` = '%s' LIMIT 1",
				dbesc($family),
				dbesc($key)
		);
		if(count($ret)) {
			// manage array value
			$val = (preg_match("|^a:[0-9]+:{.*}$|", $ret[0]['v'])?unserialize( $ret[0]['v']):$ret[0]['v']);
			$a->config[$family][$key] = $val;
			return $val;
		}
		else {
			$a->config[$family][$key] = '!<unset>!';
		}
		return false;
	}
}

/**
 * Store a config value ($value) in the category ($family)
 * under the key ($key)
 * 
 * Return the value, or false if the database update failed
 */
if(! function_exists('set_config')) {
	function set_config($family,$key,$value) {
		global $a;

		// manage array value
		$dbvalue = (is_array($value)?serialize($value):$value);

		$a->config[$family][$key] = $value;
		$ret = q("REPLACE INTO `config` ( `cat`, `k`, `v` ) VALUES ( '%s', '%s', '%s' ) ",
				dbesc($family),
				dbesc($key),
				dbesc($dbvalue)
		);
		if($ret) {
			return $value;
		}
		return $ret;

	}
}


if(! function_exists('load_pconfig')) {
	function load_pconfig($uid,$family) {
		global $a;
		$r = q("SELECT * FROM `pconfig` WHERE `cat` = '%s' AND `uid` = %d",
				dbesc($family),
				intval($uid)
		);
		if(count($r)) {
			foreach($r as $rr) {
				$k = $rr['k'];
				$a->config[$uid][$family][$k] = $rr['v'];
			}
		}
	}
}


/**
 * get a particular user-specific config variable given the family name, 
 * the user id and key. Returns false if not set.
 *
 * If a key is found in the DB but doesn't exist in
 * local config cache, pull it into the cache so we don't have
 * to hit the DB again for this item.
 */
if(! function_exists('get_pconfig')) {
	function get_pconfig($uid,$family, $key) {

		global $a;


		if(isset($a->config[$uid][$family][$key])) {
			if($a->config[$uid][$family][$key] === '!<unset>!') {
				return false;
			}
			return $a->config[$uid][$family][$key];
		}


		$ret = q("SELECT `v` FROM `pconfig` WHERE `uid` = %d AND `cat` = '%s' AND `k` = '%s' LIMIT 1",
				intval($uid),
				dbesc($family),
				dbesc($key)
		);

		if(count($ret)) {
			$val = (preg_match("|^a:[0-9]+:{.*}$|", $ret[0]['v'])?unserialize( $ret[0]['v']):$ret[0]['v']);
			$a->config[$uid][$family][$key] = $val;
			return $val;
		}
		else {
			$a->config[$uid][$family][$key] = '!<unset>!';
		}
		return false;
	}
}

/**
 * Delete a value from config. This function 
 * deletes both: db value and cache entry. 
 */
if(! function_exists('del_config')) {
	function del_config($family,$key) {

		global $a;
		if(x($a->config[$family],$key))
			unset($a->config[$family][$key]);
		$ret = q("DELETE FROM `config` WHERE `cat` = '%s' AND `k` = '%s' LIMIT 1",
				dbesc($family),
				dbesc($key)
		);
		return $ret;
	}
}


/**
 * Store a user-specific config value ($value) for user $uid in the category ($family)
 * under the key ($key). 
 * 
 * Return the value, or false if the database update failed
 */
if(! function_exists('set_pconfig')) {
	function set_pconfig($uid,$family,$key,$value) {

		global $a;

		// manage array value
		$dbvalue = (is_array($value)?serialize($value):$value);


		$a->config[$uid][$family][$key] = $value;
		$ret = q("REPLACE INTO `pconfig` ( `uid`, `cat`, `k`, `v` ) VALUES ( %d, '%s', '%s', '%s' ) ",
				intval($uid),
				dbesc($family),
				dbesc($key),
				dbesc($dbvalue)
		);
		if($ret) {
			return $value;
		}
		return $ret;

	}
}

if(! function_exists('del_pconfig')) {
	function del_pconfig($uid,$family,$key) {

		global $a;
		if(x($a->config[$uid][$family],$key))
			unset($a->config[$uid][$family][$key]);
		$ret = q("DELETE FROM `pconfig` WHERE `uid` = %d AND `cat` = '%s' AND `k` = '%s' LIMIT 1",
				intval($uid),
				dbesc($family),
				dbesc($key)
		);
		return $ret;
	}
}
