<?php
require_once("boot.php");
require_once("include/text.php");

define('NEW_UPDATE_ROUTINE_VERSION', 1170);

/*
 * send the email and do what is needed to do on update fails
 *
 * @param update_id		(int) number of failed update
 * @param error_message	(str) error message
 */
function update_fail($update_id, $error_message){
	//send the administrators an e-mail
	$admin_mail_list = "'".implode("','", array_map(dbesc, explode(",", str_replace(" ", "", $a->config['admin_email']))))."'";
	$adminlist = q("SELECT uid, language, email FROM user WHERE email IN (%s)",
		$admin_mail_list
	);

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
		'$siteurl' =>  $a->get_baseurl(),
		'$update' => DB_UPDATE_VERSION,
		'$error' => sprintf(t('Update %s failed. See error logs.'), DB_UPDATE_VERSION)
	));
	$subject=sprintf(t('Update Error at %s'), $a->get_baseurl());
	require_once('include/email.php');
	$subject = email_header_encode($subject,'UTF-8');
	mail($a->config['admin_email'], $subject, $email_msg,
		'From: ' . 'Administrator' . '@' . $_SERVER['SERVER_NAME']."\n"
		.'Content-type: text/plain; charset=UTF-8'."\n"
		.'Content-transfer-encoding: 8bit');
	*/
	//try the logger
	logger("CRITICAL: Database structure update failed: ".$retval);
	break;
}


