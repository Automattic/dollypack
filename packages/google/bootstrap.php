<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $dollypack_plugin_dir ) ) {
	$dollypack_plugin_dir = plugin_dir_path( __FILE__ );
}

add_action(
	'plugins_loaded',
	static function () use ( $dollypack_plugin_dir ) {
		if ( ! Dollypack_Package_Helper::ensure_core_runtime( 'Dollypack Google' ) ) {
			return;
		}

		if ( ! class_exists( 'Dollypack_Google_Ability', false ) ) {
			require_once $dollypack_plugin_dir . 'includes/class-dollypack-google-ability.php';
		}

		Dollypack_Google_Ability::ensure_hooks_registered();

		Dollypack_Runtime::register_ability(
			'google-calendar-read',
			array(
				'file'  => $dollypack_plugin_dir . 'abilities/google-calendar-read.php',
				'class' => 'Dollypack_Google_Calendar_Read',
			)
		);
	},
	20
);
