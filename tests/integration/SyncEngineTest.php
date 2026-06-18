<?php
/**
 * Integration tests for Sync_Engine.
 *
 * These tests use a real Trial_Repository against the WP test DB, but mock
 * the ClinicalTrials.gov HTTP layer via the pre_http_request filter.
 *
 * NOTE: These tests require a WordPress test environment with a live database.
 * They are deferred from CI until the integration bootstrap is wired to a
 * real WP test install. Running `composer test:unit` (the unit suite) will
 * not execute these tests and should continue to pass at 20/20.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Sync\Sync_Engine;
use SKMCTF\Sync\Reconciler;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Support\Logger;
use SKMCTF\LLM\Summary_Service;
use SKMCTF\Admin\Settings;
use SKMCTF\Post_Types\Trial_Meta;

final class SyncEngineTest extends WP_UnitTestCase {

	/**
	 * Set the conditions option for a test run.
	 *
	 * @param string[] $c Condition strings.
	 * @return void
	 */
	private function set_conditions( array $c ): void {
		update_option(
			Settings::OPTION,
			array_merge( Settings::all(), [ 'conditions' => $c, 'statuses' => [ 'RECRUITING' ] ] )
		);
	}

	/**
	 * Install a pre_http_request filter that returns canned study arrays in order.
	 *
	 * @param array[] $studies_by_call Each element is the studies array for call N.
	 * @return void
	 */
	private function mock_ctgov( array $studies_by_call ): void {
		$i = 0;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$i, $studies_by_call ) {
				if ( false === strpos( $url, 'clinicaltrials.gov' ) ) {
					return $pre;
				}
				$studies = $studies_by_call[ $i ] ?? [];
				$i++;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [ 'studies' => $studies ] ),
				];
			},
			10,
			3
		);
	}

	/**
	 * Build a minimal study fixture for the given NCT ID.
	 *
	 * @param string $nct  NCT identifier, e.g. 'NCT00000001'.
	 * @param string $date Last-update date string.
	 * @return array
	 */
	private function study( string $nct, string $date = '2026-03-10' ): array {
		return [
			'protocolSection' => [
				'identificationModule' => [
					'nctId'      => $nct,
					'briefTitle' => 'T ' . $nct,
				],
				'statusModule' => [
					'overallStatus'               => 'RECRUITING',
					'lastUpdatePostDateStruct'    => [ 'date' => $date ],
				],
			],
		];
	}

	/**
	 * Test 1: two trials inserted on first run; one dropped on second run is marked closed.
	 *
	 * @return void
	 */
	public function test_inserts_then_reconciles_dropped_on_clean_run(): void {
		$this->set_conditions( [ 'Pompe disease' ] );

		// First run: two trials returned.
		$this->mock_ctgov( [ [ $this->study( 'NCT00000001' ), $this->study( 'NCT00000002' ) ] ] );
		$engine  = Sync_Engine::build();
		$summary = $engine->run( 'test' );
		$this->assertSame( 2, $summary['inserted'] );
		$this->assertEmpty( $summary['errors'] );
		remove_all_filters( 'pre_http_request' );

		// Second run: only NCT1 returned -> NCT2 should be marked closed (default mode).
		$this->mock_ctgov( [ [ $this->study( 'NCT00000001' ) ] ] );
		$summary2 = Sync_Engine::build()->run( 'test' );
		// Guard: a clean second run must report no errors, so the close below
		// is attributable to reconciliation, not to a masked fetch failure.
		$this->assertEmpty( $summary2['errors'] );
		$this->assertSame( 'done', $summary2['dropped_result'] );
		$repo = new Trial_Repository();
		$id2  = $repo->find_id_by_nct( 'NCT00000002' );
		$this->assertSame( 'CLOSED', get_post_meta( $id2, Trial_Meta::KEYS['overall_status'], true ) );
	}

	/**
	 * Test 3 (include list): manually-included NCTs are fetched and upserted.
	 *
	 * Mocks the single-study endpoint (URL contains the NCT ID) to return a
	 * study object with protocolSection at the top level, as the v2 API does.
	 * Asserts the trial is inserted and survives (find_id_by_nct returns non-null).
	 *
	 * @return void
	 */
	public function test_include_nct_list_inserts_trial(): void {
		// No conditions — only the include list should insert this trial.
		$this->set_conditions( array() );
		update_option(
			Settings::OPTION,
			array_merge( Settings::all(), array( 'include_ncts' => array( 'NCT99999999' ) ) )
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false === strpos( $url, 'clinicaltrials.gov' ) ) {
					return $pre;
				}
				// Single-study endpoint returns the study object directly.
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $this->study( 'NCT99999999' ) ),
				);
			},
			10,
			3
		);

		$summary = Sync_Engine::build()->run( 'test' );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $summary['inserted'], 'Included NCT must be inserted.' );
		$this->assertNotNull(
			( new Trial_Repository() )->find_id_by_nct( 'NCT99999999' ),
			'Included NCT must be findable after run.'
		);
		// In include-only mode (no conditions) reconciliation must be skipped,
		// because $seen does not represent the full expected set.
		$this->assertSame( 'skipped_include_only', $summary['dropped_result'] );
	}

	/**
	 * Test 2 (no-wipe guard): a fetch error must NOT cause existing trials to be removed.
	 *
	 * This is the critical safety test. When the API returns an error status,
	 * had_error is set to true, and Reconciler.reconcile() returns 'skipped_error'
	 * without touching any existing posts.
	 *
	 * @return void
	 */
	public function test_fetch_error_does_not_wipe_existing(): void {
		$this->set_conditions( [ 'Pompe disease' ] );

		// Seed one trial.
		$this->mock_ctgov( [ [ $this->study( 'NCT00000003' ) ] ] );
		Sync_Engine::build()->run( 'test' );
		remove_all_filters( 'pre_http_request' );

		// Now the API errors out entirely.
		add_filter(
			'pre_http_request',
			static function () {
				return [ 'response' => [ 'code' => 503 ], 'body' => 'down' ];
			},
			10,
			3
		);
		$summary = Sync_Engine::build()->run( 'test' );
		$this->assertNotEmpty( $summary['errors'] );

		// The existing trial survives.
		$this->assertNotNull( ( new Trial_Repository() )->find_id_by_nct( 'NCT00000003' ) );
	}
}
