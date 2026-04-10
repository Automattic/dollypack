<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Dollypack_Custom_Ability_Manager' ) ) {
	class Dollypack_Custom_Ability_Manager {

		const OPTION_KEY = 'dollypack_custom_abilities';
		const DIRECTORY = 'custom-abilities';
		const LOOPBACK_ACTION = 'dollypack_test_custom_ability';
		const LOOPBACK_TRANSIENT_PREFIX = 'dollypack_custom_ability_test_';

		/**
		 * Absolute plugin directory path.
		 *
		 * @var string
		 */
		private static $plugin_dir = '';

		/**
		 * Main plugin file path.
		 *
		 * @var string
		 */
		private static $plugin_file = '';

		/**
		 * Track whether the manager has booted.
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Track whether a loopback response has already been sent.
		 *
		 * @var bool
		 */
		private static $loopback_response_sent = false;

		/**
		 * Boot the custom ability manager once.
		 *
		 * @param string $plugin_dir  Plugin directory path.
		 * @param string $plugin_file Main plugin file path.
		 * @return void
		 */
		public static function boot( $plugin_dir, $plugin_file ) {
			self::$plugin_dir  = trailingslashit( $plugin_dir );
			self::$plugin_file = $plugin_file;

			if ( self::$booted ) {
				return;
			}

			self::$booted = true;

			add_action( 'admin_post_' . self::LOOPBACK_ACTION, array( __CLASS__, 'handle_loopback_test' ) );
			add_action( 'admin_post_nopriv_' . self::LOOPBACK_ACTION, array( __CLASS__, 'handle_loopback_test' ) );

			self::compact_registry();
			self::cleanup_enabled_option( array_keys( self::get_registry() ) );
			self::register_active_abilities();
		}

		/**
		 * Return one custom ability or all generated custom abilities.
		 *
		 * @param string $name Optional ability name.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function read_custom_ability( $name = '' ) {
			$registry = self::get_registry();

			if ( '' === trim( (string) $name ) ) {
				$abilities = array();

				foreach ( array_keys( $registry ) as $slug ) {
					$abilities[] = self::prepare_response_entry( $slug, false );
				}

				usort(
					$abilities,
					static function( $left, $right ) {
						return strcmp( $left['slug'], $right['slug'] );
					}
				);

				return array(
					'abilities' => $abilities,
				);
			}

			$slug = self::normalize_slug( $name );

			if ( '' === $slug ) {
				return new WP_Error( 'invalid_custom_ability_name', 'The name parameter is required.' );
			}

			if ( ! isset( $registry[ $slug ] ) ) {
				return new WP_Error( 'custom_ability_not_found', 'No custom ability exists with that name.' );
			}

			return array(
				'ability' => self::prepare_response_entry( $slug, true ),
			);
		}

		/**
		 * Save or replace a generated custom ability draft.
		 *
		 * @param string $name    Ability name.
		 * @param array  $payload Ability payload.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function write_custom_ability( $name, $payload ) {
			$slug = self::normalize_slug( $name );

			if ( '' === $slug ) {
				return new WP_Error( 'invalid_custom_ability_name', 'The name parameter is required.' );
			}

			$payload = self::sanitize_payload( $payload, $slug );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$directory_ready = self::ensure_custom_directory();
			if ( is_wp_error( $directory_ready ) ) {
				return $directory_ready;
			}

			$generated_source = self::generate_ability_source( $slug, $payload );
			$file             = self::get_file_path( $slug );

			if ( false === file_put_contents( $file, $generated_source ) ) {
				return new WP_Error( 'custom_ability_write_failed', 'Unable to write the generated custom ability file.' );
			}

			$registry          = self::get_registry();
			$registry[ $slug ] = array(
				'payload'           => $payload,
				'state'             => 'draft',
				'last_test'         => self::get_default_last_test(),
				'quarantine_reason' => '',
			);

			self::save_registry( $registry );
			self::remove_from_enabled_option( self::get_ability_id( $slug ) );

			return array(
				'status'  => 'draft_saved',
				'ability' => self::prepare_response_entry( $slug, true ),
			);
		}

		/**
		 * Run the generated custom ability in an isolated loopback request.
		 *
		 * @param string $name       Ability name.
		 * @param array  $test_input Optional execute() input.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function test_custom_ability( $name, $test_input = array() ) {
			$slug = self::normalize_slug( $name );

			if ( '' === $slug ) {
				return new WP_Error( 'invalid_custom_ability_name', 'The name parameter is required.' );
			}

			$entry = self::get_registry_entry( $slug );
			if ( is_wp_error( $entry ) ) {
				return $entry;
			}

			$file = self::ensure_generated_file( $slug, $entry );
			if ( is_wp_error( $file ) ) {
				return $file;
			}

			$test_input = self::normalize_json_value( $test_input, 'test_input' );
			if ( is_wp_error( $test_input ) ) {
				return $test_input;
			}

			if ( ! is_array( $test_input ) ) {
				return new WP_Error( 'invalid_test_input', 'The test_input parameter must be an object.' );
			}

			$token = wp_generate_password( 32, false, false );

			set_transient(
				self::LOOPBACK_TRANSIENT_PREFIX . $token,
				array(
					'slug'       => $slug,
					'test_input' => $test_input,
				),
				5 * MINUTE_IN_SECONDS
			);

			$response = wp_remote_post(
				add_query_arg(
					array(
						'action' => self::LOOPBACK_ACTION,
						'token'  => $token,
					),
					admin_url( 'admin-post.php' )
				),
				array(
					'timeout' => 20,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			$current_hash = self::get_file_hash( $file );
			$last_test    = self::get_default_last_test();
			$last_test['hash']      = $current_hash;
			$last_test['tested_at'] = current_time( 'mysql', true );
			$last_test['transport'] = 'loopback';

			if ( is_wp_error( $response ) ) {
				$last_test['status']  = 'failed';
				$last_test['summary'] = $response->get_error_message();
				$last_test['details'] = self::normalize_for_response( $response );
				self::update_last_test( $slug, $last_test );

				return $response;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				$last_test['status']  = 'failed';
				$last_test['summary'] = 'The loopback test did not return valid JSON.';
				$last_test['details'] = array(
					'status_code' => wp_remote_retrieve_response_code( $response ),
					'body'        => self::truncate_string( $body ),
				);
				self::update_last_test( $slug, $last_test );

				return new WP_Error( 'invalid_loopback_response', 'The loopback test did not return valid JSON.' );
			}

			$details = self::normalize_json_value( $data['details'] ?? array(), 'test_details' );
			if ( is_wp_error( $details ) ) {
				$details = array();
			}

			$last_test['status']  = ! empty( $data['passed'] ) ? 'passed' : 'failed';
			$last_test['summary'] = sanitize_text_field( $data['summary'] ?? '' );
			$last_test['details'] = $details;

			self::update_last_test( $slug, $last_test );

			return array(
				'status'  => 'test_completed',
				'ability' => self::prepare_response_entry( $slug, true ),
				'test'    => $last_test,
			);
		}

		/**
		 * Mark a tested custom ability active so it can be registered on the next request.
		 *
		 * @param string $name Ability name.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function turn_on_custom_ability( $name ) {
			$slug = self::normalize_slug( $name );

			if ( '' === $slug ) {
				return new WP_Error( 'invalid_custom_ability_name', 'The name parameter is required.' );
			}

			$entry = self::get_registry_entry( $slug );
			if ( is_wp_error( $entry ) ) {
				return $entry;
			}

			$file = self::ensure_generated_file( $slug, $entry );
			if ( is_wp_error( $file ) ) {
				return $file;
			}

			$current_hash = self::get_file_hash( $file );
			$last_test    = self::normalize_last_test( $entry['last_test'] ?? array() );

			if ( '' === $current_hash ) {
				return new WP_Error( 'custom_ability_missing_file', 'The generated custom ability file could not be found.' );
			}

			if ( 'passed' !== $last_test['status'] || $current_hash !== $last_test['hash'] ) {
				return new WP_Error( 'custom_ability_requires_test', 'Run test first, then turn the custom ability on without changing the file.' );
			}

			$registry = self::get_registry();

			$registry[ $slug ]['state']             = 'active';
			$registry[ $slug ]['quarantine_reason'] = '';

			self::save_registry( $registry );
			self::remove_from_enabled_option( self::get_ability_id( $slug ) );

			Dollypack_Runtime::register_ability(
				self::get_ability_id( $slug ),
				array(
					'file'               => $file,
					'class'              => self::get_class_name( $slug ),
					'always_enabled'     => true,
					'hidden_in_settings' => true,
				)
			);

			return array(
				'status'                    => 'activated',
				'available_on_next_request' => true,
				'ability'                   => self::prepare_response_entry( $slug, true ),
			);
		}

		/**
		 * Disable a generated custom ability.
		 *
		 * @param string $name Ability name.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function turn_off_custom_ability( $name ) {
			$slug = self::normalize_slug( $name );

			if ( '' === $slug ) {
				return new WP_Error( 'invalid_custom_ability_name', 'The name parameter is required.' );
			}

			$entry = self::get_registry_entry( $slug );
			if ( is_wp_error( $entry ) ) {
				return $entry;
			}

			$registry = self::get_registry();

			$registry[ $slug ]['state']             = 'disabled';
			$registry[ $slug ]['quarantine_reason'] = '';

			self::save_registry( $registry );
			self::remove_from_enabled_option( self::get_ability_id( $slug ) );

			return array(
				'status'  => 'disabled',
				'ability' => self::prepare_response_entry( $slug, true ),
			);
		}

		/**
		 * Handle the loopback test request.
		 *
		 * @return void
		 */
		public static function handle_loopback_test() {
			$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';

			if ( '' === $token ) {
				self::send_loopback_response(
					array(
						'passed'  => false,
						'summary' => 'Missing test token.',
						'details' => array(),
					),
					400
				);
			}

			$request = get_transient( self::LOOPBACK_TRANSIENT_PREFIX . $token );
			delete_transient( self::LOOPBACK_TRANSIENT_PREFIX . $token );

			if ( ! is_array( $request ) || empty( $request['slug'] ) ) {
				self::send_loopback_response(
					array(
						'passed'  => false,
						'summary' => 'The loopback test token is invalid or expired.',
						'details' => array(),
					),
					403
				);
			}

			ob_start();
			self::$loopback_response_sent = false;

			register_shutdown_function( array( __CLASS__, 'handle_loopback_shutdown' ) );

			try {
				$slug = self::normalize_slug( $request['slug'] );

				if ( '' === $slug ) {
					throw new RuntimeException( 'The loopback test payload is missing a valid ability name.' );
				}

				$file = self::get_file_path( $slug );

				if ( ! file_exists( $file ) ) {
					throw new RuntimeException( 'The generated custom ability file could not be found.' );
				}

				require_once $file;

				$class = self::get_class_name( $slug );

				if ( ! class_exists( $class ) ) {
					throw new RuntimeException( 'The generated custom ability class could not be loaded.' );
				}

				$ability = new $class();

				if ( ! $ability instanceof Dollypack_Custom_Ability ) {
					throw new RuntimeException( 'The generated custom ability must extend Dollypack_Custom_Ability.' );
				}

				$test_input = isset( $request['test_input'] ) && is_array( $request['test_input'] ) ? $request['test_input'] : array();
				$result     = $ability->execute( $test_input );

				self::send_loopback_response(
					array(
						'passed'  => true,
						'summary' => 'The generated custom ability executed without a fatal error.',
						'details' => array(
							'result' => self::normalize_for_response( $result ),
						),
					)
				);
			} catch ( Throwable $error ) {
				self::send_loopback_response(
					array(
						'passed'  => false,
						'summary' => $error->getMessage(),
						'details' => array(
							'error' => array(
								'type'    => get_class( $error ),
								'message' => $error->getMessage(),
								'file'    => $error->getFile(),
								'line'    => $error->getLine(),
							),
						),
					),
					500
				);
			}
		}

		/**
		 * Catch fatals or unexpected exits from the loopback test request.
		 *
		 * @return void
		 */
		public static function handle_loopback_shutdown() {
			if ( self::$loopback_response_sent ) {
				return;
			}

			$error   = error_get_last();
			$summary = 'The loopback test terminated unexpectedly.';
			$details = array();

			if ( is_array( $error ) && self::is_fatal_error( $error['type'] ?? 0 ) ) {
				$summary = $error['message'] ?? $summary;
				$details = array(
					'error' => array(
						'type'    => (int) ( $error['type'] ?? 0 ),
						'message' => $error['message'] ?? '',
						'file'    => $error['file'] ?? '',
						'line'    => (int) ( $error['line'] ?? 0 ),
					),
				);
			}

			self::send_loopback_response(
				array(
					'passed'  => false,
					'summary' => $summary,
					'details' => $details,
				),
				500
			);
		}

		/**
		 * Register active custom abilities whose tested hash still matches the file on disk.
		 *
		 * @return void
		 */
		private static function register_active_abilities() {
			$registry = self::get_registry();
			$changed  = false;

			foreach ( $registry as $slug => $entry ) {
				if ( 'active' !== ( $entry['state'] ?? 'draft' ) ) {
					continue;
				}

				$file = self::ensure_generated_file( $slug, $entry );
				if ( is_wp_error( $file ) ) {
					$registry[ $slug ]['state']             = 'quarantined';
					$registry[ $slug ]['quarantine_reason'] = $file->get_error_message();
					self::remove_from_enabled_option( self::get_ability_id( $slug ) );
					$changed = true;
					continue;
				}

				$current_hash = self::get_file_hash( $file );
				$last_test    = self::normalize_last_test( $entry['last_test'] ?? array() );

				if ( '' === $current_hash ) {
					$registry[ $slug ]['state']             = 'quarantined';
					$registry[ $slug ]['quarantine_reason'] = 'The generated custom ability file is missing.';
					self::remove_from_enabled_option( self::get_ability_id( $slug ) );
					$changed = true;
					continue;
				}

				if ( 'passed' !== $last_test['status'] || $current_hash !== $last_test['hash'] ) {
					$registry[ $slug ]['state']             = 'quarantined';
					$registry[ $slug ]['quarantine_reason'] = 'The generated file changed after its last passing test.';
					self::remove_from_enabled_option( self::get_ability_id( $slug ) );
					$changed = true;
					continue;
				}

				Dollypack_Runtime::register_ability(
					self::get_ability_id( $slug ),
					array(
						'file'               => $file,
						'class'              => self::get_class_name( $slug ),
						'always_enabled'     => true,
						'hidden_in_settings' => true,
					)
				);
			}

			if ( $changed ) {
				self::save_registry( $registry );
			}
		}

		/**
		 * Return the stored registry entry for a slug.
		 *
		 * @param string $slug Ability slug.
		 * @return array<string, mixed>|WP_Error
		 */
		private static function get_registry_entry( $slug ) {
			$registry = self::get_registry();

			if ( ! isset( $registry[ $slug ] ) ) {
				return new WP_Error( 'custom_ability_not_found', 'No custom ability exists with that name.' );
			}

			return $registry[ $slug ];
		}

		/**
		 * Return the persisted registry.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		private static function get_registry() {
			$registry = get_option( self::OPTION_KEY, array() );

			return is_array( $registry ) ? $registry : array();
		}

		/**
		 * Rewrite the registry to the minimal stored shape.
		 *
		 * @return void
		 */
		private static function compact_registry() {
			$registry   = self::get_registry();
			$normalized = array();

			foreach ( $registry as $slug => $entry ) {
				$slug = self::normalize_slug( $slug );

				if ( '' === $slug ) {
					continue;
				}

				$normalized[ $slug ] = self::normalize_registry_entry( $entry );
			}

			if ( $registry !== $normalized ) {
				self::save_registry( $normalized );
			}
		}

		/**
		 * Persist the registry in a stable slug order.
		 *
		 * @param array<string, array<string, mixed>> $registry Registry values.
		 * @return void
		 */
		private static function save_registry( $registry ) {
			foreach ( $registry as $slug => $entry ) {
				$registry[ $slug ] = self::normalize_registry_entry( $entry );
			}

			ksort( $registry );
			update_option( self::OPTION_KEY, $registry );
		}

		/**
		 * Update the last test record for a slug.
		 *
		 * @param string $slug      Ability slug.
		 * @param array  $last_test Last test data.
		 * @return void
		 */
		private static function update_last_test( $slug, $last_test ) {
			$registry = self::get_registry();

			if ( ! isset( $registry[ $slug ] ) ) {
				return;
			}

			$registry[ $slug ]['last_test'] = self::normalize_last_test( $last_test );
			self::save_registry( $registry );
		}

		/**
		 * Ensure the custom abilities directory exists and is writable.
		 *
		 * @return true|WP_Error
		 */
		private static function ensure_custom_directory() {
			$directory = self::get_custom_directory();

			if ( '' === $directory ) {
				return new WP_Error( 'custom_ability_missing_plugin_dir', 'The custom ability manager is not configured with a plugin directory.' );
			}

			if ( ! file_exists( $directory ) && ! wp_mkdir_p( $directory ) ) {
				return new WP_Error( 'custom_ability_directory_create_failed', 'Unable to create the custom abilities directory.' );
			}

			if ( ! self::is_path_writable( $directory ) ) {
				return new WP_Error( 'custom_ability_directory_not_writable', 'The custom abilities directory is not writable.' );
			}

			return true;
		}

		/**
		 * Prepare a registry entry for API responses.
		 *
		 * @param string $slug         Ability slug.
		 * @param bool   $include_code Whether to include the generated code.
		 * @return array<string, mixed>
		 */
		private static function prepare_response_entry( $slug, $include_code ) {
			$entry          = self::get_registry()[ $slug ] ?? array();
			$file           = self::get_file_path( $slug );
			$current_hash   = self::get_file_hash( $file );
			$last_test      = self::normalize_last_test( $entry['last_test'] ?? array() );
			$relative_file  = self::get_relative_file( $slug );
			$payload        = is_array( $entry['payload'] ?? null ) ? $entry['payload'] : array();
			$expected_hash  = self::get_expected_generated_hash( $slug, $payload );
			$ability        = array(
				'slug'                     => $slug,
				'id'                       => self::get_ability_id( $slug ),
				'name'                     => self::get_registered_name( $slug ),
				'class'                    => self::get_class_name( $slug ),
				'label'                    => $payload['label'] ?? self::get_default_label( $slug ),
				'description'              => $payload['description'] ?? '',
				'state'                    => self::normalize_registry_state( $entry['state'] ?? 'draft' ),
				'payload'                  => $payload,
				'file'                     => $file,
				'relative_file'            => $relative_file,
				'generated_hash'           => $expected_hash,
				'current_hash'             => $current_hash,
				'has_untracked_file_edits' => '' !== $expected_hash && '' !== $current_hash && $expected_hash !== $current_hash,
				'last_test'                => $last_test,
				'quarantine_reason'        => $entry['quarantine_reason'] ?? '',
				'activation_ready'         => '' !== $current_hash && 'passed' === $last_test['status'] && $current_hash === $last_test['hash'],
				'editor_url'               => self::get_editor_url( $relative_file ),
				'editor_supported'         => self::can_use_plugin_editor(),
			);

			if ( $include_code ) {
				$ability['code'] = file_exists( $file ) ? file_get_contents( $file ) : '';
			}

			return $ability;
		}

		/**
		 * Restore a generated file from the stored payload when it is missing.
		 *
		 * @param string $slug  Ability slug.
		 * @param array  $entry Registry entry.
		 * @return string|WP_Error
		 */
		private static function ensure_generated_file( $slug, $entry ) {
			$file = self::get_file_path( $slug );

			if ( file_exists( $file ) ) {
				return $file;
			}

			if ( empty( $entry['payload'] ) || ! is_array( $entry['payload'] ) ) {
				return new WP_Error( 'custom_ability_missing_payload', 'The generated custom ability file is missing and cannot be rebuilt.' );
			}

			$directory_ready = self::ensure_custom_directory();
			if ( is_wp_error( $directory_ready ) ) {
				return $directory_ready;
			}

			$generated_source = self::generate_ability_source( $slug, $entry['payload'] );

			if ( false === file_put_contents( $file, $generated_source ) ) {
				return new WP_Error( 'custom_ability_restore_failed', 'The generated custom ability file is missing and could not be rebuilt.' );
			}

			$registry = self::get_registry();

			if ( isset( $registry[ $slug ] ) ) {
				$registry[ $slug ]['payload'] = is_array( $entry['payload'] ) ? $entry['payload'] : array();
				self::save_registry( $registry );
			}

			return $file;
		}

		/**
		 * Normalize one persisted registry entry down to the minimal stored fields.
		 *
		 * @param mixed $entry Raw registry entry.
		 * @return array<string, mixed>
		 */
		private static function normalize_registry_entry( $entry ) {
			$entry = is_array( $entry ) ? $entry : array();
			$payload = self::normalize_json_value( $entry['payload'] ?? array(), 'payload' );

			return array(
				'payload'           => is_array( $payload ) ? $payload : array(),
				'state'             => self::normalize_registry_state( $entry['state'] ?? 'draft' ),
				'last_test'         => self::normalize_last_test( $entry['last_test'] ?? array() ),
				'quarantine_reason' => sanitize_text_field( $entry['quarantine_reason'] ?? '' ),
			);
		}

		/**
		 * Normalize a stored registry state.
		 *
		 * @param mixed $state Raw state value.
		 * @return string
		 */
		private static function normalize_registry_state( $state ) {
			$state = sanitize_key( (string) $state );

			if ( ! in_array( $state, array( 'draft', 'active', 'disabled', 'quarantined' ), true ) ) {
				return 'draft';
			}

			return $state;
		}

		/**
		 * Compute the expected generated file hash from the stored payload.
		 *
		 * @param string $slug    Ability slug.
		 * @param array  $payload Ability payload.
		 * @return string
		 */
		private static function get_expected_generated_hash( $slug, $payload ) {
			if ( ! is_array( $payload ) || empty( $payload['execute_body'] ) ) {
				return '';
			}

			return sha1( self::generate_ability_source( $slug, $payload ) );
		}

		/**
		 * Normalize JSON-like input to arrays/scalars only.
		 *
		 * @param mixed  $value   Value to normalize.
		 * @param string $context Error context label.
		 * @param int    $depth   Recursion depth.
		 * @return mixed|WP_Error
		 */
		private static function normalize_json_value( $value, $context, $depth = 0 ) {
			if ( $depth > 12 ) {
				return new WP_Error( 'custom_ability_value_too_deep', sprintf( 'The %s value is nested too deeply.', $context ) );
			}

			if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
				return $value;
			}

			if ( is_object( $value ) ) {
				$value = get_object_vars( $value );
			}

			if ( ! is_array( $value ) ) {
				return new WP_Error( 'custom_ability_invalid_value', sprintf( 'The %s value must be JSON-like data.', $context ) );
			}

			$normalized = array();

			foreach ( $value as $key => $item ) {
				if ( ! is_string( $key ) && ! is_int( $key ) ) {
					return new WP_Error( 'custom_ability_invalid_key', sprintf( 'The %s value contains an invalid key type.', $context ) );
				}

				$item = self::normalize_json_value( $item, $context, $depth + 1 );

				if ( is_wp_error( $item ) ) {
					return $item;
				}

				$normalized[ $key ] = $item;
			}

			return $normalized;
		}

		/**
		 * Normalize the persisted last_test structure.
		 *
		 * @param array $last_test Stored last test data.
		 * @return array<string, mixed>
		 */
		private static function normalize_last_test( $last_test ) {
			$defaults = self::get_default_last_test();

			if ( ! is_array( $last_test ) ) {
				return $defaults;
			}

			$defaults['status']    = isset( $last_test['status'] ) ? sanitize_key( $last_test['status'] ) : $defaults['status'];
			$defaults['tested_at'] = isset( $last_test['tested_at'] ) ? sanitize_text_field( $last_test['tested_at'] ) : $defaults['tested_at'];
			$defaults['hash']      = isset( $last_test['hash'] ) ? sanitize_text_field( $last_test['hash'] ) : $defaults['hash'];
			$defaults['summary']   = isset( $last_test['summary'] ) ? sanitize_text_field( $last_test['summary'] ) : $defaults['summary'];
			$defaults['transport'] = isset( $last_test['transport'] ) ? sanitize_text_field( $last_test['transport'] ) : $defaults['transport'];

			$details = self::normalize_json_value( $last_test['details'] ?? array(), 'last_test_details' );
			$defaults['details'] = is_wp_error( $details ) ? array() : $details;

			return $defaults;
		}

		/**
		 * Return the default last_test structure.
		 *
		 * @return array<string, mixed>
		 */
		private static function get_default_last_test() {
			return array(
				'status'    => 'not_run',
				'tested_at' => '',
				'hash'      => '',
				'summary'   => '',
				'transport' => '',
				'details'   => array(),
			);
		}

		/**
		 * Sanitize and validate a custom ability payload.
		 *
		 * @param mixed  $payload Raw payload.
		 * @param string $slug    Ability slug.
		 * @return array<string, mixed>|WP_Error
		 */
		private static function sanitize_payload( $payload, $slug ) {
			if ( ! is_array( $payload ) ) {
				return new WP_Error( 'invalid_custom_ability_payload', 'The payload parameter must be an object.' );
			}

			$execute_body = isset( $payload['execute_body'] ) ? (string) $payload['execute_body'] : '';
			$execute_body = str_replace( array( "\r\n", "\r" ), "\n", $execute_body );

			if ( '' === trim( $execute_body ) ) {
				return new WP_Error( 'missing_execute_body', 'The payload.execute_body parameter is required.' );
			}

			$execute_body_validation = self::validate_execute_body( $execute_body );
			if ( is_wp_error( $execute_body_validation ) ) {
				return $execute_body_validation;
			}

			$input_schema = self::normalize_json_value( $payload['input_schema'] ?? array(), 'input_schema' );
			if ( is_wp_error( $input_schema ) ) {
				return $input_schema;
			}

			$output_schema = self::normalize_json_value( $payload['output_schema'] ?? array(), 'output_schema' );
			if ( is_wp_error( $output_schema ) ) {
				return $output_schema;
			}

			if ( ! is_array( $input_schema ) || ! is_array( $output_schema ) ) {
				return new WP_Error( 'invalid_custom_ability_schema', 'The input_schema and output_schema values must be objects.' );
			}

			$annotations = self::normalize_json_value( $payload['annotations'] ?? array(), 'annotations' );
			if ( is_wp_error( $annotations ) ) {
				return $annotations;
			}

			$annotations = is_array( $annotations ) ? $annotations : array();

			return array(
				'label'         => sanitize_text_field( $payload['label'] ?? self::get_default_label( $slug ) ),
				'description'   => sanitize_text_field( $payload['description'] ?? 'Generated custom ability.' ),
				'execute_body'  => $execute_body,
				'input_schema'  => $input_schema,
				'output_schema' => $output_schema,
				'annotations'   => array(
					'readonly'    => ! empty( $annotations['readonly'] ),
					'destructive' => ! empty( $annotations['destructive'] ),
					'idempotent'  => ! empty( $annotations['idempotent'] ),
				),
			);
		}

		/**
		 * Validate the execute() body before generating the file.
		 *
		 * @param string $execute_body Raw execute() body.
		 * @return true|WP_Error
		 */
		private static function validate_execute_body( $execute_body ) {
			if ( false !== strpos( $execute_body, '<?' ) || false !== strpos( $execute_body, '?>' ) ) {
				return new WP_Error( 'invalid_execute_body_tags', 'Provide only the execute() body, without PHP open or close tags.' );
			}

			$tokens = token_get_all( "<?php\nfunction __dollypack_custom_execute( \$input ) {\n{$execute_body}\n}\n" );

			$blocked_identifiers = array(
				'assert',
				'register_shutdown_function',
				'set_error_handler',
				'set_exception_handler',
			);

			foreach ( $tokens as $token ) {
				if ( ! is_array( $token ) ) {
					continue;
				}

				list( $type, $text ) = $token;

				if ( in_array( $type, array( T_EVAL, T_EXIT, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
					return new WP_Error(
						'blocked_execute_body_token',
						sprintf( 'The execute() body cannot use %s.', $text )
					);
				}

				if ( T_STRING === $type && in_array( strtolower( $text ), $blocked_identifiers, true ) ) {
					return new WP_Error(
						'blocked_execute_body_identifier',
						sprintf( 'The execute() body cannot call %s.', $text )
					);
				}
			}

			return true;
		}

		/**
		 * Generate the PHP source for a constrained custom ability.
		 *
		 * @param string $slug    Ability slug.
		 * @param array  $payload Normalized payload.
		 * @return string
		 */
		private static function generate_ability_source( $slug, $payload ) {
			$input_schema_export  = self::export_php_value( $payload['input_schema'], 3 );
			$output_schema_export = self::export_php_value( $payload['output_schema'], 3 );
			$meta_export          = self::export_php_value(
				array(
					'show_in_rest' => true,
					'annotations'  => $payload['annotations'],
				),
				3
			);

			return "<?php\n\n"
				. "if ( ! defined( 'ABSPATH' ) ) {\n"
				. "\texit;\n"
				. "}\n\n"
				. "if ( ! class_exists( '" . self::escape_php_single_quoted_string( self::get_class_name( $slug ) ) . "' ) ) {\n"
				. "\tclass " . self::get_class_name( $slug ) . " extends Dollypack_Custom_Ability {\n\n"
				. "\t\tprotected \$id          = '" . self::escape_php_single_quoted_string( self::get_ability_id( $slug ) ) . "';\n"
				. "\t\tprotected \$name        = '" . self::escape_php_single_quoted_string( self::get_registered_name( $slug ) ) . "';\n"
				. "\t\tprotected \$label       = '" . self::escape_php_single_quoted_string( $payload['label'] ) . "';\n"
				. "\t\tprotected \$description = '" . self::escape_php_single_quoted_string( $payload['description'] ) . "';\n\n"
				. "\t\tpublic function execute( \$input ) {\n"
				. self::indent_multiline_text( rtrim( $payload['execute_body'], "\n" ), 3 ) . "\n"
				. "\t\t}\n\n"
				. "\t\tpublic function get_input_schema() {\n"
				. "\t\t\treturn " . $input_schema_export . ";\n"
				. "\t\t}\n\n"
				. "\t\tpublic function get_output_schema() {\n"
				. "\t\t\treturn " . $output_schema_export . ";\n"
				. "\t\t}\n\n"
				. "\t\tpublic function get_meta() {\n"
				. "\t\t\treturn " . $meta_export . ";\n"
				. "\t\t}\n"
				. "\t}\n"
				. "}\n";
		}

		/**
		 * Export a PHP value and indent wrapped lines.
		 *
		 * @param mixed $value        Value to export.
		 * @param int   $indent_level Tab indentation level.
		 * @return string
		 */
		private static function export_php_value( $value, $indent_level ) {
			return self::indent_multiline_text( var_export( $value, true ), $indent_level );
		}

		/**
		 * Indent a block of text with tabs.
		 *
		 * @param string $text         Text to indent.
		 * @param int    $indent_level Tab indentation level.
		 * @return string
		 */
		private static function indent_multiline_text( $text, $indent_level ) {
			$indent = str_repeat( "\t", max( 0, (int) $indent_level ) );
			$text   = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
			$lines  = explode( "\n", $text );

			foreach ( $lines as &$line ) {
				$line = $indent . $line;
			}

			unset( $line );

			return implode( "\n", $lines );
		}

		/**
		 * Escape a string for inclusion in a single-quoted PHP literal.
		 *
		 * @param string $value Raw string value.
		 * @return string
		 */
		private static function escape_php_single_quoted_string( $value ) {
			return str_replace(
				array( '\\', '\'' ),
				array( '\\\\', '\\\'' ),
				(string) $value
			);
		}

		/**
		 * Normalize a user-supplied name to the generated ability slug.
		 *
		 * @param string $name Raw ability name.
		 * @return string
		 */
		private static function normalize_slug( $name ) {
			$name = trim( (string) $name );

			if ( '' === $name ) {
				return '';
			}

			$name = preg_replace( '#^dollypack/#', '', $name );
			$name = preg_replace( '#^custom/#', '', $name );
			$name = preg_replace( '#^custom-#', '', $name );

			if ( false !== strpos( $name, '/' ) ) {
				$parts = explode( '/', $name );
				$name  = end( $parts );
			}

			return sanitize_title( $name );
		}

		/**
		 * Return the runtime ability ID for a slug.
		 *
		 * @param string $slug Ability slug.
		 * @return string
		 */
		private static function get_ability_id( $slug ) {
			return 'custom-' . $slug;
		}

		/**
		 * Return the ability name exposed to WordPress.
		 *
		 * @param string $slug Ability slug.
		 * @return string
		 */
		private static function get_registered_name( $slug ) {
			return 'dollypack/custom-' . $slug;
		}

		/**
		 * Return the generated PHP class name for a slug.
		 *
		 * @param string $slug Ability slug.
		 * @return string
		 */
		private static function get_class_name( $slug ) {
			$parts = explode( '-', $slug );
			$parts = array_map( 'ucfirst', array_filter( $parts ) );

			return 'Dollypack_Custom_Ability_' . implode( '_', $parts );
		}

		/**
		 * Return the default label for a slug.
		 *
		 * @param string $slug Ability slug.
		 * @return string
		 */
		private static function get_default_label( $slug ) {
			return ucwords( str_replace( '-', ' ', $slug ) );
		}

		/**
		 * Return the generated custom abilities directory.
		 *
		 * @return string
		 */
		private static function get_custom_directory() {
			if ( '' === self::$plugin_dir ) {
				return '';
			}

			return self::$plugin_dir . self::DIRECTORY;
		}

		/**
		 * Return the relative generated file path for a slug.
		 *
		 * @param string $slug Ability slug.
		 * @return string
		 */
		private static function get_relative_file( $slug ) {
			return self::DIRECTORY . '/' . $slug . '.php';
		}

		/**
		 * Return the absolute generated file path for a slug.
		 *
		 * @param string $slug Ability slug.
		 * @return string
		 */
		private static function get_file_path( $slug ) {
			return self::$plugin_dir . self::get_relative_file( $slug );
		}

		/**
		 * Return the SHA-1 hash for a file or an empty string if missing.
		 *
		 * @param string $file Absolute file path.
		 * @return string
		 */
		private static function get_file_hash( $file ) {
			if ( ! file_exists( $file ) ) {
				return '';
			}

			$hash = sha1_file( $file );

			return is_string( $hash ) ? $hash : '';
		}

		/**
		 * Build the plugin editor URL for a generated file when supported.
		 *
		 * @param string $relative_file Relative file path within the plugin.
		 * @return string
		 */
		private static function get_editor_url( $relative_file ) {
			if ( empty( self::$plugin_file ) || ! self::can_use_plugin_editor() ) {
				return '';
			}

			return add_query_arg(
				array(
					'plugin' => plugin_basename( self::$plugin_file ),
					'file'   => $relative_file,
				),
				admin_url( 'plugin-editor.php' )
			);
		}

		/**
		 * Check whether the current request can open the plugin editor.
		 *
		 * @return bool
		 */
		private static function can_use_plugin_editor() {
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_plugins' ) ) {
				return false;
			}

			if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
				return false;
			}

			return true;
		}

		/**
		 * Remove a custom ability ID from the enabled abilities option.
		 *
		 * @param string $ability_id Runtime ability ID.
		 * @return void
		 */
		private static function remove_from_enabled_option( $ability_id ) {
			$enabled = get_option( Dollypack_Settings::OPTION_KEY, array() );
			$enabled = is_array( $enabled ) ? $enabled : array();
			$enabled = array_values(
				array_filter(
					$enabled,
					static function( $value ) use ( $ability_id ) {
						return $value !== $ability_id;
					}
				)
			);

			update_option( Dollypack_Settings::OPTION_KEY, $enabled );
		}

		/**
		 * Remove any stale custom ability IDs from the enabled abilities option.
		 *
		 * @param array<int, string> $slugs Custom ability slugs.
		 * @return void
		 */
		private static function cleanup_enabled_option( $slugs ) {
			if ( empty( $slugs ) ) {
				return;
			}

			foreach ( $slugs as $slug ) {
				self::remove_from_enabled_option( self::get_ability_id( $slug ) );
			}
		}

		/**
		 * Normalize arbitrary execution results for JSON responses.
		 *
		 * @param mixed $value Result value.
		 * @param int   $depth Current recursion depth.
		 * @return mixed
		 */
		private static function normalize_for_response( $value, $depth = 0 ) {
			if ( $depth > 5 ) {
				return '...';
			}

			if ( $value instanceof WP_Error ) {
				return array(
					'type'    => 'wp_error',
					'code'    => $value->get_error_code(),
					'message' => $value->get_error_message(),
					'data'    => self::normalize_for_response( $value->get_error_data(), $depth + 1 ),
				);
			}

			if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				return $value;
			}

			if ( is_string( $value ) ) {
				return self::truncate_string( $value );
			}

			if ( is_object( $value ) ) {
				return array(
					'type'       => 'object',
					'class'      => get_class( $value ),
					'properties' => self::normalize_for_response( get_object_vars( $value ), $depth + 1 ),
				);
			}

			if ( ! is_array( $value ) ) {
				return array(
					'type'  => gettype( $value ),
					'value' => self::truncate_string( wp_json_encode( $value ) ),
				);
			}

			$normalized = array();
			$count      = 0;

			foreach ( $value as $key => $item ) {
				if ( $count >= 25 ) {
					$normalized['...'] = 'truncated';
					break;
				}

				$normalized[ $key ] = self::normalize_for_response( $item, $depth + 1 );
				$count++;
			}

			return $normalized;
		}

		/**
		 * Truncate long strings in loopback responses.
		 *
		 * @param string $value Raw string.
		 * @param int    $limit Maximum string length.
		 * @return string
		 */
		private static function truncate_string( $value, $limit = 4000 ) {
			$value = (string) $value;

			if ( strlen( $value ) <= $limit ) {
				return $value;
			}

			return substr( $value, 0, $limit ) . '...';
		}

		/**
		 * Determine whether a PHP error type is fatal.
		 *
		 * @param int $type PHP error type.
		 * @return bool
		 */
		private static function is_fatal_error( $type ) {
			return in_array(
				(int) $type,
				array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ),
				true
			);
		}

		/**
		 * Check whether a path is writable with WordPress compatibility helpers.
		 *
		 * @param string $path File or directory path.
		 * @return bool
		 */
		private static function is_path_writable( $path ) {
			if ( function_exists( 'wp_is_writable' ) ) {
				return wp_is_writable( $path );
			}

			return is_writable( $path );
		}

		/**
		 * Send a JSON response for the loopback test request.
		 *
		 * @param array<string, mixed> $payload     Response payload.
		 * @param int                  $status_code HTTP status code.
		 * @return void
		 */
		private static function send_loopback_response( $payload, $status_code = 200 ) {
			self::$loopback_response_sent = true;

			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			status_header( $status_code );
			nocache_headers();
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			echo wp_json_encode( $payload );
			exit;
		}
	}
}
