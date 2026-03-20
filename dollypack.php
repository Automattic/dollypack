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

require_once DOLLYPACK_DIR . '/includes/class-dollypack-ability.php';
require_once DOLLYPACK_DIR . '/includes/class-dollypack-github-ability.php';
require_once DOLLYPACK_DIR . '/includes/class-settings.php';

/**
 * Available abilities: id => [ 'file' => ..., 'class' => ... ].
 */
function dollypack_get_available_abilities() {
	return array(
		'wp-remote-request'    => array(
			'file'  => 'abilities/wp-remote-request.php',
			'class' => 'Dollypack_WP_Remote_Request',
		),
		'github-read'          => array(
			'file'  => 'abilities/github-read.php',
			'class' => 'Dollypack_GitHub_Read',
		),
		'github-notifications' => array(
			'file'  => 'abilities/github-notifications.php',
			'class' => 'Dollypack_GitHub_Notifications',
		),
		'github-search'        => array(
			'file'  => 'abilities/github-search.php',
			'class' => 'Dollypack_GitHub_Search',
		),
		'github-write'         => array(
			'file'  => 'abilities/github-write.php',
			'class' => 'Dollypack_GitHub_Write',
		),
	);
}

/**
 * Build ability instances for all available abilities.
 * Keyed by ability id.
 */
function dollypack_get_ability_instances() {
	static $instances = null;

	if ( null !== $instances ) {
		return $instances;
	}

	$instances = array();
	$available = dollypack_get_available_abilities();

	foreach ( $available as $id => $ability ) {
		require_once DOLLYPACK_DIR . '/' . $ability['file'];
		$instances[ $id ] = new $ability['class']();
	}

	return $instances;
}

/**
 * Register the "dollypack" ability category.
 */
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'dollypack', array(
		'label'       => 'Dollypack',
		'description' => 'Abilities provided by the Dollypack plugin.',
	) );
} );

/**
 * Load and register enabled abilities.
 */
add_action( 'wp_abilities_api_init', function () {
	$instances = dollypack_get_ability_instances();
	$enabled   = get_option( 'dollypack_enabled_abilities', array_keys( $instances ) );

	foreach ( $instances as $id => $ability ) {
		if ( in_array( $id, $enabled, true ) && $ability->has_required_settings() ) {
			$ability->register();
		}
	}
} );

new Dollypack_Settings();
