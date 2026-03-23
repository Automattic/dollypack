<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Dollypack_Google_Ability extends Dollypack_Ability {

	protected $id = 'google';

	protected $group_label = 'Google';

	protected $settings = array(
		'google_client_id'     => array(
			'type'    => 'text',
			'name'    => 'Client ID',
			'label'   => 'OAuth Client ID from Google Cloud Console.',
			'storage' => 'site',
		),
		'google_client_secret' => array(
			'type'      => 'password',
			'name'      => 'Client Secret',
			'label'     => 'OAuth Client Secret from Google Cloud Console.',
			'storage'   => 'site',
			'encrypted' => true,
		),
	);

	const TOKEN_OPTION         = '_dollypack_google_access_token';
	const REFRESH_TOKEN_OPTION = '_dollypack_google_refresh_token';
	const EXPIRY_OPTION        = '_dollypack_google_token_expiry';

	const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	const SCOPE         = 'https://www.googleapis.com/auth/calendar.readonly';

	const CALLBACK_ACTION   = 'dollypack_google_oauth_callback';
	const DISCONNECT_ACTION = 'dollypack_google_disconnect';

	/**
	 * Track whether hooks have been registered to avoid duplicates.
	 */
	private static $hooks_registered = false;

	/**
	 * Token values that should be encrypted before storage.
	 */
	private static $encrypted_token_keys = array(
		self::TOKEN_OPTION,
		self::REFRESH_TOKEN_OPTION,
	);

	/**
	 * Get the current WordPress user ID for per-user OAuth storage.
	 */
	private static function get_token_user_id() {
		return get_current_user_id();
	}

	/**
	 * Read a stored OAuth token value for the current user.
	 */
	private static function get_token_value( $meta_key, $user_id = 0 ) {
		$user_id = $user_id ?: self::get_token_user_id();
		if ( ! $user_id ) {
			return '';
		}

		$value = get_user_meta( $user_id, $meta_key, true );

		if ( ! self::should_encrypt_token_value( $meta_key ) || '' === $value ) {
			return $value;
		}

		$decrypted_value = Dollypack_Crypto::decrypt( $value );

		// Migrate legacy plaintext tokens after the first successful read.
		if ( ! Dollypack_Crypto::is_encrypted_string( $value ) && '' !== $decrypted_value ) {
			self::set_token_value( $meta_key, $decrypted_value, $user_id );
		}

		return $decrypted_value;
	}

	/**
	 * Persist a stored OAuth token value for the current user.
	 */
	private static function set_token_value( $meta_key, $value, $user_id = 0 ) {
		$user_id = $user_id ?: self::get_token_user_id();
		if ( ! $user_id ) {
			return false;
		}

		if ( self::should_encrypt_token_value( $meta_key ) && '' !== $value ) {
			$value = Dollypack_Crypto::encrypt( (string) $value );
		}

		return update_user_meta( $user_id, $meta_key, $value );
	}

	/**
	 * Determine whether a token/meta value should be encrypted at rest.
	 */
	private static function should_encrypt_token_value( $meta_key ) {
		return in_array( $meta_key, self::$encrypted_token_keys, true );
	}

	/**
	 * Delete all stored OAuth token values for the current user.
	 */
	private static function delete_token_values( $user_id = 0 ) {
		$user_id = $user_id ?: self::get_token_user_id();
		if ( ! $user_id ) {
			return;
		}

		delete_user_meta( $user_id, self::TOKEN_OPTION );
		delete_user_meta( $user_id, self::REFRESH_TOKEN_OPTION );
		delete_user_meta( $user_id, self::EXPIRY_OPTION );
	}

	/**
	 * Register the Google admin-post hooks once per request.
	 */
	public static function ensure_hooks_registered() {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'admin_post_' . self::CALLBACK_ACTION, array( __CLASS__, 'handle_oauth_callback' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( __CLASS__, 'handle_disconnect' ) );
		self::$hooks_registered = true;
	}

	public function __construct() {
		self::ensure_hooks_registered();
	}

	/**
	 * Build the Google OAuth authorization URL.
	 */
	public static function get_authorization_url() {
		$client_id    = get_option( 'dollypack_google_google_client_id', '' );
		$redirect_uri = admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );
		$state        = wp_create_nonce( self::CALLBACK_ACTION );

		return add_query_arg(
			array(
				'client_id'     => $client_id,
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			),
			self::AUTHORIZE_URL
		);
	}

	/**
	 * Handle the OAuth callback from Google.
	 */
	public static function handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! wp_verify_nonce( $state, self::CALLBACK_ACTION ) ) {
			wp_die( 'Invalid state parameter.' );
		}

		if ( isset( $_GET['error'] ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=dollypack&google_error=' . sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) );
			exit;
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( empty( $code ) ) {
			wp_die( 'Missing authorization code.' );
		}

		$result = self::exchange_code_for_tokens( $code );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=dollypack&google_error=' . urlencode( $result->get_error_message() ) ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=dollypack&google_connected=1' ) );
		exit;
	}

	/**
	 * Exchange an authorization code for access and refresh tokens.
	 */
	private static function exchange_code_for_tokens( $code ) {
		$client_id     = get_option( 'dollypack_google_google_client_id', '' );
		$client_secret = Dollypack_Crypto::decrypt( get_option( 'dollypack_google_google_client_secret', '' ) );
		$redirect_uri  = admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );

		$response = wp_remote_post( self::TOKEN_URL, array(
			'body'    => array(
				'code'          => $code,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $data['error'] ) ) {
			return new WP_Error( 'google_token_error', $data['error_description'] ?? $data['error'] );
		}

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'google_token_error', 'No access token in response.' );
		}

		self::set_token_value( self::TOKEN_OPTION, $data['access_token'] );
		self::set_token_value( self::EXPIRY_OPTION, time() + (int) ( $data['expires_in'] ?? 3600 ) );

		if ( ! empty( $data['refresh_token'] ) ) {
			self::set_token_value( self::REFRESH_TOKEN_OPTION, $data['refresh_token'] );
		}

		return true;
	}

	/**
	 * Handle disconnecting Google (delete stored tokens).
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		check_admin_referer( self::DISCONNECT_ACTION );

		self::delete_token_values();

		wp_safe_redirect( admin_url( 'options-general.php?page=dollypack&google_disconnected=1' ) );
		exit;
	}

	/**
	 * Refresh the access token using the refresh token.
	 */
	private function refresh_access_token() {
		$refresh_token = self::get_token_value( self::REFRESH_TOKEN_OPTION );
		if ( empty( $refresh_token ) ) {
			return new WP_Error( 'no_refresh_token', 'No refresh token available. Please reconnect to Google.' );
		}

		$client_id     = $this->get_setting( 'google_client_id' );
		$client_secret = $this->get_setting( 'google_client_secret' );

		$response = wp_remote_post( self::TOKEN_URL, array(
			'body'    => array(
				'refresh_token' => $refresh_token,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'refresh_token',
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $data['error'] ) ) {
			// invalid_grant means the refresh token is revoked or expired.
			if ( 'invalid_grant' === $data['error'] ) {
				self::delete_token_values();
			}
			return new WP_Error( 'google_refresh_error', $data['error_description'] ?? $data['error'] );
		}

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'google_refresh_error', 'No access token in refresh response.' );
		}

		self::set_token_value( self::TOKEN_OPTION, $data['access_token'] );
		self::set_token_value( self::EXPIRY_OPTION, time() + (int) ( $data['expires_in'] ?? 3600 ) );

		return $data['access_token'];
	}

	/**
	 * Get a valid access token, refreshing if expired or expiring within 60s.
	 */
	protected function get_access_token() {
		$token  = self::get_token_value( self::TOKEN_OPTION );
		$expiry = (int) self::get_token_value( self::EXPIRY_OPTION );

		if ( ! empty( $token ) && $expiry > ( time() + 60 ) ) {
			return $token;
		}

		return $this->refresh_access_token();
	}

	/**
	 * Make an authenticated request to a Google API.
	 *
	 * @param string $url    Full API URL.
	 * @param string $method HTTP method.
	 * @param mixed  $body   Optional request body (will be JSON-encoded).
	 * @return array|WP_Error Decoded JSON response or WP_Error.
	 */
	protected function google_request( $url, $method = 'GET', $body = null ) {
		$token = $this->get_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'User-Agent'    => 'Dollypack-WordPress-Plugin',
			),
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$args['body']                    = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Google API error.';
			return new WP_Error( 'google_api_error', $message, array( 'status' => $status ) );
		}

		return $data;
	}

	/**
	 * Check that credentials are configured AND a refresh token exists.
	 */
	public function has_required_settings() {
		if ( ! parent::has_required_settings() ) {
			return false;
		}

		$refresh_token = self::get_token_value( self::REFRESH_TOKEN_OPTION );
		return ! empty( $refresh_token );
	}

	/**
	 * Render Google-specific settings rows for the admin page.
	 */
	public static function render_settings_html() {
		$client_id       = get_option( 'dollypack_google_google_client_id', '' );
		$client_secret   = get_option( 'dollypack_google_google_client_secret', '' );
		$refresh_token   = self::get_token_value( self::REFRESH_TOKEN_OPTION );
		$has_credentials = ! empty( $client_id ) && ! empty( $client_secret );
		$is_connected    = ! empty( $refresh_token );
		$redirect_uri    = admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );
		?>
		<tr>
			<th scope="row">Redirect URI</th>
			<td>
				<code><?php echo esc_html( $redirect_uri ); ?></code>
				<p class="description">Add this URL as an <strong>Authorized redirect URI</strong> in your Google Cloud Console under Credentials &gt; OAuth 2.0 Client IDs.</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Connection</th>
			<td>
				<?php if ( $is_connected ) : ?>
					<p style="color: #00a32a; margin-top: 0;">
						<strong>&#10003; Connected to Google for your account</strong>
					</p>
					<a
						href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISCONNECT_ACTION ), self::DISCONNECT_ACTION ) ); ?>"
						class="button"
					>
						Disconnect
					</a>
				<?php elseif ( $has_credentials ) : ?>
					<a
						href="<?php echo esc_url( self::get_authorization_url() ); ?>"
						class="button button-primary"
					>
						Connect to Google
					</a>
					<p class="description">Save the site-wide Client ID and Client Secret above, then click to authorize your account.</p>
				<?php else : ?>
					<p class="description">
						Enter your Google OAuth Client ID and Client Secret above, then save to enable the Connect button.
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
