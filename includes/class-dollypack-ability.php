<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Dollypack_Ability {

	/**
	 * Ability slug, e.g. 'wp-remote-request'.
	 */
	protected $id = '';

	/**
	 * Full ability name, e.g. 'dollypack/wp-remote-request'.
	 */
	protected $name = '';

	/**
	 * Human-readable label.
	 */
	protected $label = '';

	/**
	 * Description string.
	 */
	protected $description = '';

	/**
	 * Declarative settings array.
	 * Keys are setting IDs, values are arrays with 'type', 'name', 'label',
	 * and optional 'storage' / 'encrypted' flags.
	 * Storage defaults to 'site' and may be set to 'user' for per-user secrets.
	 */
	protected $settings = array();

	/**
	 * Optional group label for the settings UI, e.g. 'GitHub'.
	 * Set on parent classes to group child abilities together.
	 */
	protected $group_label = '';

	/**
	 * Allow read-only access to protected properties.
	 */
	public function __get( $name ) {
		if ( property_exists( $this, $name ) ) {
			return $this->$name;
		}
		return null;
	}

	/**
	 * Get the storage key for a setting.
	 * For site-scoped settings this becomes the option name.
	 * For user-scoped settings this becomes the user meta key.
	 *
	 * For settings declared on this class, uses this class's $id prefix.
	 * For inherited settings, uses the declaring class's $id prefix so the key is shared.
	 */
	public function get_setting_storage_key( $setting_id ) {
		// Walk up the class hierarchy to find the class that originally declares this setting.
		// getDefaultProperties() includes inherited values, so we must compare with the
		// parent's defaults to determine whether this class actually introduces the setting.
		$class = new ReflectionClass( $this );
		while ( $class && $class->getName() !== 'Dollypack_Ability' ) {
			$defaults = $class->getDefaultProperties();
				if ( isset( $defaults['settings'][ $setting_id ] ) ) {
					$parent = $class->getParentClass();
					if ( $parent && $parent->getName() !== 'Dollypack_Ability' ) {
						$parent_defaults = $parent->getDefaultProperties();
					if ( isset( $parent_defaults['settings'][ $setting_id ] ) ) {
						// Inherited from parent — keep walking up.
						$class = $parent;
						continue;
					}
					}
					// This class is the declaring class.
					$declaring_id = $defaults['id'] ?? $this->id;
					$key = 'dollypack_' . $declaring_id . '_' . $setting_id;
					if ( 'user' === $this->get_setting_storage_scope( $setting_id ) ) {
						return '_' . $key;
					}

					return $key;
				}
				$class = $class->getParentClass();
			}

			// Fallback: use own $id.
			$key = 'dollypack_' . $this->id . '_' . $setting_id;
			if ( 'user' === $this->get_setting_storage_scope( $setting_id ) ) {
				return '_' . $key;
			}

			return $key;
	}

	/**
	 * Get the merged setting definition for a setting ID.
	 */
	public function get_setting_definition( $setting_id ) {
		$settings = $this->get_all_settings();
		return $settings[ $setting_id ] ?? array();
	}

	/**
	 * Determine whether a setting is stored per site or per user.
	 */
	public function get_setting_storage_scope( $setting_id ) {
		$setting = $this->get_setting_definition( $setting_id );
		return ( isset( $setting['storage'] ) && 'user' === $setting['storage'] ) ? 'user' : 'site';
	}

	/**
	 * Determine whether a setting should be encrypted before storage.
	 */
	public function is_setting_encrypted( $setting_id ) {
		$setting = $this->get_setting_definition( $setting_id );
		return ! empty( $setting['encrypted'] );
	}

	/**
	 * Read a setting value from its configured storage scope.
	 */
	public function get_setting( $setting_id, $user_id = 0 ) {
		$storage_key = $this->get_setting_storage_key( $setting_id );

		if ( 'user' === $this->get_setting_storage_scope( $setting_id ) ) {
			$user_id = $user_id ?: get_current_user_id();
			if ( ! $user_id ) {
				return '';
			}

			$value = get_user_meta( $user_id, $storage_key, true );
		} else {
			$value = get_option( $storage_key, '' );
		}

		if ( ! $this->is_setting_encrypted( $setting_id ) || '' === $value ) {
			return $value;
		}

		$decrypted_value = Dollypack_Crypto::decrypt( $value );

		// Migrate legacy plaintext secrets the next time they are read.
		if ( ! Dollypack_Crypto::is_encrypted_string( $value ) && '' !== $decrypted_value ) {
			$this->update_setting( $setting_id, $decrypted_value, $user_id );
		}

		return $decrypted_value;
	}

	/**
	 * Persist a setting value to its configured storage scope.
	 */
	public function update_setting( $setting_id, $value, $user_id = 0 ) {
		$storage_key = $this->get_setting_storage_key( $setting_id );

		if ( $this->is_setting_encrypted( $setting_id ) && '' !== $value ) {
			$value = Dollypack_Crypto::encrypt( (string) $value );
		}

		if ( 'user' === $this->get_setting_storage_scope( $setting_id ) ) {
			$user_id = $user_id ?: get_current_user_id();
			if ( ! $user_id ) {
				return false;
			}

			return update_user_meta( $user_id, $storage_key, $value );
		}

		return update_option( $storage_key, $value );
	}

	/**
	 * Delete a setting value from its configured storage scope.
	 */
	public function delete_setting( $setting_id, $user_id = 0 ) {
		$storage_key = $this->get_setting_storage_key( $setting_id );

		if ( 'user' === $this->get_setting_storage_scope( $setting_id ) ) {
			$user_id = $user_id ?: get_current_user_id();
			if ( ! $user_id ) {
				return false;
			}

			return delete_user_meta( $user_id, $storage_key );
		}

		return delete_option( $storage_key );
	}

	/**
	 * Get all settings merged from the full class hierarchy.
	 */
	public function get_all_settings() {
		$all      = array();
		$classes  = array();
		$class    = new ReflectionClass( $this );

		// Collect the class chain (excluding the abstract base).
		while ( $class && $class->getName() !== 'Dollypack_Ability' ) {
			$classes[] = $class;
			$class     = $class->getParentClass();
		}

		// Merge from the most-parent down so children can override.
		$classes = array_reverse( $classes );
		foreach ( $classes as $c ) {
			$defaults = $c->getDefaultProperties();
			if ( ! empty( $defaults['settings'] ) && is_array( $defaults['settings'] ) ) {
				$all = array_merge( $all, $defaults['settings'] );
			}
		}

		return $all;
	}

	/**
	 * Check whether all required settings have values.
	 */
	public function has_required_settings() {
		foreach ( $this->get_all_settings() as $setting_id => $setting ) {
			if ( '' === $this->get_setting( $setting_id ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Register this ability with WordPress.
	 */
	public function register() {
		wp_register_ability( $this->name, array(
			'label'               => $this->label,
			'description'         => $this->description,
			'category'            => 'dollypack',
			'input_schema'        => $this->get_input_schema(),
			'output_schema'       => $this->get_output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'permission_callback' ),
			'meta'                => $this->get_meta(),
		) );
	}

	/**
	 * Default permission callback.
	 */
	public function permission_callback() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Execute the ability.
	 */
	abstract public function execute( $input );

	/**
	 * Return the input JSON schema.
	 */
	abstract public function get_input_schema();

	/**
	 * Return the output JSON schema.
	 */
	abstract public function get_output_schema();

	/**
	 * Return meta array (annotations, show_in_rest, etc.).
	 */
	abstract public function get_meta();
}
