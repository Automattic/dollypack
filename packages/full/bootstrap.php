<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $dollypack_plugin_dir ) ) {
	$dollypack_plugin_dir = plugin_dir_path( __FILE__ );
}

require_once $dollypack_plugin_dir . 'includes/class-dollypack-crypto.php';
require_once $dollypack_plugin_dir . 'includes/class-dollypack-ability.php';
require_once $dollypack_plugin_dir . 'includes/class-dollypack-custom-ability.php';
require_once $dollypack_plugin_dir . 'includes/class-dollypack-custom-ability-manager.php';
require_once $dollypack_plugin_dir . 'includes/class-dollypack-runtime.php';
require_once $dollypack_plugin_dir . 'includes/class-settings.php';

if ( ! class_exists( 'Dollypack_GitHub_Ability', false ) ) {
	require_once $dollypack_plugin_dir . 'includes/class-dollypack-github-ability.php';
}

if ( ! class_exists( 'Dollypack_Google_Ability', false ) ) {
	require_once $dollypack_plugin_dir . 'includes/class-dollypack-google-ability.php';
}

Dollypack_Google_Ability::ensure_hooks_registered();

Dollypack_Runtime::boot();
Dollypack_Custom_Ability_Manager::boot( $dollypack_plugin_dir, $dollypack_plugin_file ?? '' );
Dollypack_Runtime::boot_settings();

Dollypack_Runtime::register_ability(
	'wp-remote-request',
	array(
		'file'  => $dollypack_plugin_dir . 'abilities/wp-remote-request.php',
		'class' => 'Dollypack_WP_Remote_Request',
	)
);

Dollypack_Runtime::register_ability(
	'edit-custom-ability',
	array(
		'file'  => $dollypack_plugin_dir . 'abilities/edit-custom-ability.php',
		'class' => 'Dollypack_Edit_Custom_Ability',
	)
);

Dollypack_Runtime::register_ability(
	'github-read',
	array(
		'file'  => $dollypack_plugin_dir . 'abilities/github-read.php',
		'class' => 'Dollypack_GitHub_Read',
	)
);

Dollypack_Runtime::register_ability(
	'github-notifications',
	array(
		'file'  => $dollypack_plugin_dir . 'abilities/github-notifications.php',
		'class' => 'Dollypack_GitHub_Notifications',
	)
);

Dollypack_Runtime::register_ability(
	'github-search',
	array(
		'file'  => $dollypack_plugin_dir . 'abilities/github-search.php',
		'class' => 'Dollypack_GitHub_Search',
	)
);

Dollypack_Runtime::register_ability(
	'github-write',
	array(
		'file'  => $dollypack_plugin_dir . 'abilities/github-write.php',
		'class' => 'Dollypack_GitHub_Write',
	)
);

Dollypack_Runtime::register_ability(
	'google-calendar-read',
	array(
		'file'  => $dollypack_plugin_dir . 'abilities/google-calendar-read.php',
		'class' => 'Dollypack_Google_Calendar_Read',
	)
);
