<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dollypack_GitHub_Search extends Dollypack_GitHub_Ability {

	protected $id          = 'github-search';
	protected $name        = 'dollypack/github-search';
	protected $label       = 'GitHub Search';
	protected $description = 'Search GitHub for code, issues, repositories, or commits.';

	public function execute( $input ) {
		$type  = $input['type'] ?? '';
		$query = $input['query'] ?? '';

		if ( empty( $type ) || empty( $query ) ) {
			return new WP_Error( 'missing_params', 'Both type and query parameters are required.' );
		}

		$per_page = $input['per_page'] ?? 10;
		$page     = $input['page'] ?? 1;

		$endpoint = '/search/' . urlencode( $type ) . '?' . http_build_query( array(
			'q'        => $query,
			'per_page' => $per_page,
			'page'     => $page,
		) );

		return $this->github_request( $endpoint );
	}

	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'type'     => array(
					'type'        => 'string',
					'enum'        => array( 'code', 'issues', 'repositories', 'commits' ),
					'description' => 'Type of search to perform.',
					'required'    => true,
				),
				'query'    => array(
					'type'        => 'string',
					'description' => 'Search query string.',
					'required'    => true,
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => 'Number of results per page.',
					'default'     => 10,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => 'Page number.',
					'default'     => 1,
				),
			),
		);
	}

	public function get_output_schema() {
		return array(
			'type'        => 'object',
			'description' => 'Decoded JSON response with total_count and items.',
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
