<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dollypack_GitHub_Write extends Dollypack_GitHub_Ability {

	protected $id          = 'github-write';
	protected $name        = 'dollypack/github-write';
	protected $label       = 'GitHub Write';
	protected $description = 'Create or update resources on GitHub — issues, comments, pull requests, etc. Example endpoints: /repos/{owner}/{repo}/issues, /repos/{owner}/{repo}/issues/{number}/comments';

	public function execute( $input ) {
		$endpoint = $input['endpoint'] ?? '';
		$method   = $input['method'] ?? '';
		$body     = $input['body'] ?? null;

		if ( empty( $endpoint ) ) {
			return new WP_Error( 'missing_endpoint', 'The endpoint parameter is required.' );
		}

		if ( empty( $method ) ) {
			return new WP_Error( 'missing_method', 'The method parameter is required.' );
		}

		if ( empty( $body ) ) {
			return new WP_Error( 'missing_body', 'The body parameter is required.' );
		}

		return $this->github_request( $endpoint, $method, $body );
	}

	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'endpoint' => array(
					'type'        => 'string',
					'description' => 'GitHub API endpoint path, e.g. /repos/{owner}/{repo}/issues',
					'required'    => true,
				),
				'method'   => array(
					'type'        => 'string',
					'enum'        => array( 'POST', 'PATCH', 'PUT' ),
					'description' => 'HTTP method to use.',
					'required'    => true,
				),
				'body'     => array(
					'type'        => 'object',
					'description' => 'Request body to send as JSON.',
					'required'    => true,
				),
			),
		);
	}

	public function get_output_schema() {
		return array(
			'type'        => 'object',
			'description' => 'Decoded JSON response from the GitHub API.',
		);
	}

	public function get_meta() {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => false,
			),
		);
	}
}
