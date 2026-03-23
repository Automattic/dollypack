<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dollypack_Settings {

	const OPTION_KEY = 'dollypack_enabled_abilities';
	const SETTINGS_INPUT_KEY = 'dollypack_settings';
	const MANAGEABLE_ABILITIES_INPUT_KEY = 'dollypack_manageable_abilities';
	const SAVE_ACTION = 'dollypack_save_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_form_submission' ) );
	}

	public function add_menu_page() {
		add_options_page(
			'Dollypack',
			'Dollypack',
			'manage_options',
			'dollypack',
			array( $this, 'render_page' )
		);
	}

	public function sanitize_abilities( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$available = array_keys( dollypack_get_available_abilities() );
		return array_values( array_intersect( $value, $available ) );
	}

	/**
	 * Return unique setting fields keyed by storage key.
	 */
	private function get_setting_fields( $instances ) {
		$fields = array();

		foreach ( $instances as $ability ) {
			foreach ( $ability->get_all_settings() as $setting_id => $setting ) {
				$storage_key = $ability->get_setting_storage_key( $setting_id );
				if ( isset( $fields[ $storage_key ] ) ) {
					continue;
				}

				$fields[ $storage_key ] = array_merge(
					$setting,
					array(
						'ability'      => $ability,
						'setting_id'  => $setting_id,
						'storage'     => $ability->get_setting_storage_scope( $setting_id ),
						'storage_key' => $storage_key,
						'input_name'  => self::SETTINGS_INPUT_KEY . '[' . $storage_key . ']',
						'value'       => $ability->get_setting( $setting_id ),
					)
				);
			}
		}

		return $fields;
	}

	/**
	 * Persist a single setting field to its configured storage scope.
	 */
	private function save_setting_field( $field, $value, $user_id ) {
		if ( '' === $value ) {
			$field['ability']->delete_setting( $field['setting_id'], $user_id );
			return;
		}

		$field['ability']->update_setting( $field['setting_id'], $value, $user_id );
	}

	/**
	 * Handle saving mixed site/user-scoped settings from the admin page.
	 */
	public function handle_form_submission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		check_admin_referer( self::SAVE_ACTION );

		$user_id          = get_current_user_id();
		$instances        = dollypack_get_ability_instances();
		$setting_fields   = $this->get_setting_fields( $instances );
		$submitted_fields = isset( $_POST[ self::SETTINGS_INPUT_KEY ] ) ? (array) wp_unslash( $_POST[ self::SETTINGS_INPUT_KEY ] ) : array();

		foreach ( $setting_fields as $storage_key => $field ) {
			$raw_value = isset( $submitted_fields[ $storage_key ] ) ? $submitted_fields[ $storage_key ] : '';
			$value     = sanitize_text_field( $raw_value );
			$this->save_setting_field( $field, $value, $user_id );
		}

		$current_enabled   = get_option( self::OPTION_KEY, array_keys( $instances ) );
		$submitted_enabled = isset( $_POST[ self::OPTION_KEY ] ) ? (array) wp_unslash( $_POST[ self::OPTION_KEY ] ) : array();
		$submitted_enabled = $this->sanitize_abilities( $submitted_enabled );
		$manageable        = isset( $_POST[ self::MANAGEABLE_ABILITIES_INPUT_KEY ] ) ? (array) wp_unslash( $_POST[ self::MANAGEABLE_ABILITIES_INPUT_KEY ] ) : array();
		$manageable        = $this->sanitize_abilities( $manageable );
		$enabled           = array();

		foreach ( array_keys( $instances ) as $ability_id ) {
			if ( in_array( $ability_id, $manageable, true ) ) {
				if ( in_array( $ability_id, $submitted_enabled, true ) ) {
					$enabled[] = $ability_id;
				}
				continue;
			}

			if ( in_array( $ability_id, $current_enabled, true ) ) {
				$enabled[] = $ability_id;
			}
		}

		update_option( self::OPTION_KEY, $enabled );

		wp_safe_redirect( admin_url( 'options-general.php?page=dollypack&settings-updated=true' ) );
		exit;
	}

	/**
	 * Group abilities by their parent class group_label.
	 */
	private function get_grouped_abilities( $instances ) {
		$groups      = array();
		$other_group = array( 'label' => 'Other', 'settings' => array(), 'abilities' => array() );
		$collected_settings = array();
		$setting_fields     = $this->get_setting_fields( $instances );

		foreach ( $instances as $id => $ability ) {
			$group_label = $ability->group_label ?? '';

			$target_group = empty( $group_label ) ? 'Other' : $group_label;

			if ( 'Other' !== $target_group && ! isset( $groups[ $target_group ] ) ) {
				$groups[ $target_group ] = array(
					'label'        => $target_group,
					'settings'     => array(),
					'abilities'    => array(),
					'parent_class' => '',
				);
			}

			foreach ( $ability->get_all_settings() as $setting_id => $setting ) {
				$storage_key = $ability->get_setting_storage_key( $setting_id );
				if ( isset( $collected_settings[ $target_group ][ $storage_key ] ) ) {
					continue;
				}

				$collected_settings[ $target_group ][ $storage_key ] = true;
				if ( 'Other' === $target_group ) {
					$other_group['settings'][ $setting_id ] = $setting_fields[ $storage_key ];
				} else {
					$groups[ $target_group ]['settings'][ $setting_id ] = $setting_fields[ $storage_key ];
				}
			}

			$parent_class = ( new ReflectionClass( $ability ) )->getParentClass();
			if ( $parent_class && $parent_class->getName() !== 'Dollypack_Ability' ) {
				if ( 'Other' !== $target_group && empty( $groups[ $target_group ]['parent_class'] ) ) {
					$groups[ $target_group ]['parent_class'] = $parent_class->getName();
				}
			}

			if ( 'Other' === $target_group ) {
				$other_group['abilities'][ $id ] = $ability;
			} else {
				$groups[ $target_group ]['abilities'][ $id ] = $ability;
			}
		}

		if ( ! empty( $other_group['abilities'] ) ) {
			$groups['Other'] = $other_group;
		}

		return $groups;
	}

	public function render_page() {
		$instances = dollypack_get_ability_instances();
		$enabled   = get_option( self::OPTION_KEY, array_keys( $instances ) );
		$groups    = $this->get_grouped_abilities( $instances );
		?>
		<div class="wrap">
			<h1>Dollypack</h1>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>Settings saved.</p>
				</div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />

				<?php foreach ( $groups as $group ) : ?>
					<h2><?php echo esc_html( $group['label'] ); ?></h2>
					<table class="form-table">
						<?php foreach ( $group['settings'] as $setting_id => $setting ) : ?>
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $setting['storage_key'] ); ?>">
										<?php echo esc_html( $setting['name'] ); ?>
									</label>
								</th>
								<td>
									<input
										type="<?php echo esc_attr( $setting['type'] ?? 'text' ); ?>"
										id="<?php echo esc_attr( $setting['storage_key'] ); ?>"
										name="<?php echo esc_attr( $setting['input_name'] ); ?>"
										value="<?php echo esc_attr( $setting['value'] ); ?>"
										class="regular-text"
									/>
									<?php
									$description = '';
									if ( ! empty( $setting['label'] ) ) {
										$description = $setting['label'] . ' ';
									}
									$description .= ( 'user' === $setting['storage'] )
										? 'Stored for your WordPress user only.'
										: 'Stored for the whole site.';
									if ( ! empty( $setting['encrypted'] ) ) {
										$description .= ' Encrypted at rest.';
									}
									?>
									<p class="description"><?php echo esc_html( $description ); ?></p>
								</td>
							</tr>
						<?php endforeach; ?>

						<?php
						if ( ! empty( $group['parent_class'] ) && method_exists( $group['parent_class'], 'render_settings_html' ) ) {
							$group['parent_class']::render_settings_html();
						}
						?>

						<tr>
							<th scope="row">Abilities</th>
							<td>
								<?php foreach ( $group['abilities'] as $id => $ability ) : ?>
									<?php $has_settings = $ability->has_required_settings(); ?>
									<fieldset>
										<?php if ( $has_settings ) : ?>
											<input type="hidden" name="<?php echo esc_attr( self::MANAGEABLE_ABILITIES_INPUT_KEY ); ?>[]" value="<?php echo esc_attr( $id ); ?>" />
										<?php endif; ?>
										<label>
											<input
												type="checkbox"
												name="<?php echo esc_attr( self::OPTION_KEY ); ?>[]"
												value="<?php echo esc_attr( $id ); ?>"
												<?php checked( in_array( $id, $enabled, true ) ); ?>
												<?php disabled( ! $has_settings ); ?>
											/>
											<strong><?php echo esc_html( $id ); ?></strong>
											&mdash; <?php echo esc_html( $ability->description ); ?>
										</label>
										<?php if ( ! $has_settings ) : ?>
											<p class="description" style="color: #996800;">Configure the required settings or connect your account to use this ability.</p>
										<?php endif; ?>
									</fieldset>
								<?php endforeach; ?>
							</td>
						</tr>
					</table>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
