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
		if ( ! Dollypack_Package_Helper::ensure_core_runtime( 'Dollypack GitHub' ) ) {
			return;
		}

		if ( ! class_exists( 'Dollypack_GitHub_Ability', false ) ) {
			require_once $dollypack_plugin_dir . 'includes/class-dollypack-github-ability.php';
		}

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
	},
	20
);
