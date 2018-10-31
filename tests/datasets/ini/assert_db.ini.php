<?php return <<<INI

; If you're unsure about what any of the config keys below do, please check the config/defaults.ini.php for detailed
; documentation of their data type and behavior.

[database]
hostname = "localhost:3306"
username = "friendica"
password = "friendica"
database = "friendica"
charset = utf8mb4

; ****************************************************************
; Some config values below can be overruled from the admin settings
; ****************************************************************

[config]
php_path = "/usr/bin/php"

admin_email = "admin@friendica.local"

sitename = Friendica Social Network

register_policy = REGISTER_OPEN
register_text =

max_import_size = 200000

[system]
urlpath = "/friendica"

default_timezone = "Europe/Berlin"

language = "de"

allowed_themes = vier,quattro,duepuntozero,smoothly,frio
theme = vier

allowed_link_protocols[0] = ftp
allowed_link_protocols[1] = ftps
allowed_link_protocols[2] = mailto
allowed_link_protocols[3] = cid
allowed_link_protocols[4] = gopher

maximagesize = 800000

no_regfullname = true

block_local_dir = false

directory = https://dir.friendica.social

auth_cookie_lifetime = 7

INI;
// Keep this line