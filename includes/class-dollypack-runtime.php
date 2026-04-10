<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Dollypack_Runtime' ) ) {
	class Dollypack_Runtime {

		/**
		 * Registered ability definitions keyed by ability ID.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private static $abilities = array();

		/**
		 * Instantiated ability objects keyed by ability ID.
		 *
		 * @var array<string, Dollypack_Ability>|null
		 */
		private static $instances = null;

		/**
		 * Track whether the runtime hooks have been added.
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Track whether the settings page has been booted.
		 *
		 * @var bool
		 */
		private static $settings_booted = false;

		/**
		 * Register an ability definition with the runtime.
		 *
		 * @param string $id         Ability ID.
		 * @param array  $definition Ability definition.
		 */
		public static function register_ability( $id, $definition ) {
			if ( empty( $id ) || empty( $definition['class'] ) ) {
				return;
			}

			self::$abilities[ $id ] = array(
				'file'               => $definition['file'] ?? '',
				'class'              => $definition['class'],
				'always_enabled'     => ! empty( $definition['always_enabled'] ),
				'hidden_in_settings' => ! empty( $definition['hidden_in_settings'] ),
			);

			self::$instances = null;
		}

		/**
		 * Return all registered ability definitions.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		public static function get_available_abilities() {
			return self::$abilities;
		}

		/**
		 * Instantiate all registered abilities.
		 *
		 * @return array<string, Dollypack_Ability>
		 */
		public static function get_ability_instances() {
			if ( null !== self::$instances ) {
				return self::$instances;
			}

			self::$instances = array();

			foreach ( self::$abilities as $id => $ability ) {
				$file  = $ability['file'] ?? '';
				$class = $ability['class'] ?? '';

				if ( $file && file_exists( $file ) ) {
					require_once $file;
				}

				if ( ! $class || ! class_exists( $class ) ) {
					continue;
				}

				self::$instances[ $id ] = new $class();
			}

			return self::$instances;
		}

		/**
		 * Return only ability instances that should appear on the settings screen.
		 *
		 * @return array<string, Dollypack_Ability>
		 */
		public static function get_settings_ability_instances() {
			$instances = self::get_ability_instances();

			foreach ( $instances as $id => $ability ) {
				if ( ! empty( self::$abilities[ $id ]['hidden_in_settings'] ) ) {
					unset( $instances[ $id ] );
				}
			}

			return $instances;
		}

		/**
		 * Boot the shared runtime hooks once.
		 *
		 * @return void
		 */
		public static function boot() {
			if ( self::$booted ) {
				return;
			}

			self::$booted = true;

			add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_enabled_abilities' ) );
		}

		/**
		 * Boot the shared settings page once.
		 *
		 * @return void
		 */
		public static function boot_settings() {
			if ( self::$settings_booted || ! class_exists( 'Dollypack_Settings' ) ) {
				return;
			}

			self::$settings_booted = true;

			new Dollypack_Settings();
		}

		/**
		 * Register the common Dollypack category.
		 *
		 * @return void
		 */
		public static function register_category() {
			wp_register_ability_category( 'dollypack', array(
				'label'       => 'Dollypack',
				'description' => 'Abilities provided by Dollypack plugins.',
			) );
		}

		/**
		 * Register enabled abilities with WordPress.
		 *
		 * @return void
		 */
		public static function register_enabled_abilities() {
			$instances = self::get_ability_instances();
			$enabled   = get_option( 'dollypack_enabled_abilities', array_keys( $instances ) );

			foreach ( $instances as $id => $ability ) {
				$is_enabled = ! empty( self::$abilities[ $id ]['always_enabled'] ) || in_array( $id, $enabled, true );

				if ( $is_enabled && $ability->has_required_settings() ) {
					$ability->register();
				}
			}
		}
	}
}
