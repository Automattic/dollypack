<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dollypack_GitHub_Read extends Dollypack_GitHub_Ability {

	protected $id          = 'github-read';
	protected $name        = 'dollypack/github-read';
	protected $label       = 'GitHub Read';
	protected $description = 'Read files, directory listings, and repository metadata from the GitHub API. Example endpoints: /repos/{owner}/{repo}/contents/{path}, /repos/{owner}/{repo}, /repos/{owner}/{repo}/branches, /repos/{owner}/{repo}/readme';

	public function execute( $input ) {
		$endpoint = $input['endpoint'] ?? '';

		if ( empty( $endpoint ) ) {
			return new WP_Error( 'missing_endpoint', 'The endpoint parameter is required.' );
		}

		if ( ! empty( $input['ref'] ) ) {
			$separator = ( strpos( $endpoint, '?' ) !== false ) ? '&' : '?';
			$endpoint .= $separator . 'ref=' . urlencode( $input['ref'] );
		}

		return $this->github_request( $endpoint );
	}

	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'endpoint' => array(
					'type'        => 'string',
					'description' => 'GitHub API endpoint path, e.g. /repos/{owner}/{repo}/contents/{path}',
					'required'    => true,
				),
				'ref'      => array(
					'type'        => 'string',
					'description' => 'Optional branch, tag, or SHA to read from.',
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
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}
}
