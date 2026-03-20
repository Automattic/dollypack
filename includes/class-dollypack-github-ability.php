<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Dollypack_GitHub_Ability extends Dollypack_Ability {

	protected $id = 'github';

	protected $group_label = 'GitHub';

	protected $settings = array(
		'github_token' => array(
			'type'  => 'password',
			'name'  => 'GitHub Token',
			'label' => 'Personal access token for the GitHub API.',
		),
	);

	/**
	 * Make an authenticated request to the GitHub API.
	 *
	 * @param string $endpoint API path, e.g. '/repos/owner/repo/contents/README.md'.
	 * @param string $method   HTTP method.
	 * @param mixed  $body     Optional request body (will be JSON-encoded).
	 * @return array|WP_Error  Decoded JSON response or WP_Error.
	 */
	protected function github_request( $endpoint, $method = 'GET', $body = null ) {
		$token = $this->get_setting( 'github_token' );

		if ( empty( $token ) ) {
			return new WP_Error(
				'missing_github_token',
				'GitHub token is not configured. Set it in Settings > Dollypack.'
			);
		}

		$url = 'https://api.github.com' . $endpoint;

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/vnd.github+json',
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
			$message = isset( $data['message'] ) ? $data['message'] : 'GitHub API error.';
			return new WP_Error( 'github_api_error', $message, array( 'status' => $status ) );
		}

		return $data;
	}
}
