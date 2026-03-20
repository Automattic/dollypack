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
	 * Declarative settings array (PersonalOS format).
	 * Keys are setting IDs, values are arrays with 'type', 'name', 'label' etc.
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
	 * Get the option name for a setting.
	 * For settings declared on this class, uses this class's $id prefix.
	 * For inherited settings, uses the declaring class's $id prefix so the option is shared.
	 */
	public function get_setting_option_name( $setting_id ) {
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
				return 'dollypack_' . $declaring_id . '_' . $setting_id;
			}
			$class = $class->getParentClass();
		}

		// Fallback: use own $id.
		return 'dollypack_' . $this->id . '_' . $setting_id;
	}

	/**
	 * Read a setting value.
	 */
	public function get_setting( $setting_id ) {
		$option_name = $this->get_setting_option_name( $setting_id );
		return get_option( $option_name, '' );
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
