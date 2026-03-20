<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dollypack_Google_Calendar_Read extends Dollypack_Google_Ability {

	protected $id          = 'google-calendar-read';
	protected $name        = 'dollypack/google-calendar-read';
	protected $label       = 'Google Calendar Read';
	protected $description = 'Read calendars and events from Google Calendar. Actions: list_calendars, list_events, get_event.';

	const API_BASE = 'https://www.googleapis.com/calendar/v3';

	public function execute( $input ) {
		$action = $input['action'] ?? 'list_events';

		switch ( $action ) {
			case 'list_calendars':
				return $this->list_calendars();

			case 'get_event':
				return $this->get_event( $input );

			case 'list_events':
			default:
				return $this->list_events( $input );
		}
	}

	private function list_calendars() {
		return $this->google_request( self::API_BASE . '/users/me/calendarList' );
	}

	private function list_events( $input ) {
		$calendar_id = $input['calendar_id'] ?? 'primary';
		$url         = self::API_BASE . '/calendars/' . urlencode( $calendar_id ) . '/events';

		$query_params = array();

		if ( ! empty( $input['time_min'] ) ) {
			$query_params['timeMin'] = $input['time_min'];
		}
		if ( ! empty( $input['time_max'] ) ) {
			$query_params['timeMax'] = $input['time_max'];
		}
		if ( ! empty( $input['query'] ) ) {
			$query_params['q'] = $input['query'];
		}
		if ( isset( $input['max_results'] ) ) {
			$query_params['maxResults'] = (int) $input['max_results'];
		}
		if ( isset( $input['single_events'] ) ) {
			$query_params['singleEvents'] = $input['single_events'] ? 'true' : 'false';
		} else {
			$query_params['singleEvents'] = 'true';
		}
		if ( ! empty( $input['order_by'] ) ) {
			$query_params['orderBy'] = $input['order_by'];
		}
		if ( ! empty( $input['page_token'] ) ) {
			$query_params['pageToken'] = $input['page_token'];
		}

		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params );
		}

		return $this->google_request( $url );
	}

	private function get_event( $input ) {
		$calendar_id = $input['calendar_id'] ?? 'primary';
		$event_id    = $input['event_id'] ?? '';

		if ( empty( $event_id ) ) {
			return new WP_Error( 'missing_event_id', 'event_id is required for get_event action.' );
		}

		$url = self::API_BASE . '/calendars/' . urlencode( $calendar_id ) . '/events/' . urlencode( $event_id );
		return $this->google_request( $url );
	}

	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'        => 'string',
					'enum'        => array( 'list_calendars', 'list_events', 'get_event' ),
					'description' => 'Action to perform.',
					'default'     => 'list_events',
				),
				'calendar_id'   => array(
					'type'        => 'string',
					'description' => 'Calendar ID. Defaults to "primary".',
					'default'     => 'primary',
				),
				'event_id'      => array(
					'type'        => 'string',
					'description' => 'Event ID (required for get_event).',
				),
				'time_min'      => array(
					'type'        => 'string',
					'description' => 'Lower bound (inclusive) for event start time, RFC3339 format (e.g. 2026-03-20T00:00:00Z).',
				),
				'time_max'      => array(
					'type'        => 'string',
					'description' => 'Upper bound (exclusive) for event end time, RFC3339 format.',
				),
				'query'         => array(
					'type'        => 'string',
					'description' => 'Free text search terms to find events.',
				),
				'max_results'   => array(
					'type'        => 'integer',
					'description' => 'Maximum number of events to return.',
				),
				'single_events' => array(
					'type'        => 'boolean',
					'description' => 'Expand recurring events into instances. Defaults to true.',
					'default'     => true,
				),
				'order_by'      => array(
					'type'        => 'string',
					'enum'        => array( 'startTime', 'updated' ),
					'description' => 'Sort order: startTime (requires singleEvents=true) or updated.',
				),
				'page_token'    => array(
					'type'        => 'string',
					'description' => 'Token for retrieving the next page of results.',
				),
			),
		);
	}

	public function get_output_schema() {
		return array(
			'type'        => 'object',
			'description' => 'Decoded JSON response from the Google Calendar API.',
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
