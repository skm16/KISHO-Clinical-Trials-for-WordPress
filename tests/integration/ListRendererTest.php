<?php
/**
 * Integration test: List_Renderer renders cards and escapes malicious input.
 *
 * Requires a full WordPress test environment (WP_UnitTestCase).
 * Run with: composer test:integration -- --filter ListRendererTest
 *
 * NOTE: This test is deferred — the WP test suite is not configured in this
 * local dev environment. It is written to spec, passes php -l, and will run
 * once the integration bootstrap (phpunit-integration.xml.dist) is wired to
 * a WP test install. See task-11-report.md for full deferral note.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Frontend\List_Renderer;
use SKMCTF\Data\Trial_Repository;

final class ListRendererTest extends WP_UnitTestCase {

	/**
	 * Test that a trial card renders with expected content and escapes XSS.
	 */
	public function test_renders_card_for_a_trial_and_escapes(): void {
		( new Trial_Repository() )->upsert( [
			'nct_id'         => 'NCT00000009',
			'brief_title'    => 'Safe <script>x</script> Title',
			'official_title' => 'O',
			'overall_status' => 'RECRUITING',
			'phase'          => 'Phase 2',
			'study_type'     => 'INTERVENTIONAL',
			'conditions'     => [ 'Pompe Disease' ],
			'lead_sponsor'   => 'Acme',
			'locations'      => [],
			'eligibility'    => [ 'sex' => 'ALL', 'min_age' => '', 'max_age' => '', 'criteria' => '' ],
			'brief_summary'  => 'A summary.',
			'ct_last_update' => '2026-03-10',
			'ct_url'         => 'https://clinicaltrials.gov/study/NCT00000009',
		] );

		$html = List_Renderer::render( [ 'per_page' => 10 ] );

		$this->assertStringContainsString( 'Pompe Disease', $html );
		$this->assertStringContainsString( 'ClinicalTrials.gov', $html );
		$this->assertStringNotContainsString( '<script>x</script>', $html ); // must be escaped
	}

	/**
	 * Test that the empty state message is shown when no trials match.
	 */
	public function test_empty_state_when_no_trials(): void {
		$html = List_Renderer::render( [ 'status' => 'COMPLETED' ] );
		$this->assertStringContainsString( 'No clinical trials', $html );
	}
}
