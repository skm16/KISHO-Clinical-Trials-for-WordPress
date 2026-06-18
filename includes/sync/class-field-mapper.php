<?php
/**
 * Pure field mapper: transforms CT.gov v2 nested JSON into flat meta array.
 *
 * This class has NO side-effects and calls NO WordPress functions.
 * It is safe to unit-test without a WordPress environment.
 *
 * @package SKMCTF\Sync
 */

namespace SKMCTF\Sync;

/**
 * Transforms raw CT.gov v2 study JSON into the flat meta array used by Trial_Repository.
 */
final class Field_Mapper {

	/**
	 * Map phase codes to human-readable labels.
	 */
	private const PHASE_LABELS = array(
		'EARLY_PHASE1' => 'Early Phase 1',
		'PHASE1'       => 'Phase 1',
		'PHASE2'       => 'Phase 2',
		'PHASE3'       => 'Phase 3',
		'PHASE4'       => 'Phase 4',
		'NA'           => 'Not Applicable',
	);

	/**
	 * Map a single CT.gov v2 study object to our flat meta array.
	 *
	 * Returns `['nct_id' => '']` for any study that cannot be identified
	 * (missing, empty, or malformed NCT ID). Callers must check `nct_id`.
	 *
	 * @param array $study Raw study object from CT.gov v2 JSON response.
	 * @return array Flat array keyed by Trial_Meta::KEYS short keys.
	 */
	public static function map( array $study ): array {
		$p    = $study['protocolSection'] ?? array();
		$id   = $p['identificationModule'] ?? array();
		$st   = $p['statusModule'] ?? array();
		$des  = $p['designModule'] ?? array();
		$spo  = $p['sponsorCollaboratorsModule'] ?? array();
		$con  = $p['conditionsModule'] ?? array();
		$dsc  = $p['descriptionModule'] ?? array();
		$elig = $p['eligibilityModule'] ?? array();
		$locs = $p['contactsLocationsModule']['locations'] ?? array();

		$nct = strtoupper( (string) ( $id['nctId'] ?? '' ) );
		if ( ! preg_match( '/^NCT\d{8}$/', $nct ) ) {
			return array( 'nct_id' => '' );
		}

		$phases = array_map(
			static function ( $ph ) {
				$ph = (string) $ph;
				return isset( self::PHASE_LABELS[ $ph ] )
					? self::PHASE_LABELS[ $ph ]
					: ucfirst( strtolower( str_replace( '_', ' ', $ph ) ) );
			},
			(array) ( $des['phases'] ?? array() )
		);

		$locations = array();
		foreach ( (array) $locs as $loc ) {
			if ( ! is_array( $loc ) ) {
				continue;
			}
			$geo         = $loc['geoPoint'] ?? array();
			$locations[] = array(
				'facility' => (string) ( $loc['facility'] ?? '' ),
				'city'     => (string) ( $loc['city'] ?? '' ),
				'state'    => (string) ( $loc['state'] ?? '' ),
				'country'  => (string) ( $loc['country'] ?? '' ),
				'status'   => (string) ( $loc['status'] ?? '' ),
				'lat'      => isset( $geo['lat'] ) ? (float) $geo['lat'] : null,
				'lng'      => isset( $geo['lon'] ) ? (float) $geo['lon'] : null,
			);
		}

		return array(
			'nct_id'         => $nct,
			'official_title' => (string) ( $id['officialTitle'] ?? '' ),
			'brief_title'    => (string) ( $id['briefTitle'] ?? '' ),
			'overall_status' => (string) ( $st['overallStatus'] ?? '' ),
			'phase'          => implode( '/', $phases ),
			'study_type'     => (string) ( $des['studyType'] ?? '' ),
			'conditions'     => array_values( array_map( 'strval', (array) ( $con['conditions'] ?? array() ) ) ),
			'lead_sponsor'   => (string) ( $spo['leadSponsor']['name'] ?? '' ),
			'locations'      => $locations,
			'eligibility'    => array(
				'sex'      => (string) ( $elig['sex'] ?? '' ),
				'min_age'  => (string) ( $elig['minimumAge'] ?? '' ),
				'max_age'  => (string) ( $elig['maximumAge'] ?? '' ),
				'criteria' => (string) ( $elig['eligibilityCriteria'] ?? '' ),
			),
			'brief_summary'  => (string) ( $dsc['briefSummary'] ?? '' ),
			'ct_last_update' => (string) ( $st['lastUpdatePostDateStruct']['date'] ?? '' ),
			'ct_url'         => 'https://clinicaltrials.gov/study/' . $nct,
		);
	}
}
