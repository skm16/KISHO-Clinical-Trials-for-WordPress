<?php
/**
 * Unit tests for Ctgov_Client::FIELDS — the request field vocabulary.
 *
 * Pure PHP unit test (no WordPress, no network). Guards the `fields` query
 * parameter sent to the CT.gov v2 API against the live API contract.
 *
 * WHY THIS EXISTS:
 * CT.gov rejects the ENTIRE request with HTTP 400 if `fields` contains a
 * single unknown piece-name (e.g. the plural "Conditions" instead of the
 * correct singular "Condition"). The HTTP layer is mocked in every
 * integration test, so a wrong field name is invisible there — it only
 * surfaces against the real API. These assertions lock the vocabulary so a
 * regression fails the in-shell unit gate instead of silently returning zero
 * trials in production.
 *
 * Every name below was validated against https://clinicaltrials.gov/api/v2/studies
 * (HTTP 200) on 2026-06-18. CT.gov piece-names are mostly SINGULAR even when
 * the JSON response nests them under a plural container.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SKMCTF\Sync\Ctgov_Client;

final class CtgovFieldsTest extends TestCase {

	/**
	 * The exact set of piece-names confirmed valid against the live v2 API.
	 *
	 * @var string[]
	 */
	private const VALID_FIELDS = array(
		'NCTId',
		'BriefTitle',
		'OfficialTitle',
		'OverallStatus',
		'LastUpdatePostDate',
		'BriefSummary',
		'Condition',
		'Phase',
		'StudyType',
		'LeadSponsorName',
		'Sex',
		'MinimumAge',
		'MaximumAge',
		'EligibilityCriteria',
		'LocationFacility',
		'LocationCity',
		'LocationState',
		'LocationCountry',
		'LocationStatus',
		'LocationGeoPoint',
	);

	public function test_fields_constant_matches_validated_vocabulary(): void {
		$actual = explode( ',', Ctgov_Client::FIELDS );
		$this->assertSame(
			self::VALID_FIELDS,
			$actual,
			'Ctgov_Client::FIELDS drifted from the live-API-validated piece-names. '
			. 'Any unknown name makes CT.gov 400 the whole request (zero trials synced).'
		);
	}

	/**
	 * Regression guard for the specific bug that shipped in 1.0.0: the plural
	 * "Conditions" piece-name, which 400s. The correct name is "Condition".
	 */
	public function test_does_not_use_plural_conditions(): void {
		$fields = explode( ',', Ctgov_Client::FIELDS );
		$this->assertNotContains(
			'Conditions',
			$fields,
			'"Conditions" (plural) is not a valid CT.gov piece-name — use "Condition".'
		);
		$this->assertContains( 'Condition', $fields );
	}
}
