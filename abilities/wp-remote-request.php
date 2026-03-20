<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dollypack_WP_Remote_Request extends Dollypack_Ability {

	protected $id          = 'wp-remote-request';
	protected $name        = 'dollypack/wp-remote-request';
	protected $label       = 'WP Remote Request';
	protected $description = 'Perform an HTTP request using wp_remote_request().';

	public function execute( $input ) {
		$url = $input['url'] ?? '';

		if ( empty( $url ) ) {
			return new WP_Error( 'missing_url', 'The url parameter is required.' );
		}

		$args = array(
			'method'  => $input['method'] ?? 'GET',
			'timeout' => $input['timeout'] ?? 30,
		);

		if ( ! empty( $input['headers'] ) ) {
			$args['headers'] = (array) $input['headers'];
		}

		if ( isset( $input['body'] ) ) {
			$args['body'] = $input['body'];
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'status_code' => wp_remote_retrieve_response_code( $response ),
			'headers'     => wp_remote_retrieve_headers( $response )->getAll(),
			'body'        => wp_remote_retrieve_body( $response ),
		);
	}

	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'url'     => array(
					'type'        => 'string',
					'description' => 'The URL to send the request to.',
					'required'    => true,
				),
				'method'  => array(
					'type'        => 'string',
					'description' => 'HTTP method.',
					'default'     => 'GET',
				),
				'headers' => array(
					'type'                 => 'object',
					'description'          => 'Request headers.',
					'additional_properties' => array( 'type' => 'string' ),
				),
				'body'    => array(
					'type'        => 'string',
					'description' => 'Request body.',
				),
				'timeout' => array(
					'type'        => 'integer',
					'description' => 'Request timeout in seconds.',
					'default'     => 30,
				),
			),
		);
	}

	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status_code' => array(
					'type'        => 'integer',
					'description' => 'HTTP response status code.',
				),
				'headers'     => array(
					'type'        => 'object',
					'description' => 'Response headers.',
				),
				'body'        => array(
					'type'        => 'string',
					'description' => 'Response body.',
				),
			),
		);
	}

	public function get_meta() {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}
}