function table_structure($table) {
	$structures = q("DESCRIBE `%s`", $table);

	$indexes = q("SHOW INDEX FROM `%s`", $table);

	$fielddata = array();
	$indexdata = array();

	if (is_array($indexes))
		foreach ($indexes AS $index) {
			if ($index["Index_type"] == "FULLTEXT")
				continue;

			$column = $index["Column_name"];
			if ($index["Sub_part"] != "")
				$column .= "(".$index["Sub_part"].")";

			$indexdata[$index["Key_name"]][] = $column;
		}

	if (is_array($structures)) {
		foreach($structures AS $field) {
			$fielddata[$field["Field"]]["type"] = $field["Type"];
			if ($field["Null"] == "NO")
				$fielddata[$field["Field"]]["not null"] = true;

			if (isset($field["Default"]))
				$fielddata[$field["Field"]]["default"] = $field["Default"];

			if ($field["Extra"] != "")
				$fielddata[$field["Field"]]["extra"] = $field["Extra"];

			if ($field["Key"] == "PRI")
				$fielddata[$field["Field"]]["primary"] = true;
		}
	}
	return(array("fields"=>$fielddata, "indexes"=>$indexdata));
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

function update_structure($verbose, $action, $tables=null, $definition=null) {
	global $a, $db;

	$errors = false;

	logger('updating structure', LOGGER_DEBUG);

	// Get the current structure
	$database = array();

	if (is_null($tables))
		$tables = q("show tables");

	foreach ($tables AS $table) {
		$table = current($table);

		$database[$table] = table_structure($table);
	}

	// Get the definition
	if (is_null($definition))
		$definition = db_definition();

	// Compare it
	foreach ($definition AS $name => $structure) {
		$sql3="";
		if (!isset($database[$name])) {
			$r = db_create_table($name, $structure["fields"], $verbose, $action);
                        if(false === $r)
				$errors .=  t('Errors encountered creating database tables.').$name.EOL;
		} else {
			// Drop the index if it isn't present in the definition
			foreach ($database[$name]["indexes"] AS $indexname => $fieldnames)
				if (!isset($structure["indexes"][$indexname])) {
					$sql2=db_drop_index($indexname);
					if ($sql3 == "")
						$sql3 = "ALTER TABLE `".$name."` ".$sql2;
					else
						$sql3 .= ", ".$sql2;
				}

			// Compare the field structure field by field
			foreach ($structure["fields"] AS $fieldname => $parameters) {
				if (!isset($database[$name]["fields"][$fieldname])) {
					$sql2=db_add_table_field($fieldname, $parameters);
					if ($sql3 == "")
						$sql3 = "ALTER TABLE `".$name."` ".$sql2;
					else
						$sql3 .= ", ".$sql2;
				} else {
					// Compare the field definition
					$current_field_definition = implode(",",$database[$name]["fields"][$fieldname]);
					$new_field_definition = implode(",",$parameters);
					if ($current_field_definition != $new_field_definition) {
						$sql2=db_modify_table_field($fieldname, $parameters);
						if ($sql3 == "")
							$sql3 = "ALTER TABLE `".$name."` ".$sql2;
						else
							$sql3 .= ", ".$sql2;
					}

				}
			}
		}

		// Create the index
		foreach ($structure["indexes"] AS $indexname => $fieldnames) {
			if (!isset($database[$name]["indexes"][$indexname])) {
				$sql2=db_create_index($indexname, $fieldnames);
				if ($sql2 != "") {
					if ($sql3 == "")
						$sql3 = "ALTER TABLE `".$name."` ".$sql2;
					else
						$sql3 .= ", ".$sql2;
				}
			}
		}

		if ($sql3 != "") {
			$sql3 .= ";";

			if ($verbose)
				echo $sql3."\n";

			if ($action) {
				$r = @$db->q($sql3);
				if(false === $r)
					$errors .= t('Errors encountered performing database changes.').$sql3.EOL;
			}
		}
	}

	return $errors;
}

function db_field_command($parameters, $create = true) {
	$fieldstruct = $parameters["type"];

	if ($parameters["not null"])
		$fieldstruct .= " NOT NULL";

	if (isset($parameters["default"])){
		if (strpos(strtolower($parameters["type"]),"int")!==false) {
			$fieldstruct .= " DEFAULT ".$parameters["default"];
		} else {
			$fieldstruct .= " DEFAULT '".$parameters["default"]."'";
		}
	}
	if ($parameters["extra"] != "")
		$fieldstruct .= " ".$parameters["extra"];

	if (($parameters["primary"] != "") AND $create)
		$fieldstruct .= " PRIMARY KEY";

	return($fieldstruct);
}

function db_create_table($name, $fields, $verbose, $action, $indexes=null) {
	global $a, $db;

	$r = true;

	$sql = "";
	$sql_rows = array();
	foreach($fields AS $fieldname => $field) {
		$sql_rows[] = "`".dbesc($fieldname)."` ".db_field_command($field);
	}

	if (!is_null($indexes)) {

		foreach ($indexes AS $indexname => $fieldnames) {
			$sql_index = db_create_index($indexname, $fieldnames, "");
			if (!is_null($sql_index)) $sql_rows[] = $sql_index;
		}
	}

	$sql = implode(",\n\t", $sql_rows);

	$sql = sprintf("CREATE TABLE IF NOT EXISTS `%s` (\n\t", dbesc($name)).$sql."\n) DEFAULT CHARSET=utf8";

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

	if ($indexname == "PRIMARY")
		return;

	$names = "";
	foreach ($fieldnames AS $fieldname) {
		if ($names != "")
			$names .= ",";

		if (preg_match('|(.+)\((\d+)\)|', $fieldname, $matches))
			$names .= "`".dbesc($matches[1])."`(".intval($matches[2]).")";
		else
			$names .= "`".dbesc($fieldname)."`";
	}

	$method = strtoupper(trim($method));
	if ($method!="" && $method!="ADD") {
		throw new Exception("Invalid parameter 'method' in db_create_index(): '$method'");
		killme();
	}

	$sql = sprintf("%s INDEX `%s` (%s)", $method, dbesc($indexname), $names);
	return($sql);
}

function db_definition() {

	$database = array();

	$database["addon"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"version" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"installed" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"hidden" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"timestamp" => array("type" => "bigint(20)", "not null" => "1", "default" => "0"),
					"plugin_admin" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["attach"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"hash" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"filename" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"filetype" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"filesize" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"data" => array("type" => "longblob", "not null" => "1"),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"edited" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"allow_cid" => array("type" => "mediumtext", "not null" => "1"),
					"allow_gid" => array("type" => "mediumtext", "not null" => "1"),
					"deny_cid" => array("type" => "mediumtext", "not null" => "1"),
					"deny_gid" => array("type" => "mediumtext", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["auth_codes"] = array(
			"fields" => array(
					"id" => array("type" => "varchar(40)", "not null" => "1", "primary" => "1"),
					"client_id" => array("type" => "varchar(20)", "not null" => "1", "default" => ""),
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
					"k" => array("type" => "varchar(255)", "not null" => "1", "primary" => "1"),
					"v" => array("type" => "text", "not null" => "1"),
					"updated" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					),
			"indexes" => array(
					"PRIMARY" => array("k"),
					"updated" => array("updated"),
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
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("client_id"),
					)
			);
	$database["config"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"cat" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"k" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"v" => array("type" => "text", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"cat_k" => array("cat(30)","k(30)"),
					)
			);
	$database["contact"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"self" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"remote_self" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"rel" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"duplex" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"network" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nick" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"location" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"about" => array("type" => "text", "not null" => "1"),
					"keywords" => array("type" => "text", "not null" => "1"),
					"gender" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"attag" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"photo" => array("type" => "text", "not null" => "1"),
					"thumb" => array("type" => "text", "not null" => "1"),
					"micro" => array("type" => "text", "not null" => "1"),
					"site-pubkey" => array("type" => "text", "not null" => "1"),
					"issued-id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"dfrn-id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nurl" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"addr" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"alias" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pubkey" => array("type" => "text", "not null" => "1"),
					"prvkey" => array("type" => "text", "not null" => "1"),
					"batch" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"request" => array("type" => "text", "not null" => "1"),
					"notify" => array("type" => "text", "not null" => "1"),
					"poll" => array("type" => "text", "not null" => "1"),
					"confirm" => array("type" => "text", "not null" => "1"),
					"poco" => array("type" => "text", "not null" => "1"),
					"aes_allow" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"ret-aes" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"usehub" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"subhub" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"hub-verify" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"last-update" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"success_update" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"name-date" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"uri-date" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"avatar-date" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"term-date" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"priority" => array("type" => "tinyint(3)", "not null" => "1", "default" => "0"),
					"blocked" => array("type" => "tinyint(1)", "not null" => "1", "default" => "1"),
					"readonly" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"writable" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"forum" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"prv" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"hidden" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"archive" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"pending" => array("type" => "tinyint(1)", "not null" => "1", "default" => "1"),
					"rating" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"reason" => array("type" => "text", "not null" => "1"),
					"closeness" => array("type" => "tinyint(2)", "not null" => "1", "default" => "99"),
					"info" => array("type" => "mediumtext", "not null" => "1"),
					"profile-id" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"bdyear" => array("type" => "varchar(4)", "not null" => "1", "default" => ""),
					"bd" => array("type" => "date", "not null" => "1", "default" => "0000-00-00"),
					"notify_new_posts" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"fetch_further_information" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"ffi_keyword_blacklist" => array("type" => "mediumtext", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					)
			);
	$database["conv"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"guid" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"recips" => array("type" => "mediumtext", "not null" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"creator" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"updated" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"subject" => array("type" => "mediumtext", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					)
			);
	$database["deliverq"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"cmd" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"item" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"contact" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["dsprphotoq"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"msg" => array("type" => "mediumtext", "not null" => "1"),
					"attempt" => array("type" => "tinyint(4)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["event"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"cid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"uri" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"edited" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"start" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"finish" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"summary" => array("type" => "text", "not null" => "1"),
					"desc" => array("type" => "text", "not null" => "1"),
					"location" => array("type" => "text", "not null" => "1"),
					"type" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nofinish" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"adjust" => array("type" => "tinyint(1)", "not null" => "1", "default" => "1"),
					"ignore" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"allow_cid" => array("type" => "mediumtext", "not null" => "1"),
					"allow_gid" => array("type" => "mediumtext", "not null" => "1"),
					"deny_cid" => array("type" => "mediumtext", "not null" => "1"),
					"deny_gid" => array("type" => "mediumtext", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					)
			);
	$database["fcontact"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
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
					"pubkey" => array("type" => "text", "not null" => "1"),
					"updated" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"addr" => array("addr"),
					)
			);
	$database["ffinder"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"cid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"fid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
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
					"key" => array("type" => "text", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"server" => array("server"),
					)
			);
	$database["fsuggest"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"cid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"request" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"photo" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"note" => array("type" => "text", "not null" => "1"),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["gcign"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"gcid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
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
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nurl" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"photo" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"connect" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"updated" => array("type" => "datetime", "default" => "0000-00-00 00:00:00"),
					"location" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"about" => array("type" => "text", "not null" => "1"),
					"keywords" => array("type" => "text", "not null" => "1"),
					"gender" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"network" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"generation" => array("type" => "tinyint(3)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"nurl" => array("nurl"),
					)
			);
	$database["glink"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"cid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"gcid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"zcid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"updated" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"cid_uid_gcid_zcid" => array("cid","uid","gcid","zcid"),
					"gcid" => array("gcid"),
					"zcid" => array("zcid"),
					)
			);
	$database["group"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
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
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"gid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"contact-id" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid_gid_contactid" => array("uid","gid","contact-id"),
					)
			);
	$database["guid"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"guid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"guid" => array("guid"),
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
					"hook_file_function" => array("hook(30)","file(60)","function(30)"),
					)
			);
	$database["intro"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"fid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"contact-id" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"knowyou" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"duplex" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"note" => array("type" => "text", "not null" => "1"),
					"hash" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"datetime" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"blocked" => array("type" => "tinyint(1)", "not null" => "1", "default" => "1"),
					"ignore" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["item"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"guid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"uri" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"contact-id" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"type" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"wall" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"gravity" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"parent" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"parent-uri" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"extid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"thr-parent" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"edited" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"commented" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"received" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"changed" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"owner-name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"owner-link" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"owner-avatar" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"author-name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"author-link" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"author-avatar" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"title" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"body" => array("type" => "mediumtext", "not null" => "1"),
					"app" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"verb" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"object-type" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"object" => array("type" => "text", "not null" => "1"),
					"target-type" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"target" => array("type" => "text", "not null" => "1"),
					"postopts" => array("type" => "text", "not null" => "1"),
					"plink" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"resource-id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"event-id" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"tag" => array("type" => "mediumtext", "not null" => "1"),
					"attach" => array("type" => "mediumtext", "not null" => "1"),
					"inform" => array("type" => "mediumtext", "not null" => "1"),
					"file" => array("type" => "mediumtext", "not null" => "1"),
					"location" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"coord" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"allow_cid" => array("type" => "mediumtext", "not null" => "1"),
					"allow_gid" => array("type" => "mediumtext", "not null" => "1"),
					"deny_cid" => array("type" => "mediumtext", "not null" => "1"),
					"deny_gid" => array("type" => "mediumtext", "not null" => "1"),
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
					"rendered-html" => array("type" => "mediumtext", "not null" => "1"),
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
					"uid_created" => array("uid","created"),
					"uid_unseen" => array("uid","unseen"),
					"uid_network_received" => array("uid","network","received"),
					"uid_received" => array("uid","received"),
					"uid_network_commented" => array("uid","network","commented"),
					"uid_commented" => array("uid","commented"),
					"uid_title" => array("uid","title"),
					"uid_thrparent" => array("uid","thr-parent"),
					"uid_parenturi" => array("uid","parent-uri"),
					"uid_contactid_created" => array("uid","contact-id","created"),
					"wall_body" => array("wall","body(6)"),
					"uid_visible_moderated_created" => array("uid","visible","moderated","created"),
					"uid_uri" => array("uid","uri"),
					"uid_wall_created" => array("uid","wall","created"),
					"resource-id" => array("resource-id"),
					"uid_type" => array("uid","type"),
					"uid_starred" => array("uid","starred"),
					"contactid_allowcid_allowpid_denycid_denygid" => array("contact-id","allow_cid(10)","allow_gid(10)","deny_cid(10)","deny_gid(10)"),
					"uid_wall_parent_created" => array("uid","wall","parent","created"),
					"uid_type_changed" => array("uid","type","changed"),
					"contactid_verb" => array("contact-id","verb"),
					"deleted_changed" => array("deleted","changed"),
					"uid_wall_changed" => array("uid","wall","changed"),
					"uid_eventid" => array("uid","event-id"),
					"uid_authorlink" => array("uid","author-link"),
					"uid_ownerlink" => array("uid","owner-link"),
					)
			);
	$database["item_id"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"iid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"sid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"service" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					"sid" => array("sid"),
					"service" => array("service"),
					"iid" => array("iid"),
					)
			);
	$database["locks"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"name" => array("type" => "varchar(128)", "not null" => "1", "default" => ""),
					"locked" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"created" => array("type" => "datetime", "default" => "0000-00-00 00:00:00"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["mail"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"guid" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"from-name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"from-photo" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"from-url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"contact-id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"convid" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0"),
					"title" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"body" => array("type" => "mediumtext", "not null" => "1"),
					"seen" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"reply" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"replied" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"unknown" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"uri" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"parent-uri" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					"guid" => array("guid"),
					"convid" => array("convid"),
					"reply" => array("reply"),
					"uri" => array("uri"),
					"parent-uri" => array("parent-uri"),
					)
			);
	$database["mailacct"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"server" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"port" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"ssltype" => array("type" => "varchar(16)", "not null" => "1", "default" => ""),
					"mailbox" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"user" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pass" => array("type" => "text", "not null" => "1"),
					"reply_to" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"action" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"movetofolder" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pubmail" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"last_check" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["manage"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"mid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid_mid" => array("uid","mid"),
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
					"date" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"msg" => array("type" => "mediumtext", "not null" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"link" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"parent" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"seen" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"verb" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"otype" => array("type" => "varchar(16)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					)
			);
	$database["notify-threads"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"notify-id" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"master-parent-item" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"parent-item" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"receiver-uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"master-parent-item" => array("master-parent-item"),
					"receiver-uid" => array("receiver-uid"),
					)
			);
	$database["pconfig"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"cat" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"k" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"v" => array("type" => "mediumtext", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid_cat_k" => array("uid","cat(30)","k(30)"),
					)
			);
	$database["photo"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"contact-id" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"guid" => array("type" => "varchar(64)", "not null" => "1", "default" => ""),
					"resource-id" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"edited" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"title" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"desc" => array("type" => "text", "not null" => "1"),
					"album" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"filename" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"type" => array("type" => "varchar(128)", "not null" => "1", "default" => "image/jpeg"),
					"height" => array("type" => "smallint(6)", "not null" => "1", "default" => "0"),
					"width" => array("type" => "smallint(6)", "not null" => "1", "default" => "0"),
					"datasize" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"data" => array("type" => "mediumblob", "not null" => "1"),
					"scale" => array("type" => "tinyint(3)", "not null" => "1", "default" => "0"),
					"profile" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"allow_cid" => array("type" => "mediumtext", "not null" => "1"),
					"allow_gid" => array("type" => "mediumtext", "not null" => "1"),
					"deny_cid" => array("type" => "mediumtext", "not null" => "1"),
					"deny_gid" => array("type" => "mediumtext", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					"resource-id" => array("resource-id"),
					"guid" => array("guid"),
					)
			);
	$database["poll"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"q0" => array("type" => "mediumtext", "not null" => "1"),
					"q1" => array("type" => "mediumtext", "not null" => "1"),
					"q2" => array("type" => "mediumtext", "not null" => "1"),
					"q3" => array("type" => "mediumtext", "not null" => "1"),
					"q4" => array("type" => "mediumtext", "not null" => "1"),
					"q5" => array("type" => "mediumtext", "not null" => "1"),
					"q6" => array("type" => "mediumtext", "not null" => "1"),
					"q7" => array("type" => "mediumtext", "not null" => "1"),
					"q8" => array("type" => "mediumtext", "not null" => "1"),
					"q9" => array("type" => "mediumtext", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					)
			);
	$database["poll_result"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"poll_id" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"choice" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"poll_id" => array("poll_id"),
					"choice" => array("choice"),
					)
			);
	$database["profile"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"profile-name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"is-default" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"hide-friends" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pdesc" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"dob" => array("type" => "varchar(32)", "not null" => "1", "default" => "0000-00-00"),
					"address" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"locality" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"region" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"postal-code" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"country-name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"hometown" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"gender" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"marital" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"with" => array("type" => "text", "not null" => "1"),
					"howlong" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"sexual" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"politic" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"religion" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pub_keywords" => array("type" => "text", "not null" => "1"),
					"prv_keywords" => array("type" => "text", "not null" => "1"),
					"likes" => array("type" => "text", "not null" => "1"),
					"dislikes" => array("type" => "text", "not null" => "1"),
					"about" => array("type" => "text", "not null" => "1"),
					"summary" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"music" => array("type" => "text", "not null" => "1"),
					"book" => array("type" => "text", "not null" => "1"),
					"tv" => array("type" => "text", "not null" => "1"),
					"film" => array("type" => "text", "not null" => "1"),
					"interest" => array("type" => "text", "not null" => "1"),
					"romance" => array("type" => "text", "not null" => "1"),
					"work" => array("type" => "text", "not null" => "1"),
					"education" => array("type" => "text", "not null" => "1"),
					"contact" => array("type" => "text", "not null" => "1"),
					"homepage" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"photo" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"thumb" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"publish" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"net-publish" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"hometown" => array("hometown"),
					)
			);
	$database["profile_check"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"cid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
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
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"callback_url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"topic" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nickname" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"push" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"last_update" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"secret" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["queue"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"cid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"network" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"last" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"content" => array("type" => "mediumtext", "not null" => "1"),
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
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"uid" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0"),
					"password" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"language" => array("type" => "varchar(16)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["search"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"term" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"uid" => array("uid"),
					"term" => array("term"),
					)
			);
	$database["session"] = array(
			"fields" => array(
					"id" => array("type" => "bigint(20) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"sid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"data" => array("type" => "text", "not null" => "1"),
					"expire" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"sid" => array("sid"),
					"expire" => array("expire"),
					)
			);
	$database["sign"] = array(
			"fields" => array(
					"id" => array("type" => "int(10) unsigned", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"iid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"retract_iid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"signed_text" => array("type" => "mediumtext", "not null" => "1"),
					"signature" => array("type" => "text", "not null" => "1"),
					"signer" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"iid" => array("iid"),
					"retract_iid" => array("retract_iid"),
					)
			);
	$database["spam"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"spam" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"ham" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"term" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"date" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
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
					"oid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"otype" => array("type" => "tinyint(3) unsigned", "not null" => "1", "default" => "0"),
					"type" => array("type" => "tinyint(3) unsigned", "not null" => "1", "default" => "0"),
					"term" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"guid" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"received" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"global" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"aid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("tid"),
					"oid_otype_type_term" => array("oid","otype","type","term"),
					"uid_term_tid" => array("uid","term","tid"),
					"type_term" => array("type","term"),
					"uid_otype_type_term_global_created" => array("uid","otype","type","term","global","created"),
					"otype_type_term_tid" => array("otype","type","term","tid"),
					"guid" => array("guid"),
					)
			);
	$database["thread"] = array(
			"fields" => array(
					"iid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0", "primary" => "1"),
					"uid" => array("type" => "int(10) unsigned", "not null" => "1", "default" => "0"),
					"contact-id" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0"),
					"created" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"edited" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"commented" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"received" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"changed" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
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
					"created" => array("created"),
					"commented" => array("commented"),
					"uid_network_commented" => array("uid","network","commented"),
					"uid_network_created" => array("uid","network","created"),
					"uid_contactid_commented" => array("uid","contact-id","commented"),
					"uid_contactid_created" => array("uid","contact-id","created"),
					"wall_private_received" => array("wall","private","received"),
					"uid_created" => array("uid","created"),
					"uid_commented" => array("uid","commented"),
					)
			);
	$database["tokens"] = array(
			"fields" => array(
					"id" => array("type" => "varchar(40)", "not null" => "1", "primary" => "1"),
					"secret" => array("type" => "text", "not null" => "1"),
					"client_id" => array("type" => "varchar(20)", "not null" => "1", "default" => ""),
					"expires" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"scope" => array("type" => "varchar(200)", "not null" => "1", "default" => ""),
					"uid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					)
			);
	$database["unique_contacts"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"url" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"nick" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"name" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"avatar" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"location" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"about" => array("type" => "text", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"url" => array("url"),
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
					"register_date" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"login_date" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"default-location" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"allow_location" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"theme" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"pubkey" => array("type" => "text", "not null" => "1"),
					"prvkey" => array("type" => "text", "not null" => "1"),
					"spubkey" => array("type" => "text", "not null" => "1"),
					"sprvkey" => array("type" => "text", "not null" => "1"),
					"verified" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"blocked" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"blockwall" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"hidewall" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"blocktags" => array("type" => "tinyint(1) unsigned", "not null" => "1", "default" => "0"),
					"unkmail" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"cntunkmail" => array("type" => "int(11)", "not null" => "1", "default" => "10"),
					"notify-flags" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "65535"),
					"page-flags" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0"),
					"prvnets" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"pwdreset" => array("type" => "varchar(255)", "not null" => "1", "default" => ""),
					"maxreq" => array("type" => "int(11)", "not null" => "1", "default" => "10"),
					"expire" => array("type" => "int(11) unsigned", "not null" => "1", "default" => "0"),
					"account_removed" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"account_expired" => array("type" => "tinyint(1)", "not null" => "1", "default" => "0"),
					"account_expires_on" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"expire_notification_sent" => array("type" => "datetime", "not null" => "1", "default" => "0000-00-00 00:00:00"),
					"service_class" => array("type" => "varchar(32)", "not null" => "1", "default" => ""),
					"def_gid" => array("type" => "int(11)", "not null" => "1", "default" => "0"),
					"allow_cid" => array("type" => "mediumtext", "not null" => "1"),
					"allow_gid" => array("type" => "mediumtext", "not null" => "1"),
					"deny_cid" => array("type" => "mediumtext", "not null" => "1"),
					"deny_gid" => array("type" => "mediumtext", "not null" => "1"),
					"openidserver" => array("type" => "text", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("uid"),
					"nickname" => array("nickname"),
					)
			);
	$database["userd"] = array(
			"fields" => array(
					"id" => array("type" => "int(11)", "not null" => "1", "extra" => "auto_increment", "primary" => "1"),
					"username" => array("type" => "varchar(255)", "not null" => "1"),
					),
			"indexes" => array(
					"PRIMARY" => array("id"),
					"username" => array("username"),
					)
			);

	return($database);
}


/*
 * run from command line
 */
function dbstructure_run(&$argv, &$argc) {
	global $a, $db;

	if(is_null($a)){
		$a = new App;
	}

	if(is_null($db)) {
		@include(".htconfig.php");
		require_once("include/dba.php");
		$db = new dba($db_host, $db_user, $db_pass, $db_data);
			unset($db_host, $db_user, $db_pass, $db_data);
	}

	if ($argc==2) {
		switch ($argv[1]) {
			case "update":
				update_structure(true, true);
				return;
			case "dumpsql":
				print_structure(db_definition());
				return;
		}
	}


	// print help
	echo $argv[0]." <command>\n";
	echo "\n";
	echo "commands:\n";
	echo "update		update database schema\n";
	echo "dumpsql		dump database schema\n";
	return;




}

if (array_search(__file__,get_included_files())===0){
	dbstructure_run($_SERVER["argv"],$_SERVER["argc"]);
	killme();
}
