<?php
/**
 * CT.gov v2 API client.
 *
 * Fetches studies from the ClinicalTrials.gov v2 REST API using WordPress
 * HTTP functions. Paginates automatically and never throws — all failures
 * are returned as WP_Error.
 *
 * @package SKMCTF\Sync
 */

namespace SKMCTF\Sync;

final class Ctgov_Client {

	/**
	 * Base endpoint for the CT.gov v2 studies resource.
	 */
	private const ENDPOINT = 'https://clinicaltrials.gov/api/v2/studies';

	/**
	 * Maximum pages to fetch in a single run (safety circuit-breaker).
	 */
	private const MAX_PAGES = 50;

	/**
	 * Comma-separated list of CT.gov v2 field piece names to request.
	 *
	 * These are the "piece name" identifiers used in the `fields` query
	 * parameter. The API returns them nested under module paths in the JSON.
	 */
	public const FIELDS = 'NCTId,BriefTitle,OfficialTitle,OverallStatus,LastUpdatePostDate,BriefSummary,Conditions,Phase,StudyType,LeadSponsorName,Sex,MinimumAge,MaximumAge,EligibilityCriteria,LocationFacility,LocationCity,LocationState,LocationCountry,LocationStatus,LocationGeoPoint';

	/**
	 * Fetch all studies for a given condition and set of statuses.
	 *
	 * Paginates through all result pages until no nextPageToken is present
	 * or MAX_PAGES is reached. Returns all raw study objects (pre-mapping).
	 *
	 * @param string   $condition Free-text condition query (e.g. "Pompe disease").
	 * @param string[] $statuses  List of overall-status codes (e.g. ['RECRUITING']).
	 * @return array { studies: array[], error: \WP_Error|null }
	 */
	public function fetch_all_for_condition( string $condition, array $statuses ): array {
		$studies = [];
		$token   = null;
		$pages   = 0;

		do {
			$pages++;
			$args = [
				'query.cond'           => $condition,
				'filter.overallStatus' => implode( '|', array_map( 'sanitize_text_field', $statuses ) ),
				'fields'               => self::FIELDS,
				'pageSize'             => 100,
				'format'               => 'json',
			];

			if ( null === $token ) {
				$args['countTotal'] = 'true';
			} else {
				$args['pageToken'] = $token;
			}

			$url      = self::ENDPOINT . '?' . http_build_query( $args );
			$response = $this->request( $url );

			if ( is_wp_error( $response ) ) {
				return [ 'studies' => [], 'error' => $response ];
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return [
					'studies' => [],
					'error'   => new \WP_Error( 'skmctf_http', "CT.gov returned HTTP {$code}." ),
				];
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || ! isset( $data['studies'] ) ) {
				return [
					'studies' => [],
					'error'   => new \WP_Error( 'skmctf_parse', 'Unexpected CT.gov response.' ),
				];
			}

			foreach ( $data['studies'] as $s ) {
				$studies[] = $s;
			}

			$token = $data['nextPageToken'] ?? null;

		} while ( $token && $pages < self::MAX_PAGES );

		return [ 'studies' => $studies, 'error' => null ];
	}

	/**
	 * Perform a single GET request with one retry on transient failure.
	 *
	 * @param string $url Fully-formed request URL.
	 * @return array|\WP_Error WordPress HTTP response or WP_Error.
	 */
	private function request( string $url ) {
		$opts = [
			'timeout'    => 15,
			'user-agent' => 'KishoClinicalTrials/1.0 (+https://skm.digital)',
			'headers'    => [ 'Accept' => 'application/json' ],
		];

		$response = wp_remote_get( $url, $opts );

		// One retry on WP_Error or 5xx transient server error.
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
			$response = wp_remote_get( $url, $opts );
		}

		return $response;
	}
}
