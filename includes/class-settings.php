<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dollypack_Settings {

	const OPTION_KEY = 'dollypack_enabled_abilities';
	const SETTINGS_INPUT_KEY = 'dollypack_settings';
	const MANAGEABLE_ABILITIES_INPUT_KEY = 'dollypack_manageable_abilities';
	const SAVE_ACTION = 'dollypack_save_settings';
	const CUSTOM_ABILITY_ACTION = 'dollypack_manage_custom_ability';
	const CUSTOM_ABILITY_NOTICE_PREFIX = 'dollypack_custom_ability_notice_';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_form_submission' ) );
		add_action( 'admin_post_' . self::CUSTOM_ABILITY_ACTION, array( $this, 'handle_custom_ability_submission' ) );
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
		$available = array_keys( Dollypack_Runtime::get_settings_ability_instances() );
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
		$instances        = Dollypack_Runtime::get_ability_instances();
		$visible_instances = Dollypack_Runtime::get_settings_ability_instances();
		$setting_fields   = $this->get_setting_fields( $visible_instances );
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
	 * Handle admin actions for generated custom abilities.
	 *
	 * @return void
	 */
	public function handle_custom_ability_submission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		check_admin_referer( self::CUSTOM_ABILITY_ACTION );

		$custom_action = isset( $_POST['custom_ability_action'] ) ? sanitize_key( wp_unslash( $_POST['custom_ability_action'] ) ) : '';
		$name          = isset( $_POST['custom_ability_name'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_ability_name'] ) ) : '';
		$result        = null;

		switch ( $custom_action ) {
			case 'test':
				$result = Dollypack_Custom_Ability_Manager::test_custom_ability( $name, array() );
				break;

			case 'turn_on':
				$result = Dollypack_Custom_Ability_Manager::turn_on_custom_ability( $name );
				break;

			case 'turn_off':
				$result = Dollypack_Custom_Ability_Manager::turn_off_custom_ability( $name );
				break;

			default:
				$result = new WP_Error( 'invalid_custom_ability_action', 'Unknown custom ability action.' );
				break;
		}

		if ( is_wp_error( $result ) ) {
			$this->set_custom_ability_notice(
				'error',
				$result->get_error_message()
			);
		} else {
			$this->set_custom_ability_notice(
				'success',
				$this->get_custom_ability_success_message( $custom_action, $name, $result )
			);
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=dollypack#dollypack-custom-abilities' ) );
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

	/**
	 * Return a user-scoped notice for custom ability management, then clear it.
	 *
	 * @return array<string, string>
	 */
	private function pop_custom_ability_notice() {
		$key    = self::CUSTOM_ABILITY_NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		delete_transient( $key );

		if ( ! is_array( $notice ) ) {
			return array();
		}

		return array(
			'type'    => sanitize_key( $notice['type'] ?? '' ),
			'message' => sanitize_text_field( $notice['message'] ?? '' ),
		);
	}

	/**
	 * Persist a short notice for the custom abilities UI.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function set_custom_ability_notice( $type, $message ) {
		set_transient(
			self::CUSTOM_ABILITY_NOTICE_PREFIX . get_current_user_id(),
			array(
				'type'    => sanitize_key( $type ),
				'message' => sanitize_text_field( $message ),
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Return all generated custom abilities for admin display.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_custom_abilities() {
		$result = Dollypack_Custom_Ability_Manager::read_custom_ability();

		if ( is_wp_error( $result ) || empty( $result['abilities'] ) || ! is_array( $result['abilities'] ) ) {
			return array();
		}

		return $result['abilities'];
	}

	/**
	 * Build a user-facing success message for a custom ability action.
	 *
	 * @param string $action Admin action slug.
	 * @param string $name   Ability name.
	 * @param array  $result Manager result.
	 * @return string
	 */
	private function get_custom_ability_success_message( $action, $name, $result ) {
		$display_name = trim( (string) $name );

		if ( is_array( $result ) && ! empty( $result['ability']['label'] ) ) {
			$display_name = $result['ability']['label'];
		}

		switch ( $action ) {
			case 'test':
				$summary = '';
				if ( is_array( $result ) && ! empty( $result['test']['summary'] ) ) {
					$summary = sanitize_text_field( $result['test']['summary'] );
				}

				return '' !== $summary
					? sprintf( 'Test completed for %s. %s', $display_name, $summary )
					: sprintf( 'Test completed for %s.', $display_name );

			case 'turn_on':
				return sprintf( 'Turned on %s.', $display_name );

			case 'turn_off':
				return sprintf( 'Turned off %s.', $display_name );

			default:
				return sprintf( 'Updated %s.', $display_name );
		}
	}

	/**
	 * Render the Custom Abilities admin section.
	 *
	 * @return void
	 */
	private function render_custom_abilities_section() {
		$abilities = $this->get_custom_abilities();
		?>
		<div id="dollypack-custom-abilities">
			<h2>Custom Abilities</h2>
			<p class="description">Generated abilities are managed separately because they can be drafted, tested, activated, disabled, or quarantined. Test after any manual file edit before turning one on again.</p>

			<?php if ( empty( $abilities ) ) : ?>
				<p>No custom abilities have been generated yet.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col">Ability</th>
							<th scope="col">State</th>
							<th scope="col">Last Test</th>
							<th scope="col">Issues</th>
							<th scope="col">Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $abilities as $ability ) : ?>
							<?php
							$label            = $ability['label'] ?? ( $ability['slug'] ?? 'Custom Ability' );
							$slug             = $ability['slug'] ?? '';
							$description      = $ability['description'] ?? '';
							$state            = $ability['state'] ?? 'draft';
							$last_test        = is_array( $ability['last_test'] ?? null ) ? $ability['last_test'] : array();
							$last_test_status = $last_test['status'] ?? 'not_run';
							$last_test_time   = $last_test['tested_at'] ?? '';
							$last_test_summary = $last_test['summary'] ?? '';
							$quarantine_reason = $ability['quarantine_reason'] ?? '';
							$has_file_edits   = ! empty( $ability['has_untracked_file_edits'] );
							$activation_ready = ! empty( $ability['activation_ready'] );
							$editor_url       = $ability['editor_url'] ?? '';
							$relative_file    = $ability['relative_file'] ?? '';
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $label ); ?></strong>
									<p><code><?php echo esc_html( $slug ); ?></code></p>
									<?php if ( '' !== $description ) : ?>
										<p><?php echo esc_html( $description ); ?></p>
									<?php endif; ?>
									<?php if ( '' !== $relative_file ) : ?>
										<p><code><?php echo esc_html( $relative_file ); ?></code></p>
									<?php endif; ?>
									<?php if ( '' !== $editor_url ) : ?>
										<p><a href="<?php echo esc_url( $editor_url ); ?>">Open in plugin editor</a></p>
									<?php endif; ?>
								</td>
								<td>
									<strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $state ) ) ); ?></strong>
									<?php if ( 'active' === $state ) : ?>
										<p class="description">Available for remote use.</p>
									<?php elseif ( 'quarantined' === $state ) : ?>
										<p class="description">Held back until it is fixed and re-tested.</p>
									<?php elseif ( 'disabled' === $state ) : ?>
										<p class="description">Kept on disk but not exposed remotely.</p>
									<?php else : ?>
										<p class="description">Saved but not active.</p>
									<?php endif; ?>
								</td>
								<td>
									<strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $last_test_status ) ) ); ?></strong>
									<?php if ( '' !== $last_test_time ) : ?>
										<p><?php echo esc_html( $last_test_time ); ?></p>
									<?php endif; ?>
									<?php if ( '' !== $last_test_summary ) : ?>
										<p><?php echo esc_html( $last_test_summary ); ?></p>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( '' !== $quarantine_reason ) : ?>
										<p><?php echo esc_html( $quarantine_reason ); ?></p>
									<?php endif; ?>
									<?php if ( $has_file_edits ) : ?>
										<p>Generated file changed outside Dollypack. Test again before turning it on.</p>
									<?php endif; ?>
									<?php if ( '' === $quarantine_reason && ! $has_file_edits && 'failed' === $last_test_status && '' !== $last_test_summary ) : ?>
										<p><?php echo esc_html( $last_test_summary ); ?></p>
									<?php endif; ?>
									<?php if ( '' === $quarantine_reason && ! $has_file_edits && 'failed' !== $last_test_status ) : ?>
										<p class="description">No known issues.</p>
									<?php endif; ?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin:0 8px 8px 0;">
										<?php wp_nonce_field( self::CUSTOM_ABILITY_ACTION ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( self::CUSTOM_ABILITY_ACTION ); ?>" />
										<input type="hidden" name="custom_ability_action" value="test" />
										<input type="hidden" name="custom_ability_name" value="<?php echo esc_attr( $slug ); ?>" />
										<?php submit_button( 'Test', 'secondary small', 'submit', false ); ?>
									</form>

									<?php if ( 'active' === $state ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin:0 8px 8px 0;">
											<?php wp_nonce_field( self::CUSTOM_ABILITY_ACTION ); ?>
											<input type="hidden" name="action" value="<?php echo esc_attr( self::CUSTOM_ABILITY_ACTION ); ?>" />
											<input type="hidden" name="custom_ability_action" value="turn_off" />
											<input type="hidden" name="custom_ability_name" value="<?php echo esc_attr( $slug ); ?>" />
											<?php submit_button( 'Turn Off', 'secondary small', 'submit', false ); ?>
										</form>
									<?php else : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin:0 8px 8px 0;">
											<?php wp_nonce_field( self::CUSTOM_ABILITY_ACTION ); ?>
											<input type="hidden" name="action" value="<?php echo esc_attr( self::CUSTOM_ABILITY_ACTION ); ?>" />
											<input type="hidden" name="custom_ability_action" value="turn_on" />
											<input type="hidden" name="custom_ability_name" value="<?php echo esc_attr( $slug ); ?>" />
											<button type="submit" class="button button-primary button-small" <?php disabled( ! $activation_ready ); ?>>Turn On</button>
										</form>
										<?php if ( ! $activation_ready ) : ?>
											<p class="description">Run a passing test on the current file before turning it on.</p>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_page() {
		$instances = Dollypack_Runtime::get_settings_ability_instances();
		$enabled   = get_option( self::OPTION_KEY, array_keys( $instances ) );
		$groups    = $this->get_grouped_abilities( $instances );
		$custom_notice = $this->pop_custom_ability_notice();
		?>
		<div class="wrap">
			<h1>Dollypack</h1>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>Settings saved.</p>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $custom_notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'error' === ( $custom_notice['type'] ?? '' ) ? 'error' : 'success' ); ?> is-dismissible">
					<p><?php echo esc_html( $custom_notice['message'] ); ?></p>
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
			<?php $this->render_custom_abilities_section(); ?>
		</div>
		<?php
	}
}
