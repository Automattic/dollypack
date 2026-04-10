<?php
/**
 * Plugin Name: Dollypack Full
 * Description: Full Dollypack bundle with core, GitHub, and Google abilities.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Automattic
 * Author URI: https://automattic.com/ai
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dollypack_plugin_dir = plugin_dir_path( __FILE__ );
$dollypack_plugin_file = __FILE__;

require_once $dollypack_plugin_dir . 'includes/class-dollypack-package-helper.php';

if ( Dollypack_Package_Helper::abort_if_conflicting_plugins_active(
	'Dollypack Full',
	plugin_basename( __FILE__ ),
	array(
		'dollypack/dollypack.php',
		'dollypack-core/dollypack-core.php',
		'dollypack-github/dollypack-github.php',
		'dollypack-google/dollypack-google.php',
	)
) ) {
	return;
}

require_once __DIR__ . '/bootstrap.php';
