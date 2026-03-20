<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dollypack_GitHub_Notifications extends Dollypack_GitHub_Ability {

	protected $id          = 'github-notifications';
	protected $name        = 'dollypack/github-notifications';
	protected $label       = 'GitHub Notifications';
	protected $description = 'List and manage GitHub notifications. Use action \'list\' to retrieve notifications, \'mark-read\' to mark a specific notification as read.';

	public function execute( $input ) {
		$action = $input['action'] ?? 'list';

		if ( 'mark-read' === $action ) {
			$notification_id = $input['notification_id'] ?? '';
			if ( empty( $notification_id ) ) {
				return new WP_Error( 'missing_notification_id', 'notification_id is required for mark-read action.' );
			}
			return $this->github_request( '/notifications/threads/' . $notification_id, 'PATCH' );
		}

		// Default: list notifications.
		$query_params = array();
		if ( isset( $input['all'] ) ) {
			$query_params['all'] = $input['all'] ? 'true' : 'false';
		}
		if ( isset( $input['participating'] ) ) {
			$query_params['participating'] = $input['participating'] ? 'true' : 'false';
		}

		$endpoint = '/notifications';
		if ( ! empty( $query_params ) ) {
			$endpoint .= '?' . http_build_query( $query_params );
		}

		return $this->github_request( $endpoint );
	}

	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'          => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'mark-read' ),
					'description' => 'Action to perform.',
					'default'     => 'list',
				),
				'notification_id' => array(
					'type'        => 'string',
					'description' => 'Notification thread ID (required for mark-read).',
				),
				'all'             => array(
					'type'        => 'boolean',
					'description' => 'Show notifications marked as read.',
				),
				'participating'   => array(
					'type'        => 'boolean',
					'description' => 'Only show notifications the user is directly participating in.',
				),
			),
		);
	}

	public function get_output_schema() {
		return array(
			'type'        => 'object',
			'description' => 'Decoded JSON response from the GitHub Notifications API.',
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
