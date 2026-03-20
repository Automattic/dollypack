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
	}

	public function sanitize_abilities( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$available = array_keys( dollypack_get_available_abilities() );
		return array_values( array_intersect( $value, $available ) );
	}

	public function render_page() {
		$available = dollypack_get_available_abilities();
		$enabled   = get_option( self::OPTION_KEY, array_keys( $available ) );
		?>
		<div class="wrap">
			<h1>Dollypack</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'dollypack_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">Enabled Abilities</th>
						<td>
							<?php foreach ( $available as $id => $ability ) : ?>
								<fieldset>
									<label>
										<input
											type="checkbox"
											name="<?php echo esc_attr( self::OPTION_KEY ); ?>[]"
											value="<?php echo esc_attr( $id ); ?>"
											<?php checked( in_array( $id, $enabled, true ) ); ?>
										/>
										<strong><?php echo esc_html( $id ); ?></strong>
									</label>
									<p class="description"><?php echo esc_html( $ability['description'] ); ?></p>
								</fieldset>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
