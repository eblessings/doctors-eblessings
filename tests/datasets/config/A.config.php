<?php

/**
 * A test file for local configuration
 *
 */

return [
	'database' => [
		'hostname' => 'testhost',
		'username' => 'testuser',
		'password' => 'testpw',
		'database' => 'testdb',
		'charset' => 'utf8mb4',
		'pdo_emulate_prepares' => true,
	],

	'config' => [
		'admin_email' => 'admin@test.it',
		'sitename' => 'Friendica Social Network',
		'register_policy' => \Friendica\Module\Register::OPEN,
		'register_text' => '',
	],
	'system' => [
		'default_timezone' => 'UTC',
		'language' => 'en',
		'theme' => 'frio',
	],
];
