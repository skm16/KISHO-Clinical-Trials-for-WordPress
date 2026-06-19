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

/**
 * HTTP client for the ClinicalTrials.gov v2 REST API with automatic pagination.
 */
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
	public const FIELDS = 'NCTId,BriefTitle,OfficialTitle,OverallStatus,LastUpdatePostDate,BriefSummary,Condition,Phase,StudyType,LeadSponsorName,Sex,MinimumAge,MaximumAge,EligibilityCriteria,LocationFacility,LocationCity,LocationState,LocationCountry,LocationStatus,LocationGeoPoint';

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
		$studies = array();
		$token   = null;
		$pages   = 0;

		// Filter seam: allows synonym expansion / MONDO normalization of the condition query.
		$query_cond = (string) apply_filters( 'skmctf_condition_query', $condition );

		do {
			++$pages;
			$args = array(
				'query.cond'           => $query_cond,
				'filter.overallStatus' => implode( '|', array_map( 'sanitize_text_field', $statuses ) ),
				'fields'               => self::FIELDS,
				'pageSize'             => 100,
				'format'               => 'json',
			);

			if ( null === $token ) {
				$args['countTotal'] = 'true';
			} else {
				$args['pageToken'] = $token;
			}

			$url      = self::ENDPOINT . '?' . http_build_query( $args );
			$response = $this->request( $url );

			if ( is_wp_error( $response ) ) {
				return array(
					'studies' => array(),
					'error'   => $response,
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return array(
					'studies' => array(),
					'error'   => new \WP_Error( 'skmctf_http', "CT.gov returned HTTP {$code}." ),
				);
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || ! isset( $data['studies'] ) ) {
				return array(
					'studies' => array(),
					'error'   => new \WP_Error( 'skmctf_parse', 'Unexpected CT.gov response.' ),
				);
			}

			foreach ( $data['studies'] as $s ) {
				$studies[] = $s;
			}

			$token = $data['nextPageToken'] ?? null;

		} while ( $token && $pages < self::MAX_PAGES );

		return array(
			'studies' => $studies,
			'error'   => null,
		);
	}

	/**
	 * Fetch specific studies by their NCT IDs.
	 *
	 * Each NCT is fetched individually from the single-study endpoint.
	 * Failures for individual NCTs are collected but do not abort the batch.
	 *
	 * @param string[] $nct_ids List of NCT IDs (e.g. ['NCT01234567']).
	 * @return array{studies:array[],error:\WP_Error|null}
	 */
	public function fetch_by_nct_ids( array $nct_ids ): array {
		$studies = array();
		$errors  = array();
		foreach ( $nct_ids as $nct ) {
			$nct = strtoupper( trim( (string) $nct ) );
			if ( ! preg_match( '/^NCT\d{8}$/', $nct ) ) {
				continue;
			}
			$url      = self::ENDPOINT . '/' . rawurlencode( $nct ) . '?' . http_build_query(
				array(
					'fields' => self::FIELDS,
					'format' => 'json',
				)
			);
			$response = $this->request( $url );
			if ( is_wp_error( $response ) ) {
				$errors[] = $nct;
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				// 404 = NCT not found; skip silently (not a fatal error).
				continue;
			}
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			// Single-study endpoint returns the study object directly (protocolSection at top level).
			if ( is_array( $data ) && isset( $data['protocolSection'] ) ) {
				$studies[] = $data;
			}
		}
		$error = empty( $errors ) ? null : new \WP_Error(
			'skmctf_include_fetch',
			'Some included NCTs failed to fetch: ' . implode( ', ', $errors )
		);
		return array(
			'studies' => $studies,
			'error'   => $error,
		);
	}

	/**
	 * Perform a single GET request with one retry on transient failure.
	 *
	 * @param string $url Fully-formed request URL.
	 * @return array|\WP_Error WordPress HTTP response or WP_Error.
	 */
	private function request( string $url ) {
		$opts = array(
			'timeout'    => 15,
			'user-agent' => 'KishoClinicalTrials/1.0 (+https://skm.digital)',
			'headers'    => array( 'Accept' => 'application/json' ),
		);

		$response = wp_remote_get( $url, $opts );

		// One retry on WP_Error or 5xx transient server error.
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
			$response = wp_remote_get( $url, $opts );
		}

		return $response;
	}
}
