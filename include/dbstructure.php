<?php

use \Friendica\Core\Config;

require_once("boot.php");
require_once("include/text.php");

define('NEW_UPDATE_ROUTINE_VERSION', 1170);

/*
 * Converts all tables from MyISAM to InnoDB
 */
function convert_to_innodb() {
	global $db;

	$r = q("SELECT `TABLE_NAME` FROM `information_schema`.`tables` WHERE `engine` = 'MyISAM' AND `table_schema` = '%s'",
		dbesc($db->database_name()));

	if (!dbm::is_result($r)) {
		echo t('There are no tables on MyISAM.')."\n";
		return;
	}

	foreach ($r AS $table) {
		$sql = sprintf("ALTER TABLE `%s` engine=InnoDB;", dbesc($table['TABLE_NAME']));
		echo $sql."\n";

		$result = @$db->q($sql);
		if (!dbm::is_result($result)) {
			print_update_error($db, $sql);
		}
	}
}

/*
 * send the email and do what is needed to do on update fails
 *
 * @param update_id		(int) number of failed update
 * @param error_message	(str) error message
 */
function update_fail($update_id, $error_message) {
	//send the administrators an e-mail
	$admin_mail_list = "'".implode("','", array_map(dbesc, explode(",", str_replace(" ", "", $a->config['admin_email']))))."'";
	$adminlist = q("SELECT uid, language, email FROM user WHERE email IN (%s)",
		$admin_mail_list
	);

	// No valid result?
	if (!dbm::is_result($adminlist)) {
		logger(sprintf('Cannot notify administrators about update_id=%d, error_message=%s', $update_id, $error_message), LOGGER_WARNING);

		// Don't continue
		return;
	}

	// every admin could had different language
	foreach ($adminlist as $admin) {
		$lang = (($admin['language'])?$admin['language']:'en');
		push_lang($lang);

		$preamble = deindent(t("
			The friendica developers released update %s recently,
			but when I tried to install it, something went terribly wrong.
			This needs to be fixed soon and I can't do it alone. Please contact a
			friendica developer if you can not help me on your own. My database might be invalid."));
		$body = t("The error message is\n[pre]%s[/pre]");
		$preamble = sprintf($preamble, $update_id);
		$body = sprintf($body, $error_message);

		notification(array(
			'type' => "SYSTEM_EMAIL",
			'to_email' => $admin['email'],
			'preamble' => $preamble,
			'body' => $body,
			'language' => $lang,
		));
	}




	/*
	$email_tpl = get_intltext_template("update_fail_eml.tpl");
	$email_msg = replace_macros($email_tpl, array(
		'$sitename' => $a->config['sitename'],
		'$siteurl' =>  App::get_baseurl(),
		'$update' => DB_UPDATE_VERSION,
		'$error' => sprintf(t('Update %s failed. See error logs.'), DB_UPDATE_VERSION)
	));
	$subject=sprintf(t('Update Error at %s'), App::get_baseurl());
	require_once('include/email.php');
	$subject = email_header_encode($subject,'UTF-8');
	mail($a->config['admin_email'], $subject, $email_msg,
		'From: ' . 'Administrator' . '@' . $_SERVER['SERVER_NAME']."\n"
		.'Content-type: text/plain; charset=UTF-8'."\n"
		.'Content-transfer-encoding: 8bit');
	*/
	//try the logger
	logger("CRITICAL: Database structure update failed: ".$retval);
}


function table_structure($table) {
	$structures = q("DESCRIBE `%s`", $table);

	$full_columns = q("SHOW FULL COLUMNS FROM `%s`", $table);

	$indexes = q("SHOW INDEX FROM `%s`", $table);

	$table_status = q("SHOW TABLE STATUS WHERE `name` = '%s'", $table);

	if (dbm::is_result($table_status)) {
		$table_status = $table_status[0];
	} else {
		$table_status = array();
	}

	$fielddata = array();
	$indexdata = array();

	if (dbm::is_result($indexes))
		foreach ($indexes AS $index) {
			if ($index['Key_name'] != 'PRIMARY' && $index['Non_unique'] == '0' && !isset($indexdata[$index["Key_name"]])) {
				$indexdata[$index["Key_name"]] = array('UNIQUE');
			}

			$column = $index["Column_name"];
			// On utf8mb4 a varchar index can only have a length of 191
			// The "show index" command sometimes returns this value although this value wasn't added manually.
			// Because we don't want to add this number to every index, we ignore bigger numbers
			if (($index["Sub_part"] != "") AND (($index["Sub_part"] < 191) OR ($index["Key_name"] == "PRIMARY"))) {
				$column .= "(".$index["Sub_part"].")";
			}

			$indexdata[$index["Key_name"]][] = $column;
		}
	if (dbm::is_result($structures)) {
		foreach ($structures AS $field) {
			$fielddata[$field["Field"]]["type"] = $field["Type"];
			if ($field["Null"] == "NO") {
				$fielddata[$field["Field"]]["not null"] = true;
			}

			if (isset($field["Default"])) {
				$fielddata[$field["Field"]]["default"] = $field["Default"];
			}

			if ($field["Extra"] != "") {
				$fielddata[$field["Field"]]["extra"] = $field["Extra"];
			}

			if ($field["Key"] == "PRI") {
				$fielddata[$field["Field"]]["primary"] = true;
			}
		}
	}
	if (dbm::is_result($full_columns)) {
		foreach ($full_columns AS $column) {
			$fielddata[$column["Field"]]["Collation"] = $column["Collation"];
		}
	}

	return array("fields" => $fielddata, "indexes" => $indexdata, "table_status" => $table_status);
}

function print_structure($database) {
	echo "-- ------------------------------------------\n";
	echo "-- ".FRIENDICA_PLATFORM." ".FRIENDICA_VERSION." (".FRIENDICA_CODENAME,")\n";
	echo "-- DB_UPDATE_VERSION ".DB_UPDATE_VERSION."\n";
	echo "-- ------------------------------------------\n\n\n";
	foreach ($database AS $name => $structure) {
		echo "--\n";
		echo "-- TABLE $name\n";
		echo "--\n";
		db_create_table($name, $structure['fields'], true, false, $structure["indexes"]);

		echo "\n";
	}
}

/**
 * @brief Print out database error messages
 *
 * @param object $db Database object
 * @param string $message Message to be added to the error message
 *
 * @return string Error message
 */
function print_update_error($db, $message) {
	echo sprintf(t("\nError %d occurred during database update:\n%s\n"),
		$db->errorno, $db->error);

	return t('Errors encountered performing database changes: ').$message.EOL;
}

function update_structure($verbose, $action, $tables=null, $definition=null) {
	global $a, $db;

	if ($action) {
		Config::set('system', 'maintenance', 1);
		Config::set('system', 'maintenance_reason', sprintf(t(': Database update'), dbm::date().' '.date('e')));
	}

	$errors = false;

	logger('updating structure', LOGGER_DEBUG);

	// Get the current structure
	$database = array();

	if (is_null($tables)) {
		$tables = q("SHOW TABLES");
	}

	if (dbm::is_result($tables)) {
		foreach ($tables AS $table) {
			$table = current($table);

			logger(sprintf('updating structure for table %s ...', $table), LOGGER_DEBUG);
			$database[$table] = table_structure($table);
		}
	}

	// Get the definition
	if (is_null($definition)) {
		$definition = db_definition();
	}

	// MySQL >= 5.7.4 doesn't support the IGNORE keyword in ALTER TABLE statements
	if ((version_compare($db->server_info(), '5.7.4') >= 0) AND
		!(strpos($db->server_info(), 'MariaDB') !== false)) {
		$ignore = '';
	} else {
		$ignore = ' IGNORE';
	}

	// Compare it
	foreach ($definition AS $name => $structure) {
		$is_new_table = False;
		$group_by = "";
		$sql3 = "";
		if (!isset($database[$name])) {
			$r = db_create_table($name, $structure["fields"], $verbose, $action, $structure['indexes']);
			if (!dbm::is_result($r)) {
				$errors .= print_update_error($db, $name);
			}
			$is_new_table = True;
		} else {
			$is_unique = false;
			$temp_name = $name;

			foreach ($structure["indexes"] AS $indexname => $fieldnames) {
				if (isset($database[$name]["indexes"][$indexname])) {
					$current_index_definition = implode(",",$database[$name]["indexes"][$indexname]);
				} else {
					$current_index_definition = "__NOT_SET__";
				}
				$new_index_definition = implode(",",$fieldnames);
				if ($current_index_definition != $new_index_definition) {
					if ($fieldnames[0] == "UNIQUE") {
						$is_unique = true;
						if ($ignore == "") {
							$temp_name = "temp-".$name;
						}
					}
				}
			}

			/*
			 * Drop the index if it isn't present in the definition
			 * or the definition differ from current status
			 * and index name doesn't start with "local_"
			 */
			foreach ($database[$name]["indexes"] as $indexname => $fieldnames) {
				$current_index_definition = implode(",",$fieldnames);
				if (isset($structure["indexes"][$indexname])) {
					$new_index_definition = implode(",",$structure["indexes"][$indexname]);
				} else {
					$new_index_definition = "__NOT_SET__";
				}
				if ($current_index_definition != $new_index_definition && substr($indexname, 0, 6) != 'local_') {
					$sql2=db_drop_index($indexname);
					if ($sql3 == "") {
						$sql3 = "ALTER".$ignore." TABLE `".$temp_name."` ".$sql2;
					} else {
						$sql3 .= ", ".$sql2;
					}
				}
			}
			// Compare the field structure field by field
			foreach ($structure["fields"] AS $fieldname => $parameters) {
				if (!isset($database[$name]["fields"][$fieldname])) {
					$sql2=db_add_table_field($fieldname, $parameters);
					if ($sql3 == "") {
						$sql3 = "ALTER" . $ignore . " TABLE `".$temp_name."` ".$sql2;
					} else {
						$sql3 .= ", ".$sql2;
					}
				} else {
					// Compare the field definition
					$field_definition = $database[$name]["fields"][$fieldname];

					// Remove the relation data that is used for the referential integrity
					unset($parameters['relation']);

					// We change the collation after the indexes had been changed.
					// This is done to avoid index length problems.
					// So here we always ensure that there is no need to change it.
					unset($parameters['Collation']);
					unset($field_definition['Collation']);

					$current_field_definition = implode(",", $field_definition);
					$new_field_definition = implode(",", $parameters);
					if ($current_field_definition != $new_field_definition) {
						$sql2 = db_modify_table_field($fieldname, $parameters);
						if ($sql3 == "") {
							$sql3 = "ALTER" . $ignore . " TABLE `".$temp_name."` ".$sql2;
						} else {
							$sql3 .= ", ".$sql2;
						}
					}
				}
			}
		}

		/*
		 * Create the index if the index don't exists in database
		 * or the definition differ from the current status.
		 * Don't create keys if table is new
		 */
		if (!$is_new_table) {
			foreach ($structure["indexes"] AS $indexname => $fieldnames) {
				if (isset($database[$name]["indexes"][$indexname])) {
					$current_index_definition = implode(",",$database[$name]["indexes"][$indexname]);
				} else {
					$current_index_definition = "__NOT_SET__";
				}
				$new_index_definition = implode(",",$fieldnames);
				if ($current_index_definition != $new_index_definition) {
					$sql2 = db_create_index($indexname, $fieldnames);

					// Fetch the "group by" fields for unique indexes
					if ($fieldnames[0] == "UNIQUE") {
						$group_by = db_group_by($indexname, $fieldnames);
					}
					if ($sql2 != "") {
						if ($sql3 == "") {
							$sql3 = "ALTER" . $ignore . " TABLE `".$temp_name."` ".$sql2;
						} else {
							$sql3 .= ", ".$sql2;
						}
					}
				}
			}

			if (isset($database[$name]["table_status"]["Collation"])) {
				if ($database[$name]["table_status"]["Collation"] != 'utf8mb4_general_ci') {
					$sql2 = "DEFAULT COLLATE utf8mb4_general_ci";

					if ($sql3 == "") {
						$sql3 = "ALTER" . $ignore . " TABLE `".$temp_name."` ".$sql2;
					} else {
						$sql3 .= ", ".$sql2;
					}
				}
			}

			if ($sql3 != "") {
				$sql3 .= "; ";
			}

			// Now have a look at the field collations
			// Compare the field structure field by field
			foreach ($structure["fields"] AS $fieldname => $parameters) {
				// Compare the field definition
				$field_definition = $database[$name]["fields"][$fieldname];

				// Define the default collation if not given
				if (!isset($parameters['Collation']) AND !is_null($field_definition['Collation'])) {
					$parameters['Collation'] = 'utf8mb4_general_ci';
				} else {
					$parameters['Collation'] = null;
				}

				if ($field_definition['Collation'] != $parameters['Collation']) {
					$sql2 = db_modify_table_field($fieldname, $parameters);
					if (($sql3 == "") OR (substr($sql3, -2, 2) == "; ")) {
						$sql3 .= "ALTER" . $ignore . " TABLE `".$temp_name."` ".$sql2;
					} else {
						$sql3 .= ", ".$sql2;
					}
				}
			}
		}

		if ($sql3 != "") {
			if (substr($sql3, -2, 2) != "; ") {
				$sql3 .= ";";
			}

			if ($verbose) {
				// Ensure index conversion to unique removes duplicates
				if ($is_unique) {
					if ($ignore != "") {
						echo "SET session old_alter_table=1;\n";
					} else {
						echo "CREATE TABLE `".$temp_name."` LIKE `".$name."`;\n";
					}
				}

				echo $sql3."\n";

				if ($is_unique) {
					if ($ignore != "") {
						echo "SET session old_alter_table=0;\n";
					} else {
						echo "INSERT INTO `".$temp_name."` SELECT * FROM `".$name."`".$group_by.";\n";
						echo "DROP TABLE `".$name."`;\n";
						echo "RENAME TABLE `".$temp_name."` TO `".$name."`;\n";
					}
				}
			}

			if ($action) {
				Config::set('system', 'maintenance_reason', sprintf(t('%s: updating %s table.'), dbm::date().' '.date('e'), $name));

				// Ensure index conversion to unique removes duplicates
				if ($is_unique) {
					if ($ignore != "") {
						$db->q("SET session old_alter_table=1;");
					} else {
						$r = $db->q("CREATE TABLE `".$temp_name."` LIKE `".$name."`;");
						if (!dbm::is_result($r)) {
							$errors .= print_update_error($db, $sql3);
							return $errors;
						}
					}
				}

				$r = @$db->q($sql3);
				if (!dbm::is_result($r)) {
					$errors .= print_update_error($db, $sql3);
				}
				if ($is_unique) {
					if ($ignore != "") {
						$db->q("SET session old_alter_table=0;");
					} else {
						$r = $db->q("INSERT INTO `".$temp_name."` SELECT * FROM `".$name."`".$group_by.";");
						if (!dbm::is_result($r)) {
							$errors .= print_update_error($db, $sql3);
							return $errors;
						}
						$r = $db->q("DROP TABLE `".$name."`;");
						if (!dbm::is_result($r)) {
							$errors .= print_update_error($db, $sql3);
							return $errors;
						}
						$r = $db->q("RENAME TABLE `".$temp_name."` TO `".$name."`;");
						if (!dbm::is_result($r)) {
							$errors .= print_update_error($db, $sql3);
							return $errors;
						}
					}
				}
			}
		}
	}

	if ($action) {
		Config::set('system', 'maintenance', 0);
		Config::set('system', 'maintenance_reason', '');
	}

	return $errors;
}

function db_field_command($parameters, $create = true) {
	$fieldstruct = $parameters["type"];

	if (!is_null($parameters["Collation"])) {
		$fieldstruct .= " COLLATE ".$parameters["Collation"];
	}

	if ($parameters["not null"])
		$fieldstruct .= " NOT NULL";

	if (isset($parameters["default"])) {
		if (strpos(strtolower($parameters["type"]),"int")!==false) {
			$fieldstruct .= " DEFAULT ".$parameters["default"];
		} else {
			$fieldstruct .= " DEFAULT '".$parameters["default"]."'";
		}
	}
	if ($parameters["extra"] != "")
		$fieldstruct .= " ".$parameters["extra"];

	/*if (($parameters["primary"] != "") AND $create)
		$fieldstruct .= " PRIMARY KEY";*/

	return($fieldstruct);
}

function db_create_table($name, $fields, $verbose, $action, $indexes=null) {
	global $a, $db;

	$r = true;

	$sql = "";

	$sql_rows = array();
	$primary_keys = array();
	foreach ($fields AS $fieldname => $field) {
		$sql_rows[] = "`".dbesc($fieldname)."` ".db_field_command($field);
		if (x($field,'primary') and $field['primary']!='') {
			$primary_keys[] = $fieldname;
		}
	}

	if (!is_null($indexes)) {
		foreach ($indexes AS $indexname => $fieldnames) {
			$sql_index = db_create_index($indexname, $fieldnames, "");
			if (!is_null($sql_index)) $sql_rows[] = $sql_index;
		}
	}

	$sql = implode(",\n\t", $sql_rows);

	$sql = sprintf("CREATE TABLE IF NOT EXISTS `%s` (\n\t", dbesc($name)).$sql."\n) DEFAULT COLLATE utf8mb4_general_ci";
	if ($verbose)
		echo $sql.";\n";

	if ($action)
		$r = @$db->q($sql);

	return $r;
}

function db_add_table_field($fieldname, $parameters) {
	$sql = sprintf("ADD `%s` %s", dbesc($fieldname), db_field_command($parameters));
	return($sql);
}

function db_modify_table_field($fieldname, $parameters) {
	$sql = sprintf("MODIFY `%s` %s", dbesc($fieldname), db_field_command($parameters, false));
	return($sql);
}

function db_drop_index($indexname) {
	$sql = sprintf("DROP INDEX `%s`", dbesc($indexname));
	return($sql);
}

function db_create_index($indexname, $fieldnames, $method="ADD") {

	$method = strtoupper(trim($method));
	if ($method!="" && $method!="ADD") {
		throw new Exception("Invalid parameter 'method' in db_create_index(): '$method'");
		killme();
	}

	if ($fieldnames[0] == "UNIQUE") {
		array_shift($fieldnames);
		$method .= ' UNIQUE';
	}

	$names = "";
	foreach ($fieldnames AS $fieldname) {
		if ($names != "")
			$names .= ",";

		if (preg_match('|(.+)\((\d+)\)|', $fieldname, $matches)) {
			$names .= "`".dbesc($matches[1])."`(".intval($matches[2]).")";
		} else {
			$names .= "`".dbesc($fieldname)."`";
		}
	}

	if ($indexname == "PRIMARY") {
		return sprintf("%s PRIMARY KEY(%s)", $method, $names);
	}


	$sql = sprintf("%s INDEX `%s` (%s)", $method, dbesc($indexname), $names);
	return($sql);
}

function db_group_by($indexname, $fieldnames) {

	if ($fieldnames[0] != "UNIQUE") {
		return "";
	}

	array_shift($fieldnames);

	$names = "";
	foreach ($fieldnames AS $fieldname) {
		if ($names != "")
			$names .= ",";

		if (preg_match('|(.+)\((\d+)\)|', $fieldname, $matches)) {
			$names .= "`".dbesc($matches[1])."`";
		} else {
			$names .= "`".dbesc($fieldname)."`";
		}
	}

	$sql = sprintf(" GROUP BY %s", $names);
	return $sql;
}

function db_definition() {

	$database = array();

	$database["addon"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"name" => array("type" => "varchar(190)", "not null" => "1", "default" => ""),
					"version" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"installed" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"hidden" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"timestamp" => array("type" => "bigint(20)", "not null" => "1", "default" => "0"),
					"plugin_admin" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"name" => array("UNIQUE", "name"),
					)
			);
	$database["attach"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"hash" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"filename" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"filetype" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"filesize" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"data" => array("type" => "longblob", "not null" => "1"),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"edited" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"allow_cid" => array("type" => "mediumtext"),
					"allow_gid" => array("type" => "mediumtext"),
					"deny_cid" => array("type" => "mediumtext"),
					"deny_gid" => array("type" => "mediumtext"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["auth_codes"] = array(
			"fields" => array(
					"id" => array("type" => "varchar(40)", "not null" => "1", "primary" => "1"),
					"client_id" => array("type" => "varchar(20)", "not null" => "1", "default" => "", "relation" => array("clients" => "client_id")),
					"redirect_uri" => array("type" => "varchar(200)", "not null" => "1", "default" => ""),
					"expires" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"scope" => array("type" => "varchar(250)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["cache"] = array(
			"fields" => array(
					"k" => array("type" => "varbinary(255)", "not null" => "1", "primary" => "1"),
					"v" => array("type" => "mediumtext"),
					"expire_mode" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"updated" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("k"),
					"expire_mode_updated" => array("expire_mode", "updated"),
					)
			);
	$database["challenge"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"challenge" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"dfrn-id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"expire" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"type" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"last_update" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["clients"] = array(
			"fields" => array(
					"client_id" => array("type" => "varchar(20)", "not null" => "1", "primary" => "1"),
					"pw" => array("type" => "varchar(20)", "not null" => "1", "default" => ""),
					"redirect_uri" => array("type" => "varchar(200)", "not null" => "1", "default" => ""),
					"name" => array("type" => "text"),
					"icon" => array("type" => "text"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					),
			"indexes" => array(
					"PRIMARY" => array("client_id"),
					)
			);
	$database["config"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"cat" => array("type" => "varbinary(255)", "not null" => "1", "default" => ""),
					"k" => array("type" => "varbinary(255)", "not null" => "1", "default" => ""),
					"v" => array("type" => "mediumtext"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"cat_k" => array("UNIQUE", "cat", "k"),
					)
			);
	$database["contact"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"self" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"remote_self" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"rel" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"duplex" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"network" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nick" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"location" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"about" => array("type" => "text"),
					"keywords" => array("type" => "text"),
					"gender" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"xmpp" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"attag" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"avatar" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"photo" => array("type" => "text"),
					"thumb" => array("type" => "text"),
					"micro" => array("type" => "text"),
					"site-pubkey" => array("type" => "text"),
					"issued-id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"dfrn-id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nurl" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"addr" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"alias" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pubkey" => array("type" => "text"),
					"prvkey" => array("type" => "text"),
					"batch" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"request" => array("type" => "text"),
					"notify" => array("type" => "text"),
					"poll" => array("type" => "text"),
					"confirm" => array("type" => "text"),
					"poco" => array("type" => "text"),
					"aes_allow" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"ret-aes" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"usehub" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"subhub" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"hub-verify" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"last-update" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"success_update" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"failure_update" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"name-date" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"uri-date" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"avatar-date" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"term-date" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"last-item" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"priority" => array("type" => "tinyint(3)", "not null" => "1", "default" => "0"),
					"blocked" => array("type" => "tinyint(1)", "not null" => "1", "default" => "1"),
					"readonly" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"writable" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"forum" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"prv" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"contact-type" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"hidden" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"archive" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"pending" => array("type" => "tinyint(1)", "not null" => "1", "default" => "1"),
					"rating" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"reason" => array("type" => "text"),
					"closeness" => array("type" => "tinyint(2)", "not null" => "1", "default" => "99"),
					"info" => array("type" => "mediumtext"),
					"profile-id" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"bdyear" => array("type" => "varchar(4)", "not null" => "1", "default" => ""),
					"bd" => array("type" => "date", "not null" => "1", "default" => "0001-01-01"),
					"notify_new_posts" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"fetch_further_information" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"ffi_keyword_blacklist" => array("type" => "text"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid_name" => array("uid", "name(190)"),
					"self_uid" => array("self", "uid"),
					"alias_uid" => array("alias(32)", "uid"),
					"pending_uid" => array("pending", "uid"),
					"blocked_uid" => array("blocked", "uid"),
					"uid_rel_network_poll" => array("uid", "rel", "network(4)", "poll(64)", "archive"),
					"uid_network_batch" => array("uid", "network(4)", "batch(64)"),
					"addr_uid" => array("addr(32)", "uid"),
					"nurl_uid" => array("nurl(32)", "uid"),
					"nick_uid" => array("nick(32)", "uid"),
					"dfrn-id" => array("dfrn-id"),
					"issued-id" => array("issued-id"),
					)
			);
	$database["conv"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"guid" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"recips" => array("type" => "text"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"creator" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"updated" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"subject" => array("type" => "text"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					)
			);
	$database["conversation"] = array(
			"fields" => array(
					"item-uri" => array("type" => "varbinary(255)", "not null" => "1", "primary" => "1"),
					"reply-to-uri" => array("type" => "varbinary(255)", "not null" => "1", "default" => ""),
					"conversation-uri" => array("type" => "varbinary(255)", "not null" => "1", "default" => ""),
					"conversation-href" => array("type" => "varbinary(255)", "not null" => "1", "default" => ""),
					"protocol" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"source" => array("type" => "mediumtext"),
					"received" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("item-uri"),
					"conversation-uri" => array("conversation-uri"),
					)
			);
	$database["event"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"guid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"cid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"uri" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"edited" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"start" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"finish" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"summary" => array("type" => "text"),
					"desc" => array("type" => "text"),
					"location" => array("type" => "text"),
					"type" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nofinish" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"adjust" => array("type" => "tinyint(1)", "not null" => "1", "default" => "1"),
					"ignore" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"allow_cid" => array("type" => "mediumtext"),
					"allow_gid" => array("type" => "mediumtext"),
					"deny_cid" => array("type" => "mediumtext"),
					"deny_gid" => array("type" => "mediumtext"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid_start" => array("uid", "start"),
					)
			);
	$database["fcontact"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"guid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"photo" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"request" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nick" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"addr" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"batch" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"notify" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"poll" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"confirm" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"priority" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"network" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"alias" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pubkey" => array("type" => "text"),
					"updated" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"addr" => array("addr(32)"),
					"url" => array("UNIQUE", "url(190)"),
					)
			);
	$database["ffinder"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"cid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"fid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("fcontact" => "id")),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["fserver"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"server" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"posturl" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"key" => array("type" => "text"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"server" => array("server(32)"),
					)
			);
	$database["fsuggest"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"cid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"request" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"photo" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"note" => array("type" => "text"),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["gcign"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"gcid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("gcontact" => "id")),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					"gcid" => array("gcid"),
					)
			);
	$database["gcontact"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nick" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nurl" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"photo" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"connect" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"updated" => array("type" => "datetime", "default" => NULL_DATE),
					"last_contact" => array("type" => "datetime", "default" => NULL_DATE),
					"last_failure" => array("type" => "datetime", "default" => NULL_DATE),
					"location" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"about" => array("type" => "text"),
					"keywords" => array("type" => "text"),
					"gender" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"birthday" => array("type" => "varchar(32)", "not null" => "1", "default" => "0001-01-01"),
					"community" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"contact-type" => array("type" => "tinyint(1)", "not null" => "1", "default" => "-1"),
					"hide" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"nsfw" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"network" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"addr" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"notify" => array("type" => "text"),
					"alias" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"generation" => array("type" => "tinyint(3)", "not null" => "1", "default" => "0"),
					"server_url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"nurl" => array("UNIQUE", "nurl(190)"),
					"name" => array("name(64)"),
					"nick" => array("nick(32)"),
					"addr" => array("addr(64)"),
					"hide_network_updated" => array("hide", "network(4)", "updated"),
					"updated" => array("updated"),
					)
			);
	$database["glink"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"cid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"gcid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("gcontact" => "id")),
					"zcid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("gcontact" => "id")),
					"updated" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"cid_uid_gcid_zcid" => array("UNIQUE", "cid","uid","gcid","zcid"),
					"gcid" => array("gcid"),
					)
			);
	$database["group"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"visible" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"deleted" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					)
			);
	$database["group_member"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"gid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("group" => "id")),
					"contact-id" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"contactid" => array("contact-id"),
					"gid_contactid" => array("gid", "contact-id"),
					"uid_gid_contactid" => array("UNIQUE", "uid", "gid", "contact-id"),
					)
			);
	$database["gserver"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nurl" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"version" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"site_name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"info" => array("type" => "text"),
					"register_policy" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"poco" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"noscrape" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"network" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"platform" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"last_poco_query" => array("type" => "datetime", "default" => NULL_DATE),
					"last_contact" => array("type" => "datetime", "default" => NULL_DATE),
					"last_failure" => array("type" => "datetime", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"nurl" => array("UNIQUE", "nurl(190)"),
					)
			);
	$database["hook"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"hook" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"file" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"function" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"priority" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"hook_file_function" => array("UNIQUE", "hook(50)","file(80)","function(60)"),
					)
			);
	$database["intro"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"fid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("fcontact" => "id")),
					"contact-id" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"knowyou" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"duplex" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"note" => array("type" => "text"),
					"hash" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"datetime" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"blocked" => array("type" => "tinyint(1)", "not null" => "1", "default" => "1"),
					"ignore" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["item"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1", "relation" => array("thread" => "iid")),
					"guid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"uri" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"contact-id" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"gcontact-id" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0", "relation" => array("gcontact" => "id")),
					"type" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"wall" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"gravity" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"parent" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("item" => "id")),
					"parent-uri" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"extid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"thr-parent" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"edited" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"commented" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"received" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"changed" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"owner-id" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"owner-name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"owner-link" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"owner-avatar" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"author-id" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"author-name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"author-link" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"author-avatar" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"title" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"body" => array("type" => "mediumtext"),
					"app" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"verb" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"object-type" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"object" => array("type" => "text"),
					"target-type" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"target" => array("type" => "text"),
					"postopts" => array("type" => "text"),
					"plink" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"resource-id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"event-id" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("event" => "id")),
					"tag" => array("type" => "mediumtext"),
					"attach" => array("type" => "mediumtext"),
					"inform" => array("type" => "mediumtext"),
					"file" => array("type" => "mediumtext"),
					"location" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"coord" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"allow_cid" => array("type" => "mediumtext"),
					"allow_gid" => array("type" => "mediumtext"),
					"deny_cid" => array("type" => "mediumtext"),
					"deny_gid" => array("type" => "mediumtext"),
					"private" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"pubmail" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"moderated" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"visible" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"spam" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"starred" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"bookmark" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"unseen" => array("type" => "tinyint(1)", "not null" => "1", "default" => "1"),
					"deleted" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"origin" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"forum_mode" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"last-child" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "1"),
					"mention" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"network" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"rendered-hash" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"rendered-html" => array("type" => "mediumtext"),
					"global" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"guid" => array("guid"),
					"uri" => array("uri"),
					"parent" => array("parent"),
					"parent-uri" => array("parent-uri"),
					"extid" => array("extid"),
					"uid_id" => array("uid","id"),
					"uid_contactid_id" => array("uid","contact-id","id"),
					"uid_created" => array("uid","created"),
					"uid_unseen_contactid" => array("uid","unseen","contact-id"),
					"uid_network_received" => array("uid","network(4)","received"),
					"uid_network_commented" => array("uid","network(4)","commented"),
					"uid_thrparent" => array("uid","thr-parent(190)"),
					"uid_parenturi" => array("uid","parent-uri(190)"),
					"uid_contactid_created" => array("uid","contact-id","created"),
					"authorid_created" => array("author-id","created"),
					"ownerid" => array("owner-id"),
					"uid_uri" => array("uid", "uri(190)"),
					"resource-id" => array("resource-id"),
					"contactid_allowcid_allowpid_denycid_denygid" => array("contact-id","allow_cid(10)","allow_gid(10)","deny_cid(10)","deny_gid(10)"), //
					"uid_type_changed" => array("uid","type(190)","changed"),
					"contactid_verb" => array("contact-id","verb(190)"),
					"deleted_changed" => array("deleted","changed"),
					"uid_wall_changed" => array("uid","wall","changed"),
					"uid_eventid" => array("uid","event-id"),
					"uid_authorlink" => array("uid","author-link(190)"),
					"uid_ownerlink" => array("uid","owner-link(190)"),
					)
			);
	$database["item_id"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"iid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("item" => "id")),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"sid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"service" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					"sid" => array("sid"),
					"service" => array("service(32)"),
					"iid" => array("iid"),
					)
			);
	$database["locks"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"name" => array("type" => "varchar(128)", "not null" => "1", "default" => ""),
					"locked" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"created" => array("type" => "datetime", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["mail"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"guid" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"from-name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"from-photo" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"from-url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"contact-id" => array("type" => "varchar(255)", "not null" => "1", "default" => "", "relation" => array("contact" => "id")),
					"convid" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0", "relation" => array("conv" => "id")),
					"title" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"body" => array("type" => "mediumtext"),
					"seen" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"reply" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"replied" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"unknown" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"uri" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"parent-uri" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid_seen" => array("uid", "seen"),
					"convid" => array("convid"),
					"uri" => array("uri(64)"),
					"parent-uri" => array("parent-uri(64)"),
					)
			);
	$database["mailacct"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"server" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"port" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"ssltype" => array("type" => "varchar(16)", "not null" => "1", "default" => ""),
					"mailbox" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"user" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pass" => array("type" => "text"),
					"reply_to" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"action" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"movetofolder" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pubmail" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"last_check" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["manage"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"mid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid_mid" => array("UNIQUE", "uid","mid"),
					)
			);
	$database["notify"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"hash" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"type" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"photo" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"date" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"msg" => array("type" => "mediumtext"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"link" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"iid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("item" => "id")),
					"parent" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("item" => "id")),
					"seen" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"verb" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"otype" => array("type" => "varchar(16)", "not null" => "1", "default" => ""),
					"name_cache" => array("type" => "tinytext"),
					"msg_cache" => array("type" => "mediumtext")
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"hash_uid" => array("hash", "uid"),
					"seen_uid_date" => array("seen", "uid", "date"),
					"uid_date" => array("uid", "date"),
					"uid_type_link" => array("uid", "type", "link(190)"),
					)
			);
	$database["notify-threads"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"notify-id" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("notify" => "id")),
					"master-parent-item" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("item" => "id")),
					"parent-item" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"receiver-uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["oembed"] = array(
			"fields" => array(
					"url" => array("type" => "varbinary(255)", "not null" => "1", "primary" => "1"),
					"content" => array("type" => "mediumtext"),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("url"),
					"created" => array("created"),
					)
			);
	$database["parsed_url"] = array(
			"fields" => array(
					"url" => array("type" => "varbinary(255)", "not null" => "1", "primary" => "1"),
					"guessing" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0", "primary" => "1"),
					"oembed" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0", "primary" => "1"),
					"content" => array("type" => "mediumtext"),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("url", "guessing", "oembed"),
					"created" => array("created"),
					)
			);
	$database["pconfig"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"cat" => array("type" => "varbinary(255)", "not null" => "1", "default" => ""),
					"k" => array("type" => "varbinary(255)", "not null" => "1", "default" => ""),
					"v" => array("type" => "mediumtext"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid_cat_k" => array("UNIQUE", "uid", "cat", "k"),
					)
			);
	$database["photo"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"contact-id" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"guid" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"resource-id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"edited" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"title" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"desc" => array("type" => "text"),
					"album" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"filename" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"type" => array("type" => "varchar(128)", "not null" => "1", "default" => "image/jpeg"),
					"height" => array("type" => "smallint(6)", "not null" => "1", "default" => "0"),
					"width" => array("type" => "smallint(6)", "not null" => "1", "default" => "0"),
					"datasize" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"data" => array("type" => "mediumblob", "not null" => "1"),
					"scale" => array("type" => "tinyint(3)", "not null" => "1", "default" => "0"),
					"profile" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"allow_cid" => array("type" => "mediumtext"),
					"allow_gid" => array("type" => "mediumtext"),
					"deny_cid" => array("type" => "mediumtext"),
					"deny_gid" => array("type" => "mediumtext"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid_contactid" => array("uid", "contact-id"),
					"uid_profile" => array("uid", "profile"),
					"uid_album_scale_created" => array("uid", "album(32)", "scale", "created"),
					"uid_album_resource-id_created" => array("uid", "album(32)", "resource-id(64)", "created"),
					"resource-id" => array("resource-id(64)"),
					)
			);
	$database["poll"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"q0" => array("type" => "text"),
					"q1" => array("type" => "text"),
					"q2" => array("type" => "text"),
					"q3" => array("type" => "text"),
					"q4" => array("type" => "text"),
					"q5" => array("type" => "text"),
					"q6" => array("type" => "text"),
					"q7" => array("type" => "text"),
					"q8" => array("type" => "text"),
					"q9" => array("type" => "text"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					)
			);
	$database["poll_result"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"poll_id" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("poll" => "id")),
					"choice" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"poll_id" => array("poll_id"),
					"choice" => array("choice"),
					)
			);
	$database["process"] = array(
			"fields" => array(
					"pid" => array("type" => "int(10) unsigned", "not null" => "1", "primary" => "1"),
					"command" => array("type" => "varbinary(32)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("pid"),
					"command" => array("command"),
					)
			);
	$database["profile"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"profile-name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"is-default" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"hide-friends" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pdesc" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"dob" => array("type" => "varchar(32)", "not null" => "1", "default" => "0001-01-01"),
					"address" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"locality" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"region" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"postal-code" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"country-name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"hometown" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"gender" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"marital" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"with" => array("type" => "text"),
					"howlong" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"sexual" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"politic" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"religion" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pub_keywords" => array("type" => "text"),
					"prv_keywords" => array("type" => "text"),
					"likes" => array("type" => "text"),
					"dislikes" => array("type" => "text"),
					"about" => array("type" => "text"),
					"summary" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"music" => array("type" => "text"),
					"book" => array("type" => "text"),
					"tv" => array("type" => "text"),
					"film" => array("type" => "text"),
					"interest" => array("type" => "text"),
					"romance" => array("type" => "text"),
					"work" => array("type" => "text"),
					"education" => array("type" => "text"),
					"contact" => array("type" => "text"),
					"homepage" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"xmpp" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"photo" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"thumb" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"publish" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"net-publish" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid_is-default" => array("uid", "is-default"),
					)
			);
	$database["profile_check"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"cid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"dfrn_id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"sec" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"expire" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["push_subscriber"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"callback_url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"topic" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nickname" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"push" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"last_update" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"secret" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["queue"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"cid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"network" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"last" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"content" => array("type" => "mediumtext"),
					"batch" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"cid" => array("cid"),
					"created" => array("created"),
					"last" => array("last"),
					"network" => array("network"),
					"batch" => array("batch"),
					)
			);
	$database["register"] = array(
			"fields" => array(
					"id" => array("type" => "int(11) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"hash" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"uid" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"password" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"language" => array("type" => "varchar(16)", "not null" => "1", "default" => ""),
					"note" => array("type" => "text"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["search"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"term" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					)
			);
	$database["session"] = array(
			"fields" => array(
					"id" => array("type" => "bigint(20) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"sid" => array("type" => "varbinary(255)", "not null" => "1", "default" => ""),
					"data" => array("type" => "text"),
					"expire" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"sid" => array("sid(64)"),
					"expire" => array("expire"),
					)
			);
	$database["sign"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"iid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("item" => "id")),
					"signed_text" => array("type" => "mediumtext"),
					"signature" => array("type" => "text"),
					"signer" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"iid" => array("iid"),
					)
			);
	$database["spam"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"spam" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"ham" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"term" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"date" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					"spam" => array("spam"),
					"ham" => array("ham"),
					"term" => array("term"),
					)
			);
	$database["term"] = array(
			"fields" => array(
					"tid" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"oid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("item" => "id")),
					"otype" => array("type" => "tinyint(3) unsigned", "not null" => "1", "default" => "0"),
					"type" => array("type" => "tinyint(3) unsigned", "not null" => "1", "default" => "0"),
					"term" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"guid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"received" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"global" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"aid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					),
			"indexes" => array(
					"PRIMARY" => array("tid"),
					"oid_otype_type_term" => array("oid","otype","type","term(32)"),
					"uid_otype_type_term_global_created" => array("uid","otype","type","term(32)","global","created"),
					"uid_otype_type_url" => array("uid","otype","type","url(64)"),
					"guid" => array("guid(64)"),
					)
			);
	$database["thread"] = array(
			"fields" => array(
					"iid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "primary" => "1", "relation" => array("item" => "id")),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					"contact-id" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"gcontact-id" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0", "relation" => array("gcontact" => "id")),
					"owner-id" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"author-id" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0", "relation" => array("contact" => "id")),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"edited" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"commented" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"received" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"changed" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"wall" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"private" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"pubmail" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"moderated" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"visible" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"spam" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"starred" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"ignored" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"bookmark" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"unseen" => array("type" => "tinyint(1)", "not null" => "1", "default" => "1"),
					"deleted" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"origin" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"forum_mode" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"mention" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"network" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("iid"),
					"uid_network_commented" => array("uid","network","commented"),
					"uid_network_created" => array("uid","network","created"),
					"uid_contactid_commented" => array("uid","contact-id","commented"),
					"uid_contactid_created" => array("uid","contact-id","created"),
					"contactid" => array("contact-id"),
					"ownerid" => array("owner-id"),
					"authorid" => array("author-id"),
					"uid_created" => array("uid","created"),
					"uid_commented" => array("uid","commented"),
					"uid_wall_created" => array("uid","wall","created"),
					)
			);
	$database["tokens"] = array(
			"fields" => array(
					"id" => array("type" => "varchar(40)", "not null" => "1", "primary" => "1"),
					"secret" => array("type" => "text"),
					"client_id" => array("type" => "varchar(20)", "not null" => "1", "default" => "", "relation" => array("clients" => "client_id")),
					"expires" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"scope" => array("type" => "varchar(200)", "not null" => "1", "default" => ""),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0", "relation" => array("user" => "uid")),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["user"] = array(
			"fields" => array(
					"uid" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"guid" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"username" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"password" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nickname" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"email" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"openid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"timezone" => array("type" => "varchar(128)", "not null" => "1", "default" => ""),
					"language" => array("type" => "varchar(32)", "not null" => "1", "default" => "en"),
					"register_date" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"login_date" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"default-location" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"allow_location" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"theme" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pubkey" => array("type" => "text"),
					"prvkey" => array("type" => "text"),
					"spubkey" => array("type" => "text"),
					"sprvkey" => array("type" => "text"),
					"verified" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"blocked" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"blockwall" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"hidewall" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"blocktags" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"unkmail" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"cntunkmail" => array("type" => "int(11)", "not null" => "1", "default" => "10"),
					"notify-flags" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "65535"),
					"page-flags" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0"),
					"account-type" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0"),
					"prvnets" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"pwdreset" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"maxreq" => array("type" => "int(11)", "not null" => "1", "default" => "10"),
					"expire" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0"),
					"account_removed" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"account_expired" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"account_expires_on" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"expire_notification_sent" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"service_class" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"def_gid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"allow_cid" => array("type" => "mediumtext"),
					"allow_gid" => array("type" => "mediumtext"),
					"deny_cid" => array("type" => "mediumtext"),
					"deny_gid" => array("type" => "mediumtext"),
					"openidserver" => array("type" => "text"),
					),
			"indexes" => array(
					"PRIMARY" => array("uid"),
					"nickname" => array("nickname(32)"),
					)
			);
	$database["userd"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"username" => array("type" => "varchar(255)", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"username" => array("username(32)"),
					)
			);
	$database["workerqueue"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"parameter" => array("type" => "text"),
					"priority" => array("type" => "tinyint(3) unsigned", "not null" => "1", "default" => "0"),
					"created" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					"pid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"executed" => array("type" => "datetime", "not null" => "1", "default" => NULL_DATE),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);

	return($database);
}


/*
 * run from command line
 */
function dbstructure_run(&$argv, &$argc) {
	global $a, $db;

	if (is_null($a)) {
		$a = new App;
	}

	if (is_null($db)) {
		@include(".htconfig.php");
		require_once("include/dba.php");
		$db = new dba($db_host, $db_user, $db_pass, $db_data);
			unset($db_host, $db_user, $db_pass, $db_data);
	}

	if ($argc==2) {
		switch ($argv[1]) {
			case "dryrun":
				update_structure(true, false);
				return;
			case "update":
				update_structure(true, true);

				$build = get_config('system','build');
				if (!x($build)) {
					set_config('system','build',DB_UPDATE_VERSION);
					$build = DB_UPDATE_VERSION;
				}

				$stored = intval($build);
				$current = intval(DB_UPDATE_VERSION);

				// run any left update_nnnn functions in update.php
				for ($x = $stored; $x < $current; $x ++) {
					$r = run_update_function($x);
					if (!$r) break;
				}

				set_config('system','build',DB_UPDATE_VERSION);
				return;
			case "dumpsql":
				print_structure(db_definition());
				return;
			case "toinnodb":
				convert_to_innodb();
				return;
		}
	}


	// print help
	echo $argv[0]." <command>\n";
	echo "\n";
	echo "Commands:\n";
	echo "dryrun		show database update schema queries without running them\n";
	echo "update		update database schema\n";
	echo "dumpsql		dump database schema\n";
	echo "toinnodb	convert all tables from MyISAM to InnoDB\n";
	return;

}

if (array_search(__file__,get_included_files())===0) {
	dbstructure_run($_SERVER["argv"],$_SERVER["argc"]);
	killme();
}
