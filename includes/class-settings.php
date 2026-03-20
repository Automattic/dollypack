<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dollypack_Settings {

	const OPTION_KEY = 'dollypack_enabled_abilities';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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

	public function register_settings() {
		register_setting( 'dollypack_settings', self::OPTION_KEY, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_abilities' ),
			'default'           => array_keys( dollypack_get_available_abilities() ),
		) );

		// Register each setting option declared by abilities.
		$instances = dollypack_get_ability_instances();
		$registered_options = array();

		foreach ( $instances as $ability ) {
			foreach ( $ability->get_all_settings() as $setting_id => $setting ) {
				$option_name = $ability->get_setting_option_name( $setting_id );
				if ( isset( $registered_options[ $option_name ] ) ) {
					continue;
				}
				$registered_options[ $option_name ] = true;

				register_setting( 'dollypack_settings', $option_name, array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				) );
			}
		}
	}

	public function sanitize_abilities( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$available = array_keys( dollypack_get_available_abilities() );
		return array_values( array_intersect( $value, $available ) );
	}

	/**
	 * Group abilities by their parent class group_label.
	 */
	private function get_grouped_abilities( $instances ) {
		$groups      = array();
		$other_group = array( 'label' => 'Other', 'settings' => array(), 'abilities' => array() );
		$collected_settings = array();

		foreach ( $instances as $id => $ability ) {
			$group_label = $ability->group_label ?? '';

			if ( empty( $group_label ) ) {
				$other_group['abilities'][ $id ] = $ability;
				continue;
			}

			if ( ! isset( $groups[ $group_label ] ) ) {
				$groups[ $group_label ] = array(
					'label'     => $group_label,
					'settings'  => array(),
					'abilities' => array(),
				);
			}

			$parent_class = ( new ReflectionClass( $ability ) )->getParentClass();
			if ( $parent_class && $parent_class->getName() !== 'Dollypack_Ability' ) {
				$parent_defaults = $parent_class->getDefaultProperties();
				if ( ! empty( $parent_defaults['settings'] ) ) {
					foreach ( $parent_defaults['settings'] as $setting_id => $setting ) {
						$option_name = $ability->get_setting_option_name( $setting_id );
						if ( ! isset( $collected_settings[ $option_name ] ) ) {
							$collected_settings[ $option_name ] = true;
							$groups[ $group_label ]['settings'][ $setting_id ] = array_merge(
								$setting,
								array( 'option_name' => $option_name )
							);
						}
					}
				}
			}

			$groups[ $group_label ]['abilities'][ $id ] = $ability;
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
			<form method="post" action="options.php">
				<?php settings_fields( 'dollypack_settings' ); ?>

				<?php foreach ( $groups as $group ) : ?>
					<h2><?php echo esc_html( $group['label'] ); ?></h2>
					<table class="form-table">
						<?php foreach ( $group['settings'] as $setting_id => $setting ) : ?>
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $setting['option_name'] ); ?>">
										<?php echo esc_html( $setting['name'] ); ?>
									</label>
								</th>
								<td>
									<input
										type="<?php echo esc_attr( $setting['type'] ?? 'text' ); ?>"
										id="<?php echo esc_attr( $setting['option_name'] ); ?>"
										name="<?php echo esc_attr( $setting['option_name'] ); ?>"
										value="<?php echo esc_attr( get_option( $setting['option_name'], '' ) ); ?>"
										class="regular-text"
									/>
									<?php if ( ! empty( $setting['label'] ) ) : ?>
										<p class="description"><?php echo esc_html( $setting['label'] ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>

						<tr>
							<th scope="row">Abilities</th>
							<td>
								<?php
								foreach ( $group['abilities'] as $id => $ability ) :
									$has_settings = $ability->has_required_settings();
								?>
									<fieldset>
										<label>
											<input
												type="checkbox"
												name="<?php echo esc_attr( self::OPTION_KEY ); ?>[]"
												value="<?php echo esc_attr( $id ); ?>"
												<?php checked( $has_settings && in_array( $id, $enabled, true ) ); ?>
												<?php disabled( ! $has_settings ); ?>
											/>
											<strong><?php echo esc_html( $id ); ?></strong>
											&mdash; <?php echo esc_html( $ability->description ); ?>
										</label>
										<?php if ( ! $has_settings ) : ?>
											<p class="description" style="color: #996800;">Configure the settings above to enable this ability.</p>
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
