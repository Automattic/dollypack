<?php
/**
 * Plugin Name: Dollypack
 * Description: A pack of WordPress Abilities exposed via REST.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Artpi
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOLLYPACK_DIR', __DIR__ );

require_once DOLLYPACK_DIR . '/includes/class-settings.php';

/**
 * Available abilities: id => relative file path.
 */
function dollypack_get_available_abilities() {
	return array(
		'wp-remote-request' => array(
			'file'        => 'abilities/wp-remote-request.php',
			'description' => 'Perform an HTTP request using wp_remote_request().',
		),
	);
}

/**
 * Register the "dolly" ability category.
 */
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'dollypack', array(
		'label'       => 'Dollypack',
		'description' => 'Abilities provided by the Dollypack plugin.',
	) );
} );

/**
 * Load enabled abilities.
 */
add_action( 'wp_abilities_api_init', function () {
	$available = dollypack_get_available_abilities();
	$enabled   = get_option( 'dollypack_enabled_abilities', array_keys( $available ) );

	foreach ( $available as $id => $ability ) {
		if ( in_array( $id, $enabled, true ) ) {
			require_once DOLLYPACK_DIR . '/' . $ability['file'];
		}
	}
} );

new Dollypack_Settings();
