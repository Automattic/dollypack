<?php
/**
 * Plugin Name: Dollypack Google
 * Description: Google abilities for Dollypack.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: dollypack-core
 * Author: Automattic
 * Author URI: https://automattic.com/ai
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dollypack_plugin_dir = plugin_dir_path( __FILE__ );

require_once $dollypack_plugin_dir . 'includes/class-dollypack-package-helper.php';

if ( Dollypack_Package_Helper::abort_if_conflicting_plugins_active(
	'Dollypack Google',
	plugin_basename( __FILE__ ),
	array(
		'dollypack/dollypack.php',
		'dollypack-full/dollypack-full.php',
	)
) ) {
	return;
}

require_once __DIR__ . '/bootstrap.php';
