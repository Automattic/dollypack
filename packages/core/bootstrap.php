<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $dollypack_plugin_dir ) ) {
	$dollypack_plugin_dir = plugin_dir_path( __FILE__ );
}

require_once $dollypack_plugin_dir . 'includes/class-dollypack-crypto.php';
require_once $dollypack_plugin_dir . 'includes/class-dollypack-ability.php';
require_once $dollypack_plugin_dir . 'includes/class-dollypack-runtime.php';
require_once $dollypack_plugin_dir . 'includes/class-settings.php';

Dollypack_Runtime::boot();
Dollypack_Runtime::boot_settings();

Dollypack_Runtime::register_ability(
	'wp-remote-request',
	array(
		'file'  => $dollypack_plugin_dir . 'abilities/wp-remote-request.php',
		'class' => 'Dollypack_WP_Remote_Request',
	)
);
